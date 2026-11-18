<?php

declare(strict_types=1);

use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\Service\DelayAgent;
use Aclips\Fakesign\Service\SigningSimulator;
use Aclips\Fakesign\Service\DocumentStore;

$calls = [];

$stand = static function (string $url, array $fields) use (&$calls): void {
    $calls[] = ['url' => $url, 'fields' => $fields];
};

$reset = static function () use (&$calls): void {
    $calls = [];
};

// Возврат после теста: no-op вместо null (null = HTTP-транспорт по умолчанию,
// сеть в тестах недопустима; no-op ставит и tests/bootstrap.php).
$noop = static fn(): bool => true;

$lastOfType = static function (string $type) use (&$calls): ?array {
    foreach (array_reverse($calls) as $call)
    {
        $payloadType = $call['fields']['payload']['type'] ?? null;
        if ($payloadType === $type)
        {
            return $call;
        }
    }

    return null;
};

$handle = static function (string $path, array $body = []): array {
    return Api::handle($path, 'POST', [], $body === [] ? '' : json_encode($body));
};

return [
    'встраивание: sign через API шлёт memberStatusChanged в транспорт' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle('v1/b2e.document.signing.sign/doc-cb1/mem-cb1');
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        $call = $lastOfType('memberStatusChanged');
        expect_true($call !== null, 'вызов транспорта произошёл');
        expect_true(str_contains($call['url'] ?? '', 'sign.api.Callback.handle'), 'адрес обработчика портала');
        expect_true(str_contains($call['url'] ?? '', 'token='), 'токен в url');
        expect_eq($call['fields']['payload']['data']['documentUid'] ?? null, 'doc-cb1', 'documentUid');
        expect_eq($call['fields']['payload']['data']['status'] ?? null, 'done', 'статус участника');
    },
    'не все известные участники подписали - documentStatus НЕ шлётся' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $store = new DocumentStore();
        $doc = 'doc-cb2' . uniqid();
        $store->touch($doc, 'm-a');
        $store->touch($doc, 'm-b');

        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/b2e.document.signing.sign/$doc/m-a");
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        expect_true($lastOfType('memberStatusChanged') !== null, 'memberStatus ушёл');
        expect_eq($lastOfType('DocumentStatus'), null, 'документ не завершён - колбэка нет');
    },
    'все известные участники подписали - documentStatus done' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $store = new DocumentStore();
        $doc = 'doc-cb3' . uniqid();
        $store->touch($doc, 'm-a');
        $store->touch($doc, 'm-b');

        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/b2e.document.signing.sign/$doc/m-a");
            $handle("v1/b2e.document.signing.sign/$doc/m-b");
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        $call = $lastOfType('DocumentStatus');
        expect_true($call !== null, 'documentStatus отправлен');
        expect_eq($call['fields']['payload']['data']['status'] ?? null, 'done', 'документ done');
        expect_eq($call['fields']['payload']['data']['code'] ?? null, $doc, 'code=uid документа');
    },
    'документ НЕ завершается после подписи одним из двух участников (configure знает обоих)' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $doc = 'R-TWO' . uniqid();
        $m1 = str_repeat('1', 32);
        $m2 = str_repeat('2', 32);
        // Портал на configure сообщил обоих участников (манифест),
        // но ссылку пока открыл/подписал только первый.
        $handle("v1/document.signing.configure/$doc", [
            'members' => [['key' => null, 'uid' => $m1], ['key' => null, 'uid' => $m2]],
        ]);

        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/b2e.document.signing.sign/$doc/$m1");
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        expect_true($lastOfType('memberStatusChanged') !== null, 'memberStatus первому участнику ушёл');
        expect_eq(
            $lastOfType('DocumentStatus'),
            null,
            'ВТОРОЙ участник ещё не подписал - documentStatus НЕ должен уходить (иначе "Подписание завершено" после первого)'
        );

        // Второй подписал - теперь документ действительно завершён.
        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/b2e.document.signing.sign/$doc/$m2");
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        $call = $lastOfType('DocumentStatus');
        expect_true($call !== null, 'после второго - documentStatus уходит');
        expect_eq($call['fields']['payload']['data']['status'] ?? null, 'done', 'статус документа done');
    },
    'завершение через отказ (все завершены, есть refused) - documentStatus stopped' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $store = new DocumentStore();
        $doc = 'doc-cb4' . uniqid();
        $store->touch($doc, 'm-a');

        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $handle("v1/b2e.document.signing.refuse/$doc/m-a");
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        $call = $lastOfType('DocumentStatus');
        expect_true($call !== null, 'documentStatus по отказу');
        expect_eq($call['fields']['payload']['data']['status'] ?? null, 'stopped', 'документ stopped');
    },
    'завершение: участнику уходит memberResultFile с ZIP-контейнером, документу - ResultFile' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $doc = 'R-FILE' . uniqid();
        $member = str_repeat('f', 32);
        $handle("v1/document.signing.configure/$doc", [
            'members' => [['key' => null, 'uid' => $member]],
        ]);

        $reset();
        CallbackSender::setTransport($stand);
        try {
            $handle("v1/b2e.document.signing.sign/$doc/$member");
        } finally {
            CallbackSender::setTransport($noop);
        }

        $memberFile = $lastOfType('memberResultFile');
        expect_true($memberFile !== null, 'memberResultFile ушёл');
        $file = $memberFile['fields']['payload']['data']['file'] ?? [];
        expect_true(str_ends_with((string)($file['name'] ?? ''), '.zip'), 'имя .zip');
        $bytes = (string)base64_decode((string)($file['content'] ?? ''), true);
        expect_true(str_starts_with($bytes, 'PK'), 'магия ZIP участника');

        $docFile = $lastOfType('ResultFile');
        expect_true($docFile !== null, 'ResultFile ушёл');
        expect_eq($docFile['fields']['payload']['data']['documentCode'] ?? null, $doc, 'documentCode');
        $docBytes = (string)base64_decode((string)($docFile['fields']['payload']['data']['file']['content'] ?? ''), true);
        expect_true(str_starts_with($docBytes, 'PK'), 'магия ZIP документа');

        expect_true($lastOfType('DocumentStatus') !== null, 'DocumentStatus done уходит');
    },
    'задержка через DelayAgent тоже шлёт колбэки' => static function () use ($handle, $stand, $lastOfType, $reset, $noop): void {
        Bootstrap::register();
        $store = new DocumentStore();
        $doc = 'doc-cb5' . uniqid();
        $delayed = (new SigningSimulator($store))
            ->apply($store->touch($doc, 'm-a'), SigningSimulator::ACTION_DELAY, 30);

        $reset();
        CallbackSender::setTransport($stand);
        try
        {
            $due = new \DateTimeImmutable('@' . (strtotime((string)$delayed->requestedAt) + 31));
            DelayAgent::run($due);
        }
        finally
        {
            CallbackSender::setTransport($noop);
        }

        $call = $lastOfType('memberStatusChanged');
        expect_true($call !== null, 'агент отправил memberStatus');
        expect_eq($call['fields']['payload']['data']['status'] ?? null, 'done', 'done после задержки');
    },
];
