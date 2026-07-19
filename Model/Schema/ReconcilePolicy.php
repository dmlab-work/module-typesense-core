<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Schema;

/**
 * How the reconciler should choose between an in-place PATCH and a rebuild.
 *
 * A value object the consumer builds from its own config and hands to
 * {@see Reconciler::reconcile()}; core reads no config of its own.
 */
class ReconcilePolicy
{
    /** Decide per collection size and type compatibility. */
    public const AUTO = 'auto';

    /** Always PATCH in place. */
    public const IN_PLACE = 'in_place';

    /** Always answer `needs-rebuild`. */
    public const REBUILD = 'rebuild';

    /**
     * @param int $rebuildThreshold document count above which a change must be a rebuild; 0 disables the size rule
     * @param string $decisionOverride one of the decision constants; AUTO decides per size and type compatibility
     */
    public function __construct(
        private readonly int $rebuildThreshold = 0,
        private readonly string $decisionOverride = self::AUTO
    ) {
    }

    /**
     * Document count above which a schema change must be a rebuild, not an in-place PATCH.
     */
    public function getRebuildThreshold(): int
    {
        return $this->rebuildThreshold;
    }

    /**
     * Forced reconciler decision, one of the decision constants.
     */
    public function getDecisionOverride(): string
    {
        return $this->decisionOverride;
    }
}
