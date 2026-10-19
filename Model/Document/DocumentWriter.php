<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Document;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;

/**
 * Document writes over `/documents`.
 *
 * Exposes the write primitives only — what to write, and into which collection, is the
 * consumer's decision (single-writer principle: one module owns a given collection).
 *
 * @api
 */
class DocumentWriter
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPSERT = 'upsert';
    public const ACTION_UPDATE = 'update';
    public const ACTION_EMPLACE = 'emplace';

    public const DEFAULT_BATCH_SIZE = 100;

    /**
     * How many rejected documents an ImportResult carries; the rest are counted only.
     */
    private const MAX_COLLECTED_ERRORS = 20;

    private const JSONL_CONTENT_TYPE = 'text/plain';

    /**
     * Import actions whose second delivery leaves the same state, given every document carries its `id`;
     * a `create` replay is a 409 per document.
     */
    private const REPLAY_SAFE_ACTIONS = [self::ACTION_UPSERT, self::ACTION_UPDATE, self::ACTION_EMPLACE];

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
     * Write a single document, replacing it if the id already exists.
     *
     * Replayed only with an `id`: without one the engine generates it, so a second delivery is a duplicate.
     *
     * @param string $collection
     * @param array<string,mixed> $document
     * @return array<mixed> the stored document
     * @throws TypesenseException
     */
    public function upsert(string $collection, array $document): array
    {
        return $this->client->request(
            'POST',
            $this->documentsPath($collection),
            $document,
            ['action' => self::ACTION_UPSERT],
            replaySafe: $this->hasId($document) ? true : null
        );
    }

    /**
     * Import documents in bounded batches.
     *
     * The iterable is consumed lazily and one JSONL body is built per batch, so the full
     * document set is never materialised — Magento's HTTP client takes a string body, so
     * bounded batches are the only memory guarantee available.
     *
     * @param string $collection
     * @param iterable<array<string,mixed>> $documents
     * @param string $action one of the ACTION_* constants
     * @param int $batchSize documents per request
     * @throws TypesenseException on a transport or HTTP failure only; per-document failures, including documents
     *     that cannot be JSON-encoded, are returned, not thrown
     */
    public function importBatch(
        string $collection,
        iterable $documents,
        string $action = self::ACTION_UPSERT,
        int $batchSize = self::DEFAULT_BATCH_SIZE
    ): ImportResult {
        if ($batchSize < 1) {
            throw new TypesenseException(sprintf('The import batch size must be at least 1, got %d.', $batchSize));
        }

        $successCount = 0;
        $failureCount = 0;
        $errors = [];
        $offset = 0;
        $batch = [];

        foreach ($documents as $document) {
            $batch[] = $document;
            if (count($batch) < $batchSize) {
                continue;
            }

            $this->sendBatch($collection, $batch, $action, $offset, $successCount, $failureCount, $errors);
            $offset += count($batch);
            $batch = [];
        }

        if ($batch !== []) {
            $this->sendBatch($collection, $batch, $action, $offset, $successCount, $failureCount, $errors);
        }

        return new ImportResult($successCount, $failureCount, $errors);
    }

    /**
     * Remove one document by id.
     *
     * @param string $collection
     * @param string $id
     * @return array<mixed> the removed document
     * @throws TypesenseException 404 when the document does not exist
     */
    public function delete(string $collection, string $id): array
    {
        return $this->client->request('DELETE', $this->documentsPath($collection) . '/' . rawurlencode($id));
    }

    /**
     * Remove every document matching a filter and return how many went.
     *
     * Sent exactly once, to one node: the filter matches at send time, so a replay after a lost
     * response would remove whatever matches then — including documents written in between.
     *
     * @param string $collection
     * @param string $filterBy a Typesense `filter_by` expression, e.g. `store_id:=1`
     * @throws TypesenseException
     */
    public function deleteBy(string $collection, string $filterBy): int
    {
        $response = $this->client->request(
            'DELETE',
            $this->documentsPath($collection),
            null,
            ['filter_by' => $filterBy],
            $this->settings->getOperationTimeout(),
            replaySafe: false
        );

        return (int)($response['num_deleted'] ?? 0);
    }

    /**
     * POST one batch and fold its per-line outcome into the running totals.
     *
     * Documents that cannot be encoded fail on their own and are left out of the body; response
     * line `i` belongs to the `i`-th encoded document.
     *
     * @param string $collection
     * @param array<int,array<string,mixed>> $batch
     * @param string $action
     * @param int $offset position of the batch's first document in the whole stream
     * @param int $successCount
     * @param int $failureCount
     * @param array<int,array{line:int,error:string,document:mixed}> $errors
     * @throws TypesenseException
     */
    private function sendBatch(
        string $collection,
        array $batch,
        string $action,
        int $offset,
        int &$successCount,
        int &$failureCount,
        array &$errors
    ): void {
        $encoded = [];
        $encodeErrors = [];
        $replaySafe = in_array($action, self::REPLAY_SAFE_ACTIONS, true);
        foreach ($batch as $index => $document) {
            try {
                $encoded[$index] = json_encode($document, JSON_THROW_ON_ERROR);
                $replaySafe = $replaySafe && $this->hasId($document);
            } catch (\JsonException $e) {
                $encodeErrors[$index] = 'Unable to encode the document: ' . $e->getMessage();
            }
        }

        $lines = [];
        if ($encoded !== []) {
            $response = $this->client->requestRaw(
                'POST',
                $this->documentsPath($collection) . '/import',
                implode("\n", $encoded),
                self::JSONL_CONTENT_TYPE,
                ['action' => $action],
                $this->settings->getOperationTimeout(),
                replaySafe: $replaySafe ? true : null
            );
            // The engine answers with exactly one line per document. Anything past the sent
            // count is not a result for a document we sent, and must not count as one.
            $lines = array_slice($this->splitLines($response), 0, count($encoded));
        }

        $sentIndex = 0;
        foreach ($batch as $index => $document) {
            if (isset($encodeErrors[$index])) {
                $this->collectFailure($failureCount, $errors, $offset + $index, $encodeErrors[$index], $document);
                continue;
            }

            // A short response means documents went unaccounted for, which must never read
            // as a clean import.
            if (!array_key_exists($sentIndex, $lines)) {
                $sentIndex++;
                $this->collectFailure(
                    $failureCount,
                    $errors,
                    $offset + $index,
                    'The import response carried no result for this document.',
                    $document
                );
                continue;
            }

            $decoded = json_decode($lines[$sentIndex++], true);
            $decoded = is_array($decoded) ? $decoded : [];

            if (($decoded['success'] ?? false) === true) {
                $successCount++;
                continue;
            }

            $this->collectFailure(
                $failureCount,
                $errors,
                $offset + $index,
                is_string($decoded['error'] ?? null) ? $decoded['error'] : 'Unparsable import response.',
                $decoded['document'] ?? $document
            );
        }
    }

    /**
     * Count one rejected document, keeping a sample of the errors.
     *
     * @param int $failureCount
     * @param array<int,array{line:int,error:string,document:mixed}> $errors
     * @param int $line position of the document in the whole stream
     * @param string $error
     * @param mixed $document
     */
    private function collectFailure(
        int &$failureCount,
        array &$errors,
        int $line,
        string $error,
        mixed $document
    ): void {
        $failureCount++;
        if (count($errors) < self::MAX_COLLECTED_ERRORS) {
            $errors[] = ['line' => $line, 'error' => $error, 'document' => $document];
        }
    }

    /**
     * Lines of an import response, positionally aligned with the batch.
     *
     * Blank lines are kept, not filtered: callers index the batch by line position, so dropping
     * one would shift every later error onto the wrong document. A blank decodes to a failure,
     * which is the honest reading of a line the engine should never have sent.
     *
     * @param string $response
     * @return array<int,string>
     */
    private function splitLines(string $response): array
    {
        $trimmed = trim($response);

        return $trimmed === '' ? [] : (preg_split('/\R/', $trimmed) ?: []);
    }

    /**
     * Whether a document names its own id, so a replay overwrites it instead of creating another.
     *
     * @param mixed $document
     */
    private function hasId(mixed $document): bool
    {
        return is_array($document) && is_string($document['id'] ?? null) && $document['id'] !== '';
    }

    /**
     * Documents endpoint of one collection.
     *
     * @param string $collection
     */
    private function documentsPath(string $collection): string
    {
        return '/collections/' . rawurlencode($collection) . '/documents';
    }
}
