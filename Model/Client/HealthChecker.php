<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;
use DmLab\TypesenseCore\Model\Config\Node;
use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Cached, non-throwing `GET /health`, plus the uncached, key-checking {@see verifyConnection()}.
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

    /** Per-request timeout of {@see verifyConnection()}, a diagnostic that must answer promptly. */
    private const VERIFY_TIMEOUT = 3;

    private const API_KEY_HEADER = 'X-TYPESENSE-API-KEY';

    /**
     * @param ConnectionSettingsInterface $settings
     * @param TypesenseClient $client
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     * @param TransportInterface $transport
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $settings,
        private readonly TypesenseClient $client,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly TransportInterface $transport
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
     * Uncached check that a node is up and accepts the API key. Never throws.
     *
     * `/health` needs no key, so {@see isHealthy()} passes with a wrong one; `/debug` does need it.
     * Each node is probed directly so the verdict can name the unreachable ones.
     *
     * @param ConnectionSettingsInterface|null $settings checked instead of the injected settings
     */
    public function verifyConnection(?ConnectionSettingsInterface $settings = null): ConnectionStatus
    {
        try {
            return $this->verify($settings ?? $this->settings);
        } catch (\Throwable $e) {
            return ConnectionStatus::unreachable((string)__('No Typesense node answered: %1', $e->getMessage()));
        }
    }

    /**
     * The {@see verifyConnection()} algorithm; the caller turns any escape into `unreachable`.
     *
     * @param ConnectionSettingsInterface $settings
     */
    private function verify(ConnectionSettingsInterface $settings): ConnectionStatus
    {
        try {
            $nodes = $settings->getNodes();
        } catch (\Throwable $e) {
            return ConnectionStatus::unreachable(
                (string)__('Typesense connection is not configured: %1', $e->getMessage())
            );
        }

        $healthy = [];
        $failures = [];
        foreach ($nodes as $node) {
            $error = $this->probeHealth($node);
            if ($error !== null) {
                $failures[] = $node->getBaseUrl() . ': ' . $error;
            } else {
                $healthy[] = $node;
            }
        }

        if ($healthy === []) {
            return ConnectionStatus::unreachable(
                (string)__('No Typesense node answered: %1', implode('; ', $failures))
            );
        }

        try {
            $apiKey = $settings->getApiKey();
        } catch (\Throwable $e) {
            return ConnectionStatus::unauthorized((string)__('Typesense API key is not configured.'));
        }

        // One healthy node accepting the key is enough; a failing one only moves on to the next.
        $rejected = null;
        $debugErrors = [];
        foreach ($healthy as $node) {
            try {
                $response = $this->transport->send(
                    'GET',
                    $node->getBaseUrl() . '/debug',
                    [self::API_KEY_HEADER => $apiKey, 'Accept' => 'application/json'],
                    null,
                    self::VERIFY_TIMEOUT
                );
            } catch (\Throwable $e) {
                $debugErrors[] = $node->getBaseUrl() . ': ' . $e->getMessage();
                continue;
            }

            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                $rejected = $status;
                $debugErrors[] = $node->getBaseUrl() . ': HTTP ' . $status;
                continue;
            }
            if (!$response->isSuccessful()) {
                $debugErrors[] = $node->getBaseUrl() . ': HTTP ' . $status;
                continue;
            }

            $debug = json_decode($response->getBody(), true);
            $version = is_array($debug) && is_string($debug['version'] ?? null) && $debug['version'] !== ''
                ? $debug['version']
                : 'unknown';
            $failures = [...$failures, ...$debugErrors];

            $message = $failures === []
                ? __('Connected to Typesense %1.', $version)
                : __('Connected to Typesense %1. Unreachable nodes: %2', $version, implode('; ', $failures));

            return ConnectionStatus::ok($version, (string)$message);
        }

        if ($rejected !== null) {
            return ConnectionStatus::unauthorized(
                (string)__('Typesense rejected the API key (HTTP %1).', $rejected)
            );
        }

        return ConnectionStatus::unreachable(
            (string)__('Typesense answered /debug with an error: %1', implode('; ', $debugErrors))
        );
    }

    /**
     * Probe one node's `/health` without the key: null when healthy, otherwise why not.
     *
     * @param Node $node
     */
    private function probeHealth(Node $node): ?string
    {
        try {
            $response = $this->transport->send(
                'GET',
                $node->getBaseUrl() . '/health',
                ['Accept' => 'application/json'],
                null,
                self::VERIFY_TIMEOUT
            );
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        if (!$response->isSuccessful()) {
            return 'HTTP ' . $response->getStatusCode();
        }

        $health = json_decode($response->getBody(), true);

        return is_array($health) && ($health['ok'] ?? false) === true ? null : 'unhealthy';
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
