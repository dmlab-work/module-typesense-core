<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration\Doubles;

use DmLab\TypesenseCore\Model\Collection\OrphanFinder;

/**
 * The real finder limited to one test's prefix, so a prune run never drops a shared server's own orphans.
 */
class ScopedOrphanFinder extends OrphanFinder
{
    /** @var OrphanFinder */
    private readonly OrphanFinder $finder;

    /** @var string */
    private readonly string $prefix;

    /**
     * @param OrphanFinder $finder
     * @param string $prefix
     */
    public function __construct(OrphanFinder $finder, string $prefix)
    {
        // The parent is not constructed: every call is delegated to $finder.
        $this->finder = $finder;
        $this->prefix = $prefix;
    }

    /**
     * @inheritDoc
     */
    public function find(int $minAgeSeconds): array
    {
        return array_values(array_filter(
            $this->finder->find($minAgeSeconds),
            fn (string $name): bool => str_starts_with($name, $this->prefix)
        ));
    }

    /**
     * @inheritDoc
     */
    public function drop(string $collection): bool
    {
        return $this->finder->drop($collection);
    }
}
