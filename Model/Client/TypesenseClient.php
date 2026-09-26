<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\Http\Response;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;

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
     * a call passes `$replaySafe = false` and is treated as a write.
     *
     * Everything else is sent exactly once, to one node. The transport cannot tell a request that
     * never left from one whose response was lost (Magento's cURL adapter exposes an error string,
     * not an errno), so a replayed POST is a second key in `/keys`, a 409 on a `create` that in fact
     * succeeded, or a half-applied schema PATCH re-dropping a dropped field. Losing write failover
     * is the cheaper mistake: it fails loudly, whereas a duplicate write corrupts quietly.
     */
    private const RETRYABLE_METHODS = ['GET', 'HEAD', 'PUT', 'DELETE'];

    /**
     * @param ConnectionSettingsInterface $settings
     * @param TransportInterface $transport
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $settings,
        private readonly TransportInterface $transport
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
     * @param int|null $maxAttempts overrides the configured retry count; ignored for non-retryable methods
     * @param bool $replaySafe false forces a single delivery to a single node even for a retryable method
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
        bool $replaySafe = true
    ): array {
        $response = $this->send(
            $method,
            $path,
            $body === null ? null : $this->encode($body),
            null,
            $query,
            $timeout,
            $maxAttempts,
            $replaySafe
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
     * @throws TypesenseException on a non-2xx response or no reachable node
     */
    public function requestRaw(
        string $method,
        string $path,
        string $body,
        string $contentType,
        array $query = [],
        ?int $timeout = null
    ): string {
        return $this->send($method, $path, $body, $contentType, $query, $timeout)->getBody();
    }

    /**
     * Try every node, then retry the whole list up to the configured retry count.
     *
     * Retryable methods only; anything else gets a single delivery to a single node.
     *
     * @param string $method
     * @param string $path
     * @param string|null $encodedBody
     * @param string|null $contentType defaults to JSON when a body is present
     * @param array<string,scalar> $query
     * @param int|null $timeout
     * @param int|null $maxAttempts
     * @param bool $replaySafe
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
        bool $replaySafe = true
    ): Response {
        $method = strtoupper($method);
        $headers = $this->buildHeaders($encodedBody !== null ? $contentType ?? 'application/json' : null);
        $timeout ??= $this->settings->getConnectionTimeout();
        $suffix = $this->buildPath($path, $query);
        $nodes = $this->settings->getNodes();

        $retryable = $replaySafe && in_array($method, self::RETRYABLE_METHODS, true);
        if (!$retryable) {
            // One node, one delivery: see self::RETRYABLE_METHODS.
            $nodes = array_slice($nodes, 0, 1);
        }
        $attempts = $retryable ? $maxAttempts ?? $this->settings->getRetryCount() + 1 : 1;

        // Kept apart: a node answering 5xx and a node being unreachable are different
        // diagnoses, and node order must not decide which one the caller is told about.
        $lastHttpError = null;
        $lastTransportError = null;

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            foreach ($nodes as $node) {
                $url = $node->getBaseUrl() . $suffix;
                try {
                    $response = $this->transport->send($method, $url, $headers, $encodedBody, $timeout);
                } catch (TransportException $e) {
                    // The node is unreachable; a different one may not be.
                    $lastTransportError = $e;
                    continue;
                }

                if (!$response->isSuccessful()) {
                    // A 4xx means the node is up and disagrees; another node answers the same.
                    // A 5xx means this node is lagging, starting up or overloaded — a peer may serve it.
                    if ($response->getStatusCode() >= 500) {
                        $lastHttpError = $this->mapError($response, $method, $suffix);
                        continue;
                    }

                    throw $this->mapError($response, $method, $suffix);
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
