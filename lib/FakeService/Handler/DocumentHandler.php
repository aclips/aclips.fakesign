<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService\Handler;

use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\FakeService\Envelope;
use Aclips\Fakesign\Service\ConversionPreview;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;

/**
 * Жизненный цикл документа. Контракт - потребители в модуле sign:
 * Service\Api\DocumentService (register -> data['id'], upload/reuse - успех),
 * Service\Api\Document\{PageService,SigningService,ApiDocumentPlaceholderService,FieldService}.
 *
 * Файлы, пришедшие в v1/document.upload, сохраняются в upload/aclips.fakesign/<uid>/ -
 * по ним считаются страницы для page.list (фронт опрашивает, пока urls.length == 0,
 * см. document-setup/waitForPagesList) и рендерятся изображения страниц.
 */
final class DocumentHandler
{
    private const VIEW_PATH = 'v1/document.page.view';

    /**
     * POST v1/document.register: data['id'] = uid (RegisterResponse; пустой -> ошибка
     * "Empty document id" у портала). Реестр документов портал держит сам.
     *
     * Формат - как у реального сервиса: R-XX-XXXX-XXXX-XXXX (19 символов), снят со строк
     * b_sign_document, созданных до изоляции. Колонка портала uid = varchar(20): длиннее -
     * ядро молча обрезает значение (ORM StringField::mb_substr, а PG сам бы дал ошибку),
     * и портальный upload/getByUid не находит документ -> "Такого документа нет".
     */
    public static function register(string $path, string $method, array $query, string $body): array
    {
        $request = json_decode($body, true) ?: [];
        $uid = self::generateServiceUid();

        self::writeManifest($uid, [
            'title' => (string)($request['title'] ?? ''),
            'files' => [],
            'registerRequest' => $request,
        ]);

        return ['status' => 200, 'body' => Envelope::success(['id' => $uid])];
    }

    /**
     * Генерирует uid формата R-XX-XXXX-XXXX-XXXX (19 символов).
     */
    private static function generateServiceUid(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $group = static function (int $length) use ($alphabet): string {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $out;
        };

        return 'R-' . $group(2) . '-' . $group(4) . '-' . $group(4) . '-' . $group(4);
    }

    /**
     * POST v1/document.upload/{uid}: принимает файлы (base64 в files[].content),
     * сохраняет их - это единственный источник реального содержимого документа
     * для подсчёта страниц. data portal не читает.
     */
    public static function upload(string $path, string $method, array $query, string $body): array
    {
        $parts = self::pathParts($path);
        $uid = (string)($parts[2] ?? '');
        if ($uid === '') {
            return ['status' => 400, 'body' => Envelope::error('BAD_PATH', 'Ожидался uid в пути')];
        }

        $request = json_decode($body, true) ?: [];
        $manifest = self::readManifest($uid);
        $manifest['files'] = [];

        foreach ((array)($request['files'] ?? []) as $index => $file) {
            if (!is_array($file)) {
                continue;
            }
            $content = base64_decode((string)($file['content'] ?? ''), true);
            if ($content === false || $content === '') {
                continue;
            }
            $name = (string)($file['name'] ?? 'file');
            $type = (string)($file['type'] ?? 'application/octet-stream');
            $storedName = (int)$index . '.' . self::extensionFor($name, $type);
            if (file_put_contents(self::storageDir($uid) . '/' . $storedName, $content) === false) {
                continue;
            }
            $manifest['files'][(int)$index] = [
                'file' => $storedName,
                'type' => $type,
                'name' => $name,
            ];
        }
        ksort($manifest['files']);
        self::writeManifest($uid, $manifest);

        return ['status' => 200, 'body' => Envelope::success([])];
    }

