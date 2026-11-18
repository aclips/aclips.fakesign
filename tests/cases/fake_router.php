<?php

declare(strict_types=1);

use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Envelope;
use Aclips\Fakesign\FakeService\Router;

return [
    'Router: api-путь отделяет тип и путь' => static function (): void {
        expect_eq(Router::resolve('api/v1/document.register'), ['api', 'v1/document.register'], 'register');
        expect_eq(Router::resolve('probe'), ['action', 'probe'], 'action');
        expect_eq(
            Router::resolve('api/v1/b2e.member.getlinkforsigning/u1/m1/'),
            ['api', 'v1/b2e.member.getlinkforsigning/u1/m1/'],
            'путь с параметрами и завершающим слэшем сохраняется'
        );
    },
    'Envelope::success - обёртка формата службы' => static function (): void {
        $json = json_decode(Envelope::success(['x' => 1]), true);
        expect_eq($json['status'] ?? null, 'success', 'status');
        expect_eq($json['data'] ?? null, ['x' => 1], 'data');
        expect_eq($json['errors'] ?? null, [], 'errors пуст');
    },
    'Envelope::error - обёртка ошибки формата службы' => static function (): void {
        $json = json_decode(Envelope::error('NOT_IMPLEMENTED', 'нет хендлера'), true);
        expect_eq($json['status'] ?? null, 'error', 'status');
        expect_eq($json['data'] ?? null, null, 'data null');
        expect_eq($json['errors'][0]['code'] ?? null, 'NOT_IMPLEMENTED', 'код ошибки');
        expect_eq($json['errors'][0]['message'] ?? null, 'нет хендлера', 'сообщение');
    },
    'Api: неизвестный путь -> 501 NOT_IMPLEMENTED в формате службы' => static function (): void {
        $resp = Api::handle('v1/definitely.unknown.method', 'GET', [], '');
        expect_eq($resp['status'] ?? null, 501, 'HTTP-статус');
        $json = json_decode($resp['body'] ?? '', true);
        expect_eq($json['status'] ?? null, 'error', 'status в теле');
        expect_eq($json['errors'][0]['code'] ?? null, 'NOT_IMPLEMENTED', 'код');
    },
    'Api: известные пути (с параметрами) принимаются как success-заглушка' => static function (): void {
        foreach ([
            'v1/document.member.status.get/doc1/mem1/',
            'v1/b2e.company.get',
            'v1/b2e.member.getlinkforsigning/doc1/mem1/',
            'v1/b2e.external-sign-provider.list',
        ] as $path) {
            $resp = Api::handle($path, 'GET', [], '');
            expect_eq($resp['status'] ?? null, 200, "200 для $path");
            $json = json_decode($resp['body'] ?? '', true);
            expect_eq($json['status'] ?? null, 'success', "success для $path");
        }
    },
];
