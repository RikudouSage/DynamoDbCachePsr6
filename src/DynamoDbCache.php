<?php

namespace Rikudou\DynamoDbCache;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\KeysAndAttributes;
use DateInterval;
use LogicException;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Rikudou\Clock\Clock;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Converter\CacheItemConverterRegistry;
use Rikudou\DynamoDbCache\Converter\DefaultCacheItemConverter;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;
use Rikudou\DynamoDbCache\Exception\CacheItemNotFoundException;
use Rikudou\DynamoDbCache\Exception\InvalidArgumentException;

final class DynamoDbCache implements CacheItemPoolInterface, CacheInterface
{
    private const RESERVED_CHARACTERS = '{}()/\@:';
    private const MAX_KEY_LENGTH = 2048;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var DynamoDbClient
     */
    private $client;

    /**
     * @var string
     */
    private $primaryField;

    /**
     * @var string
     */
    private $ttlField;

    /**
     * @var string
     */
    private $valueField;

    /**
     * @var DynamoCacheItem[]
     */
    private $deferred = [];

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var CacheItemConverterRegistry
     */
    private $converter;

    /**
     * @var CacheItemEncoderInterface
     */
    private $encoder;

    /**
     * @var string|null
     */
    private $prefix;

    public function __construct(
        string $tableName,
        DynamoDbClient $client,
        string $primaryField = 'id',
        string $ttlField = 'ttl',
        string $valueField = 'value',
        ?ClockInterface $clock = null,
        ?CacheItemConverterRegistry $converter = null,
        ?CacheItemEncoderInterface $encoder = null,
        ?string $prefix = null
    ) {
        $this->tableName = $tableName;
        $this->client = $client;
        $this->primaryField = $primaryField;
        $this->ttlField = $ttlField;
        $this->valueField = $valueField;

        if ($clock === null) {
            $clock = new Clock();
        }
        $this->clock = $clock;

        if ($encoder === null) {
            $encoder = new SerializeItemEncoder();
        }
        $this->encoder = $encoder;

        if ($converter === null) {
            $converter = new CacheItemConverterRegistry(
                new DefaultCacheItemConverter($this->encoder, $this->clock)
            );
        }
        $this->converter = $converter;
        if ($prefix !== null && strlen($prefix) >= self::MAX_KEY_LENGTH) {
            throw new LogicException(
                sprintf('The prefix cannot be longer or equal to the maximum length: %d bytes', self::MAX_KEY_LENGTH)
            );
        }
        $this->prefix = $prefix;
    }

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return DynamoCacheItem
     */
    public function getItem($key): CacheItemInterface
    {
        if ($exception = $this->getExceptionForInvalidKey($this->getKey($key))) {
            throw $exception;
        }

        $finalKey = $this->getKey($key);
        if (strlen($finalKey) > self::MAX_KEY_LENGTH) {
            $finalKey = $this->generateCompliantKey($key);
        }

        try {
            $item = $this->getRawItem($finalKey);
            if (!isset($item[$this->valueField])) {
                throw new CacheItemNotFoundException();
            }
            $data = $item[$this->valueField]->getS() ?? null;

            assert(method_exists($this->clock->now(), 'setTimestamp'));

            return new DynamoCacheItem(
                $finalKey,
                $data !== null,
                $data !== null ? $this->encoder->decode($data) : null,
                isset($item[$this->ttlField]) && $item[$this->ttlField]->getN() !== null
                    ? $this->clock->now()->setTimestamp((int) $item[$this->ttlField]->getN())
                    : null,
                $this->clock,
                $this->encoder
            );
        } catch (CacheItemNotFoundException $e) {
            return new DynamoCacheItem(
                $finalKey,
                false,
                null,
                null,
                $this->clock,
                $this->encoder
            );
        }
    }