    /**
     * GET v1/document.page.list/{uid}: data.pages[] + data.ready (ListResponse).
     *
     * Галочка use_cloud_converter: включена - страницы ждут конвертер sign.bitrix24.ru
     * (пока конвертер молчит - pages пуст и ready=false, фронт продолжает опрос,
     * без перехода на локальное превью); выключена - картинка-заглушка предпросмотра.
     * Поллинг фронта завершается при pages.length > 0.
     */
    public static function pages(string $path, string $method, array $query, string $body): array
    {
        $parts = self::pathParts($path);
        $uid = (string)($parts[2] ?? '');

        if (Option::get('aclips.fakesign', 'use_cloud_converter', 'N') === 'Y') {
            $remoteUrls = ConversionPreview::fetchPages($uid);
            if ($remoteUrls !== []) {
                $pages = [];
                foreach ($remoteUrls as $remoteUrl) {
                    $pages[] = ['url' => $remoteUrl];
                }

                return ['status' => 200, 'body' => Envelope::success(['pages' => $pages, 'ready' => true])];
            }

            return ['status' => 200, 'body' => Envelope::success(['pages' => [], 'ready' => false])];
        }

        $plan = self::pagePlan($uid);
        if ($plan === []) {
            $plan = [null];
        }

        $endpoint = self::apiEndpoint();
        $pages = [];
        foreach ($plan as $index => $entry) {
            $pages[] = [
                'url' => $endpoint . self::VIEW_PATH . '/' . rawurlencode($uid) . '/' . ($index + 1),
            ];
        }

        return ['status' => 200, 'body' => Envelope::success(['pages' => $pages, 'ready' => true])];
    }

    /**
     * GET v1/document.page.view/{uid}/{page}: картинка-заглушка предпросмотра
     * (templates/document_placeholder.png - копия src/document_placeholder.png).
     */
    public static function pageView(string $path, string $method, array $query, string $body): array
    {
        $stub = @file_get_contents(dirname(__DIR__, 3) . '/templates/document_placeholder.png');
        if ($stub === false || $stub === '') {
            return ['status' => 404, 'body' => Envelope::error('STUB_NOT_FOUND', 'Картинка заглушки не найдена')];
        }

        return ['status' => 200, 'body' => $stub, 'contentType' => 'image/png'];
    }

