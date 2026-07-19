<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Schema;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;

/**
 * Compares a desired field set against a collection's actual schema.
 *
 * Pure: it reads no configuration and writes nothing. Whether a diff should be applied
 * in place is {@see Reconciler}'s call.
 *
 * @api
 */
class SchemaDiffer
{
    /**
     * Diff a desired field set against a collection schema as the engine returns it.
     *
     * A desired flag left null means "engine default" and never counts as a change —
     * that is what keeps a spec built from a partial declaration from churning the schema.
     * Extra properties are compared only where the desired spec declares them, for the
     * same reason: this layer does not model them and cannot know their defaults.
     *
     * @param FieldSpec[] $desired
     * @param array<mixed> $actualSchema the `GET /collections/:name` response
     */
    public function diff(array $desired, array $actualSchema): SchemaDiff
    {
        $actual = $this->indexActualFields($actualSchema);

        $added = [];
        $typeChanged = [];
        $flagsChanged = [];
        $seen = [];

        foreach ($desired as $field) {
            $name = $field->getName();
            $seen[$name] = true;

            if (!isset($actual[$name])) {
                $added[] = $field;
                continue;
            }

            $current = $actual[$name];
            if ($current->getType() !== $field->getType()) {
                $typeChanged[] = new FieldChange($current, $field);
                continue;
            }

            if ($this->flagsDiffer($current, $field)) {
                $flagsChanged[] = new FieldChange($current, $field);
            }
        }

        $dropped = [];
        foreach ($actual as $name => $field) {
            if (!isset($seen[$name])) {
                $dropped[] = $field;
            }
        }

        return new SchemaDiff($added, $dropped, $typeChanged, $flagsChanged);
    }

    /**
     * The schema's fields as specs, keyed by name.
     *
     * @param array<mixed> $actualSchema
     * @return array<string,FieldSpec>
     */
    private function indexActualFields(array $actualSchema): array
    {
        $fields = $actualSchema['fields'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $indexed = [];
        foreach ($fields as $field) {
            if (!is_array($field) || !isset($field['name']) || !is_string($field['name'])) {
                continue;
            }
            $indexed[$field['name']] = FieldSpec::fromArray($field);
        }

        return $indexed;
    }

    /**
     * Whether any flag or extra property the desired spec declares differs from the actual one.
     *
     * @param FieldSpec $actual
     * @param FieldSpec $desired
     */
    private function flagsDiffer(FieldSpec $actual, FieldSpec $desired): bool
    {
        $flags = [
            [$actual->getFacet(), $desired->getFacet()],
            [$actual->getIndex(), $desired->getIndex()],
            [$actual->getSort(), $desired->getSort()],
            [$actual->getOptional(), $desired->getOptional()],
        ];

        foreach ($flags as [$current, $wanted]) {
            if ($wanted !== null && $wanted !== $current) {
                return true;
            }
        }

        return $this->extraDiffers($actual->getExtra(), $desired->getExtra());
    }

    /**
     * Whether any extra property the desired spec declares differs from the actual one.
     *
     * Compared subset-wise at every depth: Typesense echoes back defaults the caller never
     * declared (`hnsw_params: {M: 8}` returns `{M: 8, ef_construction: 200}`), so a strict
     * comparison would report a difference forever and re-PATCH the field on every reconcile.
     *
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $desired
     */
    private function extraDiffers(array $actual, array $desired): bool
    {
        foreach ($desired as $key => $wanted) {
            if (!array_key_exists($key, $actual)) {
                return true;
            }

            $current = $actual[$key];
            // Lists are compared whole — a declared list is the complete value, not a subset of one.
            if (is_array($wanted) && is_array($current) && !array_is_list($wanted) && !array_is_list($current)) {
                if ($this->extraDiffers($current, $wanted)) {
                    return true;
                }

                continue;
            }

            if ($wanted !== $current) {
                return true;
            }
        }

        return false;
    }
}
