<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Test\Integration;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\Clock;
use DmLab\TypesenseCore\Model\Client\Http\CurlTransport;
use DmLab\TypesenseCore\Model\Client\NodeHealthRegistry;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;
use DmLab\TypesenseCore\Model\Collection\AliasLock;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Test\Integration\Doubles\ArrayCache;
use DmLab\TypesenseCore\Test\Integration\Doubles\EnvConnectionSettings;
use Magento\Framework\HTTP\Adapter\Curl;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\HTTP\Adapter\CurlFactory;
use Magento\Framework\Lock\Backend\FileLock;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Real client stack against the Typesense at `TYPESENSE_TEST_URL`; skipped when the env is not set.
 *
 * Every resource a test creates must carry {@see $prefix}: tearDown() drops all of them.
 */
abstract class TypesenseTestCase extends TestCase
{
    /** @var string `dmlab_it_<8 hex>_`, unique per test */
    protected string $prefix;

    /** @var EnvConnectionSettings */
    protected EnvConnectionSettings $settings;

    /** @var CurlTransport */
    protected CurlTransport $transport;

    /** @var ArrayCache */
    protected ArrayCache $cache;

    /** @var TypesenseClient */
    protected TypesenseClient $client;

    /** @var CollectionManager */
    protected CollectionManager $collections;

    /** @var AliasLock */
    protected AliasLock $aliasLock;

    /** @var AliasManager */
    protected AliasManager $aliases;

    /** @var DateTime */
    protected DateTime $dateTime;

    protected function setUp(): void
    {
        $url = (string)getenv('TYPESENSE_TEST_URL');
        $apiKey = (string)getenv('TYPESENSE_TEST_API_KEY');
        if ($url === '' || $apiKey === '') {
            $this->markTestSkipped('Set TYPESENSE_TEST_URL and TYPESENSE_TEST_API_KEY to run integration tests.');
        }

        $this->prefix = 'dmlab_it_' . bin2hex(random_bytes(4)) . '_';
        $this->settings = new EnvConnectionSettings($url, $apiKey);

        // Stubbed: without Magento's generated/ code the test autoloader's factory create() is empty.
        $curlFactory = $this->createStub(CurlFactory::class);
        $curlFactory->method('create')->willReturnCallback(static fn (): Curl => new Curl());
        $this->transport = new CurlTransport($curlFactory);
        $this->cache = new ArrayCache();
        $this->client = $this->createClient($this->settings);

        $this->dateTime = new DateTime($this->createStub(TimezoneInterface::class));
        $this->collections = new CollectionManager($this->client, $this->settings);
        $this->aliasLock = new AliasLock(new FileLock(new File(), sys_get_temp_dir() . '/dmlab_typesense_it_locks'));
        $this->aliases = new AliasManager($this->client, $this->collections, $this->dateTime, $this->aliasLock);
    }

    protected function tearDown(): void
    {
        if (!isset($this->prefix)) {
            return;
        }

        // Aliases first: a collection an alias points to cannot be told apart from a live one.
        foreach (array_keys($this->aliases->getList()) as $alias) {
            if (str_starts_with($alias, $this->prefix)) {
                $this->ignoreNotFound(fn () => $this->aliases->delete($alias));
            }
        }
        foreach ($this->collections->getList() as $collection) {
            if (str_starts_with($collection, $this->prefix)) {
                $this->ignoreNotFound(fn () => $this->collections->drop($collection));
            }
        }
        foreach ($this->client->request('GET', '/synonym_sets') as $set) {
            $name = is_array($set) ? (string)($set['name'] ?? '') : '';
            if (str_starts_with($name, $this->prefix)) {
                $path = '/synonym_sets/' . rawurlencode($name);
                $this->ignoreNotFound(fn () => $this->client->request('DELETE', $path));
            }
        }
    }

    /**
     * A client over the shared transport and node-health cache, for other connection settings.
     *
     * @param ConnectionSettingsInterface $settings
     */
    protected function createClient(ConnectionSettingsInterface $settings): TypesenseClient
    {
        return new TypesenseClient(
            $settings,
            $this->transport,
            new NodeHealthRegistry($this->cache, new NullLogger()),
            new Clock(),
            new NullLogger()
        );
    }

    /**
     * A resource name owned by this test.
     *
     * @param string $suffix
     */
    protected function resourceName(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    /**
     * Run a cleanup call, treating an already-gone resource as done.
     *
     * @param callable $call
     */
    private function ignoreNotFound(callable $call): void
    {
        try {
            $call();
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }
}