    /**
     * GET v1/document.placeholder.list/{uid}: data передаётся в ListResponse целиком -> [].
     */
    public static function placeholders(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success([])];
    }

    /**
     * POST v1/document.signing.configure/{uid}: data['members'] - эхо участников
     * из тела запроса ({key,id} - ConfigureResponse читает строго).
     */
    public static function configure(string $path, string $method, array $query, string $body): array
    {
        $request = json_decode($body, true) ?: [];
        $members = [];
        foreach ((array)($request['members'] ?? []) as $member) {
            if (!is_array($member)) {
                continue;
            }
            $members[] = [
                'key' => (string)($member['key'] ?? ''),
                'id' => (string)($member['id'] ?? ''),
            ];
        }

        $parts = self::pathParts($path);
        $documentUid = (string)($parts[2] ?? '');
        if ($documentUid !== '') {
            $manifest = self::readManifest($documentUid);
            $manifest['members'] = [];
            foreach ((array)($request['members'] ?? []) as $member) {
                if (!is_array($member)) {
                    continue;
                }
                $memberUid = (string)($member['uid'] ?? $member['id'] ?? '');
                if ($memberUid !== '') {
                    $manifest['members'][] = ['uid' => $memberUid];
                }
            }
            self::writeManifest($documentUid, $manifest);
        }

        return ['status' => 200, 'body' => Envelope::success(['members' => $members])];
    }

    /**
     * POST v1/document.signing.start/{uid}: помечаем документ начатым и шлём каждому
     * участнику memberStatusChanged(web='ready') - портальный Callback/Handler ->
     * SyncMemberStatus -> GetMemberWebStatus (наш status.get) переводит участника из
     * WAIT ("Ожидание документа") в READY, без этого подпись невозможна.
     */
    public static function start(string $path, string $method, array $query, string $body): array
    {
        $parts = self::pathParts($path);
        $documentUid = (string)($parts[2] ?? '');
        if ($documentUid === '') {
            return ['status' => 400, 'body' => Envelope::error('BAD_PATH', 'Ожидался uid в пути')];
        }

        $manifest = self::readManifest($documentUid);
        $manifest['started'] = true;
        self::writeManifest($documentUid, $manifest);

        $baseUrl = CallbackSender::portalBaseUrl();
        if ($baseUrl !== '') {
            foreach ((array)($manifest['members'] ?? []) as $member) {
                CallbackSender::send(
                    $baseUrl,
                    CallbackSender::memberStatusPayload((string)($member['uid'] ?? ''), $documentUid, 'ready')
                );
            }
        }

        return ['status' => 200, 'body' => Envelope::success([])];
    }

    /**
     * Первый файл из загрузки любого формата (оригинал подписываемого документа).
     *
     * @return array{content: string, name: string, type: string}|null
     */
    public static function originalFile(string $documentUid): ?array
    {
        if ($documentUid === '') {
            return null;
        }

        foreach (self::readManifest($documentUid)['files'] as $file) {
            if (!isset($file['file'])) {
                continue;
            }

            $content = @file_get_contents(self::storageDir($documentUid) . '/' . $file['file']);
            if (is_string($content) && $content !== '') {
                return [
                    'content' => $content,
                    'name' => (string)($file['name'] ?? 'document'),
                    'type' => (string)($file['type'] ?? 'application/octet-stream'),
                ];
            }
        }

        return null;
    }

    /**
     * Оригинальный PDF, загруженный через v1/document.upload (первый найденный).
     *
     * @return array{content: string, name: string}|null null - PDF не загружался
     */
    public static function originalPdf(string $documentUid): ?array
    {
        if ($documentUid === '') {
            return null;
        }

        foreach (self::readManifest($documentUid)['files'] as $file) {
            if (!isset($file['file'])) {
                continue;
            }
            $isPdf = ($file['type'] ?? '') === 'application/pdf'
                || str_ends_with(strtolower((string)($file['name'] ?? '')), '.pdf');
            if (!$isPdf) {
                continue;
            }

            $content = @file_get_contents(self::storageDir($documentUid) . '/' . $file['file']);
            if (is_string($content) && $content !== '') {
                return ['content' => $content, 'name' => (string)($file['name'] ?? 'document.pdf')];
            }
        }

        return null;
    }

    /**
     * Заголовок документа из манифеста (configure); если неизвестен - сам uid.
     */
    public static function documentTitle(string $documentUid): string
    {
        if ($documentUid === '') {
            return '';
        }

        $title = trim((string)(self::readManifest($documentUid)['title'] ?? ''));

        return $title !== '' ? $title : $documentUid;
    }

    /**
     * Участники документа, о которых сообщил портал на configure (манифест).
     * Используется для решения о завершении документа: строки состояния появляются
     * только при открытии ссылки и НЕ покрывают всех участников.
     *
     * @return list<string>
     */
    public static function knownMemberUids(string $documentUid): array
    {
        if ($documentUid === '') {
            return [];
        }

        $uids = [];
        foreach ((array)(self::readManifest($documentUid)['members'] ?? []) as $member) {
            $uid = (string)($member['uid'] ?? '');
            if ($uid !== '') {
                $uids[] = $uid;
            }
        }

        return $uids;
    }

    /**
     * Документ начат (signing.start вызван) - участники готовы к подписи.
     */
    public static function isStarted(string $documentUid): bool
    {
        return $documentUid !== '' && (bool)(self::readManifest($documentUid)['started'] ?? false);
    }

    /**
     * POST v1/document.signing.stop.batch: data.results (StopBatchResponse).
     */
    public static function stopBatch(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success(['results' => []])];
    }

    /**
     * Универсальная заглушка вызовов, чьи data портал не читает
     * (reuse, signUntil, fill, start, stop, sendInvite, resendMessage).
     */
    public static function accept(string $path, string $method, array $query, string $body): array
    {
        return ['status' => 200, 'body' => Envelope::success([])];
    }

    /**
     * План страниц документа: по элементу на страницу (изображение = 1, PDF = N по разбору).
     *
     * @return list<array{file: string, type: string, name: string}>
     */
    private static function pagePlan(string $uid): array
    {
        $plan = [];
        foreach (self::readManifest($uid)['files'] as $file) {
            $count = self::pageCount(
                self::storageDir($uid) . '/' . $file['file'],
                (string)$file['type']
            );
            for ($i = 0; $i < $count; $i++) {
                $plan[] = $file;
            }
        }

        return $plan;
    }

    /**
     * Определяет число страниц файла: изображение - 1, PDF - по разметке.
     */
    private static function pageCount(string $file, string $type): int
    {
        if (str_starts_with($type, 'image/')) {
            return 1;
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            return 1;
        }

        if (str_contains($content, '%PDF')) {
            // /Type /Page, но не /Pages.
            preg_match_all('#/Type\s*/Page(?!s)#', $content, $matches);
            if (count($matches[0]) > 0) {
                return count($matches[0]);
            }
            preg_match_all('#/Count\s+(\d+)#', $content, $counts);
            $values = array_map('intval', $counts[1] ?? []);

            return max([1, ...$values]);
        }

        return 1;
    }

    /**
     * @return list<string>
     */
    private static function pathParts(string $path): array
    {
        return array_values(array_filter(
            explode('/', rtrim($path, '/')),
            static fn(string $part): bool => $part !== ''
        ));
    }

    private static function extensionFor(string $name, string $type): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension !== '' && preg_match('#^[a-z0-9]{1,5}$#', $extension)) {
            return $extension;
        }

        return match ($type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    /**
     * Каталог файлов документа: upload/aclips.fakesign/<uid>.
     */
    public static function storageDir(string $uid): string
    {
        $safeUid = preg_replace('/[^A-Za-z0-9_-]/', '', $uid) ?: 'unknown';
        $dir = (string)($_SERVER['DOCUMENT_ROOT'] ?? '') . '/upload/aclips.fakesign/' . $safeUid;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /**
     * Читает манифест целиком: title, файлы, участники (configure), started (start),
     * исходный запрос register и кэш теневой конвертации. Потеря ключей при чтении
     * вычищала бы их при следующей записи.
     *
     * @return array<string, mixed> title, files[], members[], started,
     *                             registerRequest, conversionUid?, conversionUploaded?
     */
    public static function readManifest(string $uid): array
    {
        $json = @file_get_contents(self::storageDir($uid) . '/manifest.json');
        $data = $json !== false ? json_decode($json, true) : null;

        if (!is_array($data)) {
            return ['title' => '', 'files' => [], 'members' => [], 'started' => false, 'registerRequest' => []];
        }

        $manifest = [
            'title' => (string)($data['title'] ?? ''),
            'files' => array_values((array)($data['files'] ?? [])),
            'members' => array_values((array)($data['members'] ?? [])),
            'started' => (bool)($data['started'] ?? false),
            'registerRequest' => is_array($data['registerRequest'] ?? null) ? $data['registerRequest'] : [],
        ];
        if (isset($data['conversionUid'])) {
            $manifest['conversionUid'] = (string)$data['conversionUid'];
        }
        if (isset($data['conversionUploaded'])) {
            $manifest['conversionUploaded'] = (bool)$data['conversionUploaded'];
        }

        return $manifest;
    }

    /**
     * Перезаписывает манифест документа целиком (ключи передаются явно).
     */
    public static function writeManifest(string $uid, array $manifest): void
    {
        @file_put_contents(
            self::storageDir($uid) . '/manifest.json',
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Адрес API службы - ровно тот, что портал передаёт SaveBlankPreviewPage
     * (Storage::getApiEndpoint() = service.address + '/api/'): URL страницы обязан
     * начинаться с этого префикса, иначе превью не сохраняется.
     */
    private static function apiEndpoint(): string
    {
        try {
            if (Loader::includeModule('sign')) {
                $endpoint = Storage::instance()->getApiEndpoint();
                if (is_string($endpoint) && $endpoint !== '') {
                    return rtrim($endpoint, '/') . '/';
                }
            }
        } catch (\Throwable) {
            // падаем на host-вариант
        }

        return 'http://' . ((string)($_SERVER['HTTP_HOST'] ?? 'localhost')) . '/fakesign/api/';
    }
}
