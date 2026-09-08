<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Unit\Schedule;

use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\PatternKind;
use PHPdot\Scheduler\Schedule\Preset;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PresetTest extends TestCase
{
    #[Test]
    public function fixedPresetsExpandToTheirExpressions(): void
    {
        self::assertSame('60s', Preset::EveryMinute->expression('any-id'));
        self::assertSame('300s', Preset::EveryFiveMinutes->expression('any-id'));
        self::assertSame('0 * * * *', Preset::Hourly->expression('any-id'));
    }

    #[Test]
    public function dailyMidnightStaggeringStaysInsideTheHourAndIsStable(): void
    {
        $first = Preset::DailyMidnight->expression('reports:generate');
        $again = Preset::DailyMidnight->expression('reports:generate');
        $other = Preset::DailyMidnight->expression('invoices:send');

        self::assertSame($first, $again);

        foreach ([$first, $other] as $expression) {
            $pattern = new Pattern($expression);

            self::assertSame(PatternKind::Cron, $pattern->kind);

            [$minute, $hour] = explode(' ', $expression);

            self::assertSame('0', $hour);
            self::assertGreaterThanOrEqual(0, (int) $minute);
            self::assertLessThanOrEqual(59, (int) $minute);
        }

        self::assertNotSame($first, $other);
    }

    #[Test]
    public function everyExpandedPresetIsAValidPattern(): void
    {
        foreach (Preset::cases() as $preset) {
            new Pattern($preset->expression('probe-id'));

            self::addToAssertionCount(1);
        }
    }
}
