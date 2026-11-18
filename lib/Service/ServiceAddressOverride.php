<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main;
use Bitrix\Main\Config\Configuration;

/**
 * Владелец подмены адреса Sign Service.
 *
 * Ядро Bitrix читает `.settings_extra.php` ТОЛЬКО из `/local/` (фолбэк `/bitrix/`) -
 * Loader::getLocal захардкожен, конфиг из каталога модуля ядром не читается.
 * Поэтому шаблон и логика живут здесь, в модуле, а сам файл materialизуется
 * по ядровому пути. Адрес берётся из опции модуля `public_base_url`,
 * регионы - из штатного конфига модуля sign (не перекрытого подменой).
 */
final class ServiceAddressOverride
{
    private const SERVICE_SUBPATH = '/fakesign';

    /** Маркер нашего файла: по нему отличаем свою подмену от чужого конфига. */
    private const MARKER = 'Подмена адреса Sign Service на тестовый провайдер aclips.fakesign.';

    private readonly string $targetPath;

    public function __construct(?string $targetPath = null)
    {
        $this->targetPath = $targetPath
            ?: rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/local/.settings_extra.php';
    }

    /**
     * Записывает подмену адреса (все регионы sign.service.address -> $baseUrl/fakesign),
     * сохраняя чужие ключи существующего файла; файл до нашей подмены сохраняется
     * в резервную копию (.bak) для восстановления при удалении модуля.
     *
     * @throws Main\RuntimeException неизвестны регионы sign или не удалось записать файл
     */
    public function write(string $baseUrl): void
    {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '')
        {
            throw new Main\RuntimeException('aclips.fakesign: пустой базовый адрес для подмены');
        }

        $regions = (array)Configuration::getInstance('sign')->get('service.address');
        if ($regions === [])
        {
            throw new Main\RuntimeException(
                'aclips.fakesign: не удалось получить регионы sign.service.address из конфига sign'
            );
        }

        // Чужой файл до нашей подмены: сохраняем резервную копию (один раз), чтобы
        // при удалении модуля восстановить исходные настройки портала.
        if (is_file($this->targetPath))
        {
            $raw = (string)@file_get_contents($this->targetPath);
            $backupPath = $this->targetPath . '.bak';
            if (!str_contains($raw, self::MARKER) && !is_file($backupPath))
            {
                @copy($this->targetPath, $backupPath);
            }
        }

        $data = $this->readArray($this->targetPath) ?? [];

        // Слияние: заменяем только sign.value.service.address, чужие ключи сохраняем.
        $sign = is_array($data['sign'] ?? null) ? $data['sign'] : [];
        $signValue = is_array($sign['value'] ?? null) ? $sign['value'] : [];
        $signValue['service.address'] = array_fill_keys(array_keys($regions), $baseUrl . self::SERVICE_SUBPATH);
        $sign['value'] = $signValue;
        if (!isset($sign['readonly']))
        {
            $sign['readonly'] = false;
        }
        $data['sign'] = $sign;

        $content = "<?php\n\n"
            . '// ' . self::MARKER . "\n"
            . "// Файл сгенерирован модулем aclips.fakesign из настройки public_base_url;\n"
            . "// измените адрес в Настройки -> Модули -> aclips.fakesign, либо удалите файл,\n"
            . "// чтобы вернуться к штатной облачной службе.\n"
            . 'return ' . var_export($data, true) . ";\n";

        $dir = dirname($this->targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true))
        {
            throw new Main\RuntimeException('aclips.fakesign: не удалось создать каталог ' . $dir);
        }

        if (@file_put_contents($this->targetPath, $content) === false)
        {
            throw new Main\RuntimeException('aclips.fakesign: не удалось записать ' . $this->targetPath);
        }
    }

    /**
     * Убирает подмену адреса: восстанавливает резервную копию чужого файла, а для
     * своего файла вырезает только sign.value.service.address (пустой файл удаляется).
     *
     * @return bool true - подмена убрана, false - убирать нечего (нет файла или это чужой конфиг)
     */
    public function remove(): bool
    {
        $backupPath = $this->targetPath . '.bak';

        // Есть копия чужого файла до нашей подмены - восстанавливаем исходное.
        if (is_file($backupPath))
        {
            if (is_file($this->targetPath))
            {
                @unlink($this->targetPath);
            }

            return @rename($backupPath, $this->targetPath);
        }

        if (!is_file($this->targetPath))
        {
            return false;
        }

        $raw = (string)@file_get_contents($this->targetPath);
        if (!str_contains($raw, self::MARKER))
        {
            // Чужой файл без нашей подмены - не трогаем.
            return false;
        }

        $data = $this->readArray($this->targetPath);
        if ($data === null)
        {
            // Нечитаемый файл с нашим маркером - удаляем целиком.
            return @unlink($this->targetPath);
        }

        // Вырезаем только наш ключ; чужие секции и ключи остаются.
        unset($data['sign']['value']['service.address']);
        if (isset($data['sign']['value']) && $data['sign']['value'] === [])
        {
            unset($data['sign']['value']);
        }
        if (isset($data['sign']) && !isset($data['sign']['value']))
        {
            unset($data['sign']);
        }

        if ($data === [])
        {
            return @unlink($this->targetPath);
        }

        $content = "<?php\n\nreturn " . var_export($data, true) . ";\n";

        return @file_put_contents($this->targetPath, $content) !== false;
    }

    /**
     * Читает массив из PHP-файла конфигурации; null - файла нет или он не читается.
     */
    private function readArray(string $path): ?array
    {
        if (!is_file($path))
        {
            return null;
        }

        try
        {
            $data = @include $path;
        }
        catch (\Throwable)
        {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Возвращает путь к файлу подмены адреса.
     */
    public function getPath(): string
    {
        return $this->targetPath;
    }
}
