<?php

declare(strict_types=1);

/**
 * scheduler:prune — housekeeping for run history: closes died fires no one
 * adopted as expired, then deletes terminal runs past the retention window.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:prune',
    description: 'Close stale died fires as expired and delete old terminal runs.',
)]
final class PruneCommand extends Command
{
    use ReadsSchedulerInput;

    protected bool $coroutine = false;

    /**
     * @param SchedulerStoreInterface $store Where history lives
     */
    public function __construct(
        private readonly SchedulerStoreInterface $store,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addOption('stale-grace', null, InputOption::VALUE_REQUIRED, 'Seconds past lease expiry before a died fire closes', '300');
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Delete terminal runs older than this many days', '30');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $staleGrace = max(0, $this->intOption($input, 'stale-grace') ?? 300);
        $days = max(0, $this->intOption($input, 'days') ?? 30);

        $closed = $this->store->closeStaleRuns($staleGrace);
        $deleted = $this->store->pruneRuns($days);

        $this->success($output, sprintf(
            'Pruned: %d died %s closed as expired, %d terminal %s deleted (%d-day retention).',
            $closed,
            $closed === 1 ? 'fire' : 'fires',
            $deleted,
            $deleted === 1 ? 'run' : 'runs',
            $days,
        ));

        return self::SUCCESS;
    }
}
