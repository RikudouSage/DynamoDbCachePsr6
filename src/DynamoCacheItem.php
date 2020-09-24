<?php

namespace Rikudou\DynamoDbCache;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use Rikudou\Clock\Clock;
use Rikudou\Clock\ClockInterface;
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
     * @param string                 $key
     * @param bool                   $isHit
     * @param mixed                  $value
     * @param DateTimeInterface|null $expiresAt
     * @param ClockInterface|null    $clock
     */
    public function __construct(
        string $key,
        bool $isHit,
        $value,
        ?DateTimeInterface $expiresAt,
        ?ClockInterface $clock = null
    ) {
        $this->key = $key;
        $this->isHit = $isHit;
        $this->expiresAt = $expiresAt;
        $this->set($value);

        if ($clock === null) {
            $clock = new Clock();
        }
        $this->clock = $clock;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function get()
    {
        return unserialize($this->value);
    }

    public function isHit()
    {
        return $this->isHit && ($this->clock->now() < $this->expiresAt || $this->expiresAt === null);
    }

    public function set($value)
    {
        $this->value = serialize($value);

        return $this;
    }

    public function expiresAt($expiration)
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

    public function expiresAfter($time)
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

            assert($now instanceof DateTime || $now instanceof DateTimeImmutable);
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
