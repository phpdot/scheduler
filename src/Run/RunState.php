<?php

declare(strict_types=1);

/**
 * The lifecycle of one claimed fire: running until the winner records an end.
 *
 * A run whose lease expired is still stored as Running — it is adoptable, and
 * the attempt counter on the row tells how many runners have held it — until
 * pruning closes a died fire no one adopted as Expired.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Run;

enum RunState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
}
