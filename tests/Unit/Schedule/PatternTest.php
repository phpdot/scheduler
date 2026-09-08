<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Scheduler\Exception\InvalidPatternException;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\PatternKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternTest extends TestCase
{
    #[Test]
    public function everyMinuteCronClaimsTheCurrentMinuteWithinGrace(): void
    {
        $pattern = Pattern::cron('* * * * *');
        $now = new DateTimeImmutable('2026-09-05T12:34:45+00:00');

        self::assertSame('2026-09-05T12:34:00+00:00', $pattern->dueTick($now, new DateTimeZone('UTC'), 90));
    }

    #[Test]
    public function hourlyCronIsNotDueWhenItsLastFireExceedsGrace(): void
    {
        $pattern = Pattern::cron('0 * * * *');
        $now = new DateTimeImmutable('2026-09-05T12:05:00+00:00');

        self::assertNull($pattern->dueTick($now, new DateTimeZone('UTC'), 90));
    }

    #[Test]
    public function graceWindowLetsAMissedHourlyFireStillBeClaimed(): void
    {
        $pattern = Pattern::cron('0 * * * *');
        $now = new DateTimeImmutable('2026-09-05T12:05:00+00:00');

        self::assertSame('2026-09-05T12:00:00+00:00', $pattern->dueTick($now, new DateTimeZone('UTC'), 400));
    }

    #[Test]
    public function cronFieldsAreInterpretedInTheConfiguredTimezone(): void
    {
        $pattern = Pattern::cron('0 0 * * *');
        $zone = new DateTimeZone('America/New_York');
        $now = new DateTimeImmutable('2026-01-15T05:00:30+00:00');

        self::assertSame('2026-01-15T05:00:00+00:00', $pattern->dueTick($now, $zone, 90));
    }

    #[Test]
    public function intervalPatternClaimsTheCurrentEpochBucket(): void
    {
        $pattern = Pattern::every('30s');
        $now = new DateTimeImmutable('@45');

        self::assertSame('1970-01-01T00:00:30+00:00', $pattern->dueTick($now, new DateTimeZone('UTC'), 90));
    }

    #[Test]
    public function intervalBucketPastGraceIsNotDue(): void
    {
        $pattern = Pattern::every('30s');
        $now = new DateTimeImmutable('@55');

        self::assertNull($pattern->dueTick($now, new DateTimeZone('UTC'), 10));
    }

    #[Test]
    public function intervalSpecificationsNormalizeToSeconds(): void
    {
        $minutes = Pattern::every('5 minutes');
        $hours = Pattern::every('1h');

        self::assertSame(PatternKind::Interval, $minutes->kind);
        self::assertSame(300, $minutes->intervalSeconds);
        self::assertSame('300s', $minutes->expression);
        self::assertSame(3600, $hours->intervalSeconds);
        self::assertSame('3600s', $hours->expression);
    }

    #[Test]
    public function nextTickAdvancesOneFire(): void
    {
        $cron = Pattern::cron('* * * * *');
        $now = new DateTimeImmutable('2026-09-05T12:34:20+00:00');

        self::assertSame('2026-09-05T12:35:00+00:00', $cron->nextTick($now, new DateTimeZone('UTC')));

        $interval = Pattern::every('30s');

        self::assertSame('1970-01-01T00:01:00+00:00', $interval->nextTick(new DateTimeImmutable('@45'), new DateTimeZone('UTC')));
    }

    #[Test]
    public function impossibleCronFailsLoudly(): void
    {
        $pattern = Pattern::cron('0 0 31 2 *');

        $this->expectException(InvalidPatternException::class);

        $pattern->dueTick(new DateTimeImmutable('2026-09-05T12:00:00+00:00'), new DateTimeZone('UTC'), 90);
    }

    #[Test]
    public function malformedExpressionsAreRejectedOnConstruction(): void
    {
        foreach (['', '   ', 'not a pattern', '0s', '90000s', '* * *', '61 * * * *'] as $expression) {
            try {
                new Pattern($expression);
                self::fail(sprintf('"%s" must be rejected.', $expression));
            } catch (InvalidPatternException) {
            }
        }

        self::assertSame(PatternKind::Cron, (new Pattern('* * * * *'))->kind);
        self::assertSame(PatternKind::Cron, (new Pattern('@daily'))->kind);
    }
}
