<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Schema;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;

/**
 * The difference between a desired field set and a collection's actual schema.
 *
 * @api
 */
class SchemaDiff
{
    /**
     * @param FieldSpec[] $added fields the collection does not have
     * @param FieldSpec[] $dropped fields the collection has and the desired set does not
     * @param FieldChange[] $typeChanged fields whose type differs
     * @param FieldChange[] $flagsChanged fields whose flags or extra properties differ
     */
    public function __construct(
        private readonly array $added = [],
        private readonly array $dropped = [],
        private readonly array $typeChanged = [],
        private readonly array $flagsChanged = []
    ) {
    }

    /**
     * Fields the collection does not have.
     *
     * @return FieldSpec[]
     */
    public function getAdded(): array
    {
        return $this->added;
    }

    /**
     * Fields the collection has and the desired set does not.
     *
     * @return FieldSpec[]
     */
    public function getDropped(): array
    {
        return $this->dropped;
    }

    /**
     * Fields whose type differs.
     *
     * @return FieldChange[]
     */
    public function getTypeChanged(): array
    {
        return $this->typeChanged;
    }

    /**
     * Fields whose flags or extra properties differ, with the type unchanged.
     *
     * @return FieldChange[]
     */
    public function getFlagsChanged(): array
    {
        return $this->flagsChanged;
    }

    /**
     * Whether the collection already matches the desired field set.
     */
    public function isEmpty(): bool
    {
        return $this->added === []
            && $this->dropped === []
            && $this->typeChanged === []
            && $this->flagsChanged === [];
    }

    /**
     * The field list for a single `PATCH /collections/:name` applying this whole diff.
     *
     * The engine rejects a field that is already part of the schema, so a change of any
     * kind — type or flags — is expressed as a drop plus a re-add in the same call.
     *
     * The re-add is the actual field with the desired one laid over it, not the desired
     * spec alone: {@see SchemaDiffer} ignores a property the desired spec leaves out, so
     * re-adding it bare would reset that property to the engine's default.
     *
     * @return FieldSpec[]
     */
    public function toPatchFields(): array
    {
        $fields = [];

        foreach ($this->dropped as $field) {
            $fields[] = FieldSpec::drop($field->getName());
        }

        foreach ([...$this->typeChanged, ...$this->flagsChanged] as $change) {
            $fields[] = FieldSpec::drop($change->getName());
            $fields[] = $change->getActual()->withOverridesFrom($change->getDesired());
        }

        foreach ($this->added as $field) {
            $fields[] = $field;
        }

        return $fields;
    }
}
