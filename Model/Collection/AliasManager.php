<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Collection;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Alias lifecycle over `/aliases`.
 *
 * An alias is the stable name a consumer queries; the physical collection behind it is
 * versioned and replaced. Repointing is atomic in the engine, which is what makes a
 * rebuild-and-swap a zero-downtime alternative to an in-place PATCH.
 *
 * @api
 */
class AliasManager
{
    /**
     * @param TypesenseClient $client
     * @param CollectionManager $collections
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly CollectionManager $collections,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Point an alias at a collection, creating the alias if it is absent.
     *
     * @param string $alias
     * @param string $targetCollection
     * @return array<mixed> the resulting alias
     * @throws TypesenseException
     */
    public function upsert(string $alias, string $targetCollection): array
    {
        return $this->client->request('PUT', $this->path($alias), ['collection_name' => $targetCollection]);
    }

    /**
     * Every alias, as `alias => target collection`.
     *
     * @return array<string,string>
     * @throws TypesenseException
     */
    public function getList(): array
    {
        $response = $this->client->request('GET', '/aliases');

        $listed = $response['aliases'] ?? [];
        if (!is_array($listed)) {
            throw new TypesenseException(
                'Typesense returned a malformed alias list: "aliases" is not an array.',
                0,
                $response
            );
        }

        $aliases = [];
        foreach ($listed as $alias) {
            if (!is_array($alias)) {
                continue;
            }
            $name = $alias['name'] ?? null;
            $target = $alias['collection_name'] ?? null;
            if (is_string($name) && $name !== '') {
                $aliases[$name] = is_string($target) ? $target : '';
            }
        }

        return $aliases;
    }

    /**
     * The collection an alias points at, or null when the alias does not exist.
     *
     * @param string $alias
     * @throws TypesenseException on any failure other than a 404
     */
    public function resolve(string $alias): ?string
    {
        try {
            $response = $this->client->request('GET', $this->path($alias));
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        $target = $response['collection_name'] ?? null;

        return is_string($target) && $target !== '' ? $target : null;
    }

    /**
     * Remove an alias. The collection behind it is left alone.
     *
     * @param string $alias
     * @return array<mixed> the removed alias
     * @throws TypesenseException 404 when the alias does not exist
     */
    public function delete(string $alias): array
    {
        return $this->client->request('DELETE', $this->path($alias));
    }

    /**
     * Repoint an alias at a new collection and drop the one it left behind.
     *
     * The old collection is dropped only after the alias moved, so a failure mid-way
     * leaves readers on a collection that still exists.
     *
     * Resolve-then-drop is not atomic: two swaps of the same alias overlapping both see the
     * same predecessor and both drop it. The loser's 404 is the state it wanted, so it is not
     * an error — serialise callers if the orphaned build of the loser also matters.
     *
     * @param string $alias
     * @param string $newCollection
     * @return string|null the dropped collection, or null when there was nothing to drop
     * @throws TypesenseException
     */
    public function swap(string $alias, string $newCollection): ?string
    {
        $previous = $this->resolve($alias);

        $this->upsert($alias, $newCollection);

        if ($previous === null || $previous === $newCollection) {
            return null;
        }

        try {
            $this->collections->drop($previous);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }

        return $previous;
    }

    /**
     * A versioned physical collection name for an alias, e.g. `products_1752710400_9f3ac1`.
     *
     * The random suffix keeps two reindexes started in the same second from generating the
     * same name — a collision would otherwise hand the second one the live collection.
     *
     * @param string $alias
     * @throws \Exception when no source of randomness is available
     */
    public function generateCollectionName(string $alias): string
    {
        return $alias . '_' . $this->dateTime->gmtTimestamp() . '_' . bin2hex(random_bytes(3));
    }

    /**
     * API path of one alias.
     *
     * @param string $alias
     */
    private function path(string $alias): string
    {
        return '/aliases/' . rawurlencode($alias);
    }
}
