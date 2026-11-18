<?php

declare(strict_types=1);

use Bitrix\Sign\Item\Api\Batch\ItemResult;
use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\Service\DocumentStore;
use Aclips\Fakesign\Service\SigningSimulator;

$handle = static function (string $path, array $body = []): array {
    return Api::handle($path, 'POST', [], $body === [] ? '' : json_encode($body));
};

$decode = static function (array $resp): array {
    $json = json_decode($resp['body'] ?? '', true);

    return is_array($json) ? $json : [];
};

$store = static fn(): DocumentStore => new DocumentStore();

return [
    'getlinkforsigning: uri ведёт на нашу страницу с одноразовым токеном' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $data = $decode($handle('v1/b2e.member.getlinkforsigning/doc-l1/mem-l1'))['data'] ?? [];
        $uri = (string)($data['uri'] ?? '');
        expect_true(str_contains($uri, '/fakesign/sign/'), 'uri на нашу страницу');

        parse_str((string)parse_url($uri, PHP_URL_QUERY), $query);
        expect_eq($query['documentUid'] ?? null, 'doc-l1', 'documentUid в ссылке');
        expect_eq($query['memberUid'] ?? null, 'mem-l1', 'memberUid в ссылке');
        expect_true(strlen((string)($query['token'] ?? '')) === 64, 'токен в ссылке');
    },
    'status.get: неизвестный -> wait, после sign -> done' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $doc = 'doc-s' . uniqid();
        $mem = 'mem-s' . uniqid();
        $before = $decode($handle("v1/document.member.status.get/$doc/$mem"))['data']['status'] ?? null;
        expect_eq($before, 'wait', 'до знака - wait');

        $handle("v1/b2e.document.signing.sign/$doc/$mem");
        $after = $decode($handle("v1/document.member.status.get/$doc/$mem"))['data']['status'] ?? null;
        expect_eq($after, 'done', 'после sign - done');
    },
    'страница sign: FormData включает submitter - иначе кнопка scenario не доходит и сервер даёт BAD_SCENARIO' => static function (): void {
        $template = file_get_contents(
            $_SERVER['DOCUMENT_ROOT'] . '/local/modules/aclips.fakesign/templates/signing.php'
        );
        expect_true($template !== false, 'шаблон читается');
        expect_true(
            str_contains($template, 'new FormData(form, event.submitter)'),
            'FormData строится с submitter (новый FormData(form) НЕ включает кнопку-отправитель)'
        );
        expect_true(
            str_contains($template, "name=\"scenario\""),
            'кнопки со scenario на месте'
        );
    },
    'configure запоминает участников; start шлёт memberStatusChanged(ready) каждому' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $doc = 'R-ST' . uniqid();
        $u1 = str_repeat('a', 32);
        $u2 = str_repeat('b', 32);
        $handle("v1/document.signing.configure/$doc", [
            'members' => [
                ['key' => null, 'uid' => $u1, 'userId' => 1],
                ['key' => null, 'uid' => $u2, 'userId' => 2],
            ],
        ]);

        $calls = [];
        $stand = static function (string $url, array $fields) use (&$calls): void {
            $calls[] = ['url' => $url, 'fields' => $fields];
        };
        $noop = static fn(): bool => true;
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/document.signing.start/$doc", ['documentUid' => $doc]);
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        $memberCalls = array_values(array_filter(
            $calls,
            static fn(array $call): bool => ($call['fields']['payload']['type'] ?? '') === 'memberStatusChanged'
        ));
        expect_eq(count($memberCalls), 2, 'колбэк готовности на каждого участника');
        $uids = array_map(static fn(array $call): string => $call['fields']['payload']['data']['memberUid'] ?? '', $memberCalls);
        sort($uids);
        expect_eq($uids, [$u1, $u2], 'uid участников - из configure');
        expect_eq($memberCalls[0]['fields']['payload']['data']['status'] ?? null, 'ready', 'web-статус ready');
        expect_true(str_contains($memberCalls[0]['url'] ?? '', 'sign.api.Callback.handle'), 'адрес обработчика портала');
    },
    'status.get: без start -> wait; после start -> ready; pending-строка -> ready; sign -> done' => static function () use ($handle, $decode, $store): void {
        Bootstrap::register();
        $docA = 'R-NS' . uniqid();
        $before = $decode($handle("v1/document.member.status.get/$docA/m-1"))['data']['status'] ?? null;
        expect_eq($before, 'wait', 'документ не начат - wait');

        $docB = 'R-ST' . uniqid();
        $handle("v1/document.signing.start/$docB", ['documentUid' => $docB]);
        $ready = $decode($handle("v1/document.member.status.get/$docB/m-1"))['data']['status'] ?? null;
        expect_eq($ready, 'ready', 'после start участники готовы к подписи (даже без нашего состояния)');

        $store()->touch($docB, 'm-2');
        $pending = $decode($handle("v1/document.member.status.get/$docB/m-2"))['data']['status'] ?? null;
        expect_eq($pending, 'ready', 'pending-строка -> ready (а не wait - иначе "Ожидание документа" вечно)');

        $handle("v1/b2e.document.signing.sign/$docB/m-2");
        $done = $decode($handle("v1/document.member.status.get/$docB/m-2"))['data']['status'] ?? null;
        expect_eq($done, 'done', 'после sign -> done');
    },
    'refuse через API переводит состояние и отражается в status.get' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $suffix = uniqid();
        $handle("v1/b2e.document.signing.sign/doc-r1$suffix");     // done участника завершён...
        $handle("v1/b2e.document.signing.refuse/doc-r1$suffix");   // ...повтор поверх done не меняет (идемпотентность)
        $doc = "doc-r2$suffix";
        $handle("v1/b2e.document.signing.refuse/$doc/mem-r2");
        $status = $decode($handle("v1/document.member.status.get/$doc/mem-r2"))['data']['status'] ?? null;
        expect_eq($status, 'refused', 'отказ -> refused');
    },
    'error через API -> stopped в контракте портала' => static function () use ($handle, $decode, $store): void {
        Bootstrap::register();
        $doc = 'doc-e' . uniqid();
        $mem = 'mem-e' . uniqid();
        $store()->touch($doc, $mem);
        (new SigningSimulator($store()))->apply($store()->touch($doc, $mem), SigningSimulator::ACTION_ERROR);
        $status = $decode($handle("v1/document.member.status.get/$doc/$mem"))['data']['status'] ?? null;
        expect_eq($status, 'stopped', 'error -> stopped');
    },
    'batch refuse: эхом items со status=success (принимается ItemResult)' => static function () use ($handle, $decode): void {
        Bootstrap::register();
        $resp = $handle('v1/b2e.document.signing.refuse.batch', [
            'items' => [
                ['documentId' => 'doc-b1', 'memberId' => 'mem-b1'],
                ['documentId' => 'doc-b1', 'memberId' => 'mem-b2'],
            ],
        ]);
        $results = $decode($resp)['data']['results'] ?? [];
        expect_eq(count($results), 2, 'по результату на элемент');
        foreach ($results as $item)
        {
            $parsed = ItemResult::createFromResponseItem($item);
            expect_true($parsed->isSuccess(), 'портальный парсер считает успехом');
        }
    },
    'CallbackSender: payload memberStatusChanged соответствует форме Callback/Messages' => static function (): void {
        $payload = CallbackSender::memberStatusPayload('mem-c1', 'doc-c1', 'done');
        expect_eq($payload['type'] ?? null, 'memberStatusChanged', 'type');
        expect_eq($payload['data']['memberUid'] ?? null, 'mem-c1', 'memberUid');
        expect_eq($payload['data']['documentUid'] ?? null, 'doc-c1', 'documentUid');
        expect_eq($payload['data']['status'] ?? null, 'done', 'status');
        expect_eq($payload['data']['provider'] ?? null, 'external', 'provider');
    },
    'CallbackSender: payload DocumentStatus с code=documentUid' => static function (): void {
        $payload = CallbackSender::documentStatusPayload('doc-c2', 'done');
        expect_eq($payload['type'] ?? null, 'DocumentStatus', 'type');
        expect_eq($payload['data']['code'] ?? null, 'doc-c2', 'code=uid документа');
        expect_eq($payload['data']['status'] ?? null, 'done', 'status');
    },
    'CallbackSender::send шлёт форму с токеном через подменённый транспорт' => static function (): void {
        $captured = [];
        $transport = static function (string $url, array $fields) use (&$captured): void {
            $captured = ['url' => $url, 'fields' => $fields];
        };
        CallbackSender::send(
            'http://portal.local',
            CallbackSender::memberStatusPayload('m', 'd', 'done'),
            $transport
        );
        expect_true(str_contains($captured['url'] ?? '', 'sign.api.Callback.handle'), 'адрес обработчика колбэка');
        expect_true(str_contains($captured['url'] ?? '', 'token='), 'токен в запросе');
        expect_eq($captured['fields']['payload']['type'] ?? null, 'memberStatusChanged', 'форма payload[type]');
    },
];
