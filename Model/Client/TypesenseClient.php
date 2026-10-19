<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\Http\Response;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;
use DmLab\TypesenseCore\Model\Config\Node;
use Psr\Log\LoggerInterface;

/**
 * The module's only door to the Typesense REST API.
 *
 * Owns authentication, node failover, bounded retry, JSON coding and error mapping.
 * No Magento search contract is implemented here — `typesense-search` wraps this class
 * to provide `Magento\AdvancedSearch\Model\Client\ClientInterface`.
 *
 * @api
 */
class TypesenseClient
{
    private const API_KEY_HEADER = 'X-TYPESENSE-API-KEY';

    /**
     * Methods whose second delivery normally leaves the same state as the first.
     *
     * A method alone does not settle it — `DELETE /documents?filter_by=…` matches at send time,
     * so a replay removes whatever matches *then*, including documents written in between. Such
     * a call passes `$replaySafe = false`; an idempotent POST (an upsert import) passes `true`.
     *
     * Everything else is delivered at most once. A request whose response was lost may have been
     * applied, so a replayed POST is a second key in `/keys`, a 409 on a `create` that in fact
     * succeeded, or a half-applied schema PATCH re-dropping a dropped field. Only a request that
     * provably never left ({@see TransportException::isRequestSent()}, from the cURL errno) moves
     * on to the next node.
     */
    private const RETRYABLE_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE'];

    /** A node that timed out this many times in one call is not tried again in that call. */
    private const MAX_TIMEOUTS_PER_NODE = 2;

    /** Seconds; cURL's millisecond resolution cannot honour less, so the deadline counts as spent. */
    private const MIN_ATTEMPT_TIMEOUT = 0.001;

    private const BACKOFF_BASE_US = 100_000;
    private const BACKOFF_CAP_US = 1_000_000;

    /**
     * @param ConnectionSettingsInterface $settings
     * @param TransportInterface $transport
     * @param NodeHealthRegistry $nodeHealth
     * @param Clock $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $settings,
        private readonly TransportInterface $transport,
        private readonly NodeHealthRegistry $nodeHealth,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send a request to the first node that answers and return the decoded body.
     *
     * @param string $method HTTP method
     * @param string $path API path, e.g. `/collections`
     * @param array<string,mixed>|null $body encoded as a JSON object
     * @param array<string,scalar> $query query parameters
     * @param int|null $timeout seconds; falls back to the configured timeout
     * @param int|null $maxAttempts overrides the configured retry count; ignored for non-replay-safe calls
     * @param bool|null $replaySafe null decides by method, true allows replay for any method, false forbids it
     * @param int|null $deadline total seconds for the call across all attempts; defaults to timeout × 2
     * @return array<mixed>
     * @throws TypesenseException on a non-2xx response, an undecodable body, or no reachable node
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?int $timeout = null,
        ?int $maxAttempts = null,
        ?bool $replaySafe = null,
        ?int $deadline = null
    ): array {
        $response = $this->send(
            $method,
            $path,
            $body === null ? null : $this->encode($body),
            null,
            $query,
            $timeout,
            $maxAttempts,
            $replaySafe,
            $deadline
        );

        return $this->decode($response, $method, $path);
    }

    /**
     * Send an already-serialised body and return the response body undecoded.
     *
     * For endpoints that neither take nor return a JSON object — `/documents/import`
     * speaks JSONL in both directions.
     *
     * @param string $method HTTP method
     * @param string $path API path
     * @param string $body verbatim request body
     * @param string $contentType
     * @param array<string,scalar> $query query parameters
     * @param int|null $timeout seconds; falls back to the configured timeout
     * @param int|null $maxAttempts overrides the configured retry count; ignored for non-replay-safe calls
     * @param bool|null $replaySafe null decides by method, true allows replay for any method, false forbids it
     * @param int|null $deadline total seconds for the call across all attempts; defaults to timeout × 2
     * @throws TypesenseException on a non-2xx response or no reachable node
     */
    public function requestRaw(
        string $method,
        string $path,
        string $body,
        string $contentType,
        array $query = [],
        ?int $timeout = null,
        ?int $maxAttempts = null,
        ?bool $replaySafe = null,
        ?int $deadline = null
    ): string {
        return $this->send(
            $method,
            $path,
            $body,
            $contentType,
            $query,
            $timeout,
            $maxAttempts,
            $replaySafe,
            $deadline
        )->getBody();
    }

