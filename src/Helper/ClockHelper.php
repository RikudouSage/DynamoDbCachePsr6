<?php

namespace Rikudou\DynamoDbCache\Helper;

use DateTimeImmutable;
use Psr\Clock\ClockInterface as PsrClock;
use Rikudou\Clock\ClockInterface as RikudouClock;

/**
 * @internal
 */
final class ClockHelper
{
    public static function psrClock(RikudouClock|PsrClock|null $clock = null): PsrClock
    {
        if ($clock instanceof RikudouClock) {
            trigger_error(sprintf('%s is deprecated, use %s instead', RikudouClock::class, PsrClock::class), E_USER_DEPRECATED);
        }

        return new class ($clock) implements PsrClock {
            public function __construct(
                private RikudouClock|PsrClock|null $clock = null,
            ) {
            }

            public function now(): DateTimeImmutable
            {
                if ($this->clock !== null) {
                    return DateTimeImmutable::createFromInterface($this->clock->now());
                }

                return new DateTimeImmutable();
            }
        };
    }

    public static function fixedTimeClock(DateTimeImmutable $now): PsrClock
    {
        return new class($now) implements PsrClock {
            public function __construct(
                private DateTimeImmutable $now,
            ) {
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
