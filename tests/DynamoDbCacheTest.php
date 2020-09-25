<?php

namespace Rikudou\Tests\DynamoDbCache;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\Result;
use DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use ReflectionObject;
use Rikudou\DynamoDbCache\DynamoDbCache;
use Rikudou\DynamoDbCache\Exception\InvalidArgumentException;
use stdClass;

final class DynamoDbCacheTest extends TestCase
{
    private $itemPoolDefault = [
        [
            'id' => [
                'S' => 'test123',
            ],
            'ttl' => [
                'N' => 1893452400, // 2030-01-01
            ],
            'value' => [
                'S' => 's:4:"test";', // serialized 'test'
            ],
        ],
        [
            'id' => [
                'S' => 'test456',
            ],
            'ttl' => [
                'N' => 1262300400, // 2010-01-01
            ],
            'value' => [
                'S' => 'i:6;', // serialized 6
            ],
        ],
        [
            'id' => [
                'S' => 'test789',
            ],
            'value' => [
                'S' => 'O:8:"stdClass":2:{s:14:"randomProperty";s:4:"test";s:15:"randomProperty2";i:8;}', // serialized stdClass
            ],
        ],
    ];

    private $itemPoolCustom = [
        [
            'customId' => [
                'S' => 'test123',
            ],
            'customTtl' => [
                'N' => 1893452400,
            ],
            'customValue' => [
                'S' => 's:4:"test";',
            ],
        ],
    ];

    private $itemPoolSaved = [];

    /**
     * @var DynamoDbCache
     */
    private $instance;

    /**
     * @var DynamoDbCache
     */
    private $instanceCustom;

    /**
     * @var DynamoDbCache
     */
    private $instanceFailure;

    protected function setUp(): void
    {
        $this->instance = new DynamoDbCache('test', $this->getFakeClient($this->itemPoolDefault));
        $this->instanceFailure = new DynamoDbCache('test', $this->getFakeClient(
            $this->itemPoolDefault,
            'id',
            '',
            '',
            'RandomErrorCode'
        ));

        $idField = 'customId';
        $ttlField = 'customTtl';
        $valueField = 'customValue';
        $this->instanceCustom = new DynamoDbCache(
            'test',
            $this->getFakeClient(
                $this->itemPoolCustom,
                $idField,
                $ttlField,
                $valueField
            ),
            $idField,
            $ttlField,
            $valueField
        );
    }

    public function testGetItemDefaultFields()
    {
        $item = $this->instance->getItem('test123');
        self::assertEquals('test123', $item->getKey());
        self::assertEquals('test', $item->get());
        self::assertTrue($item->isHit());
        self::assertEquals(1893452400, $item->getExpiresAt()->getTimestamp());
        self::assertEquals('s:4:"test";', $item->getRaw());

        // expired item
        $item = $this->instance->getItem('test456');
        self::assertEquals('test456', $item->getKey());
        self::assertEquals(6, $item->get());
        self::assertFalse($item->isHit());
        self::assertEquals(1262300400, $item->getExpiresAt()->getTimestamp());
        self::assertEquals('i:6;', $item->getRaw());

        // no expiration, serialized object
        $item = $this->instance->getItem('test789');
        self::assertEquals('test789', $item->getKey());
        self::assertTrue($item->isHit());
        self::assertNull($item->getExpiresAt());
        self::assertEquals('O:8:"stdClass":2:{s:14:"randomProperty";s:4:"test";s:15:"randomProperty2";i:8;}', $item->getRaw());
        $value = $item->get();
        self::assertInstanceOf(stdClass::class, $value);
        self::assertEquals('test', $value->randomProperty);
        self::assertEquals(8, $value->randomProperty2);

        // nonexistent item
        $item = $this->instance->getItem('test852');
        self::assertEquals('test852', $item->getKey());
        self::assertEquals(null, $item->get());
        self::assertFalse($item->isHit());
        self::assertNull($item->getExpiresAt());
        self::assertEquals('N;', $item->getRaw());

        // dynamo db failure
        $this->expectException(DynamoDbException::class);
        $this->instanceFailure->getItem('test');
    }

    public function testGetItemNonDefaultFields()
    {
        $item = $this->instanceCustom->getItem('test123');
        self::assertEquals('test123', $item->getKey());
        self::assertEquals('test', $item->get());
        self::assertTrue($item->isHit());
        self::assertEquals(1893452400, $item->getExpiresAt()->getTimestamp());
        self::assertEquals('s:4:"test";', $item->getRaw());
    }

