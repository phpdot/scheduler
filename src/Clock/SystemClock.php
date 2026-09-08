<?php

declare(strict_types=1);

/**
 * Default PSR-20 clock returning the real current time, so the engine never
 * hard-codes time() and tests can freeze the tick.
 *
 * Deliberately carries no container binding: another package binding the same
 * interface is the app's clock, and the engine accepts any implementation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
