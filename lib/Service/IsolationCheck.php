<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Service\ApiService;

/**
 * Проверка изоляции тестового провайдера от облачной службы sign.bitrix24.ru.
 *
 * Сбор фактов окружения (run) отделён от чистой оценки (evaluate), чтобы
 * логику проверок можно было тестировать без конфига и сети.
 */
final class IsolationCheck
{
    /**
     * Собирает факты окружения (модуль sign, endpoint, override, ответ фейка)
     * и оценивает изоляцию.
     *
     * @return array{checks: list<array<string, string>>, ok: bool}
     */
    public static function run(): array
    {
        $state = ['signInstalled' => Loader::includeModule('sign')];
        if (!$state['signInstalled'])
        {
            return self::evaluate($state);
        }

        try
        {
            $state['apiEndpoint'] = Storage::instance()->getApiEndpoint();
        }
        catch (\Throwable $e)
        {
            $state['apiEndpoint'] = 'ошибка: ' . $e->getMessage();
        }

        $state['globalAddresses'] = (array)(Configuration::getValue('sign')['service.address'] ?? []);
        $state['extraFileExists'] = is_file($_SERVER['DOCUMENT_ROOT'] . '/local/.settings_extra.php');

        $health = (new ApiService((string)$state['apiEndpoint']))
            ->get('v1/b2e.company.provider.get?companyUid=isolation-check');
        $state['fakeResponds'] = $health->isSuccess();
        $state['fakeError'] = implode('; ', $health->getErrorMessages());

        return self::evaluate($state);
    }

    /**
     * Оценивает изоляцию по собранным фактам: ни один адрес не ведёт в облако,
     * файл подмены существует, фейк-сервис отвечает.
     *
     * @param array{
     *     signInstalled?: bool,
     *     apiEndpoint?: string,
     *     globalAddresses?: array<array-key, string>,
     *     extraFileExists?: bool,
     *     fakeResponds?: bool,
     *     fakeError?: string
     * } $state
     *
     * @return array{checks: list<array<string, string>>, ok: bool}
     */
    public static function evaluate(array $state): array
    {
        $out = ['checks' => [], 'ok' => true];

        if (empty($state['signInstalled']))
        {
            $out['ok'] = false;
            $out['checks'][] = ['name' => 'module_sign', 'result' => 'FAIL: модуль sign не установлен'];

            return $out;
        }

        // Адрес подменён и не указывает на облачную службу.
        $serviceAddress = (string)($state['apiEndpoint'] ?? '');
        $out['checks'][] = ['name' => 'apiEndpoint', 'value' => $serviceAddress];
        if (str_contains($serviceAddress, 'sign.bitrix24'))
        {
            $out['ok'] = false;
            $out['checks'][] = ['name' => 'endpoint_is_cloud', 'result' => 'FAIL: адрес ведёт на sign.bitrix24.ru'];
        }
        else
        {
            $out['checks'][] = ['name' => 'endpoint_is_local', 'result' => 'ok'];
        }

        // Глобальный override (.settings_extra.php) перекрывает облачные дефолты
        // модуля sign: проверяем ЕГО, а не исходный модульный .settings.php.
        $globalAddresses = (array)($state['globalAddresses'] ?? []);
        if ($globalAddresses === [])
        {
            $out['ok'] = false;
            $out['checks'][] = [
                'name' => 'global_override',
                'result' => 'FAIL: sign.service.address не задан в /local/.settings_extra.php',
            ];
        }
        foreach ($globalAddresses as $region => $url)
        {
            if (str_contains((string)$url, 'sign.bitrix24'))
            {
                $out['ok'] = false;
                $out['checks'][] = ['name' => 'region_' . $region, 'result' => 'FAIL: ' . $url];
            }
        }
        $out['checks'][] = [
            'name' => 'global_override_regions',
            'result' => count($globalAddresses) . ' регионов перекрыто',
        ];

        // Конфиг-файл подмены существует.
        $out['checks'][] = [
            'name' => 'settings_extra',
            'result' => !empty($state['extraFileExists']) ? 'ok' : 'FAIL: /local/.settings_extra.php отсутствует',
        ];
        if (empty($state['extraFileExists']))
        {
            $out['ok'] = false;
        }

        // Фейк-сервис отвечает.
        $out['checks'][] = [
            'name' => 'fake_responds',
            'result' => !empty($state['fakeResponds']) ? 'ok' : 'FAIL: ' . ($state['fakeError'] ?? 'нет ответа'),
        ];
        if (empty($state['fakeResponds']))
        {
            $out['ok'] = false;
        }

        return $out;
    }
}
