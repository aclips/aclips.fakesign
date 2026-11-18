<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Cli\Command;

use Aclips\Fakesign\Service\ModuleStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Показывает состояние тестового провайдера в формате JSON: настройки модуля,
 * endpoint службы и файл подмены адреса.
 */
final class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('aclips.fakesign:status')
            ->setDescription('Состояние тестового провайдера aclips.fakesign (JSON)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode(
            ModuleStatus::collect(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return Command::SUCCESS;
    }
}
