<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The runner must stop a command on a PHP build without ext-pcntl, which is
 * every official image; a test environment cannot unload a compiled
 * extension, so the source is read instead.
 */
final class RunnerIndependenceTest extends TestCase
{
    private const string SIGNAL_EXTENSION = '/\b(?:SIG[A-Z]{2,}|pcntl_\w+)\b/';

    #[Test]
    public function theRunnerNamesNothingOnlyASignalExtensionDefines(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Runner/*.php');
        $offenders = [];

        foreach ($files === false ? [] : $files as $file) {
            $code = (string) file_get_contents($file);
            $code = (string) preg_replace('/\/\*\*.*?\*\//s', '', $code);

            if (preg_match(self::SIGNAL_EXTENSION, $code, $matched) === 1) {
                $offenders[] = basename($file) . ': ' . $matched[0];
            }
        }

        self::assertSame([], $offenders);
    }

    #[Test]
    public function everyRunnerSourceWasRead(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/src/Runner/*.php');

        self::assertNotFalse($files);
        self::assertContains('ProcessRunner.php', array_map(static fn(string $file): string => basename($file), $files));
    }
}
