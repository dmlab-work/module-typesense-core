<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Collection;

use MageDevGroup\TypesenseCore\Api\ConnectionSettingsInterface;
use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Client\TypesenseClient;

/**
 * Collection lifecycle over `/collections`.
 *
 * Owns creation, inspection, in-place schema PATCH and removal. It decides nothing:
 * whether a PATCH is the right way to apply a change is the reconciler's call, and
 * what a collection should contain is the consumer's.
 *
 * @api
 */
class CollectionManager
{
    /**
     * @param TypesenseClient $client
     * @param ConnectionSettingsInterface $settings
     */
    public function __construct(
        private readonly TypesenseClient $client,
        private readonly ConnectionSettingsInterface $settings
    ) {
    }

    /**
     * Create a collection.
     *
     * @param string $name
     * @param FieldSpec[] $fields
     * @param array<string,mixed> $options extra collection properties, e.g. `default_sorting_field`
     * @return array<mixed> the created schema
     * @throws TypesenseException 409 when the collection already exists
     */
    public function create(string $name, array $fields, array $options = []): array
    {
        $body = $options;
        $body['name'] = $name;
        $body['fields'] = array_map(static fn (FieldSpec $field): array => $field->toArray(), array_values($fields));

        return $this->client->request('POST', '/collections', $body);
    }

    /**
     * Fetch a collection schema.
     *
     * @param string $name
     * @return array<mixed>
     * @throws TypesenseException 404 when the collection does not exist
     */
    public function get(string $name): array
    {
        return $this->client->request('GET', $this->path($name));
    }

    /**
     * Whether the collection exists.
     *
     * @param string $name
     * @throws TypesenseException on any failure other than a 404
     */
    public function exists(string $name): bool
    {
        try {
            $this->get($name);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Drop a collection.
     *
     * The engine answers only once the data is gone, so this runs under the operation
     * timeout like the other long writes — a big collection outlives the read timeout.
     *
     * @param string $name
     * @return array<mixed> the dropped schema
     * @throws TypesenseException 404 when the collection does not exist
     */
    public function drop(string $name): array
    {
        return $this->client->request(
            'DELETE',
            $this->path($name),
            null,
            [],
            $this->settings->getOperationTimeout()
        );
    }

    /**
     * Apply an in-place schema change.
     *
     * Additions, drops (`extra: ['drop' => true]`) and a type change expressed as a
     * drop plus a re-add may be combined in one call. The engine rebuilds the in-memory
     * index from the stored documents — no reimport — but blocks writes while it runs.
     *
     * @param string $name
     * @param FieldSpec[] $fieldChanges
     * @return array<mixed> the resulting schema
     * @throws TypesenseException
     */
    public function patch(string $name, array $fieldChanges): array
    {
        $fields = array_map(static fn (FieldSpec $field): array => $field->toArray(), array_values($fieldChanges));

        return $this->client->request(
            'PATCH',
            $this->path($name),
            ['fields' => $fields],
            [],
            $this->settings->getOperationTimeout()
        );
    }

    /**
     * API path of one collection.
     *
     * @param string $name
     */
    private function path(string $name): string
    {
        return '/collections/' . rawurlencode($name);
    }
}
