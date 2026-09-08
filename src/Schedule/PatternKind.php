<?php

declare(strict_types=1);

/**
 * The two shapes a schedule pattern takes: cron syntax or a fixed interval.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

enum PatternKind: string
{
    case Cron = 'cron';
    case Interval = 'interval';
}
