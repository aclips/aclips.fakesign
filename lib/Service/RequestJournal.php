<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main;
use Aclips\Fakesign\Model\AuditTable;

/**
 * Журнал входящих запросов и событий тестового сценария.
 * Пишется для каждого входящего запроса - диагностический источник данных.
 */
final class RequestJournal
{
    /**
     * Ключи, значения которых маскируются перед записью и эхом.
     */
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'token',
        'x-sign-client-id',
        'x-sign-client-token',
        'x-sign-license-token',
    ];

    private const KIND_LENGTH = 50;
    private const PAYLOAD_BODY_LIMIT = 8000;

    /**
     * @return int id записи в b_aclips_fakesign_audit
     */
    public function record(string $kind, ?string $documentUid, ?string $memberUid, array $context): int
    {
        $context['body'] = mb_substr((string)($context['body'] ?? ''), 0, self::PAYLOAD_BODY_LIMIT);

        $addResult = AuditTable::add([
            'KIND' => mb_substr($kind, 0, self::KIND_LENGTH),
            'DOCUMENT_UID' => $documentUid,
            'MEMBER_UID' => $memberUid,
            'PAYLOAD' => Main\Web\Json::encode(self::maskSensitive($context)),
            'CREATED_AT' => new Main\Type\DateTime(),
        ]);

        $id = $addResult->getId();
        if ($id === null)
        {
            throw new Main\SystemException('Не удалось записать строку в b_aclips_fakesign_audit');
        }

        return (int)$id;
    }

    /**
     * Рекурсивно маскирует значения чувствительных ключей. Используется
     * и для записи в журнал, и для эха в ответе probe.
     */
    public static function maskSensitive(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value)
        {
            if (is_array($value))
            {
                $masked[$key] = self::maskSensitive($value);
                continue;
            }

            $masked[$key] = in_array(mb_strtolower((string)$key), self::SENSITIVE_KEYS, true)
                ? '***'
                : $value;
        }

        return $masked;
    }
}
