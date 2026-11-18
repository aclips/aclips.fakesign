<?php

declare(strict_types=1);

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Service\ApiService;


/**
 * Проверка изоляции от облачной службы sign.bitrix24.ru: адрес подмены записан,
 * ни один регион не указывает в облако, фейк-сервис отвечает.
 *
 * Запуск из каталога Bitrix: php local/modules/aclips.fakesign/tools/check-isolation.php
 * Код выхода 0 - изоляция цела; 1 - есть обращения/адреса облачной службы.
 */

$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');
$_SERVER['REQUEST_METHOD'] = 'GET';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include.php';

$out = ['checks' => [], 'ok' => true];

if (!Loader::includeModule('sign'))
{
    $out['ok'] = false;
    $out['checks'][] = ['name' => 'module_sign', 'result' => 'FAIL: модуль sign не установлен'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(1);
}

// 1) Адрес подменён и не указывает на облачную службу
$serviceAddress = Storage::instance()->getApiEndpoint();
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

// 2) Глобальный override (.settings_extra.php) перекрывает облачные дефолты модуля:
//    проверяем ЕГО, а не исходный модульный .settings.php (в нём облачные адреса - это норма).
$globalAddresses = (array)(Configuration::getValue('sign')['service.address'] ?? []);
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
$out['checks'][] = ['name' => 'global_override_regions', 'result' => count($globalAddresses) . ' регионов перекрыто'];

// 3) Конфиг-файл подмены существует
$extraFile = $_SERVER['DOCUMENT_ROOT'] . '/local/.settings_extra.php';
$out['checks'][] = [
    'name' => 'settings_extra',
    'result' => is_file($extraFile) ? 'ok' : 'FAIL: /local/.settings_extra.php отсутствует',
];
if (!is_file($extraFile))
{
    $out['ok'] = false;
}

// 4) Фейк-сервис отвечает
$health = (new ApiService((string)$serviceAddress))
    ->get('v1/b2e.company.provider.get?companyUid=isolation-check');
$out['checks'][] = [
    'name' => 'fake_responds',
    'result' => $health->isSuccess() ? 'ok' : 'FAIL: ' . implode('; ', $health->getErrorMessages()),
];
if (!$health->isSuccess())
{
    $out['ok'] = false;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

exit($out['ok'] ? 0 : 1);
