<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;

/**
 * Собирает состояние тестового провайдера: настройки модуля, endpoint службы
 * и файл подмены адреса (для консольной команды aclips.fakesign:status).
 */
final class ModuleStatus
{
    /**
     * @return array{
     *     module_installed: bool,
     *     public_base_url: string,
     *     apiEndpoint: string|null,
     *     address_override: array{path: string, present: bool}
     * }
     */
    public static function collect(): array
    {
        $override = new ServiceAddressOverride();

        // Storage живёт в модуле sign: подключаем, иначе класс не подхватывается.
        $endpoint = null;
        if (Loader::includeModule('sign'))
        {
            try
            {
                $endpoint = Storage::instance()->getApiEndpoint();
            }
            catch (\Throwable $e)
            {
                $endpoint = 'ошибка: ' . $e->getMessage();
            }
        }
        else
        {
            $endpoint = 'ошибка: модуль sign не установлен';
        }

        return [
            'module_installed' => true,
            'public_base_url' => Option::get('aclips.fakesign', 'public_base_url'),
            'apiEndpoint' => $endpoint,
            'address_override' => [
                'path' => $override->getPath(),
                'present' => is_file($override->getPath()),
            ],
        ];
    }
}
