<?php

declare(strict_types=1);

/**
 * scheduler:runs — run history: every recorded fire, who claimed it, how it
 * ended, and what it printed.
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
    name: 'scheduler:runs',
    description: 'Show recorded run history, newest first.',
)]
final class RunsCommand extends Command
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
        $this->addOption('schedule', null, InputOption::VALUE_REQUIRED, 'Filter to one schedule id');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum rows', '20');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runs = $this->store->runs(
            $this->textOption($input, 'schedule'),
            max(1, $this->intOption($input, 'limit') ?? 20),
        );

        if ($runs === []) {
            $this->comment($output, 'No runs recorded.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($runs as $run) {
            $rows[] = [
                'id' => (string) $run->id,
                'schedule' => $run->scheduleId,
                'tick' => $run->tick,
                'state' => $run->state->value,
                'try' => (string) $run->attempt,
                'claimed_by' => $run->claimedBy,
                'started_at' => $run->startedAt,
                'exit' => $run->exitCode === null ? '—' : (string) $run->exitCode,
                'ms' => $run->durationMs === null ? '—' : (string) $run->durationMs,
                'error' => $run->error === null ? '' : mb_substr($run->error, 0, 64),
            ];
        }

        $this->table($output, $rows);

        return self::SUCCESS;
    }
}
