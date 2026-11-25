<?php

declare(strict_types=1);

/**
 * scheduler:monitor — the health check a pager or cron consumes: every
 * enabled schedule must have covered its latest due fire, no run may have
 * failed inside the failure window, and no run may sit past its lease. Exit
 * 1 on breach.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Console\Command;
use PHPdot\Scheduler\Contract\RecentFailuresInterface;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Exception\SchedulerException;
use PHPdot\Scheduler\Run\RunRecord;
use PHPdot\Scheduler\Run\RunState;
use PHPdot\Scheduler\Schedule\Pattern;
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
        $this->addOption('failed-within', null, InputOption::VALUE_REQUIRED, 'Minutes back in which any failed run alarms, 0 disables', '1440');
        $this->addOption('missed-multiplier', null, InputOption::VALUE_REQUIRED, 'Freshness window as a multiple of the period', '2');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new DateTimeImmutable('now');
        $numbers = $this->intOptions($input, $output, ['failed-within' => 1440, 'missed-multiplier' => 2]);

        if ($numbers === null) {
            return self::FAILURE;
        }

        $failureWindow = max(0, $numbers['failed-within'] ?? 1440) * 60;
        $multiplier = max(1, $numbers['missed-multiplier'] ?? 2);
        $failures = 0;
        $rows = [];
        $breaches = 0;

        foreach ($this->store->allDefinitions() as $definition) {
            if (!$definition->enabled) {
                continue;
            }

            $latest = $this->store->latestRun($definition->id);

            if ($latest !== null && $latest->state === RunState::Running && $this->leaseExpired($latest)) {
                $rows[] = ['schedule' => $definition->id, 'period' => 'running', 'last run' => $this->lastRun($latest), 'status' => 'STUCK'];
                $breaches++;

                continue;
            }

            if ($failureWindow > 0 && $this->failedRecently($definition->id, $latest, $now, $failureWindow)) {
                $failures++;
            }

            try {
                $period = $this->periodSeconds($definition, $now);
                $previousDue = $definition->pattern->previousDueTick($now, $definition->timezone);
            } catch (SchedulerException) {
                $rows[] = ['schedule' => $definition->id, 'period' => 'invalid', 'last run' => $this->lastRun($latest), 'status' => 'pattern never matches'];
                $breaches++;

                continue;
            }

            $status = $this->freshness($definition, $latest, $previousDue, $now, max(120, $period * $multiplier));

            if ($status === 'MISSED') {
                $breaches++;
            }

            $rows[] = [
                'schedule' => $definition->id,
                'period' => $period . 's',
                'last run' => $this->lastRun($latest),
                'status' => $status,
            ];
        }

        if ($rows !== []) {
            $this->table($output, $rows);
        }

        $this->comment($output, sprintf('monitor — failed/expired in window: %d, freshness breaches: %d', $failures, $breaches));

        return ($failures > 0 || $breaches > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The schedule's freshness verdict. MISSED means a fire it owed went
     * unrun; pending means it owes nothing yet, having been defined or
     * resumed after the last instant its pattern matches. A run claims the
     * latest due tick or a newer one, and deploy-interrupted runs still
     * count — the fire ran, only its host went away.
     *
     * @param ScheduleDefinition $definition The schedule being measured
     * @param RunRecord|null $latest Its newest run, null when it never ran
     * @param string $previousDue The latest fire's tick identifier
     * @param DateTimeImmutable $now The instant the judgement is made at
     * @param int $tolerance How stale the last run may be, seconds
     *
     * @return string pending, ok, or MISSED.
     */
    private function freshness(
        ScheduleDefinition $definition,
        RunRecord|null $latest,
        string $previousDue,
        DateTimeImmutable $now,
        int $tolerance,
    ): string {
        if ($definition->expectedSince !== null && $previousDue < $definition->expectedSince->format(Pattern::TICK_FORMAT)) {
            return 'pending';
        }

        if ($latest === null) {
            return $now->getTimestamp() - (new DateTimeImmutable($previousDue))->getTimestamp() > $definition->graceSeconds ? 'MISSED' : 'ok';
        }

        if ($latest->tick >= $previousDue) {
            return 'ok';
        }

        return $now->getTimestamp() - $this->startedAt($latest)->getTimestamp() <= $tolerance ? 'ok' : 'MISSED';
    }

    /**
     * Whether a failed or expired run started inside the window. A store
     * that can ask its history answers for every run; any other store is
     * asked only about its newest one.
     *
     * @param string $scheduleId The schedule being measured
     * @param RunRecord|null $latest Its newest run, null when it never ran
     * @param DateTimeImmutable $now The instant the window ends at
     * @param int $windowSeconds How far back a failure still counts
     *
     * @return bool
     */
    private function failedRecently(string $scheduleId, RunRecord|null $latest, DateTimeImmutable $now, int $windowSeconds): bool
    {
        if ($this->store instanceof RecentFailuresInterface) {
            return $this->store->hasRecentFailure($scheduleId, $windowSeconds);
        }

        if ($latest === null || !in_array($latest->state, [RunState::Failed, RunState::Expired], true)) {
            return false;
        }

        return $this->startedAt($latest) >= $now->modify(sprintf('-%d seconds', $windowSeconds));
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
     *
     * @return DateTimeImmutable When the run started, now when its stamp is unreadable.
     */
    private function startedAt(RunRecord $run): DateTimeImmutable
    {
        $started = DateTimeImmutable::createFromFormat(self::STORED_FORMAT, $run->startedAt, new DateTimeZone('UTC'));

        return $started === false ? new DateTimeImmutable('now') : $started;
    }

    /**
     * @param RunRecord|null $latest The schedule's newest run, null when it never ran
     *
     * @return string When it started, for the table.
     */
    private function lastRun(RunRecord|null $latest): string
    {
        return $latest === null ? 'never' : $this->startedAt($latest)->format('Y-m-d H:i:s');
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
