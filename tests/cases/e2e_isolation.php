<?php

declare(strict_types=1);

use Bitrix\Main\Loader;
use Bitrix\Sign\Config\Storage;
use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\Callback\CallbackSender;

$handle = static function (string $path, array $body = []): array {
    return Api::handle($path, 'POST', [], $body === [] ? '' : json_encode($body));
};

$decode = static function (array $resp): array {
    $json = json_decode($resp['body'] ?? '', true);

    return is_array($json) ? $json : [];
};

return [
    'e2e: от компании в визаре до подписанного файла - полный проход фейка' => static function () use ($handle, $decode): void {
        Bootstrap::register();

        // 1) Выбор провайдера в визаре: company.get отдаёт нашего провайдера подключённым
        $companies = $decode($handle('v1/b2e.company.get', ['taxIds' => ['0000000000']]))['data']['companies'] ?? [];
        $provider = $companies[0]['providers'][0] ?? [];
        expect_eq($provider['code'] ?? null, 'external', 'код провайдера');
        expect_eq($provider['connected'] ?? null, true, 'провайдер подключён - окно регистрации не нужно');
        expect_true(!empty($provider['uid']), 'uid для отправки документа');

        // 2-4) Отправка документа
        $register = $decode($handle('v1/document.register', ['lang' => 'ru', 'scenario' => 'b2e', 'title' => 'E2E']))['data'] ?? [];
        $documentUid = (string)($register['id'] ?? '');
        expect_true($documentUid !== '', 'документ зарегистрирован');
        expect_eq(
            $decode($handle("v1/document.signing.configure/$documentUid", ['members' => [['key' => 'signer_1', 'id' => 'm-1']]]))['data']['members'][0]['key'] ?? null,
            'signer_1',
            'configure эхом участников'
        );
        expect_eq($decode($handle("v1/document.signing.sendInvite/$documentUid/m-1"))['status'] ?? null, 'success', 'приглашение отправлено');

        // 5-6) Ссылка и статус
        $link = $decode($handle("v1/b2e.member.getlinkforsigning/$documentUid/m-1"))['data'] ?? [];
        expect_true(str_contains((string)($link['uri'] ?? ''), '/fakesign/sign/'), 'ссылка на страницу подписания');
        expect_eq(
            $decode($handle("v1/document.member.status.get/$documentUid/m-1"))['data']['status'] ?? null,
            'ready',
            'статус готовности: ссылка выдана -> ready (иначе визар навсегда покажет Ожидание документа)'
        );

        // 7) Подписание + колбэки в портал
        $calls = [];
        $noop = static fn(): bool => true;
        $stand = static function (string $url, array $fields) use (&$calls): void {
            $calls[] = ['url' => $url, 'fields' => $fields];
        };
        $reset = static function () use (&$calls): void {
            $calls = [];
        };
        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/b2e.document.signing.sign/$documentUid/m-1");
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        expect_eq(
            $decode($handle("v1/document.member.status.get/$documentUid/m-1"))['data']['status'] ?? null,
            'done',
            'статус done'
        );
        $memberCallback = null;
        $documentCallback = null;
        foreach ($calls as $call)
        {
            $type = $call['fields']['payload']['type'] ?? null;
            if ($type === 'memberStatusChanged')
            {
                $memberCallback = $call;
            }
            if ($type === 'DocumentStatus')
            {
                $documentCallback = $call;
            }
        }
        expect_true($memberCallback !== null, 'memberStatusChanged ушёл в портал');
        expect_eq($memberCallback['fields']['payload']['data']['status'] ?? null, 'done', 'в колбэке done');
        expect_true($documentCallback !== null, 'DocumentStatus ушёл (все участники завершены)');
        expect_eq($documentCallback['fields']['payload']['data']['code'] ?? null, $documentUid, 'code=uid');
        expect_true(str_contains($memberCallback['url'] ?? '', 'sign.api.Callback.handle'), 'в адресе обработчик портала');

        // 8) Подписанный файл
        $file = $decode($handle("v1/document.signedfile.load/$documentUid/m-1"))['data'] ?? [];
        expect_eq($file['ready'] ?? null, true, 'файл готов');
        expect_true(str_contains((string)($file['file']['url'] ?? ''), '/fakesign/pdf/'), 'url на тестовый PDF');
    },
    'e2e: изоляция - портал не ходит в sign.bitrix24.ru' => static function (): void {
        Loader::includeModule('sign');
        $endpoint = Storage::instance()->getApiEndpoint();
        expect_true(!str_contains($endpoint, 'sign.bitrix24'), 'apiEndpoint локальный: ' . $endpoint);
        expect_true(
            is_file($_SERVER['DOCUMENT_ROOT'] . '/local/.settings_extra.php'),
            'конфиг подмены существует'
        );
    },
];
