<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService;

/**
 * Обёртка ответа в формате Sign Service - ровно в том виде, как его разбирает
 * клиент портала (ApiService::request: поле data обязано быть массивом, ошибки
 * читаются из errors[].{message,code,customData}).
 *
 * Формат соответствует реальному ответу службы:
 *   {"status":"success","data":{...},"errors":[]}
 *   {"status":"error","data":null,"errors":[{"message":"","code":"x","customData":null}]}
 */
final class Envelope
{
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * Ответ в формате {status: success, data, errors: []}.
     */
    public static function success(array $data): string
    {
        return json_encode(['status' => 'success', 'data' => $data, 'errors' => []], self::FLAGS);
    }

    /**
     * Ответ в формате {status: error, errors: [{message, code, customData}]}.
     */
    public static function error(string $code, string $message): string
    {
        return json_encode([
            'status' => 'error',
            'data' => null,
            'errors' => [['message' => $message, 'code' => $code, 'customData' => null]],
        ], self::FLAGS);
    }
}
