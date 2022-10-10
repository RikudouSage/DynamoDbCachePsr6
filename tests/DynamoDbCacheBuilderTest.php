<?php

namespace Rikudou\Tests\DynamoDbCache;

use AsyncAws\DynamoDb\DynamoDbClient;
use ReflectionObject;
use Rikudou\Clock\Clock;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Converter\CacheItemConverterRegistry;
use Rikudou\DynamoDbCache\DynamoDbCache;
use Rikudou\DynamoDbCache\DynamoDbCacheBuilder;
use PHPUnit\Framework\TestCase;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Encoder\JsonItemEncoder;
use Rikudou\DynamoDbCache\Enum\NetworkErrorMode;

class DynamoDbCacheBuilderTest extends TestCase
{
    /**
     * @var DynamoDbCacheBuilder
     */
    private $instance;

    protected function setUp(): void
    {
        $this->instance = DynamoDbCacheBuilder::create('test', new DynamoDbClient([
            'region' => 'eu-central-1',
        ]));
    }

    public function testCreate()
    {
        $client = new DynamoDbClient([
            'region' => 'eu-central-1',
        ]);
        $instance = DynamoDbCacheBuilder::create('test', $client);
        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertSame($client, $result['client']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testImmutability()
    {
        $registry = new CacheItemConverterRegistry();
        $encoder = new JsonItemEncoder();
        $clock = new Clock();

        self::assertNotSame($this->instance, $this->instance->withClock($clock));
        self::assertNotSame($this->instance, $this->instance->withConverterRegistry($registry));
        self::assertNotSame($this->instance, $this->instance->withEncoder($encoder));
        self::assertNotSame($this->instance, $this->instance->withPrefix('test'));
        self::assertNotSame($this->instance, $this->instance->withPrimaryField('test'));
        self::assertNotSame($this->instance, $this->instance->withTtlField('test'));
        self::assertNotSame($this->instance, $this->instance->withValueField('test'));
        self::assertNotSame($this->instance, $this->instance->withNetworkErrorMode(NetworkErrorMode::WARNING));

        $result = $this->getBuiltData($this->instance->build());
        self::assertNotSame($clock, $result['clock']);
        self::assertNotSame($encoder, $result['encoder']);
        self::assertNotSame($registry, $result['converter']);
        self::assertNotEquals('test', $result['prefix']);
        self::assertNotEquals('test', $result['primaryField']);
        self::assertNotEquals('test', $result['ttlField']);
        self::assertNotEquals('test', $result['valueField']);
    }

    public function testWithValueField()
    {
        $instance = $this->instance->withValueField('test');

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('test', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testWithTtlField()
    {
        $instance = $this->instance->withTtlField('test');
        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('test', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testWithPrimaryField()
    {
        $instance = $this->instance->withPrimaryField('test');

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('test', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testWithPrefix()
    {
        $instance = $this->instance->withPrefix('test');

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertEquals('test', $result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testWithEncoder()
    {
        $encoder = new JsonItemEncoder();
        $instance = $this->instance->withEncoder($encoder);

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertSame($encoder, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testWithConverterRegistry()
    {
        $registry = new CacheItemConverterRegistry();
        $instance = $this->instance->withConverterRegistry($registry);

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertSame($registry, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testWithClock()
    {
        $clock = new Clock();
        $instance = $this->instance->withClock($clock);

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertSame($clock, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertNull($result['prefix']);
        self::assertEquals(NetworkErrorMode::DEFAULT, $result['networkErrorMode']);
    }

    public function testAllAtOnce()
    {
        $clock = new Clock();
        $registry = new CacheItemConverterRegistry();
        $encoder = new JsonItemEncoder();

        $instance = $this->instance
            ->withClock($clock)
            ->withConverterRegistry($registry)
            ->withEncoder($encoder)
            ->withPrefix('testPrefix')
            ->withPrimaryField('id1')
            ->withTtlField('ttl1')
            ->withValueField('value1')
            ->withNetworkErrorMode(NetworkErrorMode::IGNORE)
        ;

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id1', $result['primaryField']);
        self::assertEquals('ttl1', $result['ttlField']);
        self::assertEquals('value1', $result['valueField']);
        self::assertSame($clock, $result['clock']);
        self::assertSame($registry, $result['converter']);
        self::assertSame($encoder, $result['encoder']);
        self::assertEquals('testPrefix', $result['prefix']);
        self::assertEquals(NetworkErrorMode::IGNORE, $result['networkErrorMode']);
    }

    public function testWithNetworkErrorMode()
    {
        $instance = $this->instance->withNetworkErrorMode(NetworkErrorMode::IGNORE);

        $result = $this->getBuiltData($instance->build());
        self::assertEquals('test', $result['tableName']);
        self::assertEquals('id', $result['primaryField']);
        self::assertEquals('ttl', $result['ttlField']);
        self::assertEquals('value', $result['valueField']);
        self::assertInstanceOf(ClockInterface::class, $result['clock']);
        self::assertInstanceOf(CacheItemConverterRegistry::class, $result['converter']);
        self::assertInstanceOf(CacheItemEncoderInterface::class, $result['encoder']);
        self::assertEquals(null, $result['prefix']);
        self::assertEquals(NetworkErrorMode::IGNORE, $result['networkErrorMode']);
    }

    private function getBuiltData(DynamoDbCache $cache): array
    {
        $reflection = new ReflectionObject($cache);
        $result = [];
        foreach ($reflection->getProperties() as $property) {
            $property->setAccessible(true);
            $result[$property->getName()] = $property->getValue($cache);
        }
        return $result;
    }
}
