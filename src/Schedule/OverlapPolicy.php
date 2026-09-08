<?php

declare(strict_types=1);

/**
 * What happens when a fire is due while a previous run of the same schedule is
 * still live: Forbid skips the new fire, Allow starts it beside the old one.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

enum OverlapPolicy: string
{
    case Forbid = 'forbid';
    case Allow = 'allow';
}
