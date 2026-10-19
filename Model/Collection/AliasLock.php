<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Collection;

use DmLab\TypesenseCore\Exception\TypesenseException;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Cross-process lock per alias, serialising a swap against a prune of the same alias's collections.
 *
 * The engine drops a collection an alias points to without complaint, so these must never interleave.
 */
class AliasLock
{
    private const TIMEOUT = 60;

    /**
     * @param LockManagerInterface $locks
     */
    public function __construct(
        private readonly LockManagerInterface $locks
    ) {
    }

    /**
     * Run the callback holding the alias's lock.
     *
     * @template T
     * @param string $alias
     * @param callable():T $callback
     * @return T
     * @throws TypesenseException when the lock is not acquired in time
     */
    public function synchronized(string $alias, callable $callback): mixed
    {
        // Hashed: file and DB lock backends restrict the name's characters and length.
        $name = 'dmlab_typesense_alias_' . hash('sha256', $alias);
        if (!$this->locks->lock($name, self::TIMEOUT)) {
            throw new TypesenseException(sprintf('Timed out waiting for the lock on alias "%s".', $alias));
        }

        try {
            return $callback();
        } finally {
            $this->locks->unlock($name);
        }
    }
}
