<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Console\Command;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;
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
            $apiKey = $this->settings->getApiKey();
        } catch (ConfigurationException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $down = 0;
        foreach ($nodes as $node) {
            if (!$this->reportNode($node, $apiKey, $output)) {
                $down++;
            }
        }

        return $down === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Print one node's line and say whether it is up.
     *
     * @param Node $node
     * @param string $apiKey
     * @param OutputInterface $output
     */
    private function reportNode(Node $node, string $apiKey, OutputInterface $output): bool
    {
        $baseUrl = $node->getBaseUrl();

        try {
            $health = $this->get($baseUrl . '/health', $apiKey);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>%s DOWN: %s</error>', $baseUrl, $e->getMessage()));

            return false;
        }

        if (($health['ok'] ?? false) !== true) {
            $output->writeln(sprintf('<error>%s UNHEALTHY: %s</error>', $baseUrl, json_encode($health)));

            return false;
        }

        $output->writeln(sprintf('<info>%s OK</info> (version %s)', $baseUrl, $this->readVersion($baseUrl, $apiKey)));

        return true;
    }

    /**
     * The node's version, or `unknown` when `/debug` does not say.
     *
     * A node that answers `/health` but not `/debug` is still up: the version is decoration.
     *
     * @param string $baseUrl
     * @param string $apiKey
     */
    private function readVersion(string $baseUrl, string $apiKey): string
    {
        try {
            $debug = $this->get($baseUrl . '/debug', $apiKey);
        } catch (\Throwable $e) {
            return 'unknown';
        }

        $version = $debug['version'] ?? null;

        return is_string($version) && $version !== '' ? $version : 'unknown';
    }

    /**
     * One authenticated GET against one node, decoded.
     *
     * @param string $url
     * @param string $apiKey
     * @return array<mixed>
     * @throws \RuntimeException on a non-2xx or an undecodable body
     */
    private function get(string $url, string $apiKey): array
    {
        $response = $this->transport->send(
            'GET',
            $url,
            ['X-TYPESENSE-API-KEY' => $apiKey, 'Accept' => 'application/json'],
            null,
            self::TIMEOUT
        );

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
