<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Schema;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;

/**
 * One field that exists on both sides of a diff but does not match.
 *
 * Both sides are carried because a caller reporting a change — or deciding whether the
 * type change is safe to coerce — needs the old value, not only the wanted one.
 *
 * @api
 */
class FieldChange
{
    /**
     * @param FieldSpec $actual the field as the collection has it
     * @param FieldSpec $desired the field as the consumer wants it
     */
    public function __construct(
        private readonly FieldSpec $actual,
        private readonly FieldSpec $desired
    ) {
    }

    /**
     * Field name.
     */
    public function getName(): string
    {
        return $this->desired->getName();
    }

    /**
     * The field as the collection currently has it.
     */
    public function getActual(): FieldSpec
    {
        return $this->actual;
    }

    /**
     * The field as the consumer wants it.
     */
    public function getDesired(): FieldSpec
    {
        return $this->desired;
    }
}
