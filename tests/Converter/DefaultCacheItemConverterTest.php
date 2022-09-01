<?php

namespace Rikudou\Tests\DynamoDbCache\Converter;

use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Rikudou\Clock\Clock;
use Rikudou\DynamoDbCache\Converter\DefaultCacheItemConverter;
use Rikudou\DynamoDbCache\DynamoCacheItem;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;

final class DefaultCacheItemConverterTest extends TestCase
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
        $this->dynamoCacheItem = new DynamoCacheItem(
            'test',
            true,
            'something',
            null,
            new Clock(),
            new SerializeItemEncoder()
        );
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

            public function set(mixed $value): static
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
