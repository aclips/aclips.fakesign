<?php

declare(strict_types=1);

use Aclips\Fakesign\Controller\Entrypoint;
use Aclips\Fakesign\FakeService\Api;
use Aclips\Fakesign\FakeService\Bootstrap;
use Aclips\Fakesign\FakeService\Router;
use Aclips\Fakesign\Service\RequestJournal;
use Bitrix\Main\Loader;


/**
 * Фронт-контроллер тестового сервиса aclips.fakesign.
 *
 * Установщик копирует этот файл в DOCUMENT_ROOT/fakesign/index.php (публичная часть)
 * и регистрирует правило UrlRewriter:
 *   #^/*fakesign/(?<action>[a-zA-Z0-9._/-]+)# -> action=$1&
 * (захват идёт до '?' или конца строки - многосегментные api-пути сохраняются целиком;
 *  завершающий '/' у action-путей срезается ниже)
 *
 * Ветки:
 *   fakesign/api/v1/... - API фейк-сервиса Sign Service (service.address подменён)
 *   fakesign/<action>/  - веб-точки (sign/pdf/icon)
 *
 * Константы обязательны: без них бут main/include.php рендерит страницу логина и выходит.
 */

$_SERVER['DOCUMENT_ROOT'] ??= (static function (): string {
    if (is_dir(__DIR__ . '/bitrix'))
    {
        return __DIR__;
    }
    if (is_dir(__DIR__ . '/../bitrix'))
    {
        return dirname(__DIR__);
    }

    return (string)realpath(__DIR__ . '/../../../..');
})();

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include.php';

if (!Loader::includeModule('aclips.fakesign'))
{
    header((string)($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 503 Service Unavailable', true, 503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('aclips.fakesign is not installed');
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = (string)file_get_contents('php://input');

[$routeType, $routePath] = Router::resolve((string)($_GET['action'] ?? ''));

if ($routeType === 'api')
{
    // Диспетчер urlrewrite склеивает query с мусорными ключами вида '?companyUid' -
    // разбираем СЫРУЮ строку (parseRawQueryString чинит ведущий '?').
    $apiQuery = Entrypoint::parseRawQueryString(
        (string)($_SERVER['QUERY_STRING'] ?? '')
    );
    unset($apiQuery['action']);

    // Журнал входящего запроса - до обработки; чувствительные
    // заголовки (X-Sign-Client-*) маскируются внутри RequestJournal.
    (new RequestJournal())->record(
        'api',
        null,
        null,
        [
            'path' => $routePath,
            'method' => $method,
            'query' => $apiQuery,
            'headers' => $headers,
            'body' => $body,
            'receivedAt' => gmdate('c'),
        ]
    );

    Bootstrap::register();
    $response = Api::handle($routePath, $method, $apiQuery, $body);
    $status = (int)$response['status'];
    $responseBody = (string)$response['body'];

    // Диспетчер urlrewrite шлёт '200 OK' ДО включения файла - статус ставим
    // явной статусной строкой (http_response_code здесь не работает).
    $protocol = (string)($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
    $reason = match ($status)
    {
        404 => 'Not Found',
        501 => 'Not Implemented',
        default => 'OK',
    };
    header($protocol . ' ' . $status . ' ' . $reason, true, $status);
    header('Content-Type: ' . ($response['contentType'] ?? 'application/json; charset=utf-8'));
    echo $responseBody;

    exit;
}

// Action-пути захватываются с возможным завершающим '/' - срезаем для сравнения имён.
(new Entrypoint())->run(rtrim($routePath, '/'), $_GET, $method, $body, $headers);
