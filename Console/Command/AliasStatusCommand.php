<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Console\Command;

use DmLab\TypesenseCore\Model\Collection\AliasManager;
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
     * @param string|null $name
     */
    public function __construct(
        private readonly AliasManager $aliases,
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

        return Command::SUCCESS;
    }
}
