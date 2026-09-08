<?php

declare(strict_types=1);

/**
 * Scheduler-wide settings: who this server is, how many tasks it may hold at
 * once, and how long a claim outlives its runner's heartbeats.
 *
 * Auto-bound by phpdot/config when phpdot/package is installed: the user edits
 * config/scheduler.php; the DTO is hydrated from that file.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Config;

use PHPdot\Container\Attribute\Config;
use PHPdot\Scheduler\Exception\InvalidConfigurationException;

#[Config('scheduler')]
final class SchedulerConfig
{
    /**
     * @param string $serverHost Identity for concurrency caps; empty derives the hostname
     * @param int $concurrencyLimit Max runs this host holds with live leases
     * @param int $leaseTtlSeconds How long a claim lives without a heartbeat
     * @param int $heartbeatSeconds How often a running task renews its lease
     * @param int $workIntervalSeconds Seconds between scheduler:work passes
     * @param string|null $commandCwd Working directory for commands, null for the runner's own
     * @param array<string, string> $env Extra environment for every command
     *
     * @throws InvalidConfigurationException When the lease arithmetic cannot work.
     */
    public function __construct(
        public string $serverHost = '',
        public int $concurrencyLimit = 5,
        public int $leaseTtlSeconds = 60,
        public int $heartbeatSeconds = 15,
        public int $workIntervalSeconds = 10,
        public string|null $commandCwd = null,
        public array $env = [],
    ) {
        if ($concurrencyLimit < 1) {
            throw InvalidConfigurationException::forField('concurrencyLimit', 'it must be at least 1');
        }

        if ($heartbeatSeconds < 1) {
            throw InvalidConfigurationException::forField('heartbeatSeconds', 'it must be at least 1');
        }

        if ($leaseTtlSeconds <= $heartbeatSeconds * 2) {
            throw InvalidConfigurationException::forField('leaseTtlSeconds', sprintf(
                'it must exceed twice heartbeatSeconds (%d); a lease shorter than two missed heartbeats falsely kills live runs',
                $heartbeatSeconds,
            ));
        }

        if ($workIntervalSeconds < 1) {
            throw InvalidConfigurationException::forField('workIntervalSeconds', 'it must be at least 1');
        }
    }

    /**
     * @return string The host identity used for concurrency caps.
     */
    public function resolveHost(): string
    {
        if ($this->serverHost !== '') {
            return $this->serverHost;
        }

        $hostname = gethostname();

        return $hostname === false ? 'unknown-host' : $hostname;
    }

    /**
     * @return string The per-process identity recorded on claims.
     */
    public function resolveServerId(): string
    {
        return $this->resolveHost() . ':' . (string) getmypid();
    }
}
