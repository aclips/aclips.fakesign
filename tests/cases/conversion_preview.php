<?php

declare(strict_types=1);

use Aclips\Fakesign\FakeService\Handler\DocumentHandler;
use Bitrix\Main\Config\Option;
use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\Service\ConversionPreview;

$failureTransport = static fn(string $url, string $method, ?array $body): array => ['status' => 500, 'body' => ''];

$withConverterFlag = static function (string $value, callable $test): void {
    $original = Option::get('aclips.fakesign', 'use_cloud_converter', 'N');
    Option::set('aclips.fakesign', 'use_cloud_converter', $value);
    try {
        $test();
    } finally {
        Option::set('aclips.fakesign', 'use_cloud_converter', $original);
        ConversionPreview::setTransport($failureTransport);
    }
};

return [
    'галочка снята -> страницы-заглушка (page.view с приложенной картинкой), без обращений к конвертеру' => static function () use ($withConverterFlag): void {
        $withConverterFlag('N', static function (): void {
            Bootstrap::register();
            $calls = [];
            ConversionPreview::setTransport(static function (string $url, string $method, ?array $body) use (&$calls): array {
                $calls[] = ['url' => $url, 'method' => $method];
                return ['status' => 200, 'body' => '{}'];
            });

            $resp = Api::handle('v1/document.page.list/R-STUB-UID', 'GET', [], '');
            $pages = json_decode($resp['body'], true)['data']['pages'] ?? [];
            expect_true(count($pages) >= 1, 'страницы-заглушки отданы');
            expect_true(str_contains($pages[0]['url'] ?? '', 'page.view'), 'url - page.view');
            expect_eq(count($calls), 0, 'к конвертеру не обращались');

            $view = Api::handle('v1/document.page.view/R-STUB-UID/1', 'GET', [], '');
            expect_eq($view['contentType'] ?? null, 'image/png', 'content-type image/png');
            $expected = (string)file_get_contents(
                $_SERVER['DOCUMENT_ROOT'] . '/local/modules/aclips.fakesign/templates/document_placeholder.png'
            );
            expect_true($expected !== '' && ($view['body'] ?? '') === $expected, 'тело - ровно картинка-заглушка');
        });
    },

    'галочка включена: register + upload + page.list уходят в конвертер' => static function () use ($withConverterFlag): void {
        $withConverterFlag('Y', static function (): void {
            Bootstrap::register();

            $registerBody = [
                'lang' => 'ru',
                'scenario' => 'Dss:SecondParty.ManyMembers',
                'title' => 'Документ на конвертацию',
                'version' => 4,
                'hasPlaceholders' => false,
            ];
            $reg = Api::handle('v1/document.register', 'POST', [], json_encode($registerBody));
            $uid = (string)(json_decode($reg['body'], true)['data']['id'] ?? '');
            expect_true($uid !== '', 'uid фейка получен');
            $original = "%PDF-1.4% CVT-ORIGIN %%EOF";
            Api::handle("v1/document.upload/$uid", 'POST', [], json_encode([
                'uid' => $uid,
                'files' => [['name' => 'doc.pdf', 'type' => 'application/pdf', 'content' => base64_encode($original)]],
            ]));

            $calls = [];
            $remotePages = ['https://sign.bitrix24.ru/api/v1/document.page.view/CLOUD-9/1'];
            ConversionPreview::setTransport(static function (string $url, string $method, ?array $body) use (&$calls, $remotePages): array {
                $calls[] = ['url' => $url, 'method' => $method, 'body' => $body];
                if (str_contains($url, 'document.register')) {
                    return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => ['id' => 'CLOUD-9'], 'errors' => []])];
                }
                if (str_contains($url, 'document.upload')) {
                    return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => [], 'errors' => []])];
                }
                if (str_contains($url, 'document.page.list')) {
                    $pages = array_map(static fn(string $u): array => ['url' => $u], $remotePages);
                    return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => ['ready' => true, 'pages' => $pages], 'errors' => []])];
                }
                return ['status' => 404, 'body' => ''];
            });

            $urls = ConversionPreview::fetchPages($uid);
            expect_eq($urls, $remotePages, 'страницы из конвертера');

            expect_eq(count($calls), 3, 'ровно три запроса: register, upload, page.list');
            expect_true(
                str_contains($calls[0]['url'], 'https://sign.bitrix24.ru/api/v1/document.register'),
                'URL register - фиксированный конвертер sign.bitrix24.ru'
            );
            expect_eq($calls[0]['method'] ?? null, 'POST', 'register - POST');
            $sentRegister = is_array($calls[0]['body'] ?? null) ? $calls[0]['body'] : [];
            expect_eq($sentRegister['scenario'] ?? null, 'Dss:SecondParty.ManyMembers', 'scenario из исходного запроса');
            expect_eq($sentRegister['title'] ?? null, 'Документ на конвертацию', 'title из исходного запроса');

            expect_true(str_contains($calls[1]['url'], '/api/v1/document.upload/CLOUD-9/'), 'URL upload с uid конвертера');
            $sentUpload = is_array($calls[1]['body'] ?? null) ? $calls[1]['body'] : [];
            expect_eq(base64_decode((string)($sentUpload['files'][0]['content'] ?? ''), true), $original, 'файл ушёл как есть');

            expect_true(str_contains($calls[2]['url'], '/api/v1/document.page.list/CLOUD-9/'), 'URL page.list с uid конвертера');

            $manifest = DocumentHandler::readManifest($uid);
            expect_eq($manifest['conversionUid'] ?? null, 'CLOUD-9', 'uid конвертера закэширован');
            expect_eq($manifest['conversionUploaded'] ?? null, true, 'флаг загрузки проставлен');

            // повторный вызов - только page.list (без повторных register/upload)
            ConversionPreview::fetchPages($uid);
            expect_eq(count($calls), 4, 'повторный вызов - только page.list');
        });
    },

    'галочка включена, конвертер конвертирует -> pages() пуст и ждёт (без заглушки)' => static function () use ($withConverterFlag): void {
        $withConverterFlag('Y', static function (): void {
            Bootstrap::register();
            $reg = Api::handle('v1/document.register', 'POST', [], json_encode([
                'lang' => 'ru',
                'scenario' => 'Dss:SecondParty.ManyMembers',
                'title' => 'Ожидание',
                'version' => 4,
                'hasPlaceholders' => false,
            ]));
            $uid = (string)(json_decode($reg['body'], true)['data']['id'] ?? '');
            expect_true($uid !== '', 'uid фейка получен');

            ConversionPreview::setTransport(static function (string $url, string $method, ?array $body): array {
                if (str_contains($url, 'document.register')) {
                    return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => ['id' => 'CLOUD-W'], 'errors' => []])];
                }
                if (str_contains($url, 'document.upload')) {
                    return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => [], 'errors' => []])];
                }
                if (str_contains($url, 'document.page.list')) {
                    return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => ['ready' => false, 'pages' => []], 'errors' => []])];
                }
                return ['status' => 404, 'body' => ''];
            });

            $resp = Api::handle("v1/document.page.list/$uid", 'GET', [], '');
            $data = json_decode($resp['body'], true)['data'] ?? [];
            expect_eq($data['pages'] ?? null, [], 'страниц пока нет - фронт продолжает ждать');
            expect_eq($data['ready'] ?? null, false, 'ready=false - ожидание конвертера, не заглушка');

            $manifest = DocumentHandler::readManifest($uid);
            expect_eq($manifest['conversionUid'] ?? null, 'CLOUD-W', 'sync прошёл и закэширован');
        });
    },

    'ошибка конвертера -> пусто и без кэша' => static function () use ($withConverterFlag): void {
        $withConverterFlag('Y', static function (): void {
            Bootstrap::register();
            ConversionPreview::setTransport(static fn(string $url, string $method, ?array $body): array => [
                'status' => 500,
                'body' => 'error',
            ]);

            $reg = Api::handle('v1/document.register', 'POST', [], json_encode(['lang' => 'ru', 'scenario' => 'Dss:SecondParty.ManyMembers', 'title' => 'X', 'version' => 4, 'hasPlaceholders' => false]));
            $uid = (string)(json_decode($reg['body'], true)['data']['id'] ?? '');

            expect_eq(ConversionPreview::fetchPages($uid), [], 'ошибка -> пусто (заглушка)');
            $manifest = DocumentHandler::readManifest($uid);
            expect_true(empty($manifest['conversionUid']), 'неудачный sync не кэшируется');
        });
    },
];
