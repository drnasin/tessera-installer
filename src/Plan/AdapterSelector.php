<?php

declare(strict_types=1);

namespace Tessera\Installer\Plan;

use Tessera\Installer\Adapters\AdapterInterface;
use Tessera\Installer\Adapters\AdapterRegistry;
use Tessera\Installer\Complexity;
use Tessera\Installer\ToolRouter;

/**
 * Bridges the new PlanExecutor to the existing complexity-aware routing
 * logic of ToolRouter, without forcing a full ToolRouter→AdapterRegistry
 * refactor in this turn.
 *
 * Sprint 1 strategy: the executor calls AdapterSelector to pick the
 * adapter for a step's complexity level. AdapterSelector consults a
 * ToolRouter (when supplied) and translates the legacy AiTool result
 * into an Adapter. When no ToolRouter is supplied (test harnesses,
 * `--stack` smoke runs without a real router) it falls back to "first
 * available adapter that supports this model".
 *
 * Sprint 2 will collapse this seam by porting ToolRouter to operate on
 * AdapterRegistry directly.
 */
final class AdapterSelector
{
    public function __construct(
        private AdapterRegistry $registry,
        private ?ToolRouter $router = null,
    ) {}

    /**
     * Resolve an adapter for the given complexity, honouring an explicit
     * adapter hint when present. Returns null when no adapter is
     * available — the executor turns that into a failed step.
     */
    public function select(Complexity $complexity, ?string $adapterHint = null): ?AdapterInterface
    {
        if ($adapterHint !== null) {
            $adapter = $this->registry->get($adapterHint);

            if ($adapter !== null && $adapter->isAvailable()) {
                return $adapter;
            }
        }

        if ($this->router !== null) {
            $selection = $this->router->resolve($complexity);

            if ($selection !== null) {
                $adapter = $this->registry->get($selection->tool->name());

                if ($adapter !== null) {
                    return $adapter;
                }
            }
        }

        // No router or router yielded nothing — first available adapter.
        $available = $this->registry->available();

        return $available === [] ? null : reset($available);
    }

    /**
     * Resolve the model for an adapter at the given complexity. Defers
     * to ToolRouter when present (it knows the per-tool model map), or
     * returns the explicit model hint, or null (adapter default).
     */
    public function pickModel(Complexity $complexity, AdapterInterface $adapter, ?string $modelHint = null): ?string
    {
        if ($modelHint !== null) {
            return $modelHint;
        }

        if ($this->router === null) {
            return null;
        }

        $selection = $this->router->resolve($complexity);

        if ($selection === null) {
            return null;
        }

        if ($selection->tool->name() !== $adapter->name()) {
            return null;
        }

        return $selection->model;
    }
}
