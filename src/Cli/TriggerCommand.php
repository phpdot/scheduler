<?php

declare(strict_types=1);

/**
 * scheduler:trigger — fire one schedule now, bypassing its pattern but not
 * the claim: a live run or a full host still refuses the fire.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Engine\Scheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:trigger',
    description: 'Run one schedule now, claim-guarded like any fire.',
)]
final class TriggerCommand extends Command
{
    use ReadsSchedulerInput;

    protected bool $coroutine = false;

    /**
     * @param Scheduler $engine The engine that claims and executes
     */
    public function __construct(
        private readonly Scheduler $engine,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'The schedule id');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->stringArgument($input, 'id');
        $outcome = $this->engine->trigger($id);

        if ($outcome === null) {
            $this->error($output, sprintf('No schedule "%s".', $id));

            return self::FAILURE;
        }

        $this->table($output, [[
            'schedule' => $outcome->scheduleId,
            'tick' => $outcome->tick ?? '—',
            'result' => $outcome->result->value,
            'ms' => $outcome->durationMs === null ? '—' : (string) $outcome->durationMs,
            'detail' => $outcome->detail ?? '',
        ]]);

        return self::SUCCESS;
    }
}
