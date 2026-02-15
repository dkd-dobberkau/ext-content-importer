<?php

declare(strict_types=1);

namespace Dkd\ContentImporter\Command;

use Dkd\ContentImporter\Parser\MarkdownPageParser;
use Dkd\ContentImporter\Service\PageImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'content:import',
    description: 'Import Markdown content files as TYPO3 pages and content elements',
)]
class ImportCommand extends Command
{
    public function __construct(
        private readonly MarkdownPageParser $parser,
        private readonly PageImportService $importService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'path',
                InputArgument::REQUIRED,
                'Path to directory containing Markdown files'
            )
            ->addOption(
                'pid',
                'p',
                InputOption::VALUE_REQUIRED,
                'Root page UID under which pages are created',
                '1'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $input->getArgument('path');
        $pid = (int)$input->getOption('pid');

        if (!is_dir($path)) {
            $io->error('Directory not found: ' . $path);
            return Command::FAILURE;
        }

        $io->title('TYPO3 Content Importer');
        $io->text('Importing from: ' . realpath($path));
        $io->text('Root page PID: ' . $pid);
        $io->newLine();

        $parsedPages = $this->parser->parseDirectory($path);

        if ($parsedPages === []) {
            $io->warning('No Markdown files found in: ' . $path);
            return Command::FAILURE;
        }

        $io->text(sprintf('Found %d pages to import.', count($parsedPages)));
        $io->newLine();

        $imported = $this->importService->importAll($parsedPages, $pid);

        foreach ($imported as $i => $title) {
            $io->text(sprintf('[%d/%d] %s ✓', $i + 1, count($imported), $title));
        }

        $io->newLine();
        $io->success(sprintf('%d pages imported successfully.', count($imported)));

        return Command::SUCCESS;
    }
}
