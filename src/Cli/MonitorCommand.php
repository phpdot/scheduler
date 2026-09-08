<?php

declare(strict_types=1);

/**
 * scheduler:monitor — the health check a pager or cron consumes: every
 * enabled schedule must have succeeded within its freshness window, and no
 * run may have failed or died inside the failure window. Exit 1 on breach.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Console\Command;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Exception\SchedulerException;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\PatternKind;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:monitor',
    description: 'Fail when a schedule missed its freshness window or a run failed recently.',
)]
final class MonitorCommand extends Command
{
    use ReadsSchedulerInput;

    private const string STORED_FORMAT = 'Y-m-d H:i:s.u';

    protected bool $coroutine = false;

    /**
     * @param SchedulerStoreInterface $store Where definitions and history live
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
        $this->addOption('failed-within', null, InputOption::VALUE_REQUIRED, 'Minutes back that count failures, 0 disables', '1440');
        $this->addOption('missed-multiplier', null, InputOption::VALUE_REQUIRED, 'Freshness window as a multiple of the period', '2');
        $this->addOption('scan', null, InputOption::VALUE_REQUIRED, 'How many recent runs the check reads', '1000');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable('now');
        $failureCutoff = $this->textOption($input, 'failed-within') === '0'
            ? null
            : $now->modify(sprintf('-%d minutes', max(0, $this->intOption($input, 'failed-within') ?? 1440)));
        $multiplier = max(1, $this->intOption($input, 'missed-multiplier') ?? 2);
        $runs = $this->store->runs(null, max(1, $this->intOption($input, 'scan') ?? 1000));

        $failures = 0;
        $lastSuccess = [];

        foreach ($runs as $run) {
            $startedAt = DateTimeImmutable::createFromFormat(self::STORED_FORMAT, $run->startedAt, new DateTimeZone('UTC'));

            if ($startedAt === false) {
                continue;
            }

            if ($failureCutoff !== null
                && $startedAt > $failureCutoff
                && ($run->state === RunState::Failed || $run->state === RunState::Expired)) {
                $failures++;
            }

            if ($run->state === RunState::Succeeded && !isset($lastSuccess[$run->scheduleId])) {
                $lastSuccess[$run->scheduleId] = $startedAt;
            }
        }

        $rows = [];
        $breaches = 0;

        foreach ($this->store->allDefinitions() as $definition) {
            if (!$definition->enabled) {
                continue;
            }

            try {
                $period = $this->periodSeconds($definition, $now);
            } catch (SchedulerException) {
                $rows[] = ['schedule' => $definition->id, 'period' => 'invalid', 'last success' => '—', 'status' => 'pattern never matches'];
                $breaches++;

                continue;
            }

            $freshness = max(120, $period * $multiplier);
            $success = $lastSuccess[$definition->id] ?? null;
            $missed = $success === null || $success < $now->modify(sprintf('-%d seconds', $freshness));

            $rows[] = [
                'schedule' => $definition->id,
                'period' => $period . 's',
                'last success' => $success?->format('Y-m-d H:i:s') ?? 'never',
                'status' => $missed ? 'MISSED' : 'ok',
            ];

            if ($missed) {
                $breaches++;
            }
        }

        if ($rows !== []) {
            $this->table($output, $rows);
        }

        $this->comment($output, sprintf('monitor — failed/expired in window: %d, freshness breaches: %d', $failures, $breaches > 0 ? $breaches : 0));

        return ($failures > 0 || $breaches > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param ScheduleDefinition $definition The schedule being measured
     * @param DateTimeImmutable $now The instant the period is computed from
     *
     * @throws SchedulerException When the pattern can never match.
     *
     * @return int Seconds between consecutive fires.
     */
    private function periodSeconds(ScheduleDefinition $definition, DateTimeImmutable $now): int
    {
        if ($definition->pattern->kind === PatternKind::Interval) {
            return $definition->pattern->intervalSeconds;
        }

        $first = new DateTimeImmutable($definition->pattern->nextTick($now, $definition->timezone));
        $second = new DateTimeImmutable($definition->pattern->nextTick($first->modify('+1 second'), $definition->timezone));

        return max(60, $second->getTimestamp() - $first->getTimestamp());
    }
}
