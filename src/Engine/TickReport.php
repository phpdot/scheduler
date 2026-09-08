<?php

declare(strict_types=1);

/**
 * One whole tick pass: when it ran and what every schedule did.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Engine;

use DateTimeImmutable;

final readonly class TickReport
{
    /**
     * @param DateTimeImmutable $at The instant this pass evaluated against
     * @param list<TickOutcome> $outcomes One outcome per enabled schedule
     */
    public function __construct(
        public DateTimeImmutable $at,
        public array $outcomes,
    ) {}

    /**
     * @return array<string, int> How many outcomes carry each result.
     */
    public function counts(): array
    {
        $counts = [];

        foreach ($this->outcomes as $outcome) {
            $key = $outcome->result->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
