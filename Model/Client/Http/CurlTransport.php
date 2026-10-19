<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Model\Client\Http;

use Laminas\Http\Response as LaminasResponse;
use DmLab\TypesenseCore\Exception\TransportException;
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
    private const CONNECT_TIMEOUT_MS = 5000;

    /**
     * CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT: the request never left.
     */
    private const NOT_SENT_ERRNOS = [6, 7];

    private const CURLE_OPERATION_TIMEDOUT = 28;

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
        #[\SensitiveParameter] array $headers = [],
        ?string $body = null,
        float $timeout = 5
    ): Response {
        $method = strtoupper($method);
        $curl = $this->curlFactory->create();

        try {
            // Floored so an attempt never outlasts the caller's remaining deadline.
            $timeoutMs = max(1, (int)floor($timeout * 1000));
            $options = [
                CURLOPT_TIMEOUT_MS => $timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => min(self::CONNECT_TIMEOUT_MS, $timeoutMs),
                // Without it a sub-second timeout aborts at once under the signal-based DNS resolver.
                CURLOPT_NOSIGNAL => true,
            ];
            if ($method === 'HEAD') {
                // Without NOBODY cURL waits for a body a HEAD response never has, until timeout.
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                $options[CURLOPT_NOBODY] = true;
            } elseif (!in_array($method, self::NATIVE_METHODS, true)) {
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
                // Read before close(): closing drops the handle and with it the error state.
                $errno = (int)$curl->getErrno();
                $error = (string)$curl->getError();
                $pretransfer = (float)$curl->getInfo(CURLINFO_PRETRANSFER_TIME);
                $notSent = in_array($errno, self::NOT_SENT_ERRNOS, true)
                    || ($errno === self::CURLE_OPERATION_TIMEDOUT && $pretransfer == 0.0);
                throw new TransportException(
                    sprintf('Typesense request to %s failed: %s', $url, $error ?: 'no response'),
                    0,
                    [],
                    null,
                    $errno,
                    !$notSent
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
    private function flattenHeaders(#[\SensitiveParameter] array $headers): array
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
