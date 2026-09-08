<?php

declare(strict_types=1);

/**
 * scheduler:sync — make the catalog of schedulable commands mirror the code:
 * every #[Scheduled] command becomes an entry, gone commands drop out. The
 * catalog is the interface's menu — when anything runs stays the user's
 * decision in the store.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Cli;

use PHPdot\Console\Command;
use PHPdot\Scheduler\Attribute\Scheduled;
use PHPdot\Scheduler\Contract\SchedulerStoreInterface;
use PHPdot\Scheduler\Schedule\CatalogEntry;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'scheduler:sync',
    description: 'Reconcile the catalog of #[Scheduled] commands; when they run stays the user\'s decision.',
)]
final class SyncCommand extends Command
{
    use ReadsSchedulerInput;

    protected bool $coroutine = false;

    /**
     * @param SchedulerStoreInterface $store Where the catalog lives
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
        $this->addOption('bin', null, InputOption::VALUE_REQUIRED, 'Binary that executes commands', 'dot');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();

        if ($application === null) {
            $this->error($output, 'scheduler:sync must run inside a console application.');

            return self::FAILURE;
        }

        $bin = $this->textOption($input, 'bin') ?? 'dot';

        /** @var array<string, bool> $seen */
        $seen = [];
        $entries = [];
        $failures = [];

        foreach ($application->all() as $command) {
            $declarations = (new ReflectionClass($command))->getAttributes(
                Scheduled::class,
                ReflectionAttribute::IS_INSTANCEOF,
            );

            foreach ($declarations as $declaration) {
                $scheduled = $declaration->newInstance();
                $commandName = $command->getName();

                if ($commandName === null || $commandName === '') {
                    $failures[] = sprintf('%s: scheduled but the command has no name', $command::class);

                    continue;
                }

                $name = $scheduled->name;

                if (isset($seen[$name])) {
                    $failures[] = sprintf('%s: catalog name "%s" is declared twice', $command::class, $name);

                    continue;
                }

                $seen[$name] = true;

                $entries[] = new CatalogEntry(
                    $name,
                    sprintf('%s %s', $bin, $commandName),
                    $scheduled->description ?? $command->getDescription(),
                );
            }
        }

        $counts = $this->store->reconcileCatalog($entries);

        foreach ($failures as $failure) {
            $this->error($output, $failure);
        }

        $this->comment($output, sprintf(
            'catalog — added: %d, updated: %d, removed: %d, total: %d%s',
            $counts['added'],
            $counts['updated'],
            $counts['removed'],
            count($entries),
            $failures === [] ? '' : sprintf(', failed: %d', count($failures)),
        ));

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
