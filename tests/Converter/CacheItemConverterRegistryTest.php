<?php

namespace Rikudou\Tests\DynamoDbCache\Converter;

use Psr\Cache\CacheItemInterface;
use ReflectionProperty;
use Rikudou\DynamoDbCache\Converter\CacheItemConverterInterface;
use Rikudou\DynamoDbCache\Converter\CacheItemConverterRegistry;
use PHPUnit\Framework\TestCase;
use Rikudou\DynamoDbCache\Converter\DefaultCacheItemConverter;
use Rikudou\DynamoDbCache\DynamoCacheItem;

class CacheItemConverterRegistryTest extends TestCase
{
    public function testConstruction()
    {
        $convertersReflection = new ReflectionProperty(CacheItemConverterRegistry::class, 'converters');
        $convertersReflection->setAccessible(true);

        // without any converter
        $instance = new CacheItemConverterRegistry();
        self::assertCount(1, $convertersReflection->getValue($instance));

        // with manually added default converter
        $instance = new CacheItemConverterRegistry(new DefaultCacheItemConverter());
        self::assertCount(1, $convertersReflection->getValue($instance));

        // with adding non-default converter and not adding default
        $instance = new CacheItemConverterRegistry($this->getFakeConverter());
        self::assertCount(2, $convertersReflection->getValue($instance));

        // with adding both non-default and default
        $instance = new CacheItemConverterRegistry(new DefaultCacheItemConverter(), $this->getFakeConverter());
        self::assertCount(2, $convertersReflection->getValue($instance));
    }

    private function getFakeConverter(): CacheItemConverterInterface
    {
        return new class implements CacheItemConverterInterface {

            public function supports(CacheItemInterface $cacheItem): bool
            {
                return true;
            }

            public function convert(CacheItemInterface $cacheItem): DynamoCacheItem
            {
            }
        };
    }
}
