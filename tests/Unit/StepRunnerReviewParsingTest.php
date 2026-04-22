<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\StepRunner;

/**
 * Tests for StepRunner's private review-output parser. The previous heuristic
 * matched substrings like 'no critical' / 'looks good' / 'lgtm', which gave
 * false negatives on legitimate issue reports that happened to mention
 * 'no critical'. The new impl requires an explicit STATUS sentinel.
 */
final class StepRunnerReviewParsingTest extends TestCase
{
    private function reviewFoundNoIssues(string $output): bool
    {
        $class = new \ReflectionClass(StepRunner::class);
        $method = $class->getMethod('reviewFoundNoIssues');
        $instance = $class->newInstanceWithoutConstructor();

        return $method->invoke($instance, $output);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noIssueOutputs(): array
    {
        return [
            'bare sentinel' => ['STATUS: NO_ISSUES_FOUND'],
            'sentinel without space' => ['STATUS:NO_ISSUES_FOUND'],
            'lowercase sentinel' => ['status: no_issues_found'],
            'sentinel with trailing newline' => ["STATUS: NO_ISSUES_FOUND\n"],
            'sentinel after blank line' => ["\n\nSTATUS: NO_ISSUES_FOUND"],
            'sentinel then commentary' => ["STATUS: NO_ISSUES_FOUND\nReviewer: looks clean"],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function issueOutputs(): array
    {
        return [
            'explicit issues status' => [
                "STATUS: ISSUES_FOUND\nCRITICAL: app/Models/Page.php — broken",
            ],
            // The key regression: prior heuristic treated this as "no issues"
            // because it matched 'no critical' as a substring.
            'no critical but medium issues' => [
                "STATUS: ISSUES_FOUND\nMEDIUM: header — no mobile menu close button\n"
                ."(There are no critical issues, but medium ones exist.)",
            ],
            'missing sentinel free-form text' => [
                "I reviewed the theme. It looks good overall but I noticed a few issues:\n"
                .'- the hero text is hard to read',
            ],
            'looks good without sentinel' => ['Looks good!'],
            'empty output after trim' => [''],
            'lgtm shorthand without sentinel' => ['lgtm'],
            'status issues then detail' => [
                "STATUS: ISSUES_FOUND\nHIGH: resource missing",
            ],
        ];
    }

    #[Test]
    #[DataProvider('noIssueOutputs')]
    public function recognizes_no_issues_sentinel(string $output): void
    {
        $this->assertTrue(
            $this->reviewFoundNoIssues($output),
            'Should recognize: '.addslashes($output),
        );
    }

    #[Test]
    #[DataProvider('issueOutputs')]
    public function treats_anything_else_as_issues_found(string $output): void
    {
        $this->assertFalse(
            $this->reviewFoundNoIssues($output),
            'Should NOT recognize as clean: '.addslashes($output),
        );
    }
}
