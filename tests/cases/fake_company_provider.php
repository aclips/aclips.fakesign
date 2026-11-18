<?php

declare(strict_types=1);

use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\ProviderDescriptor;

$handle = static function (string $path, string $body = ''): array {
    return Api::handle($path, 'POST', [], $body);
};

$decode = static function (array $resp): array {
    $json = json_decode($resp['body'] ?? '', true);

    return is_array($json) ? $json : [];
};

return [
    'company.get: наших провайдера для каждого запрошенного taxId' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.company.get', json_encode(['taxIds' => ['123456789', '0000000000']]));
        expect_eq($resp['status'] ?? null, 200, 'статус');
        $companies = $decode($resp)['data']['companies'] ?? [];
        expect_eq(count($companies), 2, 'по компании на каждый taxId');
        expect_eq($companies[0]['taxId'] ?? null, '123456789', 'taxId эхом');
        $provider = $companies[0]['providers'][0] ?? [];
        expect_eq($provider['code'] ?? null, 'external', 'code');
        expect_eq($provider['connected'] ?? null, true, 'провайдер подключён сразу');
        expect_true(!empty($provider['uid']), 'uid непуст');
        // listAction портала читает iconUrl (B2eCompany.php) - без него карточка
        // провайдера в визаре рисует битую картинку.
        $iconUrl = (string)($provider['iconUrl'] ?? '');
        expect_true(
            str_starts_with($iconUrl, 'http') && str_contains($iconUrl, '/fakesign/icon/'),
            'iconUrl ведёт на нашу иконку, получено: ' . $iconUrl
        );
        // date: портальное поле "Подключено" -0 даёт эпоху 01.01.1970
        expect_true((int)($provider['date'] ?? 0) > 0, 'date - реальный timestamp, не 0');
        expect_true(empty($companies[0]['register_url']), 'без облачного register_url - окно регистрации не нужно');
    },
    'контракт: uid провайдера не короче 32 (company_uid varchar(32), иначе modifyCompany -> BX_INVALID_VALUE)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $uid = ProviderDescriptor::uid();
        expect_true(strlen($uid) >= 32, 'ProviderDescriptor::uid() >= 32, получено: ' . $uid . ' (len ' . strlen($uid) . ')');

        $resp = $handle('v1/b2e.company.get', json_encode(['taxIds' => ['7713722932']]));
        $provider = $decode($resp)['data']['companies'][0]['providers'][0] ?? [];
        expect_true(strlen((string)($provider['uid'] ?? '')) >= 32, 'uid в company.get >= 32');
        expect_eq($provider['uid'] ?? null, $uid, 'uid стабилен между вызовами');
    },
    'company.provider.get: code для портального ProviderCodeService' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.company.provider.get?companyUid=x');
        expect_eq($decode($resp)['data']['code'] ?? null, 'external', 'code');
    },
    'company.registerByClient: строковый id' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.company.registerByClient', json_encode([
            'taxId' => '123456789', 'providerCode' => 'external', 'providerData' => ['companyName' => 'TEST'],
        ]));
        $id = $decode($resp)['data']['id'] ?? '';
        expect_true(is_string($id) && $id !== '', 'id - непустая строка');
    },
    'external-sign-provider.list: форма из наблюдаемого ответа службы' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.external-sign-provider.list?limit=10');
        $item = $decode($resp)['data']['result'][0] ?? [];
        foreach (['id', 'title', 'description', 'iconUri', 'companyRegUri', 'documentSignUri', 'publicKeyUri'] as $key)
        {
            expect_true(array_key_exists($key, $item), "поле $key присутствует");
        }
        expect_true(strpos((string)$item['documentSignUri'], '/fakesign/sign/') !== false, 'documentSignUri указывает на нашу страницу');
    },
    'external-sign-provider.add: result со scalar id (стабильный id провайдера)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.external-sign-provider.add', json_encode([
            'title' => 'Тестовый провайдер',
            'description' => 'ТЕСТ',
            'iconUri' => 'http://localhost/fakesign/icon/',
            'companyRegUri' => 'http://localhost/fakesign/register/',
            'documentSignUri' => 'http://localhost/fakesign/sign/',
            'publicKeyUri' => 'http://localhost/fakesign/public-key/',
        ]));
        $result = $decode($resp)['data']['result'] ?? null;
        expect_true(is_scalar($result) && (string)$result !== '', 'result - scalar id');
    },
    'schemes и fields: валидные формы для портальных парсеров' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $schemes = $decode($handle('v1/b2e.provider.getAvailableSchemes?uid=x'))['data']['schemes'] ?? null;
        expect_true(is_array($schemes) && $schemes !== [], 'schemes непустой массив');
        foreach ($schemes as $scheme)
        {
            expect_true(in_array($scheme, ['default', 'order'], true), "схема '$scheme' валидна по SchemeType");
        }

        $fields = $decode($handle('v1/b2e.provider.getFields?uid=x'))['data']['fields'] ?? null;
        expect_true(is_array($fields), 'fields - массив (createFromJsonArray принимает)');
    },
    'известный путь с хендлером не падает в NOT_IMPLEMENTED' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.company.get', json_encode(['taxIds' => ['1']]));
        expect_eq($decode($resp)['status'] ?? null, 'success', 'success-обёртка');
        expect_eq($resp['status'] ?? null, 200, 'HTTP 200');
    },
];
