<?php

namespace Rikudou\DynamoDbCache;

use AsyncAws\DynamoDb\DynamoDbClient;
use JetBrains\PhpStorm\ExpectedValues;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Converter\CacheItemConverterRegistry;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Enum\NetworkErrorMode;

final class DynamoDbCacheBuilder
{
    private string $primaryField = 'id';

    private string $ttlField = 'ttl';

    private string $valueField = 'value';

    private ?string $prefix = null;

    private ?ClockInterface $clock = null;

    private ?CacheItemConverterRegistry $converterRegistry = null;

    private ?CacheItemEncoderInterface $encoder = null;

    private int $networkErrorMode = NetworkErrorMode::DEFAULT;

    private function __construct(
        private string $tableName,
        private DynamoDbClient $client
    ) {
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

    public function withNetworkErrorMode(
        #[ExpectedValues(valuesFromClass: NetworkErrorMode::class)]
        int $networkErrorMode
    ): self {
        $copy = clone $this;
        $copy->networkErrorMode = $networkErrorMode;

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
            $this->prefix,
            $this->networkErrorMode
        );
    }
}
