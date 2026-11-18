<?php

declare(strict_types=1);

/**
 * CLI-диагностика тестового провайдера.
 *
 * Использование:
 *   php local/modules/aclips.fakesign/tools/register-provider.php status
 *   php local/modules/aclips.fakesign/tools/register-provider.php journal [limit]
 */

// Безусловно: окружение CLI может завести DOCUMENT_ROOT пустой строкой,
// а ??= в этом случае присвоение не выполняет.
$_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../../..');

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include.php';

if (!Loader::includeModule('aclips.fakesign'))
{
    fwrite(STDERR, "модуль aclips.fakesign не установлен\n");
    exit(1);
}

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;
use Aclips\Fakesign\Service\JournalExporter;
use Aclips\Fakesign\Service\ServiceAddressOverride;

// Для поля apiEndpoint (Storage живёт в модуле sign).
Loader::includeModule('sign');

$command = $argv[1] ?? 'status';

switch ($command)
{
    case 'journal':
        $limit = min(500, max(1, (int)($argv[2] ?? 25)));
        echo json_encode(
            ['rows' => JournalExporter::latest($limit)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ), "\n";
        break;

    case 'status':
        $override = new ServiceAddressOverride();
        $endpoint = null;
        try
        {
            $endpoint = Storage::instance()->getApiEndpoint();
        }
        catch (\Throwable $e)
        {
            $endpoint = 'ошибка: ' . $e->getMessage();
        }

        echo json_encode(
            [
                'module_installed' => true,
                'public_base_url' => Option::get('aclips.fakesign', 'public_base_url'),
                'apiEndpoint' => $endpoint,
                'address_override' => [
                    'path' => $override->getPath(),
                    'present' => is_file($override->getPath()),
                ],
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ), "\n";
        break;

    default:
        fwrite(STDERR, "неизвестная команда: {$command}\nдоступны: status, journal [limit]\n");
        exit(1);
}
