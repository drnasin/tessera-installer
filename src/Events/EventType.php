<?php

declare(strict_types=1);

namespace Tessera\Installer\Events;

/**
 * Closed taxonomy of events the installer emits to `.tessera/events.jsonl`.
 *
 * The values are stable strings — once written into a customer's events
 * log they don't change. New event types are added; existing ones are
 * never renamed. Deprecation goes through a v2 enum, not a string rename.
 *
 * Grouped by phase: build > step > ai > subprocess > gate > rate-limit.
 */
enum EventType: string
{
    case BuildStart = 'build.start';
    case BuildComplete = 'build.complete';
    case BuildFail = 'build.fail';
    case BuildResume = 'build.resume';

    case StepStart = 'step.start';
    case StepComplete = 'step.complete';
    case StepFail = 'step.fail';
    case StepSkip = 'step.skip';

    case AiCallStart = 'ai.call.start';
    case AiCallComplete = 'ai.call.complete';
    case AiCallRateLimited = 'ai.call.rate_limited';
    case AiCallToolDown = 'ai.call.tool_down';
    case AiFallback = 'ai.fallback';

    case SubprocessStart = 'subprocess.start';
    case SubprocessComplete = 'subprocess.complete';

    case GateCheck = 'gate.check';
    case GatePass = 'gate.pass';
    case GateFail = 'gate.fail';

    case Decision = 'decision';
    case Note = 'note';
}
