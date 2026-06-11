<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tessera\Installer\AiResponse;
use Tessera\Installer\AiTool;

/**
 * Verifies the onTick hook added to AiTool::execute() is driven from the
 * subprocess read loop. Uses a real `php -r` child (the test runtime itself)
 * so the test is deterministic and cross-platform without a fake binary.
 */
final class AiToolProgressTickTest extends TestCase
{
    /**
     * Build an AiTool whose execute command is a short-lived `php -r` process.
     * The private constructor is reachable via reflection for this test only.
     */
    private function phpSleepTool(int $sleepMicroseconds): AiTool
    {
        $reflection = new ReflectionClass(AiTool::class);
        /** @var AiTool $tool */
        $tool = $reflection->newInstanceWithoutConstructor();

        $config = [
            'binary' => 'php',
            'detect' => 'php --version',
            // stdin=false → the prompt is appended as the final argv element and ignored
            // by our snippet; the snippet just sleeps so the poll loop iterates.
            'execute' => ['php', '-r', "usleep({$sleepMicroseconds}); echo 'done';"],
            'stdin' => false,
        ];

        $reflection->getProperty('name')->setValue($tool, 'php');
        $reflection->getProperty('config')->setValue($tool, $config);
        $reflection->getProperty('version')->setValue($tool, 'test');

        return $tool;
    }

    #[Test]
    public function on_tick_is_invoked_at_least_once_during_execution(): void
    {
        $tool = $this->phpSleepTool(300_000); // 300ms → several 100ms poll iterations

        $ticks = [];
        $response = $tool->execute('ignored-prompt', getcwd(), 30, null, function (int $elapsed) use (&$ticks): void {
            $ticks[] = $elapsed;
        });

        $this->assertInstanceOf(AiResponse::class, $response);
        $this->assertTrue($response->success, 'php subprocess should exit 0');
        $this->assertNotEmpty($ticks, 'onTick must fire from the read loop');
        $this->assertSame(0, $ticks[0], 'first tick must be the immediate 0s tick');
        $this->assertContainsOnly('int', $ticks);
    }

    #[Test]
    public function execute_without_on_tick_still_works(): void
    {
        $tool = $this->phpSleepTool(50_000);

        $response = $tool->execute('ignored-prompt', getcwd(), 30);

        $this->assertTrue($response->success);
        $this->assertSame('done', $response->output);
    }
}
