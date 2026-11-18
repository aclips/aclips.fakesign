<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService;

use Bitrix\Main\Config\Option;

/**
 * Синтез описания тестового провайдера - общий источник для company.get (providers[])
 * и external-sign-provider.list. Запись статична: URI собираются из актуального
 * опции public_base_url, отдельная регистрация/реестр не нужны.
 */
final class ProviderDescriptor
{
    /**
     * Контракт портала: uid провайдера кладётся в b_sign_document.company_uid
     * (varchar(32), валидация "Минимальная длина: 32"). Формат - как у реальной
     * службы: 32-символьный hex.
     */
    public const PROVIDER_UID = '3f6c1d9a8b47e250c1d7f3a96be48210';

    public const PROVIDER_TITLE = 'Тестовый провайдер';

    /**
     * Возвращает стабильный uid провайдера (32 символа - контракт company_uid портала).
     */
    public static function uid(): string
    {
        return self::PROVIDER_UID;
    }

    /**
     * Публичный адрес тестового сервиса (опция public_base_url).
     */
    public static function baseUri(): string
    {
        $base = Option::get('aclips.fakesign', 'public_base_url');

        return $base !== '' ? rtrim($base, '/') : 'http://localhost';
    }

    /**
     * Возвращает название тестового провайдера.
     */
    public static function title(): string
    {
        return self::PROVIDER_TITLE;
    }

    /**
     * Форма записи совпадает с ответом реальной службы
     * (внешний провайдер из external-sign-provider.list).
     *
     * @return array<string, string>
     */
    public static function record(): array
    {
        $base = self::baseUri();

        return [
            'id' => self::uid(),
            'title' => self::title(),
            'description' => 'Тестовый провайдер: подписание эмулируется, подпись не является электронной. ТЕСТ.',
            'iconUri' => $base . '/fakesign/icon/',
            'companyRegUri' => $base . '/fakesign/register/',
            'documentSignUri' => $base . '/fakesign/sign/',
            'publicKeyUri' => $base . '/fakesign/public-key/',
        ];
    }
}
