<?php

declare(strict_types=1);

/**
 * Reads the host's process table: a process's descendants and its start
 * identity. Linux is read straight from /proc, so no package has to be
 * installed; elsewhere pgrep and ps answer, and their absence is reported
 * as a host that cannot list processes rather than guessed around.
 *
 * @internal
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Runner;

use Symfony\Component\Process\Process;
use Throwable;

final class ProcessTable
{
    /**
     * Every descendant of a process, breadth-first; null where the host
     * cannot list processes.
     *
     * @param int $pid The process whose descendants are listed
     *
     * @return list<int>|null
     */
    public static function descendants(int $pid): array|null
    {
        $parents = self::procParents();

        if ($parents === null) {
            return self::pgrepDescendants($pid);
        }

        $children = [];

        foreach ($parents as $child => $parent) {
            $children[$parent][] = $child;
        }

        $descendants = [];
        $queue = [$pid];

        while ($queue !== []) {
            $next = $children[array_shift($queue)] ?? [];
            $descendants = [...$descendants, ...$next];
            $queue = [...$queue, ...$next];
        }

        return $descendants;
    }

    /**
     * The start identity of each process asked for: what tells a live process
     * from a later one the kernel handed the same pid. A process that has
     * exited, that is a zombie waiting for its parent to reap it, or that
     * cannot be read, is simply absent from the answer.
     *
     * @param list<int> $pids The processes identified
     *
     * @return array<int, string> Start identities by pid.
     */
    public static function startIdentities(array $pids): array
    {
        if ($pids === []) {
            return [];
        }

        if (is_dir('/proc/self')) {
            $identities = [];

            foreach ($pids as $pid) {
                $fields = self::afterName(@file_get_contents('/proc/' . $pid . '/stat'));

                if ($fields !== null && $fields[0] !== 'Z') {
                    $identities[$pid] = $fields[19];
                }
            }

            return $identities;
        }

        return self::psStartIdentities($pids);
    }

    /**
     * @return array<int, int>|null Parent pid by pid, null without /proc.
     */
    private static function procParents(): array|null
    {
        if (!is_dir('/proc/self')) {
            return null;
        }

        $parents = [];
        $stats = glob('/proc/[0-9]*/stat');

        foreach ($stats === false ? [] : $stats as $stat) {
            $fields = self::afterName(@file_get_contents($stat));

            if ($fields !== null) {
                $parents[(int) basename(dirname($stat))] = (int) $fields[1];
            }
        }

        return $parents;
    }

    /**
     * The fields of a /proc stat line that follow the command name, which
     * may itself hold spaces and parentheses: state, parent pid, and so on.
     *
     * @param string|false $stat The stat line
     *
     * @return list<string>|null Null when the line is unreadable or too short.
     */
    private static function afterName(string|false $stat): array|null
    {
        if ($stat === false) {
            return null;
        }

        $close = strrpos($stat, ')');

        if ($close === false) {
            return null;
        }

        $fields = explode(' ', trim(substr($stat, $close + 1)));

        return count($fields) > 19 ? $fields : null;
    }

    /**
     * @param list<int> $pids The processes identified
     *
     * @return array<int, string> Start identities by pid, empty without ps.
     */
    private static function psStartIdentities(array $pids): array
    {
        $lister = new Process(['ps', '-o', 'pid=,lstart=,stat=', '-p', implode(',', $pids)]);

        try {
            $lister->mustRun();
        } catch (Throwable) {
            return [];
        }

        $identities = [];

        foreach (explode("\n", trim($lister->getOutput())) as $line) {
            if (preg_match('/^\s*(\d+)\s+(.+?)\s+(\S+)\s*$/u', $line, $matched) === 1 && !str_starts_with($matched[3], 'Z')) {
                $identities[(int) $matched[1]] = $matched[2];
            }
        }

        return $identities;
    }

    /**
     * @param int $pid The process whose descendants are listed
     *
     * @return list<int>|null Null without pgrep on the path.
     */
    private static function pgrepDescendants(int $pid): array|null
    {
        $descendants = [];
        $seen = [$pid => true];
        $queue = [$pid];

        while ($queue !== []) {
            $listed = self::pgrepChildren(array_shift($queue));

            if ($listed === null) {
                return null;
            }

            foreach ($listed as $child) {
                if (!isset($seen[$child])) {
                    $seen[$child] = true;
                    $descendants[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $descendants;
    }

    /**
     * @param int $pid The process whose children are listed
     *
     * @return list<int>|null Null when pgrep is missing or failed; a leaf answers an empty list.
     */
    private static function pgrepChildren(int $pid): array|null
    {
        $lister = new Process(['pgrep', '-P', (string) $pid]);

        try {
            $lister->run();
        } catch (Throwable) {
            return null;
        }

        if (!$lister->isSuccessful() && $lister->getExitCode() !== 1) {
            return null;
        }

        $children = [];
        $listed = preg_split('/\s+/', trim($lister->getOutput()));

        foreach ($listed === false ? [] : $listed as $chunk) {
            if (ctype_digit($chunk)) {
                $children[] = (int) $chunk;
            }
        }

        return $children;
    }
}
