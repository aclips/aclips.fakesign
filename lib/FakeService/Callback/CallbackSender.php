<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService\Callback;

use Aclips\Fakesign\FakeService\Handler\DocumentHandler;
use Aclips\Fakesign\FakeService\TestPdf;
use Aclips\Fakesign\Item\State;
use Bitrix\Main\Web\HttpClient;
use Aclips\Fakesign\Service\DocumentStore;
use Bitrix\Main\Config\Option;

/**
 * Отправка колбэков статусов В ПОРТАЛ - без них не работают таймлайн, легальный лог
 * и события BizProc (TYPE_ON_DONE из ChangeDocumentStatus и Message-ветка ChangeMemberStatus).
 *
 * Форматы сообщений - ровно Callback/Messages модуля sign:
 *   memberStatusChanged (Member/MemberStatusChanged), DocumentStatus (DocumentStatus).
 * Адрес: sign.api.Callback.handle + токен из опции sign.~sign_safe_client_token_id
 * (фильтр ClientAuth сверяет его с query-параметром token).
 */
final class CallbackSender
{
    /**
     * Подменяемый транспорт (тесты ставят стенд; в проде - null = HTTP на портал).
     * tests/bootstrap.php ставит no-op, чтобы тесты никогда не ходили в сеть.
     */
    private static ?\Closure $transport = null;

    /**
     * Подменяет транспорт отправки колбэков (для тестов); null - HTTP на портал.
     */
    public static function setTransport(?callable $transport): void
    {
        self::$transport = $transport === null ? null : \Closure::fromCallable($transport);
    }

    /**
     * Базовый адрес портала: в вебе - текущий хост (портал и тестовый сервис на одном хосте),
     * в CLI (агент) - опция sign.current_domain.
     */
    public static function portalBaseUrl(): string
    {
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            $domain = (string)Option::get('sign', 'current_domain', '');
            $host = $domain !== '' ? $domain : '';
        }

