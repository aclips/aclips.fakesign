<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService\Handler;

use Aclips\Fakesign\FakeService\Envelope;
use Aclips\Fakesign\FakeService\ProviderDescriptor;

/**
 * Хендлеры реестра внешних провайдеров: их же зовёт наш модуль через
 * Service\Api\B2e\ExternalSignProviderService (add/edit/delete/list) и
 * Service\Api\B2e\ProviderSchemesService/ProviderFieldsService.
 */
final class ProviderHandler
{
    /**
     * GET v1/b2e.external-sign-provider.list: data['result'] - записи как у реальной службы.
     */
    public static function list(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['result' => [ProviderDescriptor::record()]])];
    }

    /**
     * POST v1/b2e.external-sign-provider.add: data['result'] = scalar id
     * Запись синтезируется статически (ProviderDescriptor), отдельное хранение не нужно.
     */
    public static function add(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['result' => ProviderDescriptor::uid()])];
    }

    /**
     * POST v1/b2e.external-sign-provider.edit: подтверждает обновление (запись статична).
     */
    public static function edit(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['result' => ProviderDescriptor::uid()])];
    }

    /**
     * POST v1/b2e.external-sign-provider.delete: отвечает успехом - реестр статичен,
     * удалять нечего.
     */
    public static function delete(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['result' => 1])];
    }

    /**
     * GET v1/b2e.provider.getAvailableSchemes: data['schemes'] - только значения SchemeType.
     */
    public static function schemes(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['schemes' => ['default', 'order']])];
    }

    /**
     * GET v1/b2e.provider.getFields: data['fields'] - массив полей для
     * RequiredFieldsCollection::createFromJsonArray; пустой список валиден.
     */
    public static function fields(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['fields' => []])];
    }
}
