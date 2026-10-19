<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseCore\Console\Command;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drop versioned collections no alias points to; each one holds its index in RAM.
 */
class PruneCollectionsCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';
    private const OPTION_MIN_AGE = 'min-age';
    private const DEFAULT_MIN_AGE = 86400;

    /**
     * @param OrphanFinder $orphans
     * @param string|null $name
     */
    public function __construct(
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
        $this->setName('dmlab:typesense:collections:prune');
        $this->setDescription('Drop versioned Typesense collections that no alias points to');
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'List orphans without dropping them');
        $this->addOption(
            self::OPTION_MIN_AGE,
            null,
            InputOption::VALUE_REQUIRED,
            'Skip collections younger than this many seconds (a running rebuild is unaliased until its swap)',
            (string)self::DEFAULT_MIN_AGE
        );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $minAge = (string)$input->getOption(self::OPTION_MIN_AGE);
        if (!ctype_digit($minAge)) {
            $output->writeln('<error>--min-age must be a non-negative integer of seconds.</error>');

            return Command::FAILURE;
        }

        try {
            $orphans = $this->orphans->find((int)$minAge);
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        if ($orphans === []) {
            $output->writeln('<comment>No unaliased versioned collections.</comment>');

            return Command::SUCCESS;
        }

        if ($input->getOption(self::OPTION_DRY_RUN)) {
            foreach ($orphans as $name) {
                $output->writeln('Would drop ' . $name);
            }

            return Command::SUCCESS;
        }

        $failed = false;
        foreach ($orphans as $name) {
            try {
                if (!$this->orphans->drop($name)) {
                    $output->writeln('Skipped ' . $name . ': an alias points to it now');
                    continue;
                }
            } catch (TypesenseException $e) {
                if ($e->getStatusCode() !== 404) {
                    $output->writeln('<error>Failed to drop ' . $name . ': ' . $e->getMessage() . '</error>');
                    $failed = true;
                    continue;
                }
            }
            $output->writeln('Dropped ' . $name);
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
