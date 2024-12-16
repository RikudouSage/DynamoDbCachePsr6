<?php

namespace Rikudou\DynamoDbCache\Converter;

use Psr\Cache\CacheItemInterface;
use Rikudou\Clock\Clock;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\DynamoCacheItem;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;

final class DefaultCacheItemConverter implements CacheItemConverterInterface
{
    private CacheItemEncoderInterface $encoder;

    private ClockInterface $clock;

    public function __construct(?CacheItemEncoderInterface $encoder = null, ?ClockInterface $clock = null)
    {
        if ($encoder === null) {
            $encoder = new SerializeItemEncoder();
        }
        if ($clock === null) {
            $clock = new Clock();
        }
        $this->encoder = $encoder;
        $this->clock = $clock;
    }

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
            null,
            $this->clock,
            $this->encoder
        );

        return $cacheItem;
    }
}
