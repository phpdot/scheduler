<?php

declare(strict_types=1);

/**
 * One schedule's fate in one tick pass.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Engine;

final readonly class TickOutcome
{
    /**
     * @param string $scheduleId The schedule this outcome belongs to
     * @param string|null $tick The tick identifier, when one was evaluated
     * @param TickResult $result What happened
     * @param int|null $durationMs How long a claimed run executed
     * @param string|null $detail Why a non-execution outcome happened
     */
    public function __construct(
        public string $scheduleId,
        public string|null $tick,
        public TickResult $result,
        public int|null $durationMs,
        public string|null $detail,
    ) {}
}
