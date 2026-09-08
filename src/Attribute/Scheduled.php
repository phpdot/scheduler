<?php

declare(strict_types=1);

/**
 * Marks a console command as schedulable — nothing more. It opts the command
 * into the scheduler's catalog under an explicit name; when it runs is the
 * user's decision, made in the interface and stored as data.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Scheduled
{
    /**
     * @param string $name The catalog and schedule id this command is offered under
     * @param string|null $description Note override, null for the command's own
     */
    public function __construct(
        public string $name,
        public string|null $description = null,
    ) {}
}
