<?php

declare(strict_types=1);

/**
 * scheduler:pause — keep a schedule stored but stop it firing.
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
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:pause',
    description: 'Pause a schedule without deleting it.',
)]
class PauseCommand extends Command
{
    use ReadsSchedulerInput;

    protected bool $coroutine = false;

    /**
     * @param SchedulerStoreInterface $store Where definitions live
     */
    public function __construct(
        protected readonly SchedulerStoreInterface $store,
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
        return $this->toggle($this->stringArgument($input, 'id'), false, $output);
    }

    /**
     * @param string $id The schedule identifier
     * @param bool $enabled The enabled flag to write
     * @param OutputInterface $output Where the result is reported
     *
     * @return int The command exit code.
     */
    protected function toggle(string $id, bool $enabled, OutputInterface $output): int
    {
        $definition = $this->store->definition($id);

        if ($definition === null) {
            $this->error($output, sprintf('No schedule "%s".', $id));

            return self::FAILURE;
        }

        $this->store->saveDefinition($definition->withEnabled($enabled));
        $this->success($output, sprintf('Schedule "%s" is %s.', $id, $enabled ? 'resumed' : 'paused'));

        return self::SUCCESS;
    }
}
