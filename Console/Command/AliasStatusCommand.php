<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Console\Command;

use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Every alias and the collection behind it.
 *
 * The one view that shows what a consumer's stable name actually resolves to after a swap.
 */
class AliasStatusCommand extends Command
{
    /**
     * @param AliasManager $aliases
     * @param OrphanFinder $orphans
     * @param string|null $name
     */
    public function __construct(
        private readonly AliasManager $aliases,
        private readonly OrphanFinder $orphans,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('dmlab:typesense:alias:status');
        $this->setDescription('List Typesense aliases and their target collections');

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $aliases = $this->aliases->getList();
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($aliases === []) {
            $output->writeln('<comment>No aliases defined.</comment>');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($aliases as $alias => $collection) {
            $rows[] = [$alias, $collection];
        }

        $table = new Table($output);
        $table->setHeaders(['Alias', 'Collection']);
        $table->setRows($rows);
        $table->render();

        $this->reportOrphans($output);

        return Command::SUCCESS;
    }

    /**
     * Hint at leftover rebuilds; a failure here is not the command's verdict.
     *
     * @param OutputInterface $output
     */
    private function reportOrphans(OutputInterface $output): void
    {
        try {
            $count = count($this->orphans->find(0));
        } catch (\Throwable) {
            return;
        }

        if ($count > 0) {
            $output->writeln(
                '<comment>Unaliased versioned collections: ' . $count
                . ' (see dmlab:typesense:collections:prune)</comment>'
            );
        }
    }
}
