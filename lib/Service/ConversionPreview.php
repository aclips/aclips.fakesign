<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Aclips\Fakesign\FakeService\Handler\DocumentHandler;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Service\Container;

/**
 * Превью страниц через конвертер sign.bitrix24.ru (опция use_cloud_converter).
 *
 * Документ однократно регистрируется и загружается в сервис (тень - только ради
 * превью), результат кэшируется в манифесте; затем запрашиваются страницы.
 * При любой ошибке возвращается пусто, и вызывающий код использует локальное превью.
 */
final class ConversionPreview
{
    /** Фиксированный адрес конвертера (опция use_cloud_converter). */
    public const CONVERTER_URL = 'https://sign.bitrix24.ru';

    /** @var null|callable(string, string, ?array): array{status: int, body: string} */
    private static $transport = null;

    /**
     * Подмена транспорта: тесты подставляют стенд, по умолчанию HTTP на сервис.
     */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * Возвращает страницы документа с конвертера sign.bitrix24.ru; [] - документ
     * ещё конвертируется или запрос не удался (вызывающий продолжает ждать).
     *
     * @return list<string> url страниц
     */
    public static function fetchPages(string $documentUid): array
    {
        if ($documentUid === '')
        {
            return [];
        }

        $manifest = DocumentHandler::readManifest($documentUid);
        $conversionUid = (string)($manifest['conversionUid'] ?? '');

        if ($conversionUid === '')
        {
            $conversionUid = self::registerRemote($documentUid, $manifest);
            if ($conversionUid === '')
            {
                return [];
            }
            // Кэш сразу после register: повторные поллинги не создают дубликаты.
            $manifest['conversionUid'] = $conversionUid;
            DocumentHandler::writeManifest($documentUid, $manifest);
        }

        if (empty($manifest['conversionUploaded']))
        {
            if (!self::uploadRemote($documentUid, $conversionUid))
            {
                return []; // повтор при следующем поллинге, uid уже закэширован
            }
            $manifest['conversionUploaded'] = true;
            DocumentHandler::writeManifest($documentUid, $manifest);
        }

        return self::listRemote($conversionUid);
    }

    /**
     * Регистрирует документ в сервисе конвертации, возвращает его uid или ''.
     */
    private static function registerRemote(string $documentUid, array $manifest): string
    {
        $body = self::registerBody($documentUid, $manifest);
        if ($body === null)
        {
            return '';
        }

        $response = self::request('POST', self::CONVERTER_URL . '/api/v1/document.register', $body);
        $data = self::decodeEnvelope($response);

        return (string)($data['id'] ?? '');
    }

    /**
     * Загружает файлы документа в сервис конвертации.
     */
    private static function uploadRemote(string $documentUid, string $conversionUid): bool
    {
        $files = [];
        foreach (DocumentHandler::readManifest($documentUid)['files'] as $file)
        {
            if (!isset($file['file']))
            {
                continue;
            }
            $content = @file_get_contents(DocumentHandler::storageDir($documentUid) . '/' . $file['file']);
            if (!is_string($content) || $content === '')
            {
                continue;
            }
            $files[] = [
                'name' => (string)($file['name'] ?? 'document'),
                'type' => (string)($file['type'] ?? 'application/octet-stream'),
                'content' => base64_encode($content),
            ];
        }

        $response = self::request(
            'POST',
            self::CONVERTER_URL . '/api/v1/document.upload/' . rawurlencode($conversionUid) . '/',
            ['uid' => $conversionUid, 'files' => $files]
        );

        return self::decodeEnvelope($response) !== null;
    }

    /**
     * Запрашивает страницы документа в сервисе конвертации.
     *
     * @return list<string>
     */
    private static function listRemote(string $conversionUid): array
    {
        $response = self::request(
            'GET',
            self::CONVERTER_URL . '/api/v1/document.page.list/' . rawurlencode($conversionUid) . '/',
            null
        );
        $data = self::decodeEnvelope($response);
        if ($data === null || !is_array($data['pages'] ?? null))
        {
            return [];
        }

        $urls = [];
        foreach ($data['pages'] as $page)
        {
            $url = is_array($page) ? (string)($page['url'] ?? '') : '';
            if ($url !== '')
            {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Тело register: сохранённый исходный запрос модуля; для старых документов -
     * реконструкция из портального документа.
     */
    private static function registerBody(string $documentUid, array $manifest): ?array
    {
        $stored = $manifest['registerRequest'] ?? null;
        if (is_array($stored) && $stored !== [])
        {
            return $stored;
        }

        try
        {
            if (Loader::includeModule('sign'))
            {
                $document = Container::instance()
                    ->getDocumentService()
                    ->getByUid($documentUid);
                if ($document !== null)
                {
                    return [
                        'lang' => $document->langId !== '' ? $document->langId : 'ru',
                        'scenario' => (string)$document->scenario,
                        'title' => (string)$document->title,
                        'version' => (int)$document->version,
                        'hasPlaceholders' => false,
                    ];
                }
            }
        }
        catch (\Throwable)
        {
            return null;
        }

        return null;
    }

    /**
     * Разбирает ответ службы: data при status=success и отсутствующих errors.
     *
     * @param array{status?: int, body?: string} $response
     * @return array<string, mixed>|null
     */
    private static function decodeEnvelope(array $response): ?array
    {
        if (($response['status'] ?? 0) !== 200)
        {
            return null;
        }
        $json = json_decode((string)($response['body'] ?? ''), true);
        if (!is_array($json) || ($json['status'] ?? '') !== 'success' || !empty($json['errors']))
        {
            return null;
        }
        $data = $json['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    /**
     * Запрос к сервису конвертации (штатные заголовки - как у портала для document.*).
     *
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: string}
     */
    private static function request(string $method, string $url, ?array $body): array
    {
        $transport = self::$transport;
        if ($transport !== null)
        {
            return $transport($url, $method, $body);
        }

        // Аутентификация - как у портала (ApiService::requestWithClient):
        // X-Sign-Client-Id/Token из опций sign.~sign_safe_*.
        if (!Loader::includeModule('sign'))
        {
            return ['status' => 0, 'body' => ''];
        }
        $storage = Storage::instance();
        $clientId = $storage->getClientId();
        $clientToken = $storage->getClientToken();

        $http = (new Main\Web\HttpClient())->setTimeout(20);
        $http->setHeader('Content-Type', 'application/json');
        if ($clientId !== null && $clientId !== '')
        {
            $http->setHeader('X-Sign-Client-Id', $clientId);
        }
        if ($clientToken !== null && $clientToken !== '')
        {
            $http->setHeader('X-Sign-Client-Token', $clientToken);
        }
        $http->query($method, $url, $body === null ? '' : Main\Web\Json::encode($body));

        return ['status' => $http->getStatus(), 'body' => (string)$http->getResult()];
    }
}
