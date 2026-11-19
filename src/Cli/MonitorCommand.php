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
use PHPdot\Scheduler\Run\RunRecord;
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

        $failures = 0;
        $rows = [];
        $breaches = 0;

        foreach ($this->store->allDefinitions() as $definition) {
            if (!$definition->enabled) {
                continue;
            }

            $latest = $this->store->latestRun($definition->id);

            if ($latest !== null && $latest->state === RunState::Running && $this->leaseExpired($latest)) {
                $rows[] = ['schedule' => $definition->id, 'period' => 'running', 'last success' => '—', 'status' => 'STUCK'];
                $breaches++;

                continue;
            }

            if ($latest !== null && $failureCutoff !== null
                && $this->startedBefore($latest, $now) > $failureCutoff
                && ($latest->state === RunState::Failed || $latest->state === RunState::Expired)) {
                $failures++;
            }

            try {
                $period = $this->periodSeconds($definition, $now);
                $previousDue = $definition->pattern->previousDueTick($now, $definition->timezone);
            } catch (SchedulerException) {
                $rows[] = ['schedule' => $definition->id, 'period' => 'invalid', 'last success' => '—', 'status' => 'pattern never matches'];
                $breaches++;

                continue;
            }

            $freshness = max(120, $period * $multiplier);
            $covered = $this->coveredFire($definition, $latest, $previousDue, $now, $freshness);

            $rows[] = [
                'schedule' => $definition->id,
                'period' => $period . 's',
                'last success' => $covered?->format('Y-m-d H:i:s') ?? 'never',
                'status' => $covered === null ? 'MISSED' : 'ok',
            ];

            if ($covered === null) {
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
     * When the schedule's newest run says a fire was covered: a run claims
     * the latest due tick or a newer one. Deploy-interrupted runs still
     * count — the fire ran, only its host went away — and a quiet schedule
     * is judged against its own fires, never a global scan window.
     *
     * @param ScheduleDefinition $definition The schedule being measured
     * @param ScheduleDefinition $definition The schedule being measured
     * @param RunRecord|null $latest Its newest run, null when it never ran
     * @param string $previousDue The latest fire's tick identifier
     * @param DateTimeImmutable $now The instant the judgement is made at
     * @param int $freshness How stale a run may be, seconds
     *
     * @return DateTimeImmutable|null When the covering run started, null when missed.
     */
    private function coveredFire(
        ScheduleDefinition $definition,
        RunRecord|null $latest,
        string $previousDue,
        DateTimeImmutable $now,
        int $freshness,
    ): DateTimeImmutable|null {
        if ($latest === null) {
            $age = $now->getTimestamp() - (new DateTimeImmutable($previousDue))->getTimestamp();

            return $age > $definition->graceSeconds ? null : $now;
        }

        $started = $this->startedBefore($latest, $now);

        if ($latest->tick >= $previousDue) {
            return $started;
        }

        $stale = $now->getTimestamp() - $started->getTimestamp();

        return $stale <= $freshness ? $started : null;
    }

    /**
     * @param RunRecord $run The run whose lease is examined
     *
     * @return bool True when the run's lease has expired while it runs.
     */
    private function leaseExpired(RunRecord $run): bool
    {
        $expires = DateTimeImmutable::createFromFormat(self::STORED_FORMAT, $run->leaseExpiresAt, new DateTimeZone('UTC'));

        return $expires !== false && $expires < new DateTimeImmutable('now');
    }

    /**
     * @param RunRecord $run The run whose start is read
     * @param DateTimeImmutable $now The instant the read is made at
     *
     * @return DateTimeImmutable When the run started, now when unparsable.
     */
    private function startedBefore(RunRecord $run, DateTimeImmutable $now): DateTimeImmutable
    {
        $started = DateTimeImmutable::createFromFormat(self::STORED_FORMAT, $run->startedAt, new DateTimeZone('UTC'));

        return $started === false ? $now : $started;
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