    /**
     * Try every node, then retry the whole list up to the configured retry count, within a deadline.
     *
     * A non-replay-safe call gets one round and leaves a node only when the request provably never left.
     *
     * @param string $method
     * @param string $path
     * @param string|null $encodedBody
     * @param string|null $contentType defaults to JSON when a body is present
     * @param array<string,scalar> $query
     * @param int|null $timeout
     * @param int|null $maxAttempts
     * @param bool|null $replaySafe
     * @param int|null $deadline
     * @throws TypesenseException
     */
    private function send(
        string $method,
        string $path,
        ?string $encodedBody,
        ?string $contentType,
        array $query,
        ?int $timeout,
        ?int $maxAttempts = null,
        ?bool $replaySafe = null,
        ?int $deadline = null
    ): Response {
        $method = strtoupper($method);
        $headers = $this->buildHeaders($encodedBody !== null ? $contentType ?? 'application/json' : null);
        $timeout ??= $this->settings->getConnectionTimeout();
        $budget = $deadline ?? $timeout * 2;
        $deadlineAt = $this->clock->now() + $budget;
        $logPath = '/' . ltrim($path, '/');
        $suffix = $this->buildPath($path, $query);
        $nodes = $this->settings->getNodes();

        $retryable = $replaySafe ?? in_array($method, self::RETRYABLE_METHODS, true);
        $attempts = $retryable ? $maxAttempts ?? $this->settings->getRetryCount() + 1 : 1;

        // Kept apart: a node answering 5xx and a node being unreachable are different
        // diagnoses, and node order must not decide which one the caller is told about.
        $lastHttpError = null;
        $lastTransportError = null;
        /** @var array<string,int> $timeouts per base URL */
        $timeouts = [];
        // Read once per call and kept in step locally. A node marked before the call gets one attempt.
        $preMarked = $this->nodeHealth->marks($nodes);
        $marked = $preMarked;
        /** @var array<string,true> $exhausted base URLs not to try again in this call */
        $exhausted = [];

        for ($round = 0; $round < $attempts; $round++) {
            // Re-ordered per round: a node marked down in this call drops behind its peers.
            $roundNodes = array_values(array_filter(
                $this->nodeHealth->order($nodes, $marked),
                static fn (Node $node): bool => !isset($exhausted[$node->getBaseUrl()])
                    && ($timeouts[$node->getBaseUrl()] ?? 0) < self::MAX_TIMEOUTS_PER_NODE
            ));
            if ($roundNodes === []) {
                break;
            }
            if ($round > 0) {
                $this->backoff($round, $deadlineAt);
            }

            foreach ($roundNodes as $node) {
                $remaining = $deadlineAt - $this->clock->now();
                if ($remaining < self::MIN_ATTEMPT_TIMEOUT) {
                    $previous = $lastHttpError ?? $lastTransportError;
                    throw new TransportException(
                        sprintf(
                            'Typesense %s %s exceeded its %ds deadline: %s',
                            $method,
                            $logPath,
                            $budget,
                            $previous?->getMessage() ?? 'no attempt completed'
                        ),
                        0,
                        [],
                        $previous
                    );
                }

                $baseUrl = $node->getBaseUrl();
                $url = $baseUrl . $suffix;
                $attemptTimeout = min((float)$timeout, $remaining);
                try {
                    $response = $this->transport->send($method, $url, $headers, $encodedBody, $attemptTimeout);
                } catch (TransportException $e) {
                    // The node is unreachable; a different one may not be.
                    $this->logFailure(
                        $baseUrl,
                        $method,
                        $logPath,
                        str_replace($url, $baseUrl . $logPath, $e->getMessage())
                    );
                    $this->nodeHealth->markDown($node);
                    $marked[$baseUrl] = true;
                    if (isset($preMarked[$baseUrl])) {
                        $exhausted[$baseUrl] = true;
                    }
                    if ($e->isTimeout()) {
                        $timeouts[$baseUrl] = ($timeouts[$baseUrl] ?? 0) + 1;
                    }
                    $lastTransportError = $e;
                    if (!$retryable && $e->isRequestSent()) {
                        // It may have been applied: see self::RETRYABLE_METHODS.
                        break 2;
                    }
                    continue;
                }

                if (!$response->isSuccessful()) {
                    // A 4xx means the node is up and disagrees; another node answers the same.
                    // A 5xx means this node is lagging, starting up or overloaded — a peer may serve it.
                    if ($response->getStatusCode() >= 500) {
                        $this->logFailure($baseUrl, $method, $logPath, 'HTTP ' . $response->getStatusCode());
                        $this->nodeHealth->markDown($node);
                        $marked[$baseUrl] = true;
                        if (isset($preMarked[$baseUrl])) {
                            $exhausted[$baseUrl] = true;
                        }
                        $lastHttpError = $this->mapError($response, $method, $suffix);
                        if (!$retryable) {
                            throw $lastHttpError;
                        }
                        continue;
                    }

                    throw $this->mapError($response, $method, $suffix);
                }

                if (isset($marked[$baseUrl])) {
                    $this->nodeHealth->markUp($node);
                }

                return $response;
            }
        }

        // A node did answer: surface that response as-is so callers keep the status and body.
        if ($lastHttpError !== null) {
            throw $lastHttpError;
        }

        throw new TransportException(
            sprintf(
                'No Typesense node answered %s %s after %d attempt(s) over %d node(s): %s',
                $method,
                $suffix,
                $attempts,
                count($nodes),
                $lastTransportError?->getMessage() ?? 'unknown error'
            ),
            0,
            [],
            $lastTransportError
        );
    }

