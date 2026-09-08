<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Support;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Attribute\Scheduled;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'demo:seeded', description: 'Schedulable demo command')]
#[Scheduled(name: 'demo:seeded')]
final class SeededEchoCommand extends Command
{
    protected bool $coroutine = false;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return self::SUCCESS;
    }
}
