<?php

declare(strict_types=1);

/**
 * Named timings the interface offers — a menu over pattern strings, not a
 * second source of truth: a preset expands to its expression at define time
 * and the schedule owns the resulting string forever.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

enum Preset: string
{
    case EveryMinute = 'every-minute';
    case EveryFiveMinutes = 'every-5-minutes';
    case Hourly = 'hourly';
    case DailyMidnight = 'daily-midnight';

    /**
     * @param string $scheduleId The seed spreading staggered presets
     *
     * @return string The pattern expression this preset expands to.
     */
    public function expression(string $scheduleId): string
    {
        return match ($this) {
            self::EveryMinute => '60s',
            self::EveryFiveMinutes => '300s',
            self::Hourly => '0 * * * *',
            self::DailyMidnight => sprintf('%d 0 * * *', crc32($scheduleId) % 60),
        };
    }

    /**
     * @return string The names a user may pass, comma-separated.
     */
    public static function names(): string
    {
        return implode(', ', array_map(static fn(self $case): string => $case->value, self::cases()));
    }
}
