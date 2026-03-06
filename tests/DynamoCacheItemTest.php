<?php

namespace Rikudou\Tests\DynamoDbCache;

use DateInterval;
use DateTime;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rikudou\DynamoDbCache\DynamoCacheItem;
use Rikudou\DynamoDbCache\Encoder\SerializeItemEncoder;
use Rikudou\DynamoDbCache\Helper\ClockHelper;

final class DynamoCacheItemTest extends TestCase
{
    private const DEFAULT_KEY = 'randomKey';
    private const DEFAULT_VALUE = 'value';
    private const DEFAULT_IS_HIT = true;
    private const DEFAULT_EXPIRES_AT = null;
    private const DEFAULT_DATE = '2030-01-01T15:00:00+00:00';

    private DynamoCacheItem $instance;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->clock = ClockHelper::fixedTimeClock(new DateTimeImmutable(self::DEFAULT_DATE));
        $this->instance = new DynamoCacheItem(
            self::DEFAULT_KEY,
            self::DEFAULT_IS_HIT,
            self::DEFAULT_VALUE,
            self::DEFAULT_EXPIRES_AT,
            $this->clock,
            new SerializeItemEncoder()
        );
    }

    public function testGetKey()
    {
        self::assertEquals(self::DEFAULT_KEY, $this->instance->getKey());
    }

    public function testGet()
    {
        self::assertEquals(self::DEFAULT_VALUE, $this->instance->get());
    }

    public function testIsHit()
    {
        self::assertEquals(self::DEFAULT_IS_HIT, $this->instance->isHit());

        $this->instance->expiresAt(new DateTime('-10 minutes'));
        self::assertEquals(false, $this->instance->isHit());
    }

    public function testSet()
    {
        $this->instance->set('testvalue2');
        self::assertEquals('testvalue2', $this->instance->get());
    }

    public function testExpiresAt()
    {
        $expiresAt = new DateTime('2030-01-01 16:00:00');
        $this->instance->expiresAt($expiresAt);
        self::assertEquals($expiresAt->getTimestamp(), $this->instance->getExpiresAt()->getTimestamp());

        $this->instance->expiresAt(null);
        self::assertNull($this->instance->getExpiresAt());
    }

    public function testExpiresAfter()
    {
        $this->instance->expiresAfter(60);
        self::assertEquals('2030-01-01T15:01:00+00:00', $this->instance->getExpiresAt()->format('c'));

        $this->instance->expiresAfter(null);
        self::assertNull($this->instance->getExpiresAt());

        $this->instance->expiresAfter(new DateInterval('P1DT2H3M'));
        self::assertEquals('2030-01-02T17:03:00+00:00', $this->instance->getExpiresAt()->format('c'));
    }

    public function testGetRaw()
    {
        self::assertEquals(serialize(self::DEFAULT_VALUE), $this->instance->getRaw());
    }
}
