<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Schema\ArtifactValidator;
use Tessera\Installer\Schema\SchemaVersion;

final class ArtifactValidatorTest extends TestCase
{
    private ArtifactValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ArtifactValidator;
    }

    #[Test]
    public function valid_state_returns_no_errors(): void
    {
        $errors = $this->validator->validate(SchemaVersion::STATE, [
            'schema' => SchemaVersion::STATE,
            'project' => 'demo',
            'stack' => 'laravel',
            'status' => 'in_progress',
        ]);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function missing_schema_field_is_caught(): void
    {
        $errors = $this->validator->validate(SchemaVersion::STATE, [
            'project' => 'demo',
            'stack' => 'laravel',
            'status' => 'in_progress',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('schema', $errors[0]);
    }

    #[Test]
    public function schema_mismatch_is_caught(): void
    {
        $errors = $this->validator->validate(SchemaVersion::STATE, [
            'schema' => SchemaVersion::PLAN,
            'project' => 'demo',
            'stack' => 'laravel',
            'status' => 'in_progress',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('mismatch', $errors[0]);
    }

    #[Test]
    public function missing_required_key_is_caught(): void
    {
        $errors = $this->validator->validate(SchemaVersion::STATE, [
            'schema' => SchemaVersion::STATE,
            'project' => 'demo',
        ]);

        $messages = implode("\n", $errors);
        $this->assertStringContainsString("'stack'", $messages);
        $this->assertStringContainsString("'status'", $messages);
    }

    #[Test]
    public function wrong_type_for_required_key_is_caught(): void
    {
        $errors = $this->validator->validate(SchemaVersion::EVENT_LOG_ENTRY, [
            'schema' => SchemaVersion::EVENT_LOG_ENTRY,
            'type' => 'step.start',
            'occurred_at' => '2026-04-27T12:00:00Z',
            'trace_id' => 'abc',
            'payload' => 'should-be-array',
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("'payload'", $errors[0]);
        $this->assertStringContainsString('array', $errors[0]);
    }

    #[Test]
    public function unknown_schema_constant_only_validates_basic_shape(): void
    {
        $errors = $this->validator->validate('tessera.future-thing/v9', [
            'schema' => 'tessera.future-thing/v9',
            'whatever' => true,
        ]);

        $this->assertSame([], $errors);
    }

    #[Test]
    public function valid_event_log_entry_returns_no_errors(): void
    {
        $errors = $this->validator->validate(SchemaVersion::EVENT_LOG_ENTRY, [
            'schema' => SchemaVersion::EVENT_LOG_ENTRY,
            'type' => 'ai.call.start',
            'trace_id' => 'abcdef0123456789',
            'occurred_at' => '2026-04-27T12:00:00Z',
            'payload' => ['adapter' => 'claude'],
        ]);

        $this->assertSame([], $errors);
    }
}
