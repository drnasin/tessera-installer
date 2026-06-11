<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Console;
use Tessera\Installer\FakeConsoleInput;
use Tessera\Installer\NewCommand;
use Tessera\Installer\SystemInfo;

/**
 * Issue #20 — an empty FIRST interview answer must NOT abort the whole run.
 *
 * Before the fix, pressing Enter on the first question printed an error and
 * returned null, exiting the program (exit 1). Restarting re-paid the plan-tier
 * Q&A and the opening token-burning AI call. The fix re-prompts up to three
 * times, reusing the already-fetched AI question (no new AI call), and only
 * gives up — returning null — after the third consecutive empty answer.
 *
 * gatherRequirements() is private and the live path needs a console session
 * plus a real AI tool. These tests drive it via Reflection on an instance built
 * without the constructor, with the test-only `interviewFirstQuestion` seam set
 * so the opening AI call is skipped, and `router` left null (consistent with the
 * `?ToolRouter` declaration). Console input is a queue-based FakeConsoleInput.
 */
final class NewCommandEmptyInterviewAnswerTest extends TestCase
{
    private const ERROR = 'I need to know at least what the client does.';

    protected function tearDown(): void
    {
        Console::setInput(null);

        parent::tearDown();
    }

    /**
     * Build a NewCommand whose opening interview question is pre-supplied (no AI
     * call) and whose router is null, then invoke the private gatherRequirements().
     */
    private function invokeGatherRequirements(): mixed
    {
        $reflection = new \ReflectionClass(NewCommand::class);
        $instance = $reflection->newInstanceWithoutConstructor();

        $this->setPrivate($reflection, $instance, 'interviewFirstQuestion', 'What does the client do?');
        $this->setPrivate($reflection, $instance, 'system', SystemInfo::detect());
        $this->setPrivate($reflection, $instance, 'router', null);

        $method = $reflection->getMethod('gatherRequirements');

        return $method->invoke($instance);
    }

    private function setPrivate(\ReflectionClass $reflection, object $instance, string $property, mixed $value): void
    {
        $prop = $reflection->getProperty($property);
        $prop->setValue($instance, $value);
    }

    #[Test]
    public function three_consecutive_empty_answers_exit_cleanly_with_null(): void
    {
        Console::setInput(new FakeConsoleInput(['', '', '']));

        ob_start();
        $result = $this->invokeGatherRequirements();
        $output = $this->stripAnsi((string) ob_get_clean());

        // Clean give-up: null bubbles to run() which returns exit 1.
        $this->assertNull($result);

        // The error fired once per empty attempt — exactly three times.
        $this->assertSame(
            3,
            substr_count($output, self::ERROR),
            'Expected the error once per empty attempt (3 total) before giving up.',
        );
    }

    #[Test]
    public function empty_first_answer_re_prompts_instead_of_aborting(): void
    {
        // One empty answer, then a real one. The re-prompt must accept the
        // second answer rather than abort on the first.
        Console::setInput(new FakeConsoleInput(['', 'We make artisan bread.']));

        ob_start();
        try {
            $this->invokeGatherRequirements();
            $threw = false;
        } catch (\Error $e) {
            // Expected, NOT a flaky failure: after the valid first answer the
            // conversation loop advances to the follow-up question, which calls
            // askPrimary() -> $this->router->primary(). The router is null in this
            // unit context, so PHP raises "Call to a member function primary() on
            // null". Reaching this point proves the empty-answer guard did NOT
            // abort — execution got past the first answer into normal flow.
            $threw = true;
            $this->assertStringContainsString('primary()', $e->getMessage());
        } finally {
            $output = $this->stripAnsi((string) ob_get_clean());
        }

        $this->assertTrue($threw, 'Execution should advance past the first answer into the follow-up AI call.');

        // Only ONE empty answer was given, so the error appears exactly once —
        // proving a single re-prompt, not a multi-attempt loop or an abort.
        $this->assertSame(
            1,
            substr_count($output, self::ERROR),
            'A single empty answer should trigger exactly one re-prompt.',
        );

        // The already-fetched AI question is re-displayed on the re-prompt with
        // no new AI call (the seam supplied it; router is null and never used
        // before the follow-up).
        $this->assertStringContainsString('What does the client do?', $output);
    }

    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\033\[[0-9;]*m/', '', $text);
    }
}
