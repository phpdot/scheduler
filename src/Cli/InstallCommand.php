<?php

declare(strict_types=1);

/**
 * scheduler:install — create the scheduler tables and stamp their version;
 * idempotent, so deploy scripts can run it unconditionally.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Store\SchedulerSchema;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:install',
    description: 'Create the scheduler tables on the configured connection.',
)]
final class InstallCommand extends Command
{
    protected bool $coroutine = false;

    /**
     * @param SchedulerSchema $schema The tables this command creates
     */
    public function __construct(
        private readonly SchedulerSchema $schema,
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->schema->install();

        $this->success($output, sprintf('Scheduler schema %d installed.', SchedulerSchema::VERSION));

        return self::SUCCESS;
    }
}
