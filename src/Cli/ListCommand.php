<?php

declare(strict_types=1);

/**
 * scheduler:list — every stored schedule with its pattern, policies, and the
 * next tick it fires on.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Clock\SystemClock;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'scheduler:list',
    description: 'List every stored schedule and when it next fires.',
)]
final class ListCommand extends Command
{
    protected bool $coroutine = false;

    /**
     * @param SchedulerStoreInterface $store Where definitions live
     * @param ClockInterface $clock The clock next-fire times compute from
     */
    public function __construct(
        private readonly SchedulerStoreInterface $store,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $definitions = $this->store->allDefinitions();

        if ($definitions === [] && $this->store->catalogEntries() === []) {
            $this->comment($output, 'No schedules stored and nothing schedulable in the catalog.');

            return self::SUCCESS;
        }

        $now = $this->clock->now();
        $rows = [];

        foreach ($definitions as $definition) {
            try {
                $next = $definition->pattern->nextTick($now, $definition->timezone);
            } catch (Throwable) {
                $next = 'invalid';
            }

            $rows[$definition->id] = [
                'id' => $definition->id,
                'pattern' => $definition->pattern->expression,
                'next' => $next,
                'timezone' => $definition->timezone->getName(),
                'overlap' => $definition->overlap->value,
                'timeout' => (string) $definition->timeoutSeconds . 's',
                'enabled' => $definition->enabled ? 'yes' : 'paused',
                'command' => $definition->command,
            ];
        }

        foreach ($this->store->catalogEntries() as $entry) {
            if (isset($rows[$entry->name])) {
                continue;
            }

            $rows[$entry->name] = [
                'id' => $entry->name,
                'pattern' => '—',
                'next' => '—',
                'timezone' => '—',
                'overlap' => '—',
                'timeout' => '—',
                'enabled' => 'available',
                'command' => $entry->command,
            ];
        }

        ksort($rows);
        $this->table($output, array_values($rows));

        return self::SUCCESS;
    }
}
