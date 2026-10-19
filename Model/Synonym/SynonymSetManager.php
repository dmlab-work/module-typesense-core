<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Synonym;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;

/**
 * Synonym set lifecycle over `/synonym_sets` (Typesense ≥ 30.0).
 *
 * A set is replaced as a whole; searches reference it by name via `synonym_sets`.
 *
 * @api
 */
class SynonymSetManager
{
    private const ITEM_KEYS = ['id', 'synonyms'];

    /**
     * @param TypesenseClient $client
     */
    public function __construct(
        private readonly TypesenseClient $client
    ) {
    }

    /**
     * Create or replace a synonym set. An empty `$items` leaves an empty set.
     *
     * @param string $name
     * @param array<int,array{id:string,synonyms:string[]}> $items multi-way synonym rules
     * @throws \InvalidArgumentException on a malformed name or item, before any request
     * @throws TypesenseException
     */
    public function upsert(string $name, array $items): void
    {
        $this->assertName($name);
        if (!array_is_list($items)) {
            throw new \InvalidArgumentException('Synonym items must be a list.');
        }

        $payload = [];
        foreach ($items as $index => $item) {
            $payload[] = $this->normalizeItem($index, $item);
        }

        $this->client->request('PUT', $this->path($name), ['items' => $payload]);
    }

    /**
     * Delete a synonym set; a missing set is not an error.
     *
     * @param string $name
     * @throws \InvalidArgumentException on an empty name
     * @throws TypesenseException on any failure other than a 404
     */
    public function delete(string $name): void
    {
        $this->assertName($name);

        try {
            $this->client->request('DELETE', $this->path($name));
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Whether the synonym set exists.
     *
     * @param string $name
     * @throws \InvalidArgumentException on an empty name
     * @throws TypesenseException on any failure other than a 404
     */
    public function exists(string $name): bool
    {
        $this->assertName($name);

        try {
            $this->client->request('GET', $this->path($name));
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Validate one item and re-index its synonyms so they encode as a JSON array.
     *
     * @param int $index
     * @param mixed $item
     * @return array{id:string,synonyms:string[]}
     * @throws \InvalidArgumentException
     */
    private function normalizeItem(int $index, mixed $item): array
    {
        if (!is_array($item)) {
            throw new \InvalidArgumentException(sprintf('Synonym item #%d must be an array.', $index));
        }

        $extra = array_diff(array_map('strval', array_keys($item)), self::ITEM_KEYS);
        if ($extra !== []) {
            throw new \InvalidArgumentException(
                sprintf('Synonym item #%d has unsupported keys: %s.', $index, implode(', ', $extra))
            );
        }

        $id = $item['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException(sprintf('Synonym item #%d needs a non-empty string "id".', $index));
        }

        $synonyms = $item['synonyms'] ?? null;
        if (!is_array($synonyms) || $synonyms === []) {
            throw new \InvalidArgumentException(
                sprintf('Synonym item "%s" needs a non-empty "synonyms" list.', $id)
            );
        }
        foreach ($synonyms as $synonym) {
            if (!is_string($synonym) || $synonym === '') {
                throw new \InvalidArgumentException(
                    sprintf('Synonym item "%s" contains an empty or non-string synonym.', $id)
                );
            }
        }

        return ['id' => $id, 'synonyms' => array_values($synonyms)];
    }

    /**
     * Reject an empty set name.
     *
     * @param string $name
     * @throws \InvalidArgumentException
     */
    private function assertName(string $name): void
    {
        if ($name === '') {
            throw new \InvalidArgumentException('Synonym set name must not be empty.');
        }
    }

    /**
     * API path of one synonym set.
     *
     * @param string $name
     */
    private function path(string $name): string
    {
        return '/synonym_sets/' . rawurlencode($name);
    }
}
