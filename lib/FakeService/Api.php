<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService;

/**
 * Маршрутизатор API фейк-сервиса Sign Service.
 *
 * KNOWN_BASES - базовые пути, которые вызывает портал. Путь с хендлером,
 * зарегистрированным через register(), обрабатывается им; известный путь без
 * хендлера отвечает success-заглушкой; неизвестный - NOT_IMPLEMENTED
 * в формате службы (Envelope).
 */
final class Api
{
    /**
     * Известные порталу базовые пути (допускаются суффиксы вида /{uid}/ и .batch).
     */
    private const KNOWN_BASES = [
        'v1/client.register',
        'v1/client.domain.set',
        'v1/document.register',
        'v1/document.upload',
        'v1/document.reuse',
        'v1/document.signing.configure',
        'v1/document.signing.sendInvite',
        'v1/document.signing.start',
        'v1/document.signing.stop',
        'v1/document.signing.stop.batch',
        'v1/document.signUntil.update',
        'v1/document.field.fill',
        'v1/document.page.list',
        'v1/document.page.view',
        'v1/document.placeholder.list',
        'v1/document.signing.resendMessage',
        'v1/document.member.status.get',
        'v1/document.signedfile.load',
        'v1/document.printversion.load',
        'v1/b2e.company.get',
        'v1/b2e.company.provider.get',
        'v1/b2e.company.registerByClient',
        'v1/b2e.company.delete',
        'v1/b2e.member.getlinkforsigning',
        'v1/b2e.document.signing.sign',
        'v1/b2e.document.signing.sign.batch',
        'v1/b2e.document.signing.refuse',
        'v1/b2e.document.signing.refuse.batch',
        'v1/b2e.document.reviewer.accept',
        'v1/b2e.document.reviewer.accept.batch',
        'v1/b2e.document.member.confirmation.accept',
        'v1/b2e.document.member.confirmation.postpone',
        'v1/b2e.provider.getAvailableSchemes',
        'v1/b2e.provider.getFields',
        'v1/b2e.provider.signing.signer.mobile.start',
        'v1/b2e.user.register',
        'v1/b2e.external-sign-provider.add',
        'v1/b2e.external-sign-provider.edit',
        'v1/b2e.external-sign-provider.delete',
        'v1/b2e.external-sign-provider.list',
    ];

    /**
     * @var array<string, callable> базовый путь => обработчик(path, method, query, body):
     *                                 array{status: int, body: string, contentType?: string}
     */
    private static array $handlers = [];

    /**
     * Регистрирует обработчик для базового пути API.
     */
    public static function register(string $basePath, callable $handler): void
    {
        self::$handlers[$basePath] = $handler;
    }

    /**
     * @return array{status: int, body: string, contentType: string}
     *         HTTP-статус, тело в формате службы и Content-Type ответа
     */
    public static function handle(string $path, string $method, array $query, string $body): array
    {
        foreach (self::$handlers as $basePath => $handler) {
            if (str_starts_with($path, $basePath)) {
                $result = $handler($path, $method, $query, $body);

                // Гибкий контракт хендлеров: и ['status','body'], и позиционный [status, body].
                // contentType - для не-JSON ответов (изображения страниц page.view).
                return [
                    'status' => (int)($result['status'] ?? $result[0] ?? 200),
                    'body' => (string)($result['body'] ?? $result[1] ?? ''),
                    'contentType' => (string)($result['contentType'] ?? 'application/json; charset=utf-8'),
                ];
            }
        }

        if (self::isKnown($path)) {
            return ['status' => 200, 'body' => Envelope::success([])];
        }

        return [
            'status' => 501,
            'body' => Envelope::error('NOT_IMPLEMENTED', 'Путь не реализован фейк-сервисом: ' . $path),
        ];
    }

    /**
     * Есть ли путь в списке известных порталу.
     */
    private static function isKnown(string $path): bool
    {
        foreach (self::KNOWN_BASES as $base) {
            if (str_starts_with($path, $base)) {
                return true;
            }
        }

        return false;
    }
}
