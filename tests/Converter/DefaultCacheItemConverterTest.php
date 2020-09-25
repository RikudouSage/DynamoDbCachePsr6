<?php

namespace Rikudou\Tests\DynamoDbCache\Converter;

use Psr\Cache\CacheItemInterface;
use Rikudou\DynamoDbCache\Converter\DefaultCacheItemConverter;
use PHPUnit\Framework\TestCase;
use Rikudou\DynamoDbCache\DynamoCacheItem;

class DefaultCacheItemConverterTest extends TestCase
{
    /**
     * @var DynamoCacheItem
     */
    private $dynamoCacheItem;

    /**
     * @var CacheItemInterface
     */
    private $basicCacheItem;

    /**
     * @var DefaultCacheItemConverter
     */
    private $instance;

    protected function setUp(): void
    {
        $this->dynamoCacheItem = new DynamoCacheItem('test', true, 'something', null);
        $this->basicCacheItem = $this->createBasicCacheItem();
        $this->instance = new DefaultCacheItemConverter();
    }

    public function testSupports()
    {
        self::assertTrue($this->instance->supports($this->dynamoCacheItem));
        self::assertTrue($this->instance->supports($this->basicCacheItem));
    }

    public function testConvert()
    {
        $result = $this->instance->convert($this->dynamoCacheItem);
        self::assertInstanceOf(DynamoCacheItem::class, $result);
        self::assertEquals($this->dynamoCacheItem, $result);

        $result = $this->instance->convert($this->basicCacheItem);
        self::assertInstanceOf(DynamoCacheItem::class, $result);
    }

    private function createBasicCacheItem()
    {
        return new class implements CacheItemInterface {

            public function getKey()
            {
                return 'test';
            }

            public function get()
            {
                return 'test';
            }

            public function isHit()
            {
                return true;
            }

            public function set($value)
            {
                return $this;
            }

            public function expiresAt($expiration)
            {
                return $this;
            }

            public function expiresAfter($time)
            {
                return $this;
            }
        };
    }
}
