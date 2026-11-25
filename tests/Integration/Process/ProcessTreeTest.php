<?php

declare(strict_types=1);

namespace PHPdot\Scheduler\Tests\Integration\Process;

use PHPdot\Scheduler\Runner\ProcessTable;
use PHPdot\Scheduler\Runner\ProcessTree;
use PHPdot\Scheduler\Tests\Support\AwaitsExit;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcessTreeTest extends TestCase
{
    use AwaitsExit;

    /** @var list<Process> */
    private array $spawned = [];

    protected function tearDown(): void
    {
        foreach ($this->spawned as $process) {
            $process->stop(0);
        }

        $this->spawned = [];
    }

    #[Test]
    public function aRecycledIdentityIsNeverSignalled(): void
    {
        $root = $this->spawn('sleep 45 & wait');
        usleep(150000);
        $child = ProcessTable::descendants($root)[0] ?? 0;
        $identity = ProcessTable::startIdentities([$child])[$child] ?? '';

        self::assertGreaterThan(0, $child);

        self::assertNotSame('', $identity, 'this host must be able to read a process start time');

        (new ProcessTree([$child => 'another-process-entirely'], true))->escalate(9);

        self::assertNotFalse(posix_kill($child, 0), 'a mismatching identity must not be signalled');

        (new ProcessTree([$child => $identity], true))->escalate(9);

        self::assertTrue($this->exitsWithin($child));
    }

    #[Test]
    public function aMemberWhoseIdentityCouldNotBeReadIsLeftAlone(): void
    {
        $root = $this->spawn('sleep 45 & wait');
        usleep(150000);
        $child = ProcessTable::descendants($root)[0] ?? 0;

        (new ProcessTree([$child => ''], true))->escalate(9);

        self::assertNotFalse(posix_kill($child, 0));
    }

    #[Test]
    public function aGroupAddressIsNeverAMember(): void
    {
        foreach ([0, -1] as $group) {
            (new ProcessTree([$group => 'whatever'], true))->escalate(9);
        }

        $thisTest = getmypid();

        self::assertNotFalse($thisTest);
        self::assertNotFalse(posix_kill($thisTest, 0), 'signalling pid 0 or a negative pid addresses a process group, and this test survived');
    }

    #[Test]
    public function aTreeIsListedUnderItsRootWithEveryDescendantOnce(): void
    {
        $root = $this->spawn('sleep 45 & sleep 46 & wait');
        usleep(200000);

        $descendants = ProcessTable::descendants($root);
        $tree = ProcessTree::under($root, ProcessTable::descendants(...));

        self::assertNotNull($descendants);
        self::assertCount(2, $descendants);
        self::assertCount(2, array_unique($descendants));
        self::assertNotContains($root, $descendants);
        self::assertTrue($tree->walked);
    }

    #[Test]
    public function aChildForkedAfterCaptureIsKilledWithTheRegrownTree(): void
    {
        $ready = sys_get_temp_dir() . '/scheduler-regrow-' . uniqid() . '.ready';
        $childFile = $ready . '.pid';
        $root = $this->spawn(sprintf(
            'while [ ! -e %1$s ]; do :; done; sleep 45 & echo $! > %2$s; wait',
            escapeshellarg($ready),
            escapeshellarg($childFile),
        ));
        $captured = ProcessTree::under($root, ProcessTable::descendants(...));

        self::assertSame([], ProcessTable::descendants($root));

        touch($ready);
        $child = 0;

        for ($waited = 0; $waited < 100 && $child === 0; $waited++) {
            usleep(50000);
            $child = (int) @file_get_contents($childFile);
        }

        @unlink($ready);
        @unlink($childFile);

        self::assertGreaterThan(0, $child);

        $captured->regrown(ProcessTable::descendants(...))->escalate(9);

        self::assertTrue($this->exitsWithin($child));
    }

    #[Test]
    public function aHostThatCannotListAnswersUnwalkedHoweverOftenItIsAsked(): void
    {
        $root = $this->spawn('sleep 45');
        $tree = ProcessTree::under($root, static fn(int $pid): null => null);

        self::assertFalse($tree->walked);
        self::assertFalse($tree->regrown(ProcessTable::descendants(...))->walked);
    }

    #[Test]
    public function anExitedRootIsNothingToWalk(): void
    {
        self::assertTrue(ProcessTree::under(null, ProcessTable::descendants(...))->walked);
    }

    /**
     * Starts a command and answers the pid of its shell, kept for teardown.
     * The shell is a direct child of this process, so a command that is told
     * to fork leaves a grandchild the shell reaps on its own.
     *
     * @param string $command The shell command to start
     *
     * @return int
     */
    private function spawn(string $command): int
    {
        $process = Process::fromShellCommandline($command);
        $process->start();
        $this->spawned[] = $process;

        return $process->getPid() ?? 0;
    }
}