    public function testGetItemsDefaultFields()
    {
        $result = $this->instance->getItems([
            'test123',
            'test456',
            'test789',
            'test852',
            'test258',
        ]);

        self::assertCount(5, $result);
        foreach ($result as $item) {
            switch ($item->getKey()) {
                case 'test123':
                    self::assertEquals('test123', $item->getKey());
                    self::assertEquals('test', $item->get());
                    self::assertTrue($item->isHit());
                    self::assertEquals(1893452400, $item->getExpiresAt()->getTimestamp());
                    self::assertEquals('s:4:"test";', $item->getRaw());
                    break;
                case 'test456':
                    self::assertEquals('test456', $item->getKey());
                    self::assertEquals(6, $item->get());
                    self::assertFalse($item->isHit());
                    self::assertEquals(1262300400, $item->getExpiresAt()->getTimestamp());
                    self::assertEquals('i:6;', $item->getRaw());
                    break;
                case 'test789':
                    self::assertEquals('test789', $item->getKey());
                    self::assertTrue($item->isHit());
                    self::assertNull($item->getExpiresAt());
                    self::assertEquals('O:8:"stdClass":2:{s:14:"randomProperty";s:4:"test";s:15:"randomProperty2";i:8;}', $item->getRaw());
                    $value = $item->get();
                    self::assertInstanceOf(stdClass::class, $value);
                    self::assertEquals('test', $value->randomProperty);
                    self::assertEquals(8, $value->randomProperty2);
                    break;
                case 'test852':
                    self::assertEquals('test852', $item->getKey());
                    self::assertEquals(null, $item->get());
                    self::assertFalse($item->isHit());
                    self::assertNull($item->getExpiresAt());
                    self::assertEquals('N;', $item->getRaw());
                    break;
                case 'test258':
                    self::assertEquals('test258', $item->getKey());
                    self::assertEquals(null, $item->get());
                    self::assertFalse($item->isHit());
                    self::assertNull($item->getExpiresAt());
                    self::assertEquals('N;', $item->getRaw());
                    break;
            }
        }
    }

    public function testGetItemsNonDefaultFields()
    {
        $result = $this->instanceCustom->getItems([
            'test123',
            'test789',
        ]);

        self::assertCount(2, $result);
        foreach ($result as $item) {
            switch ($item->getKey()) {
                case 'test123':
                    self::assertTrue($item->isHit());
                    break;
                case 'test789':
                    self::assertFalse($item->isHit());
                    break;
            }
        }
    }

    public function testHasItemDefaultFields()
    {
        self::assertTrue($this->instance->hasItem('test123'));
        self::assertFalse($this->instance->hasItem('test456'));
        self::assertTrue($this->instance->hasItem('test789'));
        self::assertFalse($this->instance->hasItem('test852'));

        // dynamo db failure
        $this->expectException(DynamoDbException::class);
        $this->instanceFailure->getItem('test');
    }

    public function testHasItemNonDefaultFields()
    {
        self::assertTrue($this->instanceCustom->hasItem('test123'));
        self::assertFalse($this->instanceCustom->hasItem('test852'));
    }

    public function testClear()
    {
        self::assertFalse($this->instance->clear());
    }

    public function testDeleteItemDefaultKeys()
    {
        $result = $this->instance->deleteItem('test123');
        self::assertTrue($result);

        $result = $this->instance->deleteItem('test456');
        self::assertTrue($result);

        $result = $this->instance->deleteItem('test789');
        self::assertTrue($result);

        $result = $this->instance->deleteItem('test852');
        self::assertFalse($result);

        $item = $this->instance->getItem('test123');
        self::assertTrue($this->instance->deleteItem($item));

        $item = $this->instance->getItem('test456');
        self::assertTrue($this->instance->deleteItem($item));

        $item = $this->instance->getItem('test852');
        self::assertFalse($this->instance->deleteItem($item));
    }

    public function testDeleteItemNonDefaultKeys()
    {
        $result = $this->instanceCustom->deleteItem('test123');
        self::assertTrue($result);

        $result = $this->instanceCustom->deleteItem('test852');
        self::assertFalse($result);
    }

