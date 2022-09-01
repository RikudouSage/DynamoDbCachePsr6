<?php

namespace Rikudou\DynamoDbCache;

use DateInterval;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Rikudou\Clock\ClockInterface;
use Rikudou\DynamoDbCache\Encoder\CacheItemEncoderInterface;
use Rikudou\DynamoDbCache\Exception\InvalidArgumentException;

final class DynamoCacheItem implements CacheItemInterface
{
    /**
     * @var string
     */
    private $key;

    /**
     * @var bool
     */
    private $isHit;

    /**
     * @var DateTimeInterface|null
     */
    private $expiresAt;

    /**
     * @var string
     */
    private $value;

    /**
     * @var ClockInterface
     */
    private $clock;

    /**
     * @var CacheItemEncoderInterface
     */
    private $encoder;

    /**
     * @param string                    $key
     * @param bool                      $isHit
     * @param mixed                     $value
     * @param DateTimeInterface|null    $expiresAt
     * @param ClockInterface            $clock
     * @param CacheItemEncoderInterface $encoder
     *
     * @internal
     */
    public function __construct(
        string $key,
        bool $isHit,
        $value,
        ?DateTimeInterface $expiresAt,
        ClockInterface $clock,
        CacheItemEncoderInterface $encoder
    ) {
        $this->key = $key;
        $this->isHit = $isHit;
        $this->expiresAt = $expiresAt;
        $this->clock = $clock;
        $this->encoder = $encoder;

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

    public function set($value): static
    {
        $this->value = $this->encoder->encode($value);

        return $this;
    }

    /**
     * @param ?\DateTimeInterface $expiration
     */
    public function expiresAt($expiration): static
    {
        if ($expiration === null) {
            $this->expiresAt = null;
        } elseif ($expiration instanceof DateTimeInterface) {
            $this->expiresAt = $expiration;
        } else {
            throw new InvalidArgumentException('The expiration must be null or instance of ' . DateTimeInterface::class);
        }

        return $this;
    }

    /**
     * @param int|\DateInterval|null $time
     */
    public function expiresAfter($time): static
    {
        if ($time === null) {
            $this->expiresAt = null;
        } else {
            $now = $this->clock->now();
            if (is_int($time)) {
                $time = new DateInterval("PT{$time}S");
            }
            if (!$time instanceof DateInterval) {
                throw new InvalidArgumentException('The argument must be an int, DateInterval or null');
            }

            assert(method_exists($now, 'add'));
            $this->expiresAt = $now->add($time);
        }

        return $this;
    }

    /**
     * @return string
     *
     * @internal
     *
     */
    public function getRaw(): string
    {
        return $this->value;
    }

    /**
     * @return DateTimeInterface|null
     *
     * @internal
     *
     */
    public function getExpiresAt(): ?DateTimeInterface
    {
        return $this->expiresAt;
    }
}
