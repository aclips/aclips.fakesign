<?php

declare(strict_types=1);

use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\TestPdf;

$handle = static function (string $path): array {
    return Api::handle($path, 'GET', [], '');
};

$decode = static function (array $resp): array {
    $json = json_decode($resp['body'] ?? '', true);

    return is_array($json) ? $json : [];
};

return [
    'TestPdf: валидная структура PDF и маркировка' => static function (): void {
        $pdf = TestPdf::render('doc-pdf-1');

        expect_true(str_starts_with($pdf, '%PDF-1.4'), 'заголовок PDF');
        expect_true(str_contains($pdf, '%%EOF'), 'конец файла');
        expect_true(str_contains($pdf, 'TEST'), 'латинская маркировка в потоке текста (видна в любом просмотрщике)');
        // ТЕСТ кириллицей в свойствах документа (UTF-16BE + BOM) - видно в свойствах файла
        expect_true(str_contains($pdf, iconv('UTF-8', 'UTF-16BE', 'ТЕСТ')), 'ТЕСТ в Info/Title (UTF-16BE)');
        expect_true(str_contains($pdf, 'doc-pdf-1'), 'заголовок документа в файле');
    },
    'signedfile.load: ready=true и file.url на нашу pdf-точку' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $data = $decode($handle('v1/document.signedfile.load/doc-f1/mem-f1'))['data'] ?? [];
        expect_eq($data['ready'] ?? null, true, 'ready');
        $url = (string)($data['file']['url'] ?? '');
        expect_true(str_contains($url, '/fakesign/pdf/'), 'url ведёт на наш pdf');
        expect_true(str_contains($url, 'documentUid=doc-f1'), 'uid документа в url');
    },
    'printversion.load: та же форма (List/Page потребитель)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $data = $decode($handle('v1/document.printversion.load/doc-f2/mem-f2'))['data'] ?? [];
        expect_eq($data['ready'] ?? null, true, 'ready');
        expect_true(str_contains((string)($data['file']['url'] ?? ''), '/fakesign/pdf/'), 'url на pdf');
    },
];
