<?php

declare(strict_types=1);

/**
 * scheduler:tick — one evaluation pass: claim what is due, run what this
 * server wins, record every outcome, exit. Designed for cron every minute.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Engine\Scheduler;
use PHPdot\Scheduler\Engine\TickResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:tick',
    description: 'Run one scheduler pass: claim due tasks and execute what this server wins.',
)]
final class TickCommand extends Command
{
    protected bool $coroutine = false;

    /**
     * @param Scheduler $engine The tick engine
     */
    public function __construct(
        private readonly Scheduler $engine,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->engine->tick();
        $rows = [];

        foreach ($report->outcomes as $outcome) {
            if ($outcome->result !== TickResult::NotDue || $output->isVerbose()) {
                $rows[] = [
                    'schedule' => $outcome->scheduleId,
                    'tick' => $outcome->tick ?? '—',
                    'result' => $outcome->result->value,
                    'ms' => (string) ($outcome->durationMs ?? '—'),
                    'detail' => $outcome->detail ?? '',
                ];
            }
        }

        if ($rows !== []) {
            $this->table($output, $rows);
        }

        $summary = [];

        foreach ($report->counts() as $result => $count) {
            $summary[] = sprintf('%s: %d', $result, $count);
        }

        $this->comment($output, sprintf('tick at %s — %s', $report->at->format('Y-m-d\TH:i:sP'), $summary === [] ? 'no schedules' : implode(', ', $summary)));

        return self::SUCCESS;
    }
}
