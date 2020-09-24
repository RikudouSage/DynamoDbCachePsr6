<?php

namespace Rikudou\DynamoDbCache;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use DateTime;
use DateTimeImmutable;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Rikudou\Clock\Clock;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Exception\InvalidArgumentException;

final class DynamoDbCache implements CacheItemPoolInterface
{
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

    public function __construct(
        string $tableName,
        DynamoDbClient $client,
        string $primaryField = 'id',
        string $ttlField = 'ttl',
        string $valueField = 'value',
        ?ClockInterface $clock = null
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
    }

    /**
     * @param string $key
     *
     * @return DynamoCacheItem
     */
    public function getItem($key)
    {
        try {
            $item = $this->client->getItem([
                'Key' => [
                    $this->primaryField => [
                        'S' => $key,
                    ],
                ],
                'TableName' => $this->tableName,
            ]);
            $item = $item->get('Item');
            $data = $item[$this->valueField]['S'] ?? null;

            assert($this->clock->now() instanceof DateTime || $this->clock->now() instanceof DateTimeImmutable);

            return new DynamoCacheItem(
                $key,
                $data !== null,
                $data !== null ? unserialize($data) : null,
                ($item[$this->ttlField]['N'] ?? null) !== null
                    ? $this->clock->now()->setTimestamp((int) $item[$this->ttlField]['N'])
                    : null,
                $this->clock
            );
        } catch (DynamoDbException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                return new DynamoCacheItem($key, false, null, null, $this->clock);
            }
            throw $e;
        }
    }

    /**
     * @param string[] $keys
     *
     * @return DynamoCacheItem[]
     */
    public function getItems(array $keys = [])
    {
        $response = $this->client->batchGetItem([
            'RequestItems' => [
                $this->tableName => [
                    'Keys' => array_map(function ($key) {
                        return [
                            $this->primaryField => [
                                'S' => $key,
                            ],
                        ];
                    }, $keys),
                ],
            ],
        ]);

        $result = [];
        assert($this->clock->now() instanceof DateTime || $this->clock->now() instanceof DateTimeImmutable);
        foreach ($response->get('Responses')[$this->tableName] as $item) {
            $result[] = new DynamoCacheItem(
                $item[$this->primaryField]['S'],
                true,
                unserialize($item[$this->valueField]['S']),
                ($item[$this->ttlField]['N'] ?? null) !== null
                    ? $this->clock->now()->setTimestamp((int) $item[$this->ttlField]['N'])
                    : null,
                $this->clock
            );
        }
        foreach ($response->get('UnprocessedKeys')[$this->tableName] ?? [] as $item) {
            $unprocessedKeys = $item['Keys'];
            foreach ($unprocessedKeys as $key) {
                $result[] = new DynamoCacheItem($key['S'], false, null, null, $this->clock);
            }
        }

        if (count($result) !== count($keys)) {
            $processedKeys = array_map(function (DynamoCacheItem $cacheItem) {
                return $cacheItem->getKey();
            }, $result);
            $unprocessed = array_diff($keys, $processedKeys);
            foreach ($unprocessed as $unprocessedKey) {
                $result[] = new DynamoCacheItem($unprocessedKey, false, null, null, $this->clock);
            }
        }

        return $result;
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function hasItem($key)
    {
        return $this->getItem($key)->isHit();
    }

    /**
     * @return false
     */
    public function clear()
    {
        return false;
    }

    /**
     * @param string|DynamoCacheItem $key
     *
     * @return bool
     */
    public function deleteItem($key)
    {
        if ($key instanceof DynamoCacheItem) {
            $key = $key->getKey();
        }

        try {
            $this->client->deleteItem([
                'Key' => [
                    $this->primaryField => [
                        'S' => $key,
                    ],
                ],
                'TableName' => $this->tableName,
            ]);

            return true;
        } catch (DynamoDbException $e) {
            return false;
        }
    }

    /**
     * @param string[] $keys
     *
     * @return bool
     */
    public function deleteItems(array $keys)
    {
        try {
            $this->client->batchWriteItem([
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
            ]);

            return true;
        } catch (DynamoDbException $e) {
            return false;
        }
    }

    /**
     * @param DynamoCacheItem $item
     *
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function save(CacheItemInterface $item)
    {
        if (!$item instanceof DynamoCacheItem) {
            throw new InvalidArgumentException('This handler can only save instances of ' . DynamoCacheItem::class);
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
        } catch (DynamoDbException $e) {
            return false;
        }
    }

    /**
     * @param DynamoCacheItem $item
     *
     * @return bool
     */
    public function saveDeferred(CacheItemInterface $item)
    {
        if (!$item instanceof DynamoCacheItem) {
            throw new InvalidArgumentException('This handler can only save instances of ' . DynamoCacheItem::class);
        }

        $this->deferred[] = $item;

        return true;
    }

    /**
     * @throws InvalidArgumentException
     *
     * @return bool
     */
    public function commit()
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
}
