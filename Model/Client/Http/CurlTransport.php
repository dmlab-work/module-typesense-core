<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseCore\Model\Client\Http;

use Laminas\Http\Response as LaminasResponse;
use MageDevGroup\TypesenseCore\Exception\TransportException;
use Magento\Framework\HTTP\Adapter\CurlFactory;

/**
 * {@see TransportInterface} over Magento's cURL adapter.
 *
 * A fresh adapter per request: the adapter carries cURL handle state, and reusing
 * one across requests leaks options (a GET after a POST keeps CURLOPT_POST).
 */
class CurlTransport implements TransportInterface
{
    /**
     * Methods the adapter's write() handles natively; the rest need CURLOPT_CUSTOMREQUEST set by hand.
     */
    private const NATIVE_METHODS = ['GET', 'POST', 'PUT', 'DELETE'];

    /**
     * Establishing a TCP connection is fast or never; capping it at the caller's total
     * budget would let a long write (import, schema PATCH) wait minutes on a dead node.
     */
    private const CONNECT_TIMEOUT = 5;

    /**
     * @param CurlFactory $curlFactory
     */
    public function __construct(private readonly CurlFactory $curlFactory)
    {
    }

    /**
     * @inheritDoc
     */
    public function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = 5
    ): Response {
        $method = strtoupper($method);
        $curl = $this->curlFactory->create();

        try {
            $options = [
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(self::CONNECT_TIMEOUT, $timeout),
            ];
            if (!in_array($method, self::NATIVE_METHODS, true)) {
                // write() only branches on the native verbs; PATCH must be wired up itself.
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                $options[CURLOPT_POSTFIELDS] = (string)$body;
            }
            $curl->setOptions($options);
            // Flattened here, not left to the adapter: only newer framework releases normalize a
            // `name => value` map, older ones pass it to cURL as bare values and drop the API key.
            $curl->write($method, $url, '1.1', $this->flattenHeaders($headers), (string)$body);

            $raw = $curl->read();
            if ($raw === '') {
                throw new TransportException(
                    sprintf('Typesense request to %s failed: %s', $url, $curl->getError() ?: 'no response')
                );
            }
        } finally {
            $curl->close();
        }

        return $this->toResponse($url, $raw);
    }

    /**
     * Render a header map as `name: value` lines.
     *
     * The one shape every framework release hands to cURL untouched: older ones pass an
     * assoc array straight through, where it degrades to bare values and the key is lost.
     *
     * @param array<string,string> $headers
     * @return string[]
     */
    private function flattenHeaders(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = is_int($name) ? (string)$value : $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * Parse a raw HTTP message into a response.
     *
     * @param string $url
     * @param string $raw
     * @throws TransportException when the message is not parseable
     */
    private function toResponse(string $url, string $raw): Response
    {
        try {
            $parsed = LaminasResponse::fromString($raw);
        } catch (\Throwable $e) {
            throw new TransportException(
                sprintf('Typesense response from %s could not be parsed: %s', $url, $e->getMessage()),
                0,
                [],
                $e
            );
        }

        return new Response($parsed->getStatusCode(), $parsed->getBody());
    }
}
