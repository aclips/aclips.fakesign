<?php

declare(strict_types=1);

use Aclips\Fakesign\Service\DocumentStore;

$store = static fn(): DocumentStore => new DocumentStore();

return [
    'touch создаёт состояние и выдаёт токен' => static function () use ($store): void {
        $s = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        expect_eq($s->status, 'pending', 'статус по умолчанию');
        expect_true(is_string($s->token) && strlen($s->token) === 64, 'токен выдан (hex64)');
        expect_true($s->id > 0, 'id записи');
        expect_eq($s->isCompleted(), false, 'ещё не завершён');
    },
    'повторный touch идемпотентен (тот же id, тот же hash, без повторного токена)' => static function () use ($store): void {
        $doc = 'doc-' . uniqid();
        $mem = 'mem-' . uniqid();

        $first = $store()->touch($doc, $mem);
        $second = $store()->touch($doc, $mem);

        expect_eq($second->id, $first->id, 'та же запись');
        expect_eq($second->tokenHash, $first->tokenHash, 'hash не меняется');
        expect_eq($second->token, null, 'раскрытый токен не выдаётся повторно');
        expect_eq($second->status, 'pending', 'статус не сброшен');
    },
    'verifyToken принимает свой токен и отвергает чужой' => static function () use ($store): void {
        $s = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        $token = (string)$s->token;

        expect_eq($store()->verifyToken($token)?->id, $s->id, 'свой токен принят');
        expect_eq($store()->verifyToken('чужой-токен'), null, 'чужой отвергнут');
        expect_eq($store()->verifyToken(''), null, 'пустой отвергнут');
    },
    'reissue меняет токен: прежний перестаёт действовать' => static function () use ($store): void {
        $doc = 'doc-' . uniqid();
        $mem = 'mem-' . uniqid();
        $first = (string)$store()->touch($doc, $mem)->token;
        expect_true($first !== '', 'первый токен выдан');

        $newToken = $store()->reissue($doc, $mem);
        expect_true($newToken !== '' && $newToken !== $first, 'новый токен отличается');
        expect_eq($store()->verifyToken($first), null, 'прежний токен мёртв');
        expect_eq($store()->verifyToken($newToken)?->documentUid, $doc, 'новый токен принят');
    },
];
