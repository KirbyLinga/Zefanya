<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

trait RunsIsolatedMySqlRace
{
    private function assertIsolatedMySqlRacePasses(string $test): void
    {
        $process = new Process([
            PHP_BINARY, base_path('tests/MySql/run.php'), '--filter='.$test, '--testdox',
        ], base_path());
        $process->setTimeout(240);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('OK (1 test,', $process->getOutput());
    }
}
