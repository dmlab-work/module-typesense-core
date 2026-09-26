<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Schema;

/**
 * How a schema change should be applied — and, for an in-place one, what applying it returned.
 *
 * `needs-rebuild` is a decision, not an action: only the consumer holds the documents, so
 * core stops and says why. `getSchema()` is null in that case, which is the proof that
 * nothing was written.
 *
 * @api
 */
class Decision
{
    /** The change was applied as a single PATCH. */
    public const OUTCOME_IN_PLACE = 'in-place';

    /** The change was not applied: the consumer must rebuild the collection and swap the alias. */
    public const OUTCOME_NEEDS_REBUILD = 'needs-rebuild';

    /**
     * @param string $outcome
     * @param SchemaDiff $diff
     * @param string|null $reason why a rebuild is needed; null for an in-place outcome
     * @param array<mixed>|null $schema the PATCH response; null when nothing was written
     */
    private function __construct(
        private readonly string $outcome,
        private readonly SchemaDiff $diff,
        private readonly ?string $reason = null,
        private readonly ?array $schema = null
    ) {
    }

    /**
     * The diff was applied in place; $schema is the PATCH response, or null for a no-op.
     *
     * @param SchemaDiff $diff
     * @param array<mixed>|null $schema
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function inPlace(SchemaDiff $diff, ?array $schema = null): self
    {
        return new self(self::OUTCOME_IN_PLACE, $diff, null, $schema);
    }

    /**
     * The diff was not applied and the consumer must rebuild.
     *
     * @param SchemaDiff $diff
     * @param string $reason
     */
    // phpcs:ignore Magento2.Functions.StaticFunction -- a value object is never a DI subject
    public static function rebuild(SchemaDiff $diff, string $reason): self
    {
        return new self(self::OUTCOME_NEEDS_REBUILD, $diff, $reason);
    }

    /**
     * One of the `OUTCOME_*` constants.
     */
    public function getOutcome(): string
    {
        return $this->outcome;
    }

    /**
     * Whether the change was applied in place.
     */
    public function isInPlace(): bool
    {
        return $this->outcome === self::OUTCOME_IN_PLACE;
    }

    /**
     * Whether the consumer must rebuild the collection.
     */
    public function needsRebuild(): bool
    {
        return $this->outcome === self::OUTCOME_NEEDS_REBUILD;
    }

    /**
     * The diff behind the decision.
     */
    public function getDiff(): SchemaDiff
    {
        return $this->diff;
    }

    /**
     * Why a rebuild is needed; null for an in-place outcome.
     */
    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * The resulting schema, or null when nothing was written.
     *
     * @return array<mixed>|null
     */
    public function getSchema(): ?array
    {
        return $this->schema;
    }
}
