<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Scheduler\Schedule\CollisionCheck;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollisionCheckTest extends TestCase
{
    #[Test]
    public function theSameInstantInTwoTimezonesCollides(): void
    {
        $new = new ScheduleDefinition(
            id: 'amman-report',
            command: 'dot report',
            pattern: Pattern::cron('0 21 * * *'),
            timezone: new DateTimeZone('Asia/Amman'),
        );
        $other = new ScheduleDefinition(
            id: 'utc-report',
            command: 'dot report',
            pattern: Pattern::cron('0 18 * * *'),
        );

        $warnings = (new CollisionCheck())->warnings($new, [$other], new DateTimeImmutable('2026-01-15T10:00:00+00:00'));

        self::assertNotSame([], $warnings);
        self::assertStringContainsString('utc-report', $warnings[0]);
    }

    #[Test]
    public function differentInstantsStayQuiet(): void
    {
        $new = new ScheduleDefinition(
            id: 'nightly',
            command: 'dot report',
            pattern: Pattern::cron('0 3 * * *'),
        );
        $other = new ScheduleDefinition(
            id: 'morning',
            command: 'dot report',
            pattern: Pattern::cron('0 9 * * *'),
        );

        $warnings = (new CollisionCheck())->warnings($new, [$other], new DateTimeImmutable('2026-01-15T10:00:00+00:00'));

        self::assertSame([], $warnings);
    }

    #[Test]
    public function disabledAndImpossibleSchedulesAreSkipped(): void
    {
        $new = new ScheduleDefinition(
            id: 'nightly',
            command: 'dot report',
            pattern: Pattern::cron('0 3 * * *'),
        );
        $paused = (new ScheduleDefinition(
            id: 'paused',
            command: 'dot report',
            pattern: Pattern::cron('0 3 * * *'),
        ))->withEnabled(false);
        $impossible = new ScheduleDefinition(
            id: 'impossible',
            command: 'dot report',
            pattern: Pattern::cron('0 0 31 2 *'),
        );

        $warnings = (new CollisionCheck())->warnings($new, [$paused, $impossible], new DateTimeImmutable('2026-01-15T10:00:00+00:00'));

        self::assertSame([], $warnings);
    }
}
