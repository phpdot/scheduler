<?php

declare(strict_types=1);

/**
 * scheduler:define — create or update one schedule by id: what command runs,
 * on which pattern, and with which timeout, overlap, and grace policies.
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
use PHPdot\Scheduler\Exception\UnknownPresetException;
use PHPdot\Scheduler\Schedule\CollisionCheck;
use PHPdot\Scheduler\Schedule\OverlapPolicy;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\Preset;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'scheduler:define',
    description: 'Create or update a schedule: scheduler:define <id> [--cmd] --cron "…" — when it runs is your only decision.',
)]
final class DefineCommand extends Command
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
        $this->addArgument('cmd', InputArgument::OPTIONAL, 'Shell command override; defaults to the catalog entry or the stored one');
        $this->addOption('cron', null, InputOption::VALUE_REQUIRED, 'Cron expression, e.g. "* * * * *" or "@daily"');
        $this->addOption('every', null, InputOption::VALUE_REQUIRED, 'Interval, e.g. "30s", "5 minutes", "1h"');
        $this->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Named timing, e.g. daily-midnight (staggered)');
        $this->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Zone cron fields are interpreted in');
        $this->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Hard wall-clock cap in seconds');
        $this->addOption('idle-timeout', null, InputOption::VALUE_REQUIRED, 'No-output cap in seconds');
        $this->addOption('grace', null, InputOption::VALUE_REQUIRED, 'How late a missed fire may still be claimed, seconds');
        $this->addOption('overlap', null, InputOption::VALUE_REQUIRED, 'forbid (default) or allow');
        $this->addOption('description', null, InputOption::VALUE_REQUIRED, 'Human-facing note');
        $this->addOption('disabled', null, InputOption::VALUE_NONE, 'Store the schedule paused');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $this->stringArgument($input, 'id');
        $existing = $this->store->definition($id);
        $command = $this->stringArgument($input, 'cmd');

        if ($command === '') {
            $command = $existing !== null ? $existing->command : $this->catalogCommand($id);
        }

        if ($id === '' || $command === null) {
            $this->error($output, 'A new schedule needs a command: pass one, or run scheduler:sync and use a catalog name as the id.');

            return self::FAILURE;
        }

        $cron = $this->textOption($input, 'cron');
        $every = $this->textOption($input, 'every');
        $preset = $this->textOption($input, 'preset');

        $chosen = array_filter(
            ['cron' => $cron, 'every' => $every, 'preset' => $preset],
            static fn(string|null $value): bool => $value !== null,
        );

        if (count($chosen) > 1) {
            $this->error($output, sprintf('Choose exactly one timing — you passed --%s.', implode(' and --', array_keys($chosen))));

            return self::FAILURE;
        }

        $pattern = match (true) {
            $cron !== null => Pattern::cron($cron),
            $every !== null => Pattern::every($every),
            $preset !== null => $this->presetPattern($preset, $id),
            $existing !== null => $existing->pattern,
            default => null,
        };

        if ($pattern === null) {
            $this->error($output, 'A new schedule needs a timing: --cron, --every, or --preset.');

            return self::FAILURE;
        }

        try {
            $timezoneName = $this->textOption($input, 'timezone')
                ?? ($existing !== null ? $existing->timezone->getName() : 'UTC');
            $overlapName = strtolower(
                $this->textOption($input, 'overlap')
                    ?? ($existing !== null ? $existing->overlap->value : OverlapPolicy::Forbid->value),
            );

            $definition = new ScheduleDefinition(
                id: $id,
                command: $command,
                pattern: $pattern,
                timezone: new DateTimeZone($timezoneName),
                timeoutSeconds: $this->intOption($input, 'timeout')
                    ?? ($existing !== null ? $existing->timeoutSeconds : 300),
                idleTimeoutSeconds: $this->intOption($input, 'idle-timeout')
                    ?? ($existing !== null ? $existing->idleTimeoutSeconds : null),
                overlap: OverlapPolicy::from($overlapName),
                graceSeconds: $this->intOption($input, 'grace')
                    ?? ($existing !== null ? $existing->graceSeconds : 90),
                enabled: $this->flagOption($input, 'disabled')
                    ? false
                    : ($existing !== null ? $existing->enabled : true),
                description: $this->textOption($input, 'description')
                    ?? ($existing !== null ? $existing->description : null),
            );

            $this->store->saveDefinition($definition);
        } catch (SchedulerException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        $this->success($output, sprintf('Saved schedule "%s".', $id));

        foreach ($this->collisions($definition) as $warning) {
            $this->warning($output, $warning);
        }
        $this->table($output, [[
            'id' => $definition->id,
            'pattern' => $definition->pattern->expression,
            'timezone' => $definition->timezone->getName(),
            'timeout' => (string) $definition->timeoutSeconds . 's',
            'overlap' => $definition->overlap->value,
            'grace' => (string) $definition->graceSeconds . 's',
            'enabled' => $definition->enabled ? 'yes' : 'no',
            'command' => $definition->command,
        ]]);

        return self::SUCCESS;
    }

    /**
     * @param string $id The schedule id being created
     *
     * @return string|null The catalog's command for the id, null when absent.
     */
    private function catalogCommand(string $id): string|null
    {
        foreach ($this->store->catalogEntries() as $entry) {
            if ($entry->name === $id) {
                return $entry->command;
            }
        }

        return null;
    }

    /**
     * @param string $preset The preset name a user passed
     * @param string $id The schedule id seeding staggered presets
     *
     * @throws SchedulerException When the preset name is unknown.
     *
     * @return Pattern The preset's expression, expanded and validated.
     */
    private function presetPattern(string $preset, string $id): Pattern
    {
        $case = Preset::tryFrom($preset);

        if ($case === null) {
            throw UnknownPresetException::forPreset($preset);
        }

        return new Pattern($case->expression($id));
    }

    /**
     * @param ScheduleDefinition $definition The definition just saved
     *
     * @return list<string> Collision warnings, empty when the horizon is clear.
     */
    private function collisions(ScheduleDefinition $definition): array
    {
        return (new CollisionCheck())->warnings(
            $definition,
            $this->store->allDefinitions(),
            new DateTimeImmutable('now'),
        );
    }
}
