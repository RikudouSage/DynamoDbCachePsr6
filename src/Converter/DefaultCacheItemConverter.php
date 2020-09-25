<?php

namespace Rikudou\DynamoDbCache\Converter;

use Psr\Cache\CacheItemInterface;
use Rikudou\DynamoDbCache\DynamoCacheItem;

final class DefaultCacheItemConverter implements CacheItemConverterInterface
{
    public function supports(CacheItemInterface $cacheItem): bool
    {
        return true;
    }

    public function convert(CacheItemInterface $cacheItem): DynamoCacheItem
    {
        if ($cacheItem instanceof DynamoCacheItem) {
            return $cacheItem;
        }

        // the expiration date may be lost in the process
        $cacheItem = new DynamoCacheItem(
            $cacheItem->getKey(),
            $cacheItem->isHit(),
            $cacheItem->get(),
            null
        );

        return $cacheItem;
    }
}
