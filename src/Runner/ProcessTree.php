<?php

declare(strict_types=1);

/**
 * One command's process tree as it stood when captured: the shell and every
 * descendant, each pinned to its start identity, so a signal sent later
 * cannot land on a pid the kernel has already handed to something else.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Runner;

use Closure;

final readonly class ProcessTree
{
    private const int EXIT_POLL_MICROSECONDS = 100000;

    /**
     * @param array<int, string> $members Pids with their start identities, empty where none could be read
     * @param bool $walked Whether the host could list children at all
     * @param int|null $root The command's own pid at capture
     */
    public function __construct(
        private array $members,
        public bool $walked,
        private readonly int|null $root = null,
    ) {}

    /**
     * Capture the tree under a process while all of it is still alive: the
     * shell's death re-parents its children and hides them from any listing.
     * A pid that is not positive is never a member — signalling one would
     * address a whole process group.
     *
     * @param int|null $root The command's own pid, null once it has exited
     * @param Closure(int): (list<int>|null) $listDescendants Answers every descendant of a pid, or null where the host cannot list them
     *
     * @return self
     */
    public static function under(int|null $root, Closure $listDescendants): self
    {
        if ($root === null) {
            return new self([], true);
        }

        if ($root < 1 || !function_exists('posix_kill')) {
            return new self([], false);
        }

        $descendants = $listDescendants($root);

        if ($descendants === null) {
            return new self([], false, $root);
        }

        $pids = array_values(array_filter([$root, ...$descendants], static fn(int $pid): bool => $pid > 0));
        $identities = ProcessTable::startIdentities($pids);
        $members = [];

        foreach ($pids as $pid) {
            $members[$pid] = $identities[$pid] ?? '';
        }

        return new self($members, true, $root);
    }

    /**
     * This capture widened by whatever its root has spawned since: a member
     * that ignored the first signal may have forked meanwhile, and those
     * children belong to the same command.
     *
     * @param Closure(int): (list<int>|null) $listDescendants Answers every descendant of a pid, or null where the host cannot list them
     *
     * @return self
     */
    public function regrown(Closure $listDescendants): self
    {
        if ($this->root === null || !$this->walked) {
            return $this;
        }

        $now = self::under($this->root, $listDescendants);

        return new self($now->members + $this->members, $this->walked, $this->root);
    }

    /**
     * Signal every captured member at once: nothing has had time to exit
     * and lend its pid elsewhere.
     *
     * @param int $signal The signal every member receives
     *
     * @return void
     */
    public function signal(int $signal): void
    {
        foreach (array_keys($this->members) as $pid) {
            @posix_kill($pid, $signal);
        }
    }

    /**
     * Wait until the captured tree has exited, or the seconds have passed.
     *
     * @param int $seconds How long to wait at most
     *
     * @return void
     */
    public function awaitExit(int $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline && self::survivors($this->members) !== []) {
            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }

    /**
     * Signal only the members still running under the identity they were
     * captured with; a member whose identity could not be read is left alone.
     *
     * @param int $signal The signal the survivors receive
     *
     * @return void
     */
    public function escalate(int $signal): void
    {
        foreach (self::survivors($this->members) as $pid) {
            @posix_kill($pid, $signal);
        }
    }

    /**
     * @param array<int, string> $members Pids with their captured start identities
     *
     * @return list<int> The pids still running as the same processes.
     */
    private static function survivors(array $members): array
    {
        $current = ProcessTable::startIdentities(array_keys($members));
        $alive = [];

        foreach ($members as $pid => $identity) {
            if ($pid > 0 && $identity !== '' && ($current[$pid] ?? null) === $identity) {
                $alive[] = $pid;
            }
        }

        return $alive;
    }
}
