<?php

declare(strict_types=1);

use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\Service\ConversionPreview;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;


/**
 * Bootstrap тестового раннера aclips.fakesign.
 * Модуль может быть ещё не установлен, поэтому неймспейс регистрируется вручную.
 */

$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
$_SERVER['REQUEST_METHOD'] = 'GET';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include.php';

Loader::registerNamespace(
    'Aclips\\Fakesign',
    $_SERVER['DOCUMENT_ROOT'] . '/local/modules/aclips.fakesign/lib'
);

function expect_true(bool $condition, string $message = ''): void
{
    if (!$condition)
    {
        throw new RuntimeException($message !== '' ? $message : 'условие не выполнено');
    }
}

function expect_eq(mixed $actual, mixed $expected, string $message = ''): void
{
    if ($actual !== $expected)
    {
        throw new RuntimeException(
            ($message !== '' ? $message . ' | ' : '')
            . 'ожидалось: ' . var_export($expected, true) . ', получено: ' . var_export($actual, true)
        );
    }
}

// Тесты никогда не ходят в сеть за колбэками (HTTP-транспорт - только в проде).
CallbackSender::setTransport(static fn(): bool => true);

// Тесты не ходят в сеть: сервис конвертации по умолчанию недоступен (фолбэк на локальное превью).
ConversionPreview::setTransport(
    static fn(string $url, string $method, ?array $body): array => ['status' => 500, 'body' => '']
);

// Тесты работают с заглушкой (галочка конвертера выключена); живое значение
// восстанавливается по завершении (в т.ч. при фатале).
$originalConverterFlag = Option::get('aclips.fakesign', 'use_cloud_converter', 'N');
Option::set('aclips.fakesign', 'use_cloud_converter', 'N');
register_shutdown_function(static function () use ($originalConverterFlag): void {
    Option::set('aclips.fakesign', 'use_cloud_converter', $originalConverterFlag);
});
