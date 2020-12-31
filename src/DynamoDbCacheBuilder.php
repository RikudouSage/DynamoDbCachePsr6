<?php

namespace Rikudou\DynamoDbCache;

use Aws\DynamoDb\DynamoDbClient;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Converter\CacheItemConverterRegistry;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;

final class DynamoDbCacheBuilder
{
    /**
     * @var string
     */
    private $tableName;

    /**
     * @var DynamoDbClient
     */
    private $client;

    /**
     * @var string
     */
    private $primaryField = 'id';

    /**
     * @var string
     */
    private $ttlField = 'ttl';

    /**
     * @var string
     */
    private $valueField = 'value';

    /**
     * @var string|null
     */
    private $prefix = null;

    /**
     * @var ClockInterface|null
     */
    private $clock = null;

    /**
     * @var CacheItemConverterRegistry|null
     */
    private $converterRegistry = null;

    /**
     * @var CacheItemEncoderInterface|null
     */
    private $encoder = null;

    private function __construct(string $tableName, DynamoDbClient $client)
    {
        $this->tableName = $tableName;
        $this->client = $client;
    }

    public static function create(string $tableName, DynamoDbClient $client): self
    {
        return new self($tableName, $client);
    }

    public function withPrimaryField(?string $primaryField): self
    {
        $copy = clone $this;
        $copy->primaryField = $primaryField ?? 'id';

        return $copy;
    }

    public function withTtlField(?string $ttlField): self
    {
        $copy = clone $this;
        $copy->ttlField = $ttlField ?? 'ttl';

        return $copy;
    }

    public function withValueField(?string $valueField): self
    {
        $copy = clone $this;
        $copy->valueField = $valueField ?? 'value';

        return $copy;
    }

    public function withPrefix(?string $prefix): self
    {
        $copy = clone $this;
        $copy->prefix = $prefix;

        return $copy;
    }

    public function withClock(?ClockInterface $clock): self
    {
        $copy = clone $this;
        $copy->clock = $clock;

        return $copy;
    }

    public function withConverterRegistry(?CacheItemConverterRegistry $converterRegistry): self
    {
        $copy = clone $this;
        $copy->converterRegistry = $converterRegistry;

        return $copy;
    }

    public function withEncoder(?CacheItemEncoderInterface $encoder): self
    {
        $copy = clone $this;
        $copy->encoder = $encoder;

        return $copy;
    }

    public function build(): DynamoDbCache
    {
        return new DynamoDbCache(
            $this->tableName,
            $this->client,
            $this->primaryField,
            $this->ttlField,
            $this->valueField,
            $this->clock,
            $this->converterRegistry,
            $this->encoder,
            $this->prefix
        );
    }
}
