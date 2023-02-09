<?php

namespace Rikudou\DynamoDbCache\Converter;

use LogicException;
use Psr\Cache\CacheItemInterface;
use Rikudou\DynamoDbCache\DynamoCacheItem;

final class CacheItemConverterRegistry
{
    /**
     * @var CacheItemConverterInterface[]
     */
    private array $converters;

    public function __construct(CacheItemConverterInterface ...$converters)
    {
        $this->converters = $converters;

        $foundDefault = false;
        foreach ($converters as $converter) {
            if ($converter instanceof DefaultCacheItemConverter) {
                $foundDefault = true;
                break;
            }
        }

        if (!$foundDefault) {
            $this->converters[] = new DefaultCacheItemConverter();
        }
    }

    public function convert(CacheItemInterface $cacheItem): DynamoCacheItem
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($cacheItem)) {
                return $converter->convert($cacheItem);
            }
        }

        // @codeCoverageIgnoreStart
        // this shouldn't happen as the DefaultCacheItemConverter handles every instance of the interface
        throw new LogicException('No suitable converter found.');
        // @codeCoverageIgnoreEnd
    }
}
