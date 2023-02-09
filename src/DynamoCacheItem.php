<?php

namespace Rikudou\DynamoDbCache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;

final class DynamoCacheItem implements CacheItemInterface
{
    private string $value;

    /**
     * @internal
     */
    public function __construct(
        private string $key,
        private bool $isHit,
        mixed $value,
        private ?DateTimeInterface $expiresAt,
        private ClockInterface $clock,
        private CacheItemEncoderInterface $encoder
    ) {
        $this->set($value);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->encoder->decode($this->value);
    }

    public function isHit(): bool
    {
        return $this->isHit && ($this->clock->now() < $this->expiresAt || $this->expiresAt === null);
    }

    public function set(mixed $value): static
    {
        $this->value = $this->encoder->encode($value);

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if ($expiration === null) {
            $this->expiresAt = null;
        } else {
            $this->expiresAt = $expiration;
        }

        return $this;
    }

    public function expiresAfter(DateInterval|int|null $time): static
    {
        if ($time === null) {
            $this->expiresAt = null;
        } else {
            $now = $this->clock->now();
            if (is_int($time)) {
                $time = new DateInterval("PT{$time}S");
            }

            assert(method_exists($now, 'add'));
            $this->expiresAt = $now->add($time);
        }

        return $this;
    }

    /**
     * @internal
     */
    public function getRaw(): string
    {
        return $this->value;
    }

    /**
     * @internal
     */
    public function getExpiresAt(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }
}
