<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Cached, non-throwing `GET /health`.
 *
 * Both properties are load-bearing, not conveniences:
 * `Magento\CatalogSearch\Model\Indexer\IndexerHandlerFactory::create()` calls `isAvailable()`
 * on every handler instantiation and turns a false into a `LogicException`. Pinging per call
 * would put a live round-trip in front of every indexer run and promote a blip to a fatal.
 *
 * @api
 */
class HealthChecker
{
    private const CACHE_KEY = 'dmlab_typesense_health';
    private const CACHE_TAG = 'DMLAB_TYPESENSE';

    /** Health must answer fast or be treated as down; the configured timeout is for real work. */
    private const HEALTH_TIMEOUT = 2;

    /** Bounds one uncached check at HEALTH_TIMEOUT per node rather than per retry. */
    private const HEALTH_ATTEMPTS = 1;

    /**
     * @param ConnectionSettingsInterface $settings
     * @param TypesenseClient $client
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $settings,
        private readonly TypesenseClient $client,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether Typesense is reachable and healthy. Never throws.
     */
    public function isHealthy(): bool
    {
        $ttl = $this->settings->getHealthCacheTtl();
        if ($ttl > 0) {
            $cached = $this->load();
            if ($cached !== null) {
                return $cached;
            }
        }

        $healthy = $this->ping();

        if ($ttl > 0) {
            // Cache the negative too: an outage must not turn into a request-rate ping storm.
            $this->save($healthy, $ttl);
        }

        return $healthy;
    }

    /**
     * The cached verdict, or null when absent or unreadable.
     *
     * A cache backend is a network hop of its own (Valkey here); letting it throw would
     * break the non-throwing contract in exactly the case the cache exists to protect.
     */
    private function load(): ?bool
    {
        try {
            $cached = $this->cache->load(self::CACHE_KEY);
        } catch (\Throwable $e) {
            $this->logger->warning('Typesense health cache read failed: ' . $e->getMessage());

            return null;
        }

        if ($cached === false || $cached === null || $cached === '') {
            return null;
        }

        return $cached === '1';
    }

    /**
     * Cache one verdict; a failure to store only costs the next call a ping.
     *
     * @param bool $healthy
     * @param int $ttl
     */
    private function save(bool $healthy, int $ttl): void
    {
        try {
            $this->cache->save($healthy ? '1' : '0', self::CACHE_KEY, [self::CACHE_TAG], $ttl);
        } catch (\Throwable $e) {
            $this->logger->warning('Typesense health cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * One live health call, swallowing every failure.
     */
    private function ping(): bool
    {
        try {
            // One attempt per node: retrying would multiply HEALTH_TIMEOUT by the retry count and
            // put exactly the stall this class exists to prevent in front of the indexer.
            $response = $this->client->request(
                'GET',
                '/health',
                null,
                [],
                self::HEALTH_TIMEOUT,
                self::HEALTH_ATTEMPTS
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Typesense health check failed: ' . $e->getMessage());

            return false;
        }

        return ($response['ok'] ?? false) === true;
    }
}
