<?php

declare(strict_types=1);

/**
 * Open base for every scheduler failure, so one catch guards the whole package.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Exception;

use RuntimeException;

class SchedulerException extends RuntimeException {}
