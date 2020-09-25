<?php

namespace Rikudou\DynamoDbCache\Converter;

use Psr\Cache\CacheItemInterface;
use Rikudou\DynamoDbCache\DynamoCacheItem;

interface CacheItemConverterInterface
{
    public function supports(CacheItemInterface $cacheItem): bool;

    public function convert(CacheItemInterface $cacheItem): DynamoCacheItem;
}
