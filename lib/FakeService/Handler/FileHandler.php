<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService\Handler;

use Aclips\Fakesign\FakeService\Envelope;

/**
 * Подписанный/печатный документ. Контракт - SignedFileLoadService/PrintVersionLoad:
 * data.ready (bool) + data.file.url (строка|null -> Item\Api\Property\Response\Page\List\Page).
 * Ссылка ведёт на веб-точку pdf нашего же сервиса (браузер и портал - один хост).
 */
final class FileHandler
{
    /**
     * GET v1/document.signedfile.load: data {ready, file} для портального SignedFileLoadService.
     */
    public static function signedFile(string $path, string $method, array $query, string $body): array
    {
        return self::fileResponse($path);
    }

    /**
     * GET v1/document.printversion.load: data {ready, file} - печатная версия документа.
     */
    public static function printVersion(string $path, string $method, array $query, string $body): array
    {
        return self::fileResponse($path);
    }

    private static function fileResponse(string $path): array
    {
        $parts = array_values(array_filter(
            explode('/', rtrim($path, '/')),
            static fn(string $part): bool => $part !== ''
        ));

        $documentUid = (string)($parts[2] ?? '');

        $url = '/fakesign/pdf/?' . http_build_query(['documentUid' => $documentUid]);

        return [
            'status' => 200,
            'body' => Envelope::success([
                'ready' => true,
                'file' => ['url' => $url],
            ]),
        ];
    }
}
