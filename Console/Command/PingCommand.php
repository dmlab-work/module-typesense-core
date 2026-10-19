<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Console\Command;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Exception\TransportException;
use DmLab\TypesenseCore\Model\Client\Http\Response;
use DmLab\TypesenseCore\Model\Client\Http\TransportInterface;
use DmLab\TypesenseCore\Model\Config\Node;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Health and version of every configured node.
 *
 * Goes through the transport rather than {@see \DmLab\TypesenseCore\Model\Client\TypesenseClient}
 * on purpose: the client fails over and hides which node answered, while a diagnostic must report
 * each node separately — a healthy cluster with one dead node looks fine through the client.
 */
class PingCommand extends Command
{
    /** A diagnostic waits for an answer or calls the node down; it does not sit on the configured timeout. */
    private const TIMEOUT = 3;

    /**
     * @param ConnectionSettingsInterface $settings
     * @param TransportInterface $transport
     * @param string|null $name
     */
    public function __construct(
        private readonly ConnectionSettingsInterface $settings,
        private readonly TransportInterface $transport,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('dmlab:typesense:ping');
        $this->setDescription('Check health and version of every configured Typesense node');

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $nodes = $this->settings->getNodes();
        } catch (ConfigurationException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        try {
            $apiKey = $this->settings->getApiKey();
        } catch (ConfigurationException $e) {
            // `/health` needs no key, so a missing key still leaves the nodes worth probing.
            $apiKey = null;
        }

        $ok = true;
        foreach ($nodes as $node) {
            if (!$this->reportNode($node, $apiKey, $output)) {
                $ok = false;
            }
        }

        if ($apiKey === null) {
            $output->writeln('<error>API key not configured — health only</error>');
            $ok = false;
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Print one node's line and say whether it is up and, when a key is set, accepts it.
     *
     * @param Node $node
     * @param string|null $apiKey null when not configured: health only
     * @param OutputInterface $output
     */
    private function reportNode(Node $node, #[\SensitiveParameter] ?string $apiKey, OutputInterface $output): bool
    {
        $baseUrl = $node->getBaseUrl();

        try {
            // Sent without the key: `/health` ignores it, so a wrong key must not read as a dead node.
            $health = $this->decode($this->send($baseUrl . '/health', null));
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s DOWN: %s</error>', $baseUrl, $e->getMessage()));

            return false;
        }

        if (($health['ok'] ?? false) !== true) {
            $output->writeln(sprintf('<error>%s UNHEALTHY: %s</error>', $baseUrl, json_encode($health)));

            return false;
        }

        if ($apiKey === null) {
            $output->writeln(sprintf('<info>%s OK</info>', $baseUrl));

            return true;
        }

        return $this->reportKey($baseUrl, $apiKey, $output);
    }

    /**
     * Check the key against a healthy node's `/debug` and print the node's line.
     *
     * Only 401/403 fail the node: any other `/debug` failure leaves it up with an unknown version.
     *
     * @param string $baseUrl
     * @param string $apiKey
     * @param OutputInterface $output
     */
    private function reportKey(string $baseUrl, #[\SensitiveParameter] string $apiKey, OutputInterface $output): bool
    {
        $version = 'unknown';
        try {
            $response = $this->send($baseUrl . '/debug', $apiKey);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                $output->writeln(sprintf('<error>%s KEY REJECTED (HTTP %d)</error>', $baseUrl, $status));

                return false;
            }

            $reported = $this->decode($response)['version'] ?? null;
            if (is_string($reported) && $reported !== '') {
                $version = $reported;
            }
        } catch (\Throwable $e) {
            $version = 'unknown';
        }

        $output->writeln(sprintf('<info>%s OK</info> (version %s)', $baseUrl, $version));

        return true;
    }

    /**
     * One GET against one node, with the key header only when a key is given.
     *
     * @param string $url
     * @param string|null $apiKey
     * @throws TransportException
     */
    private function send(string $url, #[\SensitiveParameter] ?string $apiKey): Response
    {
        $headers = ['Accept' => 'application/json'];
        if ($apiKey !== null) {
            $headers['X-TYPESENSE-API-KEY'] = $apiKey;
        }

        return $this->transport->send('GET', $url, $headers, null, self::TIMEOUT);
    }

    /**
     * The decoded body of a 2xx response.
     *
     * @param Response $response
     * @return array<mixed>
     * @throws \RuntimeException on a non-2xx or an undecodable body
     */
    private function decode(Response $response): array
    {
        if (!$response->isSuccessful()) {
            throw new \RuntimeException('HTTP ' . $response->getStatusCode() . ' ' . trim($response->getBody()));
        }

        $decoded = json_decode($response->getBody(), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('undecodable response body');
        }

        return $decoded;
    }
}
