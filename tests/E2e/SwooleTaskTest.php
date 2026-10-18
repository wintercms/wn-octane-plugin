<?php

namespace Winter\Octane\Tests\E2e;

use PHPUnit\Framework\Attributes\Group;

/**
 * Swoole's task worker starts every task and every tick from a clean state.
 *
 * Under Swoole, Octane::concurrently() runs its callbacks in a task worker, and the server sends that
 * worker a tick every second. The task worker is a separate process with its own long-lived
 * application, and Octane hands it TaskReceived and TickReceived, never RequestReceived, so the
 * reset the plugin attaches to those two events is the only thing that clears it. The other servers
 * have no task workers, so this test only runs under Swoole.
 *
 * The CI jobs for the other servers exclude the "swoole" group, because the test skips there and
 * those jobs fail on a skipped test.
 */
#[Group('e2e')]
#[Group('swoole')]
class SwooleTaskTest extends OctaneServerTestCase
{
    /**
     * Skips unless the server under test is Swoole.
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        if ($this->server !== 'swoole') {
            $this->markTestSkipped('Tasks and ticks only exist under Swoole; set WINTER_OCTANE_E2E_SERVER=swoole.');
        }
    }

    /**
     * State a task plants is gone when the same task worker runs the next task.
     *
     * @return void
     */
    public function testATaskStartsFromACleanState(): void
    {
        $this->startServer();

        $result = $this->get('/_octane-smoke/task');
        $read = $result['read'];

        $this->assertIsArray($read, 'the reading task must return what it saw');
        $this->assertSame(
            $result['planted_pid'],
            $read['pid'],
            'the two tasks ran in different task workers, so this run proves nothing; with --task-workers=1 that means the task worker crashed'
        );
        $this->assertNotSame($result['pid'], $read['pid'], 'the tasks must run in the task worker, not the HTTP worker');

        $this->assertFalse($read['twig_gate_open'], 'the Twig include gate planted by the previous task survived into this one');
        $this->assertFalse($read['view_leak_present'], 'the view share planted by the previous task survived into this one');
        $this->assertFalse($read['code_parser_dirty'], 'the code parser cache planted by the previous task survived into this one');
    }

    /**
     * Every tick starts without the state the previous tick planted.
     *
     * Each tick records what it found and then plants state, so from the second tick on, a record
     * with planted state means the reset did not run on TickReceived.
     *
     * @return void
     */
    public function testATickStartsFromACleanState(): void
    {
        $this->startServer();

        $ticks = [];
        $deadline = time() + 15;

        while (count($ticks) < 3 && time() < $deadline) {
            usleep(500000);
            $ticks = $this->get('/_octane-smoke/ticks');
        }

        $this->assertGreaterThanOrEqual(3, count($ticks), "the task worker must receive ticks. Log tail:\n" . $this->logTail());

        foreach ($ticks as $index => $tick) {
            $this->assertSame($ticks[0]['pid'], $tick['pid'], 'the ticks ran in different task workers, so this run proves nothing');
            $this->assertFalse($tick['twig_gate_open'], "tick {$index} found the Twig include gate the previous tick planted");
            $this->assertFalse($tick['view_leak_present'], "tick {$index} found the view share the previous tick planted");
            $this->assertFalse($tick['code_parser_dirty'], "tick {$index} found the code parser cache the previous tick planted");
        }
    }
}
