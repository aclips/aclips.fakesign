<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService;

/**
 * Разбор пути внутри /fakesign/: либо API фейк-сервиса (fakesign/api/...),
 * либо действие веб-точки (fakesign/sign/ и т.п.).
 *
 * Путь API приходит от портала как service.address + '/api/' + 'v1/...',
 * поэтому в rest он лежит с префиксом 'api/'.
 */
final class Router
{
    private const API_PREFIX = 'api/';

    /**
     * @return array{0: 'api'|'action', 1: string}
     */
    public static function resolve(string $rest): array
    {
        if (str_starts_with($rest, self::API_PREFIX)) {
            return ['api', substr($rest, strlen(self::API_PREFIX))];
        }

        return ['action', $rest];
    }
}
