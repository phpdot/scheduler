<?php

declare(strict_types=1);

/**
 * Everything one schedule can contribute to one tick pass.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Engine;

enum TickResult: string
{
    case NotDue = 'not_due';
    case HostBusy = 'host_busy';
    case OverlapSkipped = 'overlap_skipped';
    case ClaimLost = 'claim_lost';
    case PatternError = 'pattern_error';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Interrupted = 'interrupted';
    case Fenced = 'fenced';
}