        return $host === '' ? '' : 'http://' . $host;
    }

    /**
     * Диспетчер после перехода состояния участником:
     *  1) memberStatusChanged участнику (всегда);
     *  2) DocumentStatus документу - только когда завершены ВСЕ известные участники
     *     (done -> 'done', есть refused -> 'stopped').
     */
    public static function afterMemberChange(
        State $state,
        DocumentStore $store,
        bool $checkDocument = true
    ): void
    {
        $baseUrl = self::portalBaseUrl();
        if ($baseUrl === '') {
            return;
        }

        self::send($baseUrl, self::memberStatusPayload($state->memberUid, $state->documentUid, $state->toWebStatus()));

        if ($state->status === 'done') {
            self::send($baseUrl, self::memberResultFilePayload(
                $state->memberUid,
                $state->documentUid,
                self::signedResultBlock($state->documentUid),
            ));
        }

        if ($checkDocument) {
            self::afterDocumentPossiblyCompleted($state->documentUid, $store);
        }
    }

    /**
     * ZIP-контейнер подписи в base64 для колбэков ResultFile/memberResultFile:
     * исходный файл + имитация подписи (wredc_data.xml, signature.xml).
     *
     * @return array{content: string, name: string, contentType: string}
     */
    private static function signedResultBlock(string $documentUid): array
    {
        return [
            'content' => base64_encode(self::buildSignedZip($documentUid)),
            'name' => DocumentHandler::documentTitle($documentUid) . '.zip',
            'contentType' => 'application/zip',
        ];
    }

    /**
     * Собирает ZIP: исходный документ (или тестовая заглушка), данные подписи
     * и имитация подписанного значения.
     */
    private static function buildSignedZip(string $documentUid): string
    {
        $original = DocumentHandler::originalFile($documentUid);
        if ($original !== null) {
            $documentName = self::sanitizeEntryName($original['name']);
            $documentBytes = $original['content'];
        } else {
            $title = DocumentHandler::documentTitle($documentUid);
            $documentName = self::sanitizeEntryName(($title !== '' ? $title : 'document') . '.pdf');
            $documentBytes = TestPdf::render($documentUid);
        }

        $sha256 = hash('sha256', $documentBytes);
        $signedAt = date(DATE_ATOM);

        $tmp = tempnam(sys_get_temp_dir(), 'sigzip');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString($documentName, $documentBytes);
        $zip->addFromString('wredc_data.xml', self::wredcDataXml($documentUid, $documentName, $sha256, $signedAt));
        $zip->addFromString('signature.xml', self::signatureXml($sha256, $signedAt));
        $zip->close();

        $bytes = (string)file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /**
     * Данные имитации подписи (wredc_data.xml): документ, хеши, провайдер.
     * Тестовая маркировка обязательна - подпись не является электронной.
     */
    private static function wredcDataXml(
        string $documentUid,
        string $fileName,
        string $sha256,
        string $signedAt
    ): string
    {
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<WRedcData xmlns="urn:aclips.fakesign:signature:1">' . "\n"
            . '  <Document uid="' . $e($documentUid) . '"'
            . ' name="' . $e($fileName) . '"'
            . ' sha256="' . $e($sha256) . '"/>' . "\n"
            . '  <SignedAt>' . $e($signedAt) . '</SignedAt>' . "\n"
            . '  <Provider code="external">aclips.fakesign (TEST)</Provider>' . "\n"
            . '  <SignatureKind>IMITATION</SignatureKind>' . "\n"
            . '</WRedcData>' . "\n";
    }

    /**
     * Имитация подписанного значения (signature.xml) со ссылкой на wredc_data.xml.
     */
    private static function signatureXml(string $sha256, string $signedAt): string
    {
        $e = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Signature xmlns="urn:aclips.fakesign:signature:1">' . "\n"
            . '  <SignedInfo>' . "\n"
            . '    <DigestMethod>sha256</DigestMethod>' . "\n"
            . '    <DigestValue>' . $e($sha256) . '</DigestValue>' . "\n"
            . '    <SignatureMethod>TEST-IMITEDE</SignatureMethod>' . "\n"
            . '  </SignedInfo>' . "\n"
            . '  <SignatureValue>TEST-IMITEDE-SIGNATURE</SignatureValue>' . "\n"
            . '  <Object wredc:signedAt="' . $e($signedAt) . '" xmlns:wredc="urn:aclips.fakesign:signature:1"/>' . "\n"
            . '</Signature>' . "\n";
    }

    /**
     * Имя файла внутри архива без путевых разделителей.
     */
    private static function sanitizeEntryName(string $name): string
    {
        $name = str_replace(['/', '\\'], '_', $name);

        return $name !== '' ? $name : 'document';
    }

    /**
     * Колбэк memberResultFile: подписанный файл участника целиком в сообщении
     * (портал сохраняет его без обращения к службе).
     *
     * @param array{content: string, name: string, contentType: string} $file
     * @return array{type: string, data: array<string, mixed>}
     */
    public static function memberResultFilePayload(string $memberUid, string $documentUid, array $file): array
    {
        return [
            'type' => 'memberResultFile',
            'data' => [
                'documentUid' => $documentUid,
                'memberUid' => $memberUid,
                'securityCode' => '',
                'file' => $file,
            ],
        ];
    }

    /**
     * Колбэк ResultFile: итоговый подписанный файл документа (resultFileId портала).
     *
     * @param array{content: string, name: string, contentType: string} $file
     * @return array{type: string, data: array<string, mixed>}
     */
    public static function resultFilePayload(string $documentUid, array $file): array
    {
        return [
            'type' => 'ResultFile',
            'data' => [
                'documentCode' => $documentUid,
                'securityCode' => '',
                'file' => $file,
            ],
        ];
    }

    /**
     * DocumentStatus уходит, когда завершены ВСЕ известные участники документа:
     * все done -> 'done'; есть refused -> 'stopped'.
     */
    public static function afterDocumentPossiblyCompleted(string $documentUid, DocumentStore $store): void
    {
        $baseUrl = self::portalBaseUrl();
        if ($baseUrl === '') {
            return;
        }

        $knownMemberUids = DocumentHandler::knownMemberUids($documentUid);
        foreach ($store->listByDocument($documentUid) as $documentState) {
            $knownMemberUids[] = $documentState->memberUid;
        }
        $knownMemberUids = array_values(array_unique(array_filter($knownMemberUids)));
        if ($knownMemberUids === []) {
            return;
        }

        $hasRefused = false;
        foreach ($knownMemberUids as $memberUid) {
            $state = $store->findByUids($documentUid, $memberUid);
            if ($state === null || !$state->isCompleted()) {
                return;
            }
            if ($state->status === 'refused') {
                $hasRefused = true;
            }
        }

        if (!$hasRefused) {
            self::send($baseUrl, self::resultFilePayload($documentUid, self::signedResultBlock($documentUid)));
        }

        self::send($baseUrl, self::documentStatusPayload($documentUid, $hasRefused ? 'stopped' : 'done'));
    }

    /**
     * @return array{type: string, data: array<string, string>}
     */
    public static function memberStatusPayload(string $memberUid, string $documentUid, string $webStatus): array
    {
        return [
            'type' => 'memberStatusChanged',
            'data' => [
                'memberUid' => $memberUid,
                'documentUid' => $documentUid,
                'status' => $webStatus,
                'errorCode' => '',
                'sesUsername' => '',
                'sesSign' => '',
                'provider' => 'external',
                'initiatorUid' => '',
            ],
        ];
    }

    /**
     * @return array{type: string, data: array<string, string>}
     */
    public static function documentStatusPayload(string $documentUid, string $documentStatus): array
    {
        return [
            'type' => 'DocumentStatus',
            'data' => [
                'status' => $documentStatus,
                'code' => $documentUid,
                'signDate' => date(DATE_ATOM),
                'initiatorUid' => '',
            ],
        ];
    }

    /**
     * Адрес приёма колбэков на portalHost (http://<host>/bitrix/services/main/ajax.php?...).
     */
    public static function portalCallbackUrl(string $portalBaseUrl): string
    {
        $token = (string)Option::get('sign', '~sign_safe_client_token_id', '');

        return rtrim($portalBaseUrl, '/')
            . '/bitrix/services/main/ajax.php?action=sign.api.Callback.handle&token=' . rawurlencode($token);
    }

    /**
     * @param array{type: string, data: array} $payload
     * @param null|callable(string, array): void $transport тестовый стенд вместо HTTP
     */
    public static function send(string $portalBaseUrl, array $payload, ?callable $transport = null): bool
    {
        $transport = $transport ?? self::$transport;
        $url = self::portalCallbackUrl($portalBaseUrl);
        $fields = ['payload' => $payload];

        if ($transport !== null) {
            $transport($url, $fields);

            return true;
        }

        $http = new HttpClient();
        $http->query('POST', $url, http_build_query($fields));

        $status = $http->getStatus();

        return $http->getError() === [] && $status >= 200 && $status < 300;
    }
}
