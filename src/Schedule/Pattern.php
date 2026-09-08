<?php

declare(strict_types=1);

/**
 * A schedule pattern: cron syntax or an interval ('30s'), the single value
 * that decides which instants a schedule fires on.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Scheduler\Schedule;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use PHPdot\Scheduler\Exception\InvalidPatternException;
use Throwable;

final readonly class Pattern
{
    public const string TICK_FORMAT = 'Y-m-d\TH:i:sP';

    public string $expression;

    public PatternKind $kind;

    /** @var int<0, max> Interval length in seconds; 0 for cron patterns. */
    public int $intervalSeconds;

    /**
     * @param string $expression Cron syntax or an interval specification
     *
     * @throws InvalidPatternException When the expression parses as neither shape.
     */
    public function __construct(
        string $expression,
    ) {
        $trimmed = trim($expression);

        if ($trimmed === '') {
            throw InvalidPatternException::forExpression($expression, 'it is empty');
        }

        if (preg_match('/^(\d+)\s*(s|sec(?:ond)?s?|m(?:in(?:ute)?)?s?|h(?:r|our)?s?)$/i', $trimmed, $matches) === 1) {
            $seconds = self::unitSeconds(strtolower($matches[2])) * (int) $matches[1];

            if ($seconds < 1 || $seconds > 86400) {
                throw InvalidPatternException::forExpression($expression, 'intervals must be between 1 second and 1 day; use cron for longer');
            }

            $this->kind = PatternKind::Interval;
            $this->intervalSeconds = $seconds;
            $this->expression = $seconds . 's';

            return;
        }

        try {
            new CronExpression($trimmed);
        } catch (Throwable $e) {
            throw InvalidPatternException::forExpression($expression, $e->getMessage());
        }

        $this->kind = PatternKind::Cron;
        $this->intervalSeconds = 0;
        $this->expression = $trimmed;
    }

    /**
     * @param string $expression Cron syntax, validated on construction
     *
     * @return self
     */
    public static function cron(string $expression): self
    {
        return new self($expression);
    }

    /**
     * @param string $specification A count with a unit, e.g. '30s', '5 minutes', '1h'
     *
     * @return self
     */
    public static function every(string $specification): self
    {
        return new self($specification);
    }

    /**
     * The latest tick this pattern fires on that is at most $now old, or null
     * when the most recent fire is older than the grace window.
     *
     * @param DateTimeImmutable $now Evaluated against this instant
     * @param DateTimeZone $timezone The zone cron fields are interpreted in
     * @param int $graceSeconds How late a missed fire may still be claimed
     *
     * @throws InvalidPatternException When a cron expression can never match.
     *
     * @return string|null The tick identifier, null when nothing is due.
     */
    public function dueTick(DateTimeImmutable $now, DateTimeZone $timezone, int $graceSeconds): null|string
    {
        if ($this->kind === PatternKind::Interval) {
            $bucket = intdiv($now->getTimestamp(), $this->intervalSeconds) * $this->intervalSeconds;

            if ($now->getTimestamp() - $bucket > $graceSeconds) {
                return null;
            }

            return (new DateTimeImmutable('@' . $bucket))->format(self::TICK_FORMAT);
        }

        try {
            $candidate = (new CronExpression($this->expression))
                ->getPreviousRunDate($now->setTimezone($timezone), 0, true);
        } catch (Exception $e) {
            throw InvalidPatternException::forExpression($this->expression, $e->getMessage());
        }

        $candidate = DateTimeImmutable::createFromInterface($candidate)->setTimezone(new DateTimeZone('UTC'));

        if ($now->getTimestamp() - $candidate->getTimestamp() > $graceSeconds) {
            return null;
        }

        return $candidate->format(self::TICK_FORMAT);
    }

    /**
     * The next tick after $now this pattern fires on, for display.
     *
     * @param DateTimeImmutable $now Searched forward from this instant
     * @param DateTimeZone $timezone The zone cron fields are interpreted in
     *
     * @throws InvalidPatternException When a cron expression can never match.
     *
     * @return string The tick identifier.
     */
    public function nextTick(DateTimeImmutable $now, DateTimeZone $timezone): string
    {
        if ($this->kind === PatternKind::Interval) {
            $next = (intdiv($now->getTimestamp(), $this->intervalSeconds) + 1) * $this->intervalSeconds;

            return (new DateTimeImmutable('@' . $next))->format(self::TICK_FORMAT);
        }

        try {
            $candidate = (new CronExpression($this->expression))
                ->getNextRunDate($now->setTimezone($timezone));
        } catch (Exception $e) {
            throw InvalidPatternException::forExpression($this->expression, $e->getMessage());
        }

        return DateTimeImmutable::createFromInterface($candidate)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::TICK_FORMAT);
    }

    /**
     * @param string $unit A matched unit token, lowercased
     *
     * @return positive-int Seconds the unit represents.
     */
    private static function unitSeconds(string $unit): int
    {
        return match (true) {
            str_starts_with($unit, 's') => 1,
            str_starts_with($unit, 'h') => 3600,
            default => 60,
        };
    }
}
