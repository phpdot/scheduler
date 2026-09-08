<?php

declare(strict_types=1);

/**
 * scheduler:work — the tick pass as a daemon: sleep to the next interval and
 * pass again, so schedules finer than a minute fire without cron. Stops its
 * running tasks on SIGTERM/SIGINT before exiting.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use function extension_loaded;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Config\SchedulerConfig;
use PHPdot\Scheduler\Engine\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'scheduler:work',
    description: 'Run the scheduler as a daemon, enabling sub-minute schedules.',
)]
final class WorkCommand extends Command
{
    use ReadsSchedulerInput;

    protected bool $coroutine = false;

    private bool $running = true;

    /**
     * @param Scheduler $engine The tick engine
     * @param SchedulerConfig $config The interval between passes
     */
    public function __construct(
        private readonly Scheduler $engine,
        private readonly SchedulerConfig $config,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addOption(
            'interval',
            null,
            InputOption::VALUE_REQUIRED,
            'Seconds between passes',
            (string) $this->config->workIntervalSeconds,
        );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!extension_loaded('pcntl')) {
            $this->error($output, 'scheduler:work requires ext-pcntl for signal handling; use scheduler:tick under cron without it.');

            return self::FAILURE;
        }

        $interval = max(1, $this->intOption($input, 'interval') ?? $this->config->workIntervalSeconds);

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function (): void {
            $this->requestStop();
        });
        pcntl_signal(SIGINT, function (): void {
            $this->requestStop();
        });

        $this->comment($output, sprintf('working — interval %ds, host %s', $interval, $this->config->resolveHost()));

        while ($this->running) {
            $passStartedAt = time();

            try {
                $report = $this->engine->tick();

                $summary = [];

                foreach ($report->counts() as $result => $count) {
                    $summary[] = sprintf('%s: %d', $result, $count);
                }

                $this->comment($output, sprintf('tick at %s — %s', $report->at->format('H:i:s'), $summary === [] ? 'no schedules' : implode(', ', $summary)));
            } catch (Throwable $e) {
                $this->error($output, sprintf('tick failed, retrying next pass: %s', $e->getMessage()));
            }

            $sleepUntil = $passStartedAt + $interval;

            while (time() < $sleepUntil) {
                if ($this->shouldStop()) {
                    break;
                }

                sleep(1);
            }
        }

        $this->success($output, 'stopped — running tasks were stopped and recorded.');

        return self::SUCCESS;
    }

    /**
     * A signal handler's other half: stop the loop and stop live tasks.
     *
     * @return void
     */
    private function requestStop(): void
    {
        $this->running = false;
        $this->engine->abort();
    }

    /**
     * Read in its own scope: the handler that clears the flag runs from a
     * signal, which the loop's flow analysis cannot see.
     *
     * @return bool True once a stop signal has arrived.
     */
    private function shouldStop(): bool
    {
        return !$this->running;
    }
}
