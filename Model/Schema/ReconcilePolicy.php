<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Schema;

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
     * @throws \InvalidArgumentException on a negative threshold or an unknown override
     */
    public function __construct(
        private readonly int $rebuildThreshold = 0,
        private readonly string $decisionOverride = self::AUTO
    ) {
        if ($rebuildThreshold < 0) {
            throw new \InvalidArgumentException(
                sprintf('Rebuild threshold must not be negative, got %d.', $rebuildThreshold)
            );
        }
        $allowed = [self::AUTO, self::IN_PLACE, self::REBUILD];
        if (!in_array($decisionOverride, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown reconcile decision override "%s"; expected one of: %s.',
                $decisionOverride,
                implode(', ', $allowed)
            ));
        }
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
