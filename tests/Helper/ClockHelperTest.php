<?php

namespace Rikudou\Tests\DynamoDbCache\Helper;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface as PsrClock;
use Rikudou\Clock\ClockInterface as RikudouClock;
use Rikudou\Clock\TestClock;
use Rikudou\DynamoDbCache\Helper\ClockHelper;

final class ClockHelperTest extends TestCase
{
    public function testPsrClockWrapsPsrClock(): void
    {
        $date = new DateTimeImmutable('2030-01-01 15:00:00');
        $clock = new class($date) implements PsrClock {
            public function __construct(
                private DateTimeImmutable $date
            ) {
            }

            public function now(): DateTimeImmutable
            {
                return $this->date;
            }
        };

        $wrapped = ClockHelper::psrClock($clock);
        $wrappedNow = $wrapped->now();

        self::assertEquals($date->format(DateTimeInterface::RFC3339), $wrappedNow->format(DateTimeInterface::RFC3339));
        self::assertNotSame($date, $wrappedNow);
    }

    public function testPsrClockWrapsLegacyClockAndTriggersDeprecation(): void
    {
        $date = new DateTime('2030-01-01 15:00:00');
        $clock = new TestClock($date);

        $deprecations = [];
        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity !== E_USER_DEPRECATED) {
                return false;
            }

            $deprecations[] = $message;

            return true;
        });

        try {
            $wrapped = ClockHelper::psrClock($clock);
        } finally {
            restore_error_handler();
        }

        $wrappedNow = $wrapped->now();

        self::assertCount(1, $deprecations);
        self::assertStringContainsString(RikudouClock::class, $deprecations[0]);
        self::assertStringContainsString(PsrClock::class, $deprecations[0]);
        self::assertEquals($date->format(DateTimeInterface::RFC3339), $wrappedNow->format(DateTimeInterface::RFC3339));
    }

    public function testFixedTimeClockReturnsProvidedTime(): void
    {
        $date = new DateTimeImmutable('2030-01-01 15:00:00');
        $clock = ClockHelper::fixedTimeClock($date);

        self::assertSame($date, $clock->now());
        self::assertSame($clock->now(), $clock->now());
    }
}
