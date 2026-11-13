<?php

namespace Winter\Octane\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Runs PHPStan with the plugin's static-state rules (phpstan/extension.neon) on phpstan/fixtures and compares what
 * it reports with the fixtures' "// expect: <identifier>" comments.
 *
 * A comment may add text in parentheses, which the error message must contain. Every expected error has to be
 * reported on its line, and nothing else may be reported. The fixtures live outside tests/ because the rules
 * ignore test code.
 */
class PHPStanRulesTest extends TestCase
{
    /**
     * @return void
     */
    public function testRulesReportExactlyTheExpectedErrors(): void
    {
        $phpstan = dirname(__DIR__, 4) . '/vendor/bin/phpstan';
        if (!is_file($phpstan)) {
            $this->markTestSkipped('vendor/bin/phpstan is not installed in the Winter project.');
        }

        $config  = dirname(__DIR__) . '/phpstan/fixtures.neon';
        $command = [PHP_BINARY, $phpstan, 'clear-result-cache', '-c', $config];
        $this->execute($command);

        [$json, $stderr] = $this->execute([
            PHP_BINARY,
            $phpstan,
            'analyse',
            '-c',
            $config,
            '--memory-limit=1G',
            '--error-format=json',
            '--no-progress',
        ]);

        $result = json_decode($json, true);
        $this->assertIsArray($result, "PHPStan did not return a JSON result:\n" . $json . "\n" . $stderr);
        $this->assertSame([], $result['errors'], 'PHPStan reported errors outside the fixtures.');

        $reported = [];
        foreach ($result['files'] as $file => $data) {
            foreach ($data['messages'] as $message) {
                $identifier = $message['identifier'] ?? '';
                if (str_starts_with($identifier, 'winterOctane.')) {
                    $reported[realpath($file) . ':' . $message['line'] . ':' . $identifier] = $message['message'];
                }
            }
        }

        $expected = $this->expectedErrors();
        $this->assertNotEmpty($expected);
        $this->assertEqualsCanonicalizing(array_keys($expected), array_keys($reported));

        foreach ($expected as $key => $text) {
            if ($text !== '') {
                $this->assertStringContainsString($text, $reported[$key], $key);
            }
        }
    }

    /**
     * The errors the fixtures' "// expect:" comments name, keyed by file, line and identifier, with the text the
     * message must contain (or an empty string).
     *
     * @return array<string, string>
     */
    private function expectedErrors(): array
    {
        $expected = [];
        foreach (glob(dirname(__DIR__) . '/phpstan/fixtures/*.php') ?: [] as $file) {
            foreach (file($file) ?: [] as $index => $line) {
                if (preg_match('#// expect: ([\w.]+)(?: \((.+)\))?\s*$#', $line, $match)) {
                    $expected[realpath($file) . ':' . ($index + 1) . ':' . $match[1]] = $match[2] ?? '';
                }
            }
        }

        return $expected;
    }

    /**
     * Runs a command and returns its standard output and standard error.
     *
     * @param list<string> $command
     *
     * @return array{0: string, 1: string}
     */
    private function execute(array $command): array
    {
        // Standard error goes to a file: reading two pipes one after the other can block when the second fills up.
        $errorFile = (string) tempnam(sys_get_temp_dir(), 'phpstan-stderr');
        $process   = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', $errorFile, 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        proc_close($process);
        $stderr = (string) file_get_contents($errorFile);
        unlink($errorFile);

        return [$stdout, $stderr];
    }
}
