<?php

declare(strict_types=1);

use Aclips\Fakesign\Service\PublicUrl;
use Aclips\Fakesign\Service\ServiceAddressOverride;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\UrlRewriter;

Loc::loadMessages(__FILE__);

class aclips_fakesign extends CModule
{
    public $MODULE_ID = 'aclips.fakesign';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('ACLIPS_FAKESIGN_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('ACLIPS_FAKESIGN_DESCRIPTION');
        $this->PARTNER_NAME = 'Aclips';
        $this->PARTNER_URI = 'https://aclips.ru';
    }

    /**
     * Устанавливает модуль: таблицы, опции, подмена адреса службы, файлы и роутинг,
     * регистрация модуля (первой - чтобы дальше был доступен неймспейс).
     */
    public function DoInstall(): void
    {
        if (!$GLOBALS['USER']->IsAdmin() || !$this->InstallDB()) {
            return;
        }

        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);

        $this->setDefaultOptions();

        if (Option::get($this->MODULE_ID, 'public_base_url') === '') {
            $this->detectBaseUrl();
        }

        $this->writeAddressOverrideBestEffort();

        $this->InstallFiles();
    }

    /**
     * Удаление в два шага (паттерн catalog::DoUnInstall): форма «Сохранять таблицы?»
     * и выполнение. IncludeAdminFile завершает запрос die() - шаги разведены по
     * глобальному $step из module_admin.php.
     */
    public function DoUninstall(): void
    {
        global $APPLICATION, $step;

        if (!$GLOBALS['USER']->IsAdmin()) {
            return;
        }

        Loader::includeModule($this->MODULE_ID);

        $step = max((int)$step, (int)($_REQUEST['step'] ?? 0));

        if ($step < 2) {
            $APPLICATION->IncludeAdminFile(
                Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_TITLE'),
                $_SERVER['DOCUMENT_ROOT'] . '/local/modules/aclips.fakesign/install/unstep1.php'
            );
        }

        $keepData = (string)($_REQUEST['savedata'] ?? '') === 'Y';

        (new ServiceAddressOverride())->remove();
        $this->UnInstallFiles();

        if ($keepData) {
            ModuleManager::unRegisterModule($this->MODULE_ID);
        } else {
            $this->UnInstallDB();
        }

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_TITLE'),
            $_SERVER['DOCUMENT_ROOT'] . '/local/modules/aclips.fakesign/install/unstep2.php'
        );
    }

    /**
     * Публичный адрес по умолчанию: домен берётся из настроек (опция sign.current_domain),
     * схема - из текущего запроса. Явная опция aclips.fakesign.public_base_url имеет приоритет.
     */
    private function detectBaseUrl(): string
    {
        $host = Option::get('sign', 'current_domain', '');
        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        }
        if ($host === '') {
            return '';
        }

        $baseUrl = (PublicUrl::isSecureRequest($_SERVER) ? 'https://' : 'http://') . $host;

        if (Option::get($this->MODULE_ID, 'public_base_url') === '') {
            Option::set($this->MODULE_ID, 'public_base_url', $baseUrl);
        }

        return $baseUrl;
    }

    /**
     * Best-effort: сбой записи подмены не должен блокировать установку самого модуля
     * (причины - права/конфиг; проверяется потом tools/check-isolation.php).
     */
    private function writeAddressOverrideBestEffort(): void
    {
        $baseUrl = Option::get($this->MODULE_ID, 'public_base_url');
        if ($baseUrl === '') {
            return;
        }

        try {
            (new ServiceAddressOverride())->write($baseUrl);
        } catch (\Throwable $e) {
            error_log('aclips.fakesign: не удалось записать подмену адреса: ' . $e->getMessage());
        }
    }

    /**
     * Публичная точка входа: копируем контроллер в docroot/fakesign/index.php
     * (nginx закрывает local/modules - deny all) и регистрируем правило роутинга
     * штатным API (см. calendar/call install).
     */
    /**
     * Копирует точку входа в docroot/fakesign и регистрирует правило роутинга.
     */
    public function InstallFiles(): void
    {
        $this->copyEntryPoint();

        $siteId = \CSite::GetDefSite();
        if (!$siteId) {
            return;
        }

        UrlRewriter::delete($siteId, ['ID' => $this->MODULE_ID]);
        UrlRewriter::add($siteId, [
            'CONDITION' => '#^/*fakesign/(?<action>[a-zA-Z0-9._/-]+)#',
            'RULE' => 'action=$1&',
            'ID' => $this->MODULE_ID,
            'PATH' => '/fakesign/index.php',
            'SORT' => 100,
        ]);
    }

    /**
     * Удаляет правило роутинга и точку входа из docroot/fakesign.
     */
    public function UnInstallFiles(): void
    {
        $siteId = \CSite::GetDefSite();
        if ($siteId) {
            UrlRewriter::delete($siteId, ['ID' => $this->MODULE_ID]);
        }

        $entryPoint = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/fakesign/index.php';
        if (is_file($entryPoint)) {
            @unlink($entryPoint);
        }
        $dir = dirname($entryPoint);
        if (is_dir($dir) && count((array)scandir($dir)) === 2) // . и ..
        {
            @rmdir($dir);
        }
    }

    private function copyEntryPoint(): void
    {
        $source = __DIR__ . '/../public/index.php';
        $targetDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/fakesign';

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
            $GLOBALS['APPLICATION']->ThrowException(
                'aclips.fakesign: не удалось создать каталог ' . $targetDir
            );

            return;
        }

        if (!@copy($source, $targetDir . '/index.php')) {
            $GLOBALS['APPLICATION']->ThrowException(
                'aclips.fakesign: не удалось скопировать точку входа в ' . $targetDir
            );
        }
    }

    /**
     * Выполняет установочные миграции (таблицы и связанные объекты).
     *
     * @return bool false - миграция не прошла, ошибка показана администратору
     */
    public function InstallDB(): bool
    {
        $result = $this->installMigrations();
        if (!$result->isSuccess()) {
            $GLOBALS['APPLICATION']->ThrowException(implode('<br>', $result->getErrorMessages()));
            return false;
        }

        return true;
    }

    /**
     * Выполняет обратные миграции, удаляет опции и разрегистрирует модуль.
     *
     * @param bool $dropTables false - оставить таблицы данных
     * @return bool false - миграция не прошла, ошибка показана администратору
     */
    public function UnInstallDB(bool $dropTables = true): bool
    {
        $result = $this->uninstallMigrations($dropTables);
        if (!$result->isSuccess()) {
            $GLOBALS['APPLICATION']->ThrowException(implode('<br>', $result->getErrorMessages()));
            return false;
        }
        Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);

        return true;
    }

    /**
     * Опции с дефолтами; повторная установка не затирает уже выставленные значения.
     * Ядро выполняет только tables/events/agents миграции, поэтому опции здесь.
     */
    private function setDefaultOptions(): void
    {
        $defaults = [
            'public_base_url' => '',
            'use_cloud_converter' => 'Y',
        ];

        foreach ($defaults as $name => $value) {
            if (Option::get($this->MODULE_ID, $name) === '') {
                Option::set($this->MODULE_ID, $name, $value);
            }
        }
    }
}
