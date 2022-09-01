<?php

namespace Rikudou\Tests\DynamoDbCache;

use ArrayIterator;
use AsyncAws\Core\Response;
use AsyncAws\Core\Test\Http\SimpleMockedResponse;
use AsyncAws\Core\Test\ResultMockFactory;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Exception\ResourceNotFoundException;
use AsyncAws\DynamoDb\Result\BatchGetItemOutput;
use AsyncAws\DynamoDb\Result\BatchWriteItemOutput;
use AsyncAws\DynamoDb\Result\DeleteItemOutput;
use AsyncAws\DynamoDb\Result\GetItemOutput;
use AsyncAws\DynamoDb\Result\PutItemOutput;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use AsyncAws\DynamoDb\ValueObject\KeysAndAttributes;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionObject;
use Rikudou\Clock\Clock;
use Rikudou\Clock\TestClock;
use Rikudou\DynamoDbCache\DynamoCacheItem;
use Rikudou\DynamoDbCache\DynamoDbCache;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;
use Rikudou\DynamoDbCache\Exception\InvalidArgumentException;
use stdClass;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\TraceableHttpClient;

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

    /**
     * Created dynamically in setup
     */
    private $itemPoolPrefixed = [];

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
    private $instancePrefixed;

    /**
     * @var string
     */
    private $prefix;

    protected function setUp(): void
    {
        $this->prefix = 'randomPrefix#';
        $this->itemPoolPrefixed = array_map(function (array $item): array {
            $item['id']['S'] = $this->prefix . $item['id']['S'];
            return $item;
        }, $this->itemPoolDefault);

        $this->instance = new DynamoDbCache('test', $this->getFakeClient($this->itemPoolDefault));

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
        $this->instancePrefixed = new DynamoDbCache(
            'test',
            $this->getFakeClient($this->itemPoolPrefixed),
            'id',
            'ttl',
            'value',
            null,
            null,
            null,
            $this->prefix
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
    }

    public function testGetItemPrefixed()
    {
        $item = $this->instancePrefixed->getItem('test123');
        self::assertEquals($this->prefix . 'test123', $item->getKey());
        self::assertEquals('test', $item->get());
        self::assertTrue($item->isHit());
        self::assertEquals(1893452400, $item->getExpiresAt()->getTimestamp());
        self::assertEquals('s:4:"test";', $item->getRaw());

        // expired item
        $item = $this->instancePrefixed->getItem('test456');
        self::assertEquals($this->prefix . 'test456', $item->getKey());
        self::assertEquals(6, $item->get());
        self::assertFalse($item->isHit());
        self::assertEquals(1262300400, $item->getExpiresAt()->getTimestamp());
        self::assertEquals('i:6;', $item->getRaw());

        // no expiration, serialized object
        $item = $this->instancePrefixed->getItem('test789');
        self::assertEquals($this->prefix . 'test789', $item->getKey());
        self::assertTrue($item->isHit());
        self::assertNull($item->getExpiresAt());
        self::assertEquals('O:8:"stdClass":2:{s:14:"randomProperty";s:4:"test";s:15:"randomProperty2";i:8;}', $item->getRaw());
        $value = $item->get();
        self::assertInstanceOf(stdClass::class, $value);
        self::assertEquals('test', $value->randomProperty);
        self::assertEquals(8, $value->randomProperty2);

        // nonexistent item
        $item = $this->instancePrefixed->getItem('test852');
        self::assertEquals($this->prefix . 'test852', $item->getKey());
        self::assertEquals(null, $item->get());
        self::assertFalse($item->isHit());
        self::assertNull($item->getExpiresAt());
        self::assertEquals('N;', $item->getRaw());
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

    /**
     * @see https://github.com/RikudouSage/DynamoDbCachePsr6/issues/19
     */
    public function testGetItemKeyTooLong()
    {
        $key = bin2hex(random_bytes(1025));

        $item = $this->instance->getItem($key);
        self::assertNotEquals($key, $item->getKey());
        self::assertLessThanOrEqual(2048, strlen($item->getKey()));

        $item = $this->instancePrefixed->getItem($key);
        self::assertNotEquals($key, $item->getKey());
        self::assertNotEquals($this->prefix . $key, $item->getKey());
        self::assertStringStartsWith($this->prefix, $item->getKey());
        self::assertLessThanOrEqual(2048, strlen($item->getKey()));

        $key = bin2hex(random_bytes(1023));

        $item = $this->instance->getItem($key);
        self::assertEquals($key, $item->getKey());
        $item = $this->instancePrefixed->getItem($key);
        self::assertNotEquals($key, $item->getKey());

        $this->expectException(LogicException::class);
        new DynamoDbCache(
            'test',
            $this->getFakeClient($this->itemPoolPrefixed),
            'id',
            'ttl',
            'value',
            null,
            null,
            null,
            bin2hex(random_bytes(1024)),
        );
    }

    public function testGetItemsPrefixed()
    {
        $result = $this->instancePrefixed->getItems([
            'test123',
            'test456',
            'test789',
            'test852',
            'test258',
        ]);

        self::assertCount(5, $result);
        foreach ($result as $item) {
            switch ($item->getKey()) {
                case $this->prefix . 'test123':
                    self::assertEquals($this->prefix . 'test123', $item->getKey());
                    self::assertEquals('test', $item->get());
                    self::assertTrue($item->isHit());
                    self::assertEquals(1893452400, $item->getExpiresAt()->getTimestamp());
                    self::assertEquals('s:4:"test";', $item->getRaw());
                    break;
                case $this->prefix . 'test456':
                    self::assertEquals($this->prefix . 'test456', $item->getKey());
                    self::assertEquals(6, $item->get());
                    self::assertFalse($item->isHit());
                    self::assertEquals(1262300400, $item->getExpiresAt()->getTimestamp());
                    self::assertEquals('i:6;', $item->getRaw());
                    break;
                case $this->prefix . 'test789':
                    self::assertEquals($this->prefix . 'test789', $item->getKey());
                    self::assertTrue($item->isHit());
                    self::assertNull($item->getExpiresAt());
                    self::assertEquals('O:8:"stdClass":2:{s:14:"randomProperty";s:4:"test";s:15:"randomProperty2";i:8;}', $item->getRaw());
                    $value = $item->get();
                    self::assertInstanceOf(stdClass::class, $value);
                    self::assertEquals('test', $value->randomProperty);
                    self::assertEquals(8, $value->randomProperty2);
                    break;
                case $this->prefix . 'test852':
                    self::assertEquals($this->prefix . 'test852', $item->getKey());
                    self::assertEquals(null, $item->get());
                    self::assertFalse($item->isHit());
                    self::assertNull($item->getExpiresAt());
                    self::assertEquals('N;', $item->getRaw());
                    break;
                case $this->prefix . 'test258':
                    self::assertEquals($this->prefix . 'test258', $item->getKey());
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
    }

    public function testHasItemPrefixed()
    {
        self::assertTrue($this->instancePrefixed->hasItem('test123'));
        self::assertFalse($this->instancePrefixed->hasItem('test456'));
        self::assertTrue($this->instancePrefixed->hasItem('test789'));
        self::assertFalse($this->instancePrefixed->hasItem('test852'));
    }

    public function testHasItemNonDefaultFields()
    {
        self::assertTrue($this->instanceCustom->hasItem('test123'));
        self::assertFalse($this->instanceCustom->hasItem('test852'));
    }

    public function testClear()
    {
        self::assertFalse($this->instance->clear());
        self::assertFalse($this->instanceCustom->clear());
        self::assertFalse($this->instancePrefixed->clear());
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

    public function testDeleteItemPrefixed()
    {
        $result = $this->instancePrefixed->deleteItem('test123');
        self::assertTrue($result);

        $result = $this->instancePrefixed->deleteItem('test456');
        self::assertTrue($result);

        $result = $this->instancePrefixed->deleteItem('test789');
        self::assertTrue($result);

        $result = $this->instancePrefixed->deleteItem('test852');
        self::assertFalse($result);

        $item = $this->instancePrefixed->getItem('test123');
        self::assertTrue($this->instancePrefixed->deleteItem($item));

        $item = $this->instancePrefixed->getItem('test456');
        self::assertTrue($this->instancePrefixed->deleteItem($item));

        $item = $this->instancePrefixed->getItem('test852');
        self::assertFalse($this->instancePrefixed->deleteItem($item));
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
    }

    public function testDeleteItemsPrefixed()
    {
        $result = $this->instancePrefixed->deleteItems([
            'test123',
            'test456',
            'test789',
            'test852',
        ]);
        self::assertTrue($result);
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

        $result = $this->instance->save($this->getEmptyBaseCacheItem());
        self::assertTrue($result);
    }

    public function testSavePrefixed()
    {
        $cacheItem = $this->instancePrefixed->getItem('test654');
        // initial condition check
        self::assertFalse($cacheItem->isHit());
        self::assertNull($cacheItem->get());
        // assign values
        $cacheItem->set('test654');
        $cacheItem->expiresAt(new DateTime('2030-01-01 15:30:45'));
        self::assertFalse($cacheItem->isHit());

        $result = $this->instancePrefixed->save($cacheItem);
        self::assertTrue($result);

        self::assertFalse($cacheItem->isHit());
        self::assertEquals('test654', $cacheItem->get());
        self::assertEquals('2030-01-01 15:30:45', $cacheItem->getExpiresAt()->format('Y-m-d H:i:s'));

        $result = $this->instancePrefixed->save($this->getEmptyBaseCacheItem());
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
    }

    public function testGet()
    {
        self::assertEquals('test', $this->instance->get('test123'));
        self::assertNull($this->instance->get('test456'));
        self::assertEquals('defaultValue', $this->instance->get('test456', 'defaultValue'));
        self::assertInstanceOf(stdClass::class, $this->instance->get('test789'));
        self::assertNull($this->instance->get('test852'));
        self::assertEquals('defaultValue', $this->instance->get('test852', 'defaultValue'));
    }

    public function testGetPrefixed()
    {
        self::assertEquals('test', $this->instancePrefixed->get('test123'));
        self::assertNull($this->instancePrefixed->get('test456'));
        self::assertEquals('defaultValue', $this->instancePrefixed->get('test456', 'defaultValue'));
        self::assertInstanceOf(stdClass::class, $this->instancePrefixed->get('test789'));
        self::assertNull($this->instancePrefixed->get('test852'));
        self::assertEquals('defaultValue', $this->instancePrefixed->get('test852', 'defaultValue'));
    }

    public function testSet()
    {
        $count = 0;
        self::assertCount($count, $this->itemPoolSaved);

        self::assertTrue($this->instance->set('test', 'test'));
        self::assertCount(++$count, $this->itemPoolSaved);

        $instance = new DynamoDbCache(
            'test',
            $this->getFakeClient($this->itemPoolDefault),
            'id',
            'ttl',
            'value',
            new TestClock(new DateTime('2030-01-01 15:00:00'))
        );
        self::assertTrue($instance->set('test2', 'test', 3600));
        self::assertCount(++$count, $this->itemPoolSaved);
        self::assertEquals(
            '2030-01-01 16:00:00',
            (new DateTime())->setTimestamp(end($this->itemPoolSaved)['ttl']['N'])->format('Y-m-d H:i:s')
        );

        self::assertTrue($instance->set('test3', 'test', new DateInterval('P1DT3S')));
        self::assertCount(++$count, $this->itemPoolSaved);
        self::assertEquals(
            '2030-01-02 16:00:03',
            (new DateTime())->setTimestamp(end($this->itemPoolSaved)['ttl']['N'])->format('Y-m-d H:i:s')
        );
    }

    public function testSetPrefixed()
    {
        $count = 0;
        self::assertCount($count, $this->itemPoolSaved);

        self::assertTrue($this->instancePrefixed->set('test', 'test'));
        self::assertCount(++$count, $this->itemPoolSaved);

        $instance = new DynamoDbCache(
            'test',
            $this->getFakeClient($this->itemPoolPrefixed),
            'id',
            'ttl',
            'value',
            new TestClock(new DateTime('2030-01-01 15:00:00')),
            null,
            null,
            $this->prefix
        );
        self::assertTrue($instance->set('test2', 'test', 3600));
        self::assertCount(++$count, $this->itemPoolSaved);
        self::assertEquals(
            '2030-01-01 16:00:00',
            (new DateTime())->setTimestamp(end($this->itemPoolSaved)['ttl']['N'])->format('Y-m-d H:i:s')
        );

        self::assertTrue($instance->set('test3', 'test', new DateInterval('P1DT3S')));
        self::assertCount(++$count, $this->itemPoolSaved);
        self::assertEquals(
            '2030-01-02 16:00:03',
            (new DateTime())->setTimestamp(end($this->itemPoolSaved)['ttl']['N'])->format('Y-m-d H:i:s')
        );
    }

    public function testDelete()
    {
        self::assertTrue($this->instance->delete('test123'));
        self::assertTrue($this->instance->delete('test456'));
        self::assertTrue($this->instance->delete('test789'));
        self::assertFalse($this->instance->delete('test852'));
    }

    public function testDeletePrefix()
    {
        self::assertTrue($this->instancePrefixed->delete('test123'));
        self::assertTrue($this->instancePrefixed->delete('test456'));
        self::assertTrue($this->instancePrefixed->delete('test789'));
        self::assertFalse($this->instancePrefixed->delete('test852'));
    }

    public function testGetMultiple()
    {
        $result = $this->instance->getMultiple([
            'test123',
            'test456',
            'test789',
            'test852',
            'test258',
        ]);

        self::assertCount(5, $result);

        self::assertArrayHasKey('test123', $result);
        self::assertArrayHasKey('test456', $result);
        self::assertArrayHasKey('test789', $result);
        self::assertArrayHasKey('test852', $result);
        self::assertArrayHasKey('test258', $result);

        self::assertEquals('test', $result['test123']);
        self::assertNull($result['test456']);
        self::assertInstanceOf(stdClass::class, $result['test789']);
        self::assertNull($result['test852']);
        self::assertNull($result['test258']);

        $result = $this->instance->getMultiple([
            'test456',
            'test852',
        ], 'defaultValue');

        self::assertArrayHasKey('test456', $result);
        self::assertArrayHasKey('test852', $result);
        self::assertEquals('defaultValue', $result['test456']);
        self::assertEquals('defaultValue', $result['test852']);
    }

    public function testGetMultiplePrefixed()
    {
        $result = $this->instancePrefixed->getMultiple([
            'test123',
            'test456',
            'test789',
            'test852',
            'test258',
        ]);

        self::assertCount(5, $result);

        self::assertArrayHasKey('test123', $result);
        self::assertArrayHasKey('test456', $result);
        self::assertArrayHasKey('test789', $result);
        self::assertArrayHasKey('test852', $result);
        self::assertArrayHasKey('test258', $result);

        self::assertEquals('test', $result['test123']);
        self::assertNull($result['test456']);
        self::assertInstanceOf(stdClass::class, $result['test789']);
        self::assertNull($result['test852']);
        self::assertNull($result['test258']);

        $result = $this->instancePrefixed->getMultiple([
            'test456',
            'test852',
        ], 'defaultValue');

        self::assertArrayHasKey('test456', $result);
        self::assertArrayHasKey('test852', $result);
        self::assertEquals('defaultValue', $result['test456']);
        self::assertEquals('defaultValue', $result['test852']);
    }

    public function testSetMultiple()
    {
        self::assertCount(0, $this->itemPoolSaved);
        $data = [
            'test147' => 'test',
            'test258' => 'test2',
            'test369' => 'test3',
        ];

        self::assertTrue($this->instance->setMultiple($data));
        self::assertCount(3, $this->itemPoolSaved);

        foreach ($this->itemPoolSaved as $item) {
            switch ($item['id']['S']) {
                case 'test147':
                    self::assertEquals('test147', $item['id']['S']);
                    self::assertEquals('s:4:"test";', $item['value']['S']);
                    break;
                case 'test258':
                    self::assertEquals('test258', $item['id']['S']);
                    self::assertEquals('s:5:"test2";', $item['value']['S']);
                    break;
                case 'test369':
                    self::assertEquals('test369', $item['id']['S']);
                    self::assertEquals('s:5:"test3";', $item['value']['S']);
                    break;
            }
        }

        $instance = new DynamoDbCache(
            'test',
            $this->getFakeClient($this->itemPoolDefault),
            'id',
            'ttl',
            'value',
            new TestClock(new DateTimeImmutable('2030-01-01 15:00:00'))
        );

        $this->itemPoolSaved = [];
        $instance->setMultiple($data, 3600);
        self::assertCount(3, $this->itemPoolSaved);
        foreach ($this->itemPoolSaved as $item) {
            self::assertEquals(
                '2030-01-01 16:00:00',
                (new DateTime())->setTimestamp($item['ttl']['N'])->format('Y-m-d H:i:s')
            );
        }

        $this->itemPoolSaved = [];
        $instance->setMultiple($data, new DateInterval('P1DT10M'));
        self::assertCount(3, $this->itemPoolSaved);
        foreach ($this->itemPoolSaved as $item) {
            self::assertEquals(
                '2030-01-02 15:10:00',
                (new DateTime())->setTimestamp($item['ttl']['N'])->format('Y-m-d H:i:s')
            );
        }
    }

    public function testSetMultiplePrefixed()
    {
        self::assertCount(0, $this->itemPoolSaved);
        $data = [
            'test147' => 'test',
            'test258' => 'test2',
            'test369' => 'test3',
        ];

        self::assertTrue($this->instancePrefixed->setMultiple($data));
        self::assertCount(3, $this->itemPoolSaved);

        foreach ($this->itemPoolSaved as $item) {
            switch ($item['id']['S']) {
                case $this->prefix . 'test147':
                    self::assertEquals($this->prefix . 'test147', $item['id']['S']);
                    self::assertEquals('s:4:"test";', $item['value']['S']);
                    break;
                case $this->prefix . 'test258':
                    self::assertEquals($this->prefix . 'test258', $item['id']['S']);
                    self::assertEquals('s:5:"test2";', $item['value']['S']);
                    break;
                case $this->prefix . 'test369':
                    self::assertEquals($this->prefix . 'test369', $item['id']['S']);
                    self::assertEquals('s:5:"test3";', $item['value']['S']);
                    break;
            }
        }

        $instance = new DynamoDbCache(
            'test',
            $this->getFakeClient($this->itemPoolPrefixed),
            'id',
            'ttl',
            'value',
            new TestClock(new DateTimeImmutable('2030-01-01 15:00:00')),
            null,
            null,
            $this->prefix
        );

        $this->itemPoolSaved = [];
        $instance->setMultiple($data, 3600);
        self::assertCount(3, $this->itemPoolSaved);
        foreach ($this->itemPoolSaved as $item) {
            self::assertEquals(
                '2030-01-01 16:00:00',
                (new DateTime())->setTimestamp($item['ttl']['N'])->format('Y-m-d H:i:s')
            );
        }

        $this->itemPoolSaved = [];
        $instance->setMultiple($data, new DateInterval('P1DT10M'));
        self::assertCount(3, $this->itemPoolSaved);
        foreach ($this->itemPoolSaved as $item) {
            self::assertEquals(
                '2030-01-02 15:10:00',
                (new DateTime())->setTimestamp($item['ttl']['N'])->format('Y-m-d H:i:s')
            );
        }
    }

    public function testDeleteMultiple()
    {
        self::assertTrue($this->instance->deleteMultiple([
            'test123',
            'test456',
            'test789',
        ]));
    }

    public function testDeleteMultiplePrefixed()
    {
        self::assertTrue($this->instancePrefixed->deleteMultiple([
            'test123',
            'test456',
            'test789',
        ]));
    }

    public function testHas()
    {
        self::assertTrue($this->instance->has('test123'));
        self::assertFalse($this->instance->has('test456'));
        self::assertTrue($this->instance->has('test789'));
        self::assertFalse($this->instance->has('test852'));
    }

    public function testHasPrefixed()
    {
        self::assertTrue($this->instancePrefixed->has('test123'));
        self::assertFalse($this->instancePrefixed->has('test456'));
        self::assertTrue($this->instancePrefixed->has('test789'));
        self::assertFalse($this->instancePrefixed->has('test852'));
    }

    public function testInvalidKeys()
    {
        $chars = array_filter(preg_split(
            '@@',
            (new ReflectionClass(DynamoDbCache::class))->getConstant('RESERVED_CHARACTERS')
        ));

        foreach ($chars as $char) {
            $key = 'random' . $char . 'name';

            try {
                $this->instance->getItem($key);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->getItems([$key]);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->deleteItem($key);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->deleteItems([$key]);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            $item = new DynamoCacheItem(
                $key,
                true,
                '',
                null,
                new Clock(),
                new SerializeItemEncoder()
            );

            try {
                $this->instance->save($item);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->saveDeferred($item);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            // simple cache interface

            try {
                $this->instance->get($key);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->set($key, '');
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->delete($key);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->getMultiple([$key]);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->setMultiple([$key => 'test']);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->deleteMultiple([$key]);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }

            try {
                $this->instance->has($key);
                $this->fail("Should throw an exception due to invalid character: {$char}");
            } catch (InvalidArgumentException $e) {
            }
        }

        // dummy assertion
        self::assertTrue(true);
    }

    public function testIterables()
    {
        $iterable = new ArrayIterator(['test123']);
        $iterableKeyPair = new ArrayIterator(['test123' => 'value']);

        $result = $this->instance->getMultiple($iterable);
        self::assertCount(1, $result);
        self::assertArrayHasKey('test123', $result);
        self::assertEquals('test', $result['test123']);

        self::assertCount(0, $this->itemPoolSaved);
        $result = $this->instance->setMultiple($iterableKeyPair);
        self::assertTrue($result);
        self::assertCount(1, $this->itemPoolSaved);
        self::assertEquals('s:5:"value";', $this->itemPoolSaved[0]['value']['S']);

        self::assertTrue($this->instance->deleteMultiple($iterable));
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

            public function getItem($input): GetItemOutput
            {
                assert(is_array($input));
                $availableIds = array_column(array_column($this->pool, $this->idField), 'S');
                $id = $input['Key'][$this->idField]['S'];
                if (!in_array($id, $availableIds, true)) {
                    $data = [[]];
                } else {
                    $data = array_filter($this->pool, function ($item) use ($id) {
                        return $item[$this->idField]['S'] === $id;
                    });
                }

                foreach ($data as $key => $value) {
                    $data[$key] = new MockResponse(json_encode(['Item' => $value]));
                }

                $client = new MockHttpClient(reset($data));
                return new GetItemOutput(
                    new Response(
                        $client->request('GET', 'https://example.com'),
                        $client,
                        new NullLogger()
                    )
                );
            }

            public function batchGetItem($input): BatchGetItemOutput
            {
                $table = array_key_first($input['RequestItems']);
                $keys = array_map(function (array $data) {
                    return $data[$this->idField]->getS();
                }, $input['RequestItems'][$table]->getKeys());

                $result = [
                    'Responses' => [
                        $table => [],
                    ],
                ];
                $i = 0;
                foreach ($keys as $key) {
                    $data = $this->getItem([
                        'Key' => [
                            $this->idField => [
                                'S' => $key,
                            ],
                        ],
                    ])->getItem();
                    if (!count($data)) {
                        continue;
                    }
                    $result['Responses'][$table][] = array_map(function (AttributeValue $value) {
                        return $value->getS() ? ['S' => $value->getS()] : ['N' => $value->getN()];
                    }, $data);
                    ++$i;
                }

                $client = new MockHttpClient(new MockResponse(json_encode($result)));
                return new BatchGetItemOutput(
                    new Response(
                        $client->request('GET', 'https://example.com'),
                        $client,
                        new NullLogger()
                    )
                );
            }

            public function deleteItem($input): DeleteItemOutput
            {
                $key = $input['Key'][$this->idField]['S'];
                $this->getItem([
                    'Key' => [
                        $this->idField => [
                            'S' => $key,
                        ],
                    ],
                ]);

                $client = new MockHttpClient(new MockResponse('{}'));
                return new DeleteItemOutput(
                    new Response(
                        $client->request('GET', 'https://example.com'),
                        $client,
                        new NullLogger()
                    )
                );
            }

            public function batchWriteItem($input): BatchWriteItemOutput
            {
                $table = array_key_first($input['RequestItems']);
                $keys = array_column(
                    array_column(
                        array_column(
                            array_column(
                                $input['RequestItems'][$table],
                                'DeleteRequest'
                            ),
                            'Key'
                        ),
                        $this->idField
                    ),
                    'S'
                );

                foreach ($keys as $key) {
                    $this->deleteItem([
                        'Key' => [
                            $this->idField => [
                                'S' => $key,
                            ],
                        ],
                    ]);
                }

                $client = new MockHttpClient(new MockResponse('{}'));
                return new BatchWriteItemOutput(
                    new Response(
                        $client->request('GET', 'https://example.com'),
                        $client,
                        new NullLogger()
                    )
                );
            }

            public function putItem($input): PutItemOutput
            {
                $reflection = new ReflectionObject($this->parent);
                $pool = $reflection->getProperty('itemPoolSaved');
                $pool->setAccessible(true);

                $currentPool = $pool->getValue($this->parent);
                $currentPool[] = $input['Item'];

                $pool->setValue($this->parent, $currentPool);

                $client = new MockHttpClient(new MockResponse('{}'));
                return new PutItemOutput(
                    new Response(
                        $client->request('GET', 'https://example.com'),
                        $client,
                        new NullLogger()
                    )
                );
            }
        };
    }

    private function getEmptyBaseCacheItem()
    {
        return new class implements CacheItemInterface {
            public function getKey(): string
            {
                return 'test';
            }

            public function get(): mixed
            {
                return 'test';
            }

            public function isHit(): bool
            {
                return true;
            }

            public function set($value): static
            {
                return $this;
            }

            public function expiresAt($expiration): static
            {
                return $this;
            }

            public function expiresAfter($time): static
            {
                return $this;
            }
        };
    }
}
