<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Cli\Command;

use Aclips\Fakesign\Service\JournalExporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Выводит последние записи журнала обращений к API тестового провайдера
 * в формате JSON.
 */
final class JournalCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('aclips.fakesign:journal')
            ->setDescription('Журнал обращений к API тестового провайдера (JSON)')
            ->addArgument('limit', InputArgument::OPTIONAL, 'число записей (1-500)', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = min(500, max(1, (int)$input->getArgument('limit')));

        $output->writeln(json_encode(
            ['rows' => JournalExporter::latest($limit)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return Command::SUCCESS;
    }
}
