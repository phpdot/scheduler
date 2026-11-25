<?php

declare(strict_types=1);

/**
 * A stored schedule: what runs, on which pattern, and with which runtime
 * policies — the row every identical server reads to decide what is due.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Scheduler\Exception\InvalidDefinitionException;

final readonly class ScheduleDefinition
{
    /**
     * @param string $id The schedule's stable identifier, e.g. 'reports:generate'
     * @param string $command The shell command a claimed run executes
     * @param Pattern $pattern When the schedule fires
     * @param DateTimeZone $timezone The zone cron fields are interpreted in
     * @param int $timeoutSeconds Hard wall-clock cap on one run
     * @param int|null $idleTimeoutSeconds Cap on one run producing no output
     * @param OverlapPolicy $overlap Whether a live run blocks the next fire
     * @param int $graceSeconds How late a missed fire may still be claimed
     * @param bool $enabled Paused schedules stay stored but never fire
     * @param string|null $description Human-facing note
     * @param DateTimeImmutable|null $expectedSince When the schedule last became expected to fire: its latest redefinition or resumption
     *
     * @throws InvalidDefinitionException When a field fails validation.
     */
    public function __construct(
        public string $id,
        public string $command,
        public Pattern $pattern,
        public DateTimeZone $timezone = new DateTimeZone('UTC'),
        public int $timeoutSeconds = 300,
        public int|null $idleTimeoutSeconds = null,
        public OverlapPolicy $overlap = OverlapPolicy::Forbid,
        public int $graceSeconds = 90,
        public bool $enabled = true,
        public string|null $description = null,
        public DateTimeImmutable|null $expectedSince = null,
    ) {
        if (preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $id) !== 1) {
            throw InvalidDefinitionException::forField('id', '1-64 of letters, digits, dot, colon, underscore or dash');
        }

        if (trim($command) === '') {
            throw InvalidDefinitionException::forField('command', 'it is empty');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 86400) {
            throw InvalidDefinitionException::forField('timeoutSeconds', 'between 1 and 86400');
        }

        if ($idleTimeoutSeconds !== null && ($idleTimeoutSeconds < 1 || $idleTimeoutSeconds > 86400)) {
            throw InvalidDefinitionException::forField('idleTimeoutSeconds', 'between 1 and 86400, or null');
        }

        if ($graceSeconds < 0 || $graceSeconds > 604800) {
            throw InvalidDefinitionException::forField('graceSeconds', 'between 0 and 604800');
        }
    }

    /**
     * @param bool $enabled The new enabled flag
     *
     * @return self The same definition with only the enabled flag changed.
     */
    public function withEnabled(bool $enabled): self
    {
        return new self(
            id: $this->id,
            command: $this->command,
            pattern: $this->pattern,
            timezone: $this->timezone,
            timeoutSeconds: $this->timeoutSeconds,
            idleTimeoutSeconds: $this->idleTimeoutSeconds,
            overlap: $this->overlap,
            graceSeconds: $this->graceSeconds,
            enabled: $enabled,
            description: $this->description,
            expectedSince: $this->expectedSince,
        );
    }

    /**
     * Whether two definitions say the same thing, timing, policies and state
     * included; the stamps that say when each was written do not count.
     *
     * @param ScheduleDefinition $other The definition compared against
     *
     * @return bool
     */
    public function matches(ScheduleDefinition $other): bool
    {
        return $this->id === $other->id
            && $this->command === $other->command
            && $this->pattern->expression === $other->pattern->expression
            && $this->timezone->getName() === $other->timezone->getName()
            && $this->timeoutSeconds === $other->timeoutSeconds
            && $this->idleTimeoutSeconds === $other->idleTimeoutSeconds
            && $this->overlap === $other->overlap
            && $this->graceSeconds === $other->graceSeconds
            && $this->enabled === $other->enabled
            && $this->description === $other->description;
    }
}
