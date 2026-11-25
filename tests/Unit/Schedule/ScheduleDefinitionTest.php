<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Unit\Schedule;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Scheduler\Exception\InvalidDefinitionException;
use PHPdot\Scheduler\Schedule\OverlapPolicy;
use PHPdot\Scheduler\Schedule\Pattern;
use PHPdot\Scheduler\Schedule\ScheduleDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScheduleDefinitionTest extends TestCase
{
    #[Test]
    public function defaultsCarryTheSafePolicies(): void
    {
        $definition = new ScheduleDefinition(
            id: 'reports:generate',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
        );

        self::assertSame('UTC', $definition->timezone->getName());
        self::assertSame(300, $definition->timeoutSeconds);
        self::assertNull($definition->idleTimeoutSeconds);
        self::assertSame(OverlapPolicy::Forbid, $definition->overlap);
        self::assertSame(90, $definition->graceSeconds);
        self::assertTrue($definition->enabled);
    }

    #[Test]
    public function invalidFieldsAreRejected(): void
    {
        $cases = [
            ['id', 'has spaces', 'has spaces'],
            ['id', 'is over 64 chars', str_repeat('a', 65)],
            ['command', 'is empty', '  '],
            ['timeoutSeconds', 'is zero', 0],
            ['timeoutSeconds', 'exceeds a day', 86401],
            ['graceSeconds', 'is negative', -1],
        ];

        $rejected = 0;

        foreach ($cases as [$field, $reason, $value]) {
            try {
                new ScheduleDefinition(
                    id: $field === 'id' ? $value : 'reports:generate',
                    command: $field === 'command' ? $value : 'dot reports:generate',
                    pattern: Pattern::every('60s'),
                    timeoutSeconds: $field === 'timeoutSeconds' ? $value : 300,
                    graceSeconds: $field === 'graceSeconds' ? $value : 90,
                );
                self::fail(sprintf('%s %s must be rejected.', $field, $reason));
            } catch (InvalidDefinitionException) {
                $rejected++;
            }
        }

        self::assertSame(count($cases), $rejected);
    }

    #[Test]
    public function withEnabledChangesOnlyTheFlag(): void
    {
        $definition = new ScheduleDefinition(
            id: 'reports:generate',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
            timeoutSeconds: 120,
        );
        $paused = $definition->withEnabled(false);

        self::assertFalse($paused->enabled);
        self::assertSame($definition->id, $paused->id);
        self::assertSame($definition->command, $paused->command);
        self::assertSame($definition->timeoutSeconds, $paused->timeoutSeconds);
        self::assertSame($definition->pattern->expression, $paused->pattern->expression);
    }

    #[Test]
    public function withEnabledCarriesWhenTheScheduleBecameExpected(): void
    {
        $expectedSince = new DateTimeImmutable('2026-09-05T10:00:00+00:00');
        $definition = new ScheduleDefinition(
            id: 'reports:generate',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
            expectedSince: $expectedSince,
        );

        self::assertSame($expectedSince, $definition->withEnabled(false)->expectedSince);
    }

    #[Test]
    public function definitionsMatchOnWhatTheySayNotOnWhenTheyWereWritten(): void
    {
        $definition = new ScheduleDefinition(
            id: 'reports:generate',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
            expectedSince: new DateTimeImmutable('2026-09-05T10:00:00+00:00'),
        );

        self::assertTrue($definition->matches(new ScheduleDefinition(
            id: 'reports:generate',
            command: 'dot reports:generate',
            pattern: Pattern::every('60s'),
            expectedSince: new DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        )));

        $different = [
            'command' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports', pattern: Pattern::every('60s')),
            'timing' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('30s')),
            'timeout' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), timeoutSeconds: 60),
            'idle timeout' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), idleTimeoutSeconds: 30),
            'overlap' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), overlap: OverlapPolicy::Allow),
            'grace' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), graceSeconds: 10),
            'state' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), enabled: false),
            'description' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), description: 'Nightly'),
            'zone' => new ScheduleDefinition(id: 'reports:generate', command: 'dot reports:generate', pattern: Pattern::every('60s'), timezone: new DateTimeZone('Asia/Amman')),
        ];

        foreach ($different as $field => $other) {
            self::assertFalse($definition->matches($other), $field);
        }
    }
}
