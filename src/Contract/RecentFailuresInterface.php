<?php

declare(strict_types=1);

/**
 * The store capability the monitor's failure alarm reads: whether any of a
 * schedule's runs failed inside a window, whichever run happens to be newest.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Contract;

interface RecentFailuresInterface
{
    /**
     * @param string $scheduleId The schedule whose history is asked
     * @param int $windowSeconds How far back a failure still counts
     *
     * @return bool True when a failed or expired run started inside the window.
     */
    public function hasRecentFailure(string $scheduleId, int $windowSeconds): bool;
}
