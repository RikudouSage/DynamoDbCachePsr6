<?php

namespace Rikudou\DynamoDbCache\Converter;

use Psr\Cache\CacheItemInterface;
use Psr\Clock\ClockInterface as PsrClock;
use Rikudou\Clock\ClockInterface as RikudouClock;
use Rikudou\DynamoDbCache\DynamoCacheItem;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;
use Rikudou\DynamoDbCache\Helper\ClockHelper;

final class DefaultCacheItemConverter implements CacheItemConverterInterface
{
    private CacheItemEncoderInterface $encoder;

    private PsrClock $clock;

    public function __construct(?CacheItemEncoderInterface $encoder = null, RikudouClock|PsrClock|null $clock = null)
    {
        if ($encoder === null) {
            $encoder = new SerializeItemEncoder();
        }
        $clock = ClockHelper::psrClock($clock);

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
