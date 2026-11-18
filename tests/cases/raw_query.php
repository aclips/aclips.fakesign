<?php

declare(strict_types=1);

use Aclips\Fakesign\Controller\Entrypoint;


return [
    'parseRawQueryString сохраняет литеральный + (не превращает в пробел)' => static function (): void {
        $params = Entrypoint::parseRawQueryString('payload=AA+BB==&signature=xx+yy');
        expect_eq($params['payload'] ?? null, 'AA+BB==', 'payload с +');
        expect_eq($params['signature'] ?? null, 'xx+yy', 'signature с +');
    },
    'parseRawQueryString декодирует %2B и %20' => static function (): void {
        $params = Entrypoint::parseRawQueryString('a=AA%2BB&b=x%20y');
        expect_eq($params['a'] ?? null, 'AA+B', '%2B -> +');
        expect_eq($params['b'] ?? null, 'x y', '%20 -> пробел');
    },
    'parseRawQueryString разбирает строку диспетчера с action и ?payload' => static function (): void {
        $params = Entrypoint::parseRawQueryString(
            'action=register&?payload=eyJ9&signature=s+g=='
        );
        expect_eq($params['action'] ?? null, 'register', 'action');
        expect_eq($params['payload'] ?? null, 'eyJ9', 'payload (ведущий ? в ключе снят)');
        expect_eq($params['signature'] ?? null, 's+g==', 'signature с +');
        expect_eq(isset($params['?payload']), false, 'мусорный ключ не создаётся');
    },
    'parseRawQueryString пустая строка -> пустой массив' => static function (): void {
        expect_eq(Entrypoint::parseRawQueryString(''), [], 'пусто');
        expect_eq(Entrypoint::parseRawQueryString('onlykey'), ['onlykey' => ''], 'ключ без =');
    },
];