    /**
     * Sleep a jittered, exponentially growing pause before a retry round, never past the deadline.
     *
     * @param int $round 1 for the first retry round
     * @param float $deadlineAt
     */
    private function backoff(int $round, float $deadlineAt): void
    {
        $cap = (int)min(self::BACKOFF_BASE_US * 2 ** ($round - 1), self::BACKOFF_CAP_US);
        $remainingUs = (int)(($deadlineAt - $this->clock->now()) * 1_000_000);
        $this->clock->sleep(max(0, min(random_int(0, $cap), $remainingUs)));
    }

    /**
     * One warning per failed node; never headers or body, and the path without its query string.
     *
     * @param string $baseUrl
     * @param string $method
     * @param string $path
     * @param string $reason
     */
    private function logFailure(string $baseUrl, string $method, string $path, string $reason): void
    {
        $this->logger->warning(sprintf('Typesense node %s failed for %s %s: %s', $baseUrl, $method, $path, $reason));
    }

    /**
     * Request headers for one call.
     *
     * @param string|null $contentType null when the request carries no body
     * @return array<string,string>
     */
    private function buildHeaders(?string $contentType): array
    {
        $headers = [
            self::API_KEY_HEADER => $this->settings->getApiKey(),
            'Accept' => 'application/json',
        ];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }

        return $headers;
    }

    /**
     * Path with a leading slash and the query string appended.
     *
     * @param string $path
     * @param array<string,scalar> $query
     */
    private function buildPath(string $path, array $query): string
    {
        $path = '/' . ltrim($path, '/');
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }

    /**
     * JSON-encode a request body.
     *
     * @param array<string,mixed> $body
     * @throws TypesenseException
     */
    private function encode(array $body): string
    {
        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TypesenseException(
                'Unable to encode the Typesense request body: ' . $e->getMessage(),
                0,
                [],
                $e
            );
        }
    }

    /**
     * Decode a successful response body.
     *
     * @param Response $response
     * @param string $method
     * @param string $path
     * @return array<mixed>
     * @throws TypesenseException
     */
    private function decode(Response $response, string $method, string $path): array
    {
        $body = trim($response->getBody());
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TypesenseException(
                sprintf('Typesense returned an undecodable body for %s %s: %s', $method, $path, $e->getMessage()),
                $response->getStatusCode(),
                [],
                $e
            );
        }

        return is_array($decoded) ? $decoded : [$decoded];
    }

    /**
     * Turn a non-2xx response into an exception carrying the server's own message.
     *
     * @param Response $response
     * @param string $method
     * @param string $path
     */
    private function mapError(Response $response, string $method, string $path): TypesenseException
    {
        $decoded = json_decode($response->getBody(), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $message = is_string($decoded['message'] ?? null) && $decoded['message'] !== ''
            ? $decoded['message']
            : trim($response->getBody());

        return new TypesenseException(
            sprintf(
                'Typesense %s %s failed with HTTP %d: %s',
                $method,
                $path,
                $response->getStatusCode(),
                $message === '' ? 'no message' : $message
            ),
            $response->getStatusCode(),
            $decoded
        );
    }
}
