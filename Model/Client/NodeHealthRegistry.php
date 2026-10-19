<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

use DmLab\TypesenseCore\Model\Config\Node;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Remembers failed nodes across requests so a dead node stops costing every call a timeout.
 *
 * A cache failure degrades to "nothing marked": the registry is an optimisation, never a gate.
 */
class NodeHealthRegistry
{
    private const KEY_PREFIX = 'dmlab_typesense_node_down_';
    private const CACHE_TAG = 'DMLAB_TYPESENSE';
    private const TTL = 30;

    /**
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Base URLs of the nodes currently remembered as down: one cache read per node.
     *
     * @param Node[] $nodes
     * @return array<string,true>
     */
    public function marks(array $nodes): array
    {
        $marks = [];
        foreach ($nodes as $node) {
            if ($this->isMarked($node)) {
                $marks[$node->getBaseUrl()] = true;
            }
        }

        return $marks;
    }

    /**
     * Unmarked nodes first, then marked ones, each group in configured order.
     *
     * A marked node stays a last resort so a single-round call still reaches it once its peers fail.
     *
     * @param Node[] $nodes
     * @param array<string,true>|null $marks from {@see marks()}; read from the cache when null
     * @return Node[]
     */
    public function order(array $nodes, ?array $marks = null): array
    {
        $marks ??= $this->marks($nodes);
        $up = [];
        $down = [];
        foreach ($nodes as $node) {
            if (isset($marks[$node->getBaseUrl()])) {
                $down[] = $node;
            } else {
                $up[] = $node;
            }
        }

        return [...$up, ...$down];
    }

    /**
     * Whether the node is currently remembered as down.
     *
     * @param Node $node
     */
    public function isMarked(Node $node): bool
    {
        try {
            $value = $this->cache->load($this->key($node));
        } catch (\Throwable $e) {
            $this->logger->warning('Typesense node health cache read failed: ' . $e->getMessage());

            return false;
        }

        return $value !== false && $value !== null && $value !== '';
    }

    /**
     * Remember the node as down for TTL seconds.
     *
     * @param Node $node
     */
    public function markDown(Node $node): void
    {
        try {
            $this->cache->save('1', $this->key($node), [self::CACHE_TAG], self::TTL);
        } catch (\Throwable $e) {
            $this->logger->warning('Typesense node health cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Forget a down mark; callers skip it for a node {@see marks()} did not report.
     *
     * @param Node $node
     */
    public function markUp(Node $node): void
    {
        try {
            $this->cache->remove($this->key($node));
        } catch (\Throwable $e) {
            $this->logger->warning('Typesense node health cache remove failed: ' . $e->getMessage());
        }
    }

    /**
     * Cache key for one node.
     *
     * @param Node $node
     */
    private function key(Node $node): string
    {
        return self::KEY_PREFIX . sha1($node->getBaseUrl());
    }
}
