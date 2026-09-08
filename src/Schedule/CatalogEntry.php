<?php

declare(strict_types=1);

/**
 * One schedulable command in the catalog: what the interface offers, never
 * when it runs — that is always the user's decision in the store.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

final readonly class CatalogEntry
{
    /**
     * @param string $name The schedule id this command is offered under
     * @param string $command The shell command that executes it
     * @param string|null $description The command's human-facing note
     */
    public function __construct(
        public string $name,
        public string $command,
        public string|null $description,
    ) {}
}
