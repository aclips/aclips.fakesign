<?php

declare(strict_types=1);

use Aclips\Fakesign\Controller\Entrypoint;
use Bitrix\Main\Loader;
use Bitrix\Sign\Item\Api\Document\RegisterRequest;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DocumentScenario;
use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\Callback\CallbackSender;

$handle = static function (string $path, array $body = []): array {
    return Api::handle($path, 'POST', [], json_encode($body));
};

/** @return array<string, string> имя файла => содержимое */
$unzip = static function (string $bytes): array {
    $tmp = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($tmp, $bytes);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        unlink($tmp);
        throw new RuntimeException('не удалось открыть zip');
    }
    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $entries[$name] = (string)$zip->getFromIndex($i);
    }
    $zip->close();
    unlink($tmp);

    return $entries;
};

$decode = static function (array $resp): array {
    $json = json_decode($resp['body'] ?? '', true);

    return is_array($json) ? $json : [];
};

return [
    'register: data.id - непустой uid (формат RegisterResponse, иначе Empty document id)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $data = $decode($handle('v1/document.register', ['lang' => 'ru', 'scenario' => 'b2e', 'title' => 'Т']))['data'] ?? [];
        $id = $data['id'] ?? '';
        expect_true(is_string($id) && $id !== '', 'id - непустая строка');
        // Формат со строк b_sign_document, созданных реальным сервисом:
        // "R-QL-2IAG-CM9K-QW35" (19 символов); колонка uid портала - varchar(20),
        // более длинный uid ядро молча обрезает, и upload(getByUid) не находит документ.
        expect_true(
            (bool)preg_match('#^R-[A-Z0-9]{2}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$#', $id),
            'uid в формате сервиса R-XX-XXXX-XXXX-XXXX, получено: ' . $id
        );
        expect_true(strlen($id) <= 20, 'uid не длиннее varchar(20) портала');
    },
    'configure: эхо members из тела запроса (ConfigureResponse)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/document.signing.configure/doc1', [
            'members' => [['key' => 'signer_1', 'id' => 'm-1'], ['key' => 'reviewer_1', 'id' => 'm-2']],
        ]);
        $members = $decode($resp)['data']['members'] ?? [];
        expect_eq(count($members), 2, 'оба участника');
        expect_eq($members[0]['key'] ?? null, 'signer_1', 'key');
        expect_eq($members[1]['id'] ?? null, 'm-2', 'id');
    },
    'page.list: pages непустой (фрон поллинг выходит только при urls.length>0) со ссылкой page.view' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $data = $decode($handle('v1/document.page.list/doc-page1'))['data'] ?? [];
        expect_eq($data['ready'] ?? null, true, 'ready=true');
        $pages = $data['pages'] ?? [];
        expect_true(is_array($pages) && count($pages) >= 1, 'хотя бы одна страница, иначе поллинг вечен');
        $url = (string)($pages[0]['url'] ?? '');
        // SaveBlankPreviewPage требует url ПОД apiEndpoint и content-type image/*
        expect_true(str_contains($url, '/fakesign/api/v1/document.page.view/'), 'url под apiEndpoint: ' . $url);
        expect_true(str_contains($url, 'doc-page1'), 'uid в url');
    },
    'upload сохраняет файл: изображение = 1 страница, page.view отдаёт картинку' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $uid = 'R-UP-TEST-0001-AAAA';
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $handle("v1/document.upload/$uid", [
            'uid' => $uid,
            'files' => [['name' => 'photo.png', 'type' => 'image/png', 'content' => $png]],
        ]);
        $pages = $decode($handle("v1/document.page.list/$uid"))['data']['pages'] ?? [];
        expect_eq(count($pages), 1, 'одно изображение = одна страница');

        $view = Api::handle("v1/document.page.view/$uid/1", 'GET', [], '');
        expect_eq($view['status'] ?? null, 200, 'page.view 200');
        expect_true(str_starts_with((string)($view['contentType'] ?? ''), 'image/'), 'content-type image/*: ' . ($view['contentType'] ?? ''));
        expect_true(
            str_starts_with((string)$view['body'], "\x89PNG") || str_starts_with((string)$view['body'], "\xFF\xD8"),
            'магия PNG/JPEG в теле'
        );
    },
    'многостраничный PDF: по ссылке на каждую страницу' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $uid = 'R-PD-TEST-0002-BBBB';
        $pdf = "%PDF-1.4\n"
            . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
            . "2 0 obj << /Type /Pages /Kids [3 0 R 4 0 R 5 0 R] /Count 3 >> endobj\n"
            . "3 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
            . "4 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
            . "5 0 obj << /Type /Page /Parent 2 0 R >> endobj\n"
            . "%%EOF";
        $handle("v1/document.upload/$uid", [
            'uid' => $uid,
            'files' => [['name' => 'doc.pdf', 'type' => 'application/pdf', 'content' => base64_encode($pdf)]],
        ]);
        $pages = $decode($handle("v1/document.page.list/$uid"))['data']['pages'] ?? [];
        expect_eq(count($pages), 3, 'три страницы PDF');
        expect_true(str_ends_with((string)($pages[2]['url'] ?? ''), '/3'), 'номер страницы в url');
    },
    'без upload (только register): page.list всё равно непустой - поллинг не зависает' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $pages = $decode($handle('v1/document.page.list/R-NO-UP-0003-CCCC'))['data']['pages'] ?? [];
        expect_true(count($pages) >= 1, 'fallback - одна синтетическая страница');
    },
    'оригинальный файл попадает в ZIP-контейнер подписи (байты и имя)' => static function () use ($handle, $decode, $unzip): void {
        Bootstrap::register();
        $uid = 'R-ORIG' . uniqid();
        $original = "%PDF-1.4\n% ORIGIN-MARKER-123\noriginal content\n%%EOF";
        $handle("v1/document.upload/$uid", [
            'uid' => $uid,
            'files' => [[
                'name' => 'original.pdf',
                'type' => 'application/pdf',
                'content' => base64_encode($original),
            ]],
        ]);
        $member = str_repeat('a', 32);
        $handle("v1/document.signing.configure/$uid", [
            'members' => [['key' => null, 'uid' => $member]],
        ]);

        $calls = [];
        $stand = static function (string $url, array $fields) use (&$calls): void {
            $calls[] = $fields;
        };
        $noop = static fn(): bool => true;
        CallbackSender::setTransport($stand);
        try {
            $handle("v1/b2e.document.signing.sign/$uid/$member");
        } finally {
            CallbackSender::setTransport($noop);
        }

        $memberFile = null;
        foreach ($calls as $fields) {
            if (($fields['payload']['type'] ?? '') === 'memberResultFile') {
                $memberFile = $fields['payload']['data'];
            }
        }
        expect_true($memberFile !== null, 'memberResultFile ушёл');
        expect_true(str_ends_with((string)($memberFile['file']['name'] ?? ''), '.zip'), 'имя контейнера .zip');
        expect_eq($memberFile['file']['contentType'] ?? null, 'application/zip', 'contentType zip');

        $bytes = (string)base64_decode((string)($memberFile['file']['content'] ?? ''), true);
        expect_true(str_starts_with($bytes, 'PK'), 'магия ZIP');
        $entries = $unzip($bytes);
        expect_eq($entries['original.pdf'] ?? null, $original, 'внутри - байты оригинала с оригинальным именем');
        expect_true(isset($entries['wredc_data.xml']), 'wredc_data.xml присутствует');
        expect_true(isset($entries['signature.xml']), 'signature.xml присутствует');
        expect_true(str_contains($entries['wredc_data.xml'] ?? '', $uid), 'wredc_data.xml содержит uid документа');
        expect_true(str_contains($entries['wredc_data.xml'] ?? '', hash('sha256', $original)), 'wredc_data.xml содержит sha256 оригинала');
        expect_true(str_contains($entries['wredc_data.xml'] ?? '', 'TEST'), 'тестовая маркировка в данных подписи');
    },
    'без загрузки: ZIP содержит тестовую PDF-заглушку и sig-файлы' => static function () use ($handle, $decode, $unzip): void {
        Bootstrap::register();
        $uid = 'R-STUB' . uniqid();
        $member = str_repeat('b', 32);
        $handle("v1/document.signing.configure/$uid", [
            'members' => [['key' => null, 'uid' => $member]],
        ]);

        $calls = [];
        $stand = static function (string $url, array $fields) use (&$calls): void {
            $calls[] = $fields;
        };
        $noop = static fn(): bool => true;
        CallbackSender::setTransport($stand);
        try {
            $handle("v1/b2e.document.signing.sign/$uid/$member");
        } finally {
            CallbackSender::setTransport($noop);
        }

        $memberFile = null;
        foreach ($calls as $fields) {
            if (($fields['payload']['type'] ?? '') === 'memberResultFile') {
                $memberFile = $fields['payload']['data'];
            }
        }
        expect_true($memberFile !== null, 'memberResultFile ушёл');
        $entries = $unzip((string)base64_decode((string)($memberFile['file']['content'] ?? ''), true));

        $pdfEntries = array_filter($entries, static fn(string $name, string $content): bool => false, ARRAY_FILTER_USE_BOTH);
        $foundPdf = false;
        foreach ($entries as $name => $content) {
            if (str_ends_with($name, '.pdf')) {
                $foundPdf = true;
                expect_true(str_starts_with($content, '%PDF'), 'заглушка-документ внутри - PDF');
                expect_true(str_contains($content, 'TEST - NOT A LEGAL SIGNATURE'), 'маркировка TEST на заглушке');
            }
        }
        expect_true($foundPdf, 'внутри есть pdf-документ (заглушка)');
        expect_true(isset($entries['wredc_data.xml']), 'wredc_data.xml присутствует');
        expect_true(isset($entries['signature.xml']), 'signature.xml присутствует');
    },
    'эндпоинт pdf: отдаёт оригинальный PDF, а при его отсутствии - заглушку' => static function (): void {
        Bootstrap::register();

        $uid = 'R-PDFEP' . uniqid();
        $original = "%PDF-1.4\n% EP-ORIGIN-MARKER\n%%EOF";
        Api::handle("v1/document.upload/$uid", 'POST', [], json_encode([
            'uid' => $uid,
            'files' => [['name' => 'doc.pdf', 'type' => 'application/pdf', 'content' => base64_encode($original)]],
        ]));

        ob_start();
        (new Entrypoint())->run('pdf', ['documentUid' => $uid], 'GET', '', []);
        expect_eq((string)ob_get_clean(), $original, 'эндпоинт отдаёт байты оригинала');

        $uid2 = 'R-PDFEP2' . uniqid();
        ob_start();
        (new Entrypoint())->run('pdf', ['documentUid' => $uid2], 'GET', '', []);
        $stub = (string)ob_get_clean();
        expect_true(
            str_starts_with($stub, '%PDF') && str_contains($stub, 'TEST'),
            'без оригинала - заглушка с TEST'
        );
    },
    'placeholder.list: data массив (ListResponse принимает целиком)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        expect_eq($decode($handle('v1/document.placeholder.list/doc1'))['data'] ?? null, [], 'data=[]');
    },
    'stop.batch: data.results массив (StopBatchResponse)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        expect_eq($decode($handle('v1/document.signing.stop.batch'))['data']['results'] ?? null, [], 'results массив');
    },
    'заглушки успешных вызовов (данные порталью не читаются)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        foreach ([
            'v1/document.upload/doc1',
            'v1/document.reuse/doc1',
            'v1/document.signUntil.update/doc1',
            'v1/document.field.fill/doc1',
            'v1/document.signing.start/doc1',
            'v1/document.signing.stop/doc1',
            'v1/document.signing.sendInvite/doc1/m1',
            'v1/document.signing.resendMessage/doc1/m1',
        ] as $path) {
            $resp = $handle($path, ['x' => 1]);
            expect_eq($resp['status'] ?? null, 200, "200 для $path");
            expect_eq($decode($resp)['status'] ?? null, 'success', "success для $path");
        }
    },
    'интеграционно: портальный DocumentService::register получает uid' => static function (): void {
        Bootstrap::register();
        Loader::includeModule('sign');
        $scenario = (string)(DocumentScenario::getAll()[0] ?? 'b2e');
        $response = Container::instance()->getApiDocumentService()->register(
            new RegisterRequest(lang: 'ru', scenario: $scenario, title: 'Тестовый документ')
        );
        expect_true($response->isSuccess(), 'регистрация успешна: ' . implode('; ', $response->getErrorMessages()));
        expect_true($response->uid !== '', 'uid получен портальным клиентом');
    },
];
