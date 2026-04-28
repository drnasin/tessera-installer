<?php

declare(strict_types=1);

namespace Tessera\Installer\Adapters;

use Tessera\Installer\Events\EventLog;

/**
 * Per-call execution parameters for an AdapterInterface::execute() call.
 *
 * Readonly DTO so adapters never mutate caller state. The EventLog is
 * optional — older callers (legacy ToolRouter path) pass null and adapters
 * skip event emission.
 */
final readonly class AdapterContext
{
    public function __construct(
        public string $workingDir,
        public int $timeout = 600,
        public ?string $model = null,
        public ?string $traceId = null,
        public ?EventLog $eventLog = null,
        public ?string $stepName = null,
    ) {}
}
