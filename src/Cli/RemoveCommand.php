<?php

declare(strict_types=1);

/**
 * scheduler:remove — delete a schedule definition; its run history stays.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:remove',
    description: 'Delete a schedule definition; run history is kept.',
)]
final class RemoveCommand extends Command
{
    use ReadsSchedulerInput;

    protected bool $coroutine = false;

    /**
     * @param SchedulerStoreInterface $store Where definitions live
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
        $this->addArgument('id', InputArgument::REQUIRED, 'The schedule id');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Delete without asking');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->stringArgument($input, 'id');

        if (!$this->flagOption($input, 'force') && !$this->confirm($input, $output, sprintf('Delete schedule "%s"?', $id), false)) {
            $this->comment($output, 'Nothing deleted.');

            return self::SUCCESS;
        }

        if (!$this->store->deleteDefinition($id)) {
            $this->error($output, sprintf('No schedule "%s".', $id));

            return self::FAILURE;
        }

        $this->success($output, sprintf('Deleted schedule "%s"; its runs remain in history.', $id));

        return self::SUCCESS;
    }
}
