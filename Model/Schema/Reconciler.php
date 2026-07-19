<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Schema;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Collection\CollectionManager;
use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;

/**
 * Decides how a schema change should be applied — and applies it only when it is in place.
 *
 * A PATCH rebuilds the in-memory index from the stored documents with no reimport, but it
 * blocks writes to the collection while it runs; and a type change survives only where the
 * engine can coerce the stored values. Past the configured document threshold, or on a type
 * change that is not safely coercible, the answer is `needs-rebuild` and **nothing is written**:
 * only the consumer holds the documents, so only the consumer can rebuild and swap the alias.
 *
 * @api
 */
class Reconciler
{
    /**
     * Sources a scalar `string` target can be rendered from. A `geopoint` or an `object`
     * has no scalar rendering: the engine rejects it on the rebuild and the document is lost.
     */
    private const STRINGABLE = ['int32', 'int64', 'float', 'bool', 'string'];

    /**
     * Widening transitions, `from` => `to[]`, that keep the stored values intact.
     */
    private const WIDENING = [
        'int32' => ['int64', 'float'],
        'int64' => ['float'],
    ];

    /**
     * @param SchemaDiffer $differ
     * @param CollectionManager $collections
     */
    public function __construct(
        private readonly SchemaDiffer $differ,
        private readonly CollectionManager $collections
    ) {
    }

    /**
     * Reconcile a collection towards a desired field set.
     *
     * @param string $collection
     * @param FieldSpec[] $desired
     * @param ReconcilePolicy $policy how to choose between an in-place PATCH and a rebuild
     * @throws TypesenseException 404 when the collection does not exist
     */
    public function reconcile(string $collection, array $desired, ReconcilePolicy $policy): Decision
    {
        $schema = $this->collections->get($collection);
        $diff = $this->differ->diff($desired, $schema);

        if ($diff->isEmpty()) {
            return Decision::inPlace($diff);
        }

        $override = $policy->getDecisionOverride();
        if ($override === ReconcilePolicy::REBUILD) {
            return Decision::rebuild($diff, 'A rebuild is forced by configuration.');
        }

        if ($override !== ReconcilePolicy::IN_PLACE) {
            $reason = $this->rebuildReason($diff, $schema, $policy);
            if ($reason !== null) {
                return Decision::rebuild($diff, $reason);
            }
        }

        return Decision::inPlace($diff, $this->collections->patch($collection, $diff->toPatchFields()));
    }

    /**
     * Why this diff cannot be applied in place, or null when it can.
     *
     * @param SchemaDiff $diff
     * @param array<mixed> $schema
     * @param ReconcilePolicy $policy
     */
    private function rebuildReason(SchemaDiff $diff, array $schema, ReconcilePolicy $policy): ?string
    {
        $threshold = $policy->getRebuildThreshold();
        $documents = (int)($schema['num_documents'] ?? 0);
        if ($threshold > 0 && $documents > $threshold) {
            return sprintf(
                'The collection holds %d documents, above the in-place threshold of %d; '
                . 'a PATCH would block writes for the whole rebuild.',
                $documents,
                $threshold
            );
        }

        foreach ($diff->getTypeChanged() as $change) {
            $from = $change->getActual()->getType();
            $to = $change->getDesired()->getType();
            if (!$this->isCoercible($from, $to)) {
                return sprintf(
                    'Field "%s" changes type from "%s" to "%s", which the engine cannot be trusted to coerce.',
                    $change->getName(),
                    $from,
                    $to
                );
            }
        }

        return null;
    }

    /**
     * Whether stored values survive a type change.
     *
     * Conservative on purpose: a drop plus re-add keeps whatever the engine can coerce and
     * silently loses the rest, so anything not known to widen is answered with a rebuild.
     * An arity change (scalar ↔ array) is never treated as coercible.
     *
     * @param string $from
     * @param string $to
     */
    private function isCoercible(string $from, string $to): bool
    {
        if (str_ends_with($from, '[]') !== str_ends_with($to, '[]')) {
            return false;
        }

        $fromBase = rtrim($from, '[]');
        $toBase = rtrim($to, '[]');

        if ($fromBase === $toBase) {
            return true;
        }

        // `auto` lets the engine keep each stored value's own type — nothing has to convert.
        if ($toBase === 'auto') {
            return true;
        }

        if ($toBase === 'string') {
            return in_array($fromBase, self::STRINGABLE, true);
        }

        return in_array($toBase, self::WIDENING[$fromBase] ?? [], true);
    }
}
