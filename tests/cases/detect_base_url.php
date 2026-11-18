<?php

declare(strict_types=1);

use Aclips\Fakesign\Service\PublicUrl;


return [
    'isSecureRequest: HTTPS=off (nginx fastcgi) - не secure' => static function (): void {
        expect_eq(PublicUrl::isSecureRequest(['HTTPS' => 'off']), false, 'off');
        expect_eq(PublicUrl::isSecureRequest(['HTTPS' => 'Off']), false, 'Off (nginx)');
        expect_eq(PublicUrl::isSecureRequest(['HTTPS' => '']), false, 'пусто');
        expect_eq(PublicUrl::isSecureRequest([]), false, 'нет ключа');
    },
    'isSecureRequest: HTTPS=on и X-Forwarded-Proto - secure' => static function (): void {
        expect_eq(PublicUrl::isSecureRequest(['HTTPS' => 'on']), true, 'on');
        expect_eq(PublicUrl::isSecureRequest(['HTTPS' => 'ON']), true, 'ON');
        expect_eq(
            PublicUrl::isSecureRequest(['HTTP_X_FORWARDED_PROTO' => 'https']),
            true,
            'forwarded proto'
        );
    },
];