    public function testDeleteItemsDefaultKeys()
    {
        $result = $this->instance->deleteItems([
            'test123',
            'test456',
            'test789',
            'test852',
        ]);
        self::assertTrue($result);

        $result = $this->instance->deleteItems([ // simulate throughput exceeded
            'test852',
            'test258',
        ]);
        self::assertFalse($result);
    }

    public function testDeleteItemsNonDefaultKeys()
    {
        $result = $this->instanceCustom->deleteItems([
            'test123',
            'test456',
            'test789',
            'test852',
        ]);
        self::assertTrue($result);

        $result = $this->instanceCustom->deleteItems([ // simulate throughput exceeded
            'test852',
            'test258',
        ]);
        self::assertFalse($result);
    }

    public function testSaveDefaultKeys()
    {
        $cacheItem = $this->instance->getItem('test654');
        // initial condition check
        self::assertFalse($cacheItem->isHit());
        self::assertNull($cacheItem->get());
        // assign values
        $cacheItem->set('test654');
        $cacheItem->expiresAt(new DateTime('2030-01-01 15:30:45'));
        self::assertFalse($cacheItem->isHit());

        $result = $this->instance->save($cacheItem);
        self::assertTrue($result);

        self::assertFalse($cacheItem->isHit());
        self::assertEquals('test654', $cacheItem->get());
        self::assertEquals('2030-01-01 15:30:45', $cacheItem->getExpiresAt()->format('Y-m-d H:i:s'));

        $result = $this->instanceFailure->save($cacheItem);
        self::assertFalse($result);

        $result = $this->instance->save($this->getEmptyBaseCacheItem());
        self::assertTrue($result);
    }

    public function testSaveNonDefaultKeys()
    {
        $cacheItem = $this->instanceCustom->getItem('test654');
        // initial condition check
        self::assertFalse($cacheItem->isHit());
        self::assertNull($cacheItem->get());
        // assign values
        $cacheItem->set('test654');
        $cacheItem->expiresAt(new DateTime('2030-01-01 15:30:45'));
        self::assertFalse($cacheItem->isHit());

        $result = $this->instanceCustom->save($cacheItem);
        self::assertTrue($result);

        self::assertFalse($cacheItem->isHit());
        self::assertEquals('test654', $cacheItem->get());
        self::assertEquals('2030-01-01 15:30:45', $cacheItem->getExpiresAt()->format('Y-m-d H:i:s'));
    }

    public function testSaveDeferred()
    {
        $cacheItem = $this->instance->getItem('test852');

        $result = $this->instance->saveDeferred($cacheItem);
        self::assertTrue($result);

        $reflection = new ReflectionObject($this->instance);
        $deferredList = $reflection->getProperty('deferred');
        $deferredList->setAccessible(true);

        self::assertCount(1, $deferredList->getValue($this->instance));

        self::assertTrue($this->instance->saveDeferred($this->getEmptyBaseCacheItem()));
    }

    public function testCommit()
    {
        $deferred = (new ReflectionObject($this->instance))->getProperty('deferred');
        $deferred->setAccessible(true);

        // empty
        self::assertTrue($this->instance->commit());

        // success
        $item = $this->instance->getItem('test852');
        $item->set('test');
        $this->instance->saveDeferred($item);
        self::assertCount(1, $deferred->getValue($this->instance));
        self::assertCount(0, $this->itemPoolSaved);
        $result = $this->instance->commit();
        self::assertTrue($result);
        self::assertCount(0, $deferred->getValue($this->instance));
        self::assertCount(1, $this->itemPoolSaved);

        // failure
        $this->itemPoolSaved = [];
        $deferred = (new ReflectionObject($this->instanceFailure))->getProperty('deferred');
        $deferred->setAccessible(true);
        $item = $this->instance->getItem('test852');
        $item->set('test');
        $this->instanceFailure->saveDeferred($item);
        self::assertCount(1, $deferred->getValue($this->instanceFailure));
        self::assertCount(0, $this->itemPoolSaved);
        $result = $this->instanceFailure->commit();
        self::assertFalse($result);
        self::assertCount(1, $deferred->getValue($this->instanceFailure));
        self::assertCount(0, $this->itemPoolSaved);
    }

