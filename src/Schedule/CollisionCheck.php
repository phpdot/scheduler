<?php

declare(strict_types=1);

/**
 * Warns when a schedule's next fires land on instants another enabled
 * schedule also fires — two schedules on the same instant both run, so the
 * define-time warning is where the collision becomes visible.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

use DateTimeImmutable;
use PHPdot\Scheduler\Exception\SchedulerException;

final class CollisionCheck
{
    private const int HORIZON = 3;

    /**
     * @param ScheduleDefinition $new The definition being created or updated
     * @param list<ScheduleDefinition> $others The other enabled definitions
     * @param DateTimeImmutable $now The instant the horizon is computed from
     *
     * @return list<string> One warning line per colliding schedule and instant.
     */
    public function warnings(ScheduleDefinition $new, array $others, DateTimeImmutable $now): array
    {
        $warnings = [];
        $newFires = $this->fires($new, $now);

        if ($newFires === []) {
            return $warnings;
        }

        foreach ($others as $other) {
            if ($other->id === $new->id || !$other->enabled) {
                continue;
            }

            foreach ($this->fires($other, $now) as $tick) {
                if (in_array($tick, $newFires, true)) {
                    $warnings[] = sprintf('"%s" also fires at %s', $other->id, $tick);
                }
            }
        }

        return $warnings;
    }

    /**
     * @param ScheduleDefinition $definition Whose horizon is computed
     * @param DateTimeImmutable $now Searched forward from this instant
     *
     * @return list<string> The next fire instants as tick identifiers.
     */
    private function fires(ScheduleDefinition $definition, DateTimeImmutable $now): array
    {
        $fires = [];
        $cursor = $now;

        try {
            for ($i = 0; $i < self::HORIZON; $i++) {
                $tick = $definition->pattern->nextTick($cursor, $definition->timezone);
                $fires[] = $tick;
                $cursor = (new DateTimeImmutable($tick))->modify('+1 second');
            }
        } catch (SchedulerException) {
            return [];
        }

        return $fires;
    }
}
