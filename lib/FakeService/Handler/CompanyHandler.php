<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService\Handler;

use Aclips\Fakesign\FakeService\Envelope;
use Aclips\Fakesign\FakeService\ProviderDescriptor;
use Bitrix\Main\Config\Option;

/**
 * Хендлеры компания/провайдер для портальных вызовов (контракт - потребители в
 * модуле sign: Service\Api\B2e\CompanyService::get/registerByClient/delete,
 * Service\Api\B2e\ProviderCodeService, Operation\GetRegisteredCompanies).
 */
final class CompanyHandler
{
    /**
     * POST v1/b2e.company.get: data['companies'], индексируется портальным кодом по taxId.
     * Наш провайдер сразу connected - облачное окно регистрации (register_url) не нужно.
     */
    public static function companyGet(string $path, string $method, array $query, string $body): array
    {
        $request = json_decode($body, true) ?: [];
        $taxIds = (array)($request['taxIds'] ?? []);

        $companies = [];
        foreach ($taxIds as $taxId) {
            $companies[] = [
                'taxId' => (string)$taxId,
                'providers' => [
                    [
                        'code' => 'external',
                        'uid' => ProviderDescriptor::uid(),
                        'connected' => true,
                        'blocked' => false,
                        'virtual' => false,
                        'autoRegister' => false,
                        'expires' => null,
                        'name' => ProviderDescriptor::title(),
                        'iconUrl' => ProviderDescriptor::baseUri() . '/fakesign/icon/',
                        'date' => self::connectedAt(),
                    ],
                ],
            ];
        }

        return ['status' => 200, 'body' => Envelope::success(['companies' => $companies])];
    }

    /**
     * Момент подключения провайдера (поле date карточки "Подключено").
     * Ставится один раз лениво - стабильное значение, а не текущее время.
     */
    private static function connectedAt(): int
    {
        $timestamp = (int)Option::get('aclips.fakesign', 'provider_connected_at', '0');
        if ($timestamp <= 0) {
            $timestamp = time();
            Option::set('aclips.fakesign', 'provider_connected_at', (string)$timestamp);
        }

        return $timestamp;
    }

    /**
     * GET v1/b2e.company.provider.get: data['code'] (kebab-форма представительного кода).
     */
    public static function providerCode(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['code' => 'external'])];
    }

    /**
     * POST v1/b2e.company.registerByClient: data['id'] - строка (RegisterByClientResponse).
     */
    public static function registerByClient(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['id' => ProviderDescriptor::uid()])];
    }

    /**
     * POST v1/b2e.company.delete: ответ игнорируется порталом (DeleteResponse пуст).
     */
    public static function companyDelete(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success([])];
    }
}
