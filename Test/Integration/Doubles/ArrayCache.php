<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration\Doubles;

use Magento\Framework\App\CacheInterface;

/**
 * In-memory cache for one test; lifetimes are ignored.
 */
class ArrayCache implements CacheInterface
{
    /** @var array<string,array{data:string,tags:string[]}> */
    private array $entries = [];

    /**
     * @inheritDoc
     */
    public function getFrontend()
    {
        throw new \LogicException('ArrayCache has no cache frontend.');
    }

    /**
     * @inheritDoc
     */
    public function load($identifier)
    {
        return $this->entries[$identifier]['data'] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        $this->entries[$identifier] = ['data' => (string)$data, 'tags' => array_values($tags)];

        return true;
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        unset($this->entries[$identifier]);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function clean($tags = [])
    {
        $this->entries = $tags === []
            ? []
            : array_filter(
                $this->entries,
                static fn (array $entry): bool => array_intersect($tags, $entry['tags']) === []
            );

        return true;
    }
}
