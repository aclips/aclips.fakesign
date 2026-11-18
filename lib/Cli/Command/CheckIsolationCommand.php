<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Cli\Command;

use Aclips\Fakesign\Service\IsolationCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Проверяет изоляцию от облачной службы sign.bitrix24.ru: адрес подмены записан,
 * ни один регион не указывает в облако, фейк-сервис отвечает.
 */
final class CheckIsolationCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('aclips.fakesign:check-isolation')
            ->setDescription('Проверка изоляции от облачной службы sign.bitrix24.ru (JSON)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = IsolationCheck::run();

        $output->writeln(json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }
}
