<?php

declare(strict_types=1);

namespace Tests\Stress;

use PHPUnit\Framework\TestCase;

abstract class StressTestCase extends TestCase
{
    protected function logSection(string $title, array $lines): void
    {
        $divider = str_repeat('=', 60);
        $content = PHP_EOL . $divider . PHP_EOL;
        $content .= "{$title}" . PHP_EOL;
        $content .= $divider . PHP_EOL;

        foreach ($lines as $line) {
            $content .= "  {$line}" . PHP_EOL;
        }

        $content .= $divider . PHP_EOL;

        $this->writeStressLog($content);
    }

    protected function writeStressLog(string $text): void
    {
        $logDir = BASE_PATH . '/runtime/logs';
        if (! is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }

        file_put_contents($logDir . '/stress-tests.log', $text, FILE_APPEND);

        if (getenv('STRESS_TEST_VERBOSE') === '1') {
            fwrite(STDOUT, $text);
        }
    }
}