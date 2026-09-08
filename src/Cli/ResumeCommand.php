<?php

declare(strict_types=1);

/**
 * scheduler:resume — let a paused schedule fire again.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:resume',
    description: 'Resume a paused schedule.',
)]
final class ResumeCommand extends PauseCommand
{
    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->toggle($this->stringArgument($input, 'id'), true, $output);
    }
}
