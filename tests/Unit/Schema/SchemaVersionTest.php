<?php

declare(strict_types=1);

namespace Tessera\Installer\Tests\Unit\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tessera\Installer\Schema\SchemaVersion;

final class SchemaVersionTest extends TestCase
{
    #[Test]
    public function all_returns_six_known_schemas(): void
    {
        $all = SchemaVersion::all();

        $this->assertCount(6, $all);
        $this->assertContains(SchemaVersion::STATE, $all);
        $this->assertContains(SchemaVersion::EVENT_LOG_ENTRY, $all);
        $this->assertContains(SchemaVersion::PLAN, $all);
    }

    #[Test]
    public function every_schema_constant_uses_namespaced_format(): void
    {
        foreach (SchemaVersion::all() as $schema) {
            $this->assertStringStartsWith('tessera.', $schema, "{$schema} should start with tessera.");
            $this->assertMatchesRegularExpression('#^tessera\.[a-z\-]+/v\d+$#', $schema);
        }
    }

    #[Test]
    #[DataProvider('parsableSchemas')]
    public function parse_extracts_artifact_and_version(string $schema, string $expectedArtifact, int $expectedVersion): void
    {
        $parsed = SchemaVersion::parse($schema);

        $this->assertSame([$expectedArtifact, $expectedVersion], $parsed);
    }

    public static function parsableSchemas(): array
    {
        return [
            'state v1' => [SchemaVersion::STATE, 'state', 1],
            'event v1' => [SchemaVersion::EVENT_LOG_ENTRY, 'event', 1],
            'plan v1' => [SchemaVersion::PLAN, 'plan', 1],
            'cached v1' => [SchemaVersion::CACHED_RESPONSE, 'cached-response', 1],
            'gate v1' => [SchemaVersion::GATE_RESULT, 'gate-result', 1],
            'stack v1' => [SchemaVersion::STACK_MANIFEST, 'stack', 1],
        ];
    }

    #[Test]
    #[DataProvider('unparsableSchemas')]
    public function parse_returns_null_for_malformed(string $input): void
    {
        $this->assertNull(SchemaVersion::parse($input));
    }

    public static function unparsableSchemas(): array
    {
        return [
            'no namespace' => ['state/v1'],
            'wrong namespace' => ['other.state/v1'],
            'missing version' => ['tessera.state'],
            'non-numeric version' => ['tessera.state/vX'],
            'empty artifact' => ['tessera./v1'],
            'empty string' => [''],
        ];
    }
}