    private function getFakeClient(
        array $pool,
        string $idField = 'id',
        string $ttlField = 'ttl',
        string $valueField = 'value',
        string $awsErrorCode = 'ResourceNotFoundException'
    ): DynamoDbClient {
        return new class($pool, $idField, $ttlField, $valueField, $awsErrorCode, $this) extends DynamoDbClient {
            private $pool;

            private $idField;

            private $ttlField;

            private $valueField;

            private $awsErrorCode;

            private $parent;

            public function __construct(
                array $pool,
                string $idField,
                string $ttlField,
                string $valueField,
                string $awsErrorCode,
                DynamoDbCacheTest $parent
            ) {
                $this->pool = $pool;
                $this->idField = $idField;
                $this->ttlField = $ttlField;
                $this->valueField = $valueField;
                $this->awsErrorCode = $awsErrorCode;
                $this->parent = $parent;
            }

            public function getItem(array $args = [], bool $raw = false)
            {
                $availableIds = array_column(array_column($this->pool, $this->idField), 'S');
                $id = $args['Key'][$this->idField]['S'];
                if (!in_array($id, $availableIds, true)) {
                    throw $this->getException();
                }

                $data = array_filter($this->pool, function ($item) use ($id) {
                    return $item[$this->idField]['S'] === $id;
                });

                if ($raw) {
                    return reset($data);
                }

                return new Result([
                    'Item' => reset($data),
                ]);
            }

            public function batchGetItem(array $args = [])
            {
                $table = array_key_first($args['RequestItems']);
                $keys = array_column(
                    array_column(
                        $args['RequestItems'][$table]['Keys'],
                        $this->idField
                    ),
                    'S'
                );

                $result = [
                    'Responses' => [
                        $table => [],
                    ],
                ];
                $i = 0;
                foreach ($keys as $key) {
                    try {
                        $data = $this->getItem([
                            'Key' => [
                                $this->idField => [
                                    'S' => $key,
                                ],
                            ],
                        ], true);
                        $result['Responses'][$table][] = $data;
                    } catch (DynamoDbException $e) {
                        if ($i % 2 === 0) {
                            $result['UnprocessedKeys'][$table][]['Keys'][]['S'] = $key;
                        }
                    }
                    ++$i;
                }

                return new Result($result);
            }

            public function deleteItem(array $args = [])
            {
                $key = $args['Key'][$this->idField]['S'];
                $this->getItem([
                    'Key' => [
                        $this->idField => [
                            'S' => $key,
                        ],
                    ],
                ]);
            }

            public function batchWriteItem(array $args = [])
            {
                $table = array_key_first($args['RequestItems']);
                $keys = array_column(
                    array_column(
                        array_column(
                            array_column(
                                $args['RequestItems'][$table],
                                'DeleteRequest'
                            ),
                            'Key'
                        ),
                        $this->idField
                    ),
                    'S'
                );
                $count = count($keys);
                $unprocessed = 0;

                foreach ($keys as $key) {
                    try {
                        $this->deleteItem([
                            'Key' => [
                                $this->idField => [
                                    'S' => $key,
                                ],
                            ],
                        ]);
                    } catch (DynamoDbException $e) {
                        ++$unprocessed;
                    }
                }

                if ($unprocessed === $count) {
                    throw $this->getException('ProvisionedThroughputExceededException');
                }
            }

            public function putItem(array $args = [])
            {
                if ($this->awsErrorCode !== 'ResourceNotFoundException') {
                    throw $this->getException();
                }
                $reflection = new ReflectionObject($this->parent);
                $pool = $reflection->getProperty('itemPoolSaved');
                $pool->setAccessible(true);

                $currentPool = $pool->getValue($this->parent);
                $currentPool[] = $args['Item'];

                $pool->setValue($this->parent, $currentPool);
            }

            private function getException(string $errorCode = null): DynamoDbException
            {
                if ($errorCode === null) {
                    $errorCode = $this->awsErrorCode;
                }

                return new class($errorCode) extends DynamoDbException {
                    /**
                     * @var string
                     */
                    private $awsErrorCode;

                    public function __construct(string $errorCode)
                    {
                        $this->awsErrorCode = $errorCode;
                    }

                    public function getAwsErrorCode()
                    {
                        return $this->awsErrorCode;
                    }
                };
            }
        };
    }

    private function getEmptyBaseCacheItem()
    {
        return new class implements CacheItemInterface {
            public function getKey()
            {
                return 'test';
            }

            public function get()
            {
            }

            public function isHit()
            {
                return true;
            }

            public function set($value)
            {
            }

            public function expiresAt($expiration)
            {
            }

            public function expiresAfter($time)
            {
            }
        };
    }
}