    /**
     * @param string[] $keys
     *
     * @throws InvalidArgumentException
     *
     * @return DynamoCacheItem[]
     */
    public function getItems(array $keys = []): iterable
    {
        $keys = array_map(function ($key) {
            if ($exception = $this->getExceptionForInvalidKey($this->getKey($key))) {
                throw $exception;
            }

            return $this->getKey($key);
        }, $keys);
        $response = $this->client->batchGetItem([
            'RequestItems' => [
                $this->tableName => new KeysAndAttributes([
                    'Keys' => array_map(function ($key) {
                        return [
                            $this->primaryField => new AttributeValue([
                                'S' => $key,
                            ]),
                        ];
                    }, $keys),
                ]),
            ],
        ]);

        $result = [];
        assert(method_exists($this->clock->now(), 'setTimestamp'));
        foreach ($response->getResponses()[$this->tableName] as $item) {
            if (!isset($item[$this->primaryField]) || $item[$this->primaryField]->getS() === null) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            if (!isset($item[$this->valueField]) || $item[$this->valueField]->getS() === null) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }
            $result[] = new DynamoCacheItem(
                $item[$this->primaryField]->getS(),
                true,
                $this->encoder->decode($item[$this->valueField]->getS()),
                isset($item[$this->ttlField]) && $item[$this->ttlField]->getN() !== null
                    ? $this->clock->now()->setTimestamp((int) $item[$this->ttlField]->getN())
                    : null,
                $this->clock,
                $this->encoder
            );
        }

        if (count($result) !== count($keys)) {
            $processedKeys = array_map(function (DynamoCacheItem $cacheItem) {
                return $cacheItem->getKey();
            }, $result);
            $unprocessed = array_diff($keys, $processedKeys);
            foreach ($unprocessed as $unprocessedKey) {
                $result[] = new DynamoCacheItem(
                    $unprocessedKey,
                    false,
                    null,
                    null,
                    $this->clock,
                    $this->encoder
                );
            }
        }

