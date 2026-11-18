<?php

declare(strict_types=1);

use Aclips\Fakesign\Cli\Command\CheckIsolationCommand;
use Aclips\Fakesign\Cli\Command\JournalCommand;
use Aclips\Fakesign\Cli\Command\StatusCommand;
use Aclips\Fakesign\Service\IsolationCheck;
use Aclips\Fakesign\Service\ModuleStatus;

/**
 * Контракт консольных команд: чистая оценка изоляции IsolationCheck::evaluate,
 * регистрация команд в .settings.php модуля и имена команд.
 */
$checkNamed = static function (array $out, string $name): ?array {
    foreach ($out['checks'] as $check)
    {
        if ($check['name'] === $name)
        {
            return $check;
        }
    }

    return null;
};

$cleanState = static fn(): array => [
    'signInstalled' => true,
    'apiEndpoint' => 'http://rt.localhost/fakesign',
    'globalAddresses' => [
        'ru' => 'http://rt.localhost/fakesign',
        'eu' => 'http://rt.localhost/fakesign',
    ],
    'extraFileExists' => true,
    'fakeResponds' => true,
    'fakeError' => '',
];

return [
    'IsolationCheck: изолированное состояние даёт ok=true' => static function () use ($cleanState, $checkNamed): void {
        $out = IsolationCheck::evaluate($cleanState());

        expect_eq($out['ok'], true, 'изоляция цела');
        expect_eq($checkNamed($out, 'endpoint_is_local')['result'] ?? null, 'ok', 'endpoint локальный');
        expect_eq($checkNamed($out, 'fake_responds')['result'] ?? null, 'ok', 'фейк отвечает');
        expect_eq($checkNamed($out, 'settings_extra')['result'] ?? null, 'ok', 'файл подмены есть');
    },

    'IsolationCheck: облачный endpoint проваливает изоляцию' => static function () use ($cleanState, $checkNamed): void {
        $state = $cleanState();
        $state['apiEndpoint'] = 'https://sign.bitrix24.ru/api/rest';

        $out = IsolationCheck::evaluate($state);

        expect_eq($out['ok'], false, 'облачный endpoint - провал');
        expect_eq(
            $checkNamed($out, 'endpoint_is_cloud')['result'] ?? null,
            'FAIL: адрес ведёт на sign.bitrix24.ru',
            'проверка endpoint_is_cloud'
        );
    },

    'IsolationCheck: облачный регион проваливает изоляцию' => static function () use ($cleanState, $checkNamed): void {
        $state = $cleanState();
        $state['globalAddresses']['eu'] = 'https://sign.bitrix24.ru/api/rest';

        $out = IsolationCheck::evaluate($state);

        expect_eq($out['ok'], false, 'облачный регион - провал');
        expect_eq(
            $checkNamed($out, 'region_eu')['result'] ?? null,
            'FAIL: https://sign.bitrix24.ru/api/rest',
            'проверка region_eu'
        );
    },

    'IsolationCheck: пустой override проваливает изоляцию' => static function () use ($cleanState, $checkNamed): void {
        $state = $cleanState();
        $state['globalAddresses'] = [];

        $out = IsolationCheck::evaluate($state);

        expect_eq($out['ok'], false, 'пустой override - провал');
        expect_true(
            str_starts_with((string)($checkNamed($out, 'global_override')['result'] ?? ''), 'FAIL:'),
            'проверка global_override'
        );
    },

    'IsolationCheck: отсутствующий файл подмены проваливает изоляцию' => static function () use ($cleanState, $checkNamed): void {
        $state = $cleanState();
        $state['extraFileExists'] = false;

        $out = IsolationCheck::evaluate($state);

        expect_eq($out['ok'], false, 'нет файла подмены - провал');
        expect_true(
            str_starts_with((string)($checkNamed($out, 'settings_extra')['result'] ?? ''), 'FAIL:'),
            'проверка settings_extra'
        );
    },

    'IsolationCheck: фейк-сервис без ответа проваливает изоляцию' => static function () use ($cleanState, $checkNamed): void {
        $state = $cleanState();
        $state['fakeResponds'] = false;
        $state['fakeError'] = 'connection refused';

        $out = IsolationCheck::evaluate($state);

        expect_eq($out['ok'], false, 'фейк не отвечает - провал');
        expect_eq(
            $checkNamed($out, 'fake_responds')['result'] ?? null,
            'FAIL: connection refused',
            'проверка fake_responds'
        );
    },

    'IsolationCheck: незагруженный модуль sign проваливает изоляцию' => static function (): void {
        $out = IsolationCheck::evaluate(['signInstalled' => false]);

        expect_eq($out['ok'], false, 'модуль sign не установлен - провал');
        expect_eq(count($out['checks']), 1, 'другие проверки не выполняются');
        expect_eq($out['checks'][0]['name'] ?? null, 'module_sign', 'проверка module_sign');
    },

    'ModuleStatus: collect собирает состояние с рабочим endpoint' => static function (): void {
        $status = ModuleStatus::collect();

        expect_eq($status['module_installed'], true, 'модуль установлен');
        expect_true(is_string($status['public_base_url']) && $status['public_base_url'] !== '', 'public_base_url задан');
        expect_true(
            is_string($status['apiEndpoint'])
            && $status['apiEndpoint'] !== ''
            && !str_starts_with($status['apiEndpoint'], 'ошибка')
            && !str_contains($status['apiEndpoint'], 'sign.bitrix24'),
            'endpoint читается и не облачный'
        );
        expect_true(is_file($status['address_override']['path']), 'путь файла подмены существует');
        expect_eq($status['address_override']['present'], true, 'файл подмены присутствует');
    },

    'модуль регистрирует три консольные команды в .settings.php' => static function (): void {
        $path = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/aclips.fakesign/.settings.php';
        expect_true(is_file($path), 'файл .settings.php модуля существует');

        $settings = include $path;
        $commands = $settings['console']['value']['commands'] ?? [];
        expect_true(is_array($commands), 'секция console.value.commands присутствует');

        foreach ([StatusCommand::class, JournalCommand::class, CheckIsolationCommand::class] as $class)
        {
            expect_true(in_array($class, $commands, true), 'зарегистрирована команда ' . $class);
        }
    },

    'консольные команды имеют ожидаемые имена' => static function (): void {
        if (!class_exists(\Symfony\Component\Console\Command\Command::class))
        {
            // composer install не выполнен - имена команд проверяются только с зависимостями.
            return;
        }

        expect_true(class_exists(StatusCommand::class), 'класс StatusCommand загружается');
        expect_true(class_exists(JournalCommand::class), 'класс JournalCommand загружается');
        expect_true(class_exists(CheckIsolationCommand::class), 'класс CheckIsolationCommand загружается');

        expect_eq((new StatusCommand())->getName(), 'aclips.fakesign:status', 'имя status');
        expect_eq((new JournalCommand())->getName(), 'aclips.fakesign:journal', 'имя journal');
        expect_eq((new CheckIsolationCommand())->getName(), 'aclips.fakesign:check-isolation', 'имя check-isolation');
    },
];