        return $result;
    }

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function hasItem($key): bool
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @return false
     */
    public function clear(): bool
    {
        return false;
    }

    /**
     * @param string|DynamoCacheItem $key
     *
     * @throws HttpException
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function deleteItem($key): bool
    {
        if ($key instanceof DynamoCacheItem) {
            $key = $key->getKey();
        } else {
            $key = $this->getKey($key);
        }

        if ($exception = $this->getExceptionForInvalidKey($key)) {
            throw $exception;
        }

        $item = $this->getRawItem($key);
        if (!isset($item[$this->valueField])) {
            return false;
        }

        return $this->client->deleteItem([
            'Key' => [
                $this->primaryField => [
                    'S' => $key,
                ],
            ],
            'TableName' => $this->tableName,
        ])->resolve();
    }

    /**
     * @param string[] $keys
     *
     * @throws HttpException
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function deleteItems(array $keys): bool
    {
        $keys = array_map(function ($key) {
            if ($exception = $this->getExceptionForInvalidKey($this->getKey($key))) {
                throw $exception;
            }

            return $this->getKey($key);
        }, $keys);

        return $this->client->batchWriteItem([
            'RequestItems' => [
                $this->tableName => array_map(function ($key) {
                    return [
                        'DeleteRequest' => [
                            'Key' => [
                                $this->primaryField => [
                                    'S' => $key,
                                ],
                            ],
                        ],
                    ];
                }, $keys),
            ],
        ])->resolve();
    }

    /**
     * @param CacheItemInterface $item
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function save(CacheItemInterface $item): bool
    {
        $item = $this->converter->convert($item);
        if ($exception = $this->getExceptionForInvalidKey($item->getKey())) {
            throw $exception;
        }

        try {
            $data = [
                'Item' => [
                    $this->primaryField => [
                        'S' => $item->getKey(),
                    ],
                    $this->valueField => [
                        'S' => $item->getRaw(),
                    ],
                ],
                'TableName' => $this->tableName,
            ];

            if ($expiresAt = $item->getExpiresAt()) {
                $data['Item'][$this->ttlField]['N'] = (string) $expiresAt->getTimestamp();
            }

            $this->client->putItem($data);

            return true;
            // @codeCoverageIgnoreStart
        } catch (ClientException $e) {
            return false;
            // @codeCoverageIgnoreEnd
        }
    }

    /**
     * @param CacheItemInterface $item
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item): bool
    {
        if ($exception = $this->getExceptionForInvalidKey($item->getKey())) {
            throw $exception;
        }
        $item = $this->converter->convert($item);

        $this->deferred[] = $item;

        return true;
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function commit(): bool
    {
        $result = true;
        foreach ($this->deferred as $key => $item) {
            $itemResult = $this->save($item);
            $result = $itemResult && $result;

            if ($itemResult) {
                unset($this->deferred[$key]);
            }
        }

        return $result;
    }

    /**
     * @param string $key
     * @param mixed  $default
     *
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $item = $this->getItem($key);
        if (!$item->isHit()) {
            return $default;
        }

        return $item->get();
    }

    /**
     * @param string                $key
     * @param mixed                 $value
     * @param int|DateInterval|null $ttl
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function set($key, $value, $ttl = null)
    {
        $item = $this->getItem($key);
        if ($ttl !== null) {
            $item->expiresAfter($ttl);
        }
        $item->set($value);

        return $this->save($item);
    }

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function delete($key)
    {
        return $this->deleteItem($key);
    }

    /**
     * @param iterable<string> $keys
     * @param mixed            $default
     *
     * @throws InvalidArgumentException
     *
     * @return mixed[]
     */
    public function getMultiple($keys, $default = null)
    {
        $result = array_combine(
            $this->iterableToArray($keys),
            array_map(function (DynamoCacheItem $item) use ($default) {
                if ($item->isHit()) {
                    return $item->get();
                }

                return $default;
            }, $this->iterableToArray($this->getItems($this->iterableToArray($keys))))
        );
        assert(is_array($result));

        return $result;
    }

    /**
     * @param iterable<string,mixed> $values
     * @param int|DateInterval|null  $ttl
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function setMultiple($values, $ttl = null)
    {
        foreach ($values as $key => $value) {
            $item = $this->getItem($key);
            $item->set($value);
            if ($ttl !== null) {
                $item->expiresAfter($ttl);
            }
            $this->saveDeferred($item);
        }

        return $this->commit();
    }

    /**
     * @param iterable<string> $keys
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function deleteMultiple($keys)
    {
        return $this->deleteItems($this->iterableToArray($keys));
    }

    /**
     * @param string $key
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function has($key)
    {
        return $this->hasItem($key);
    }

    private function getExceptionForInvalidKey(string $key): ?InvalidArgumentException
    {
        if (strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            return new InvalidArgumentException(
                sprintf(
                    "The key '%s' cannot contain any of the reserved characters: '%s'",
                    $key,
                    self::RESERVED_CHARACTERS
                )
            );
        }

        return null;
    }

    /**
     * @param iterable<mixed,mixed> $iterable
     *
     * @return array<mixed,mixed>
     */
    private function iterableToArray(iterable $iterable): array
    {
        if (is_array($iterable)) {
            return $iterable;
        } else {
            /** @noinspection PhpParamsInspection */
            return iterator_to_array($iterable);
        }
    }

    private function getKey(string $key): string
    {
        if ($this->prefix !== null) {
            return $this->prefix . $key;
        }

        return $key;
    }

    /**
     * @return array<string, AttributeValue>
     */
    private function getRawItem(string $key): array
    {
        $item = $this->client->getItem([
            'Key' => [
                $this->primaryField => [
                    'S' => $key,
                ],
            ],
            'TableName' => $this->tableName,
        ]);

        return $item->getItem();
    }

    private function generateCompliantKey(string $key): string
    {
        $key = $this->getKey($key);
        $suffix = '_trunc_' . md5($key);

        return substr(
            $this->getKey($key),
            0,
            self::MAX_KEY_LENGTH - strlen($suffix)
        ) . $suffix;
    }
}
