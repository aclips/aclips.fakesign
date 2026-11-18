<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Controller;

use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\FakeService\Envelope;
use Aclips\Fakesign\FakeService\Handler\DocumentHandler;
use Aclips\Fakesign\FakeService\TestPdf;
use Aclips\Fakesign\Service\DocumentStore;
use Aclips\Fakesign\Service\RequestJournal;
use Aclips\Fakesign\Service\SigningSimulator;
use Bitrix\Main;

/**
 * Точка входа тестового сервиса (/fakesign/<action>/): сначала журнал
 * входящего запроса, затем выбор обработчика по действию.
 */
final class Entrypoint
{
    /** Пути, для которых сервис отвечает 501 Not Implemented. */
    private const NOT_IMPLEMENTED_ACTIONS = ['callback'];

    private const STATUS_REASONS = [
        302 => 'Found',
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
    ];

    private const ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
    . '<rect width="64" height="64" rx="8" fill="#f0f0f0" stroke="#999"/>'
    . '<text x="32" y="38" text-anchor="middle" font-family="sans-serif" font-size="14" fill="#666">ТЕСТ</text>'
    . '</svg>';

    /**
     * Разбирает QUERY_STRING без нормализации '+': rawurldecode сохраняет литеральный
     * '+' (parse_str превращал бы его в пробел), нарушив base64-данные в query.
     *
     * @return array<string, string>
     */
    public static function parseRawQueryString(string $queryString): array
    {
        $params = [];
        foreach (explode('&', $queryString) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key = rawurldecode(ltrim($parts[0], '?'));
            if ($key === '') {
                continue;
            }

            $params[$key] = rawurldecode($parts[1] ?? '');
        }

        return $params;
    }

    /**
     * Обрабатывает запрос точки входа: пишет журнал и выбирает действие по $action.
     */
    public function run(string $action, array $query, string $method, string $body, array $headers): void
    {
        $query = array_filter(
            $query,
            static fn($key): bool => !str_starts_with((string)$key, '?'),
            ARRAY_FILTER_USE_KEY
        );

        $rawParams = self::parseRawQueryString((string)($_SERVER['QUERY_STRING'] ?? ''));

        (new RequestJournal())->record(
            $action !== '' ? $action : 'probe',
            isset($query['documentUid']) ? (string)$query['documentUid'] : null,
            isset($query['memberUid']) ? (string)$query['memberUid'] : null,
            [
                'action' => $action,
                'method' => $method,
                'query' => $query,
                'rawQuery' => $rawParams,
                'headers' => $headers,
                'body' => $body,
                'receivedAt' => gmdate('c'),
            ]
        );

        if ($action === 'sign') {
            $store = new DocumentStore();

            if ($method === 'POST') {
                $state = $store->verifyToken((string)($_POST['token'] ?? ''));
                if ($state === null) {
                    $this->raw(
                        403,
                        Envelope::error('TOKEN_INVALID', 'Неверный или отсутствующий токен'),
                        'application/json; charset=utf-8'
                    );

                    return;
                }

                $scenario = (string)($_POST['scenario'] ?? '');
                if (!in_array($scenario, SigningSimulator::ACTIONS, true)) {
                    $allowed = implode(', ', SigningSimulator::ACTIONS);
                    $this->raw(
                        400,
                        Envelope::error('BAD_SCENARIO', 'Действие должно быть: ' . $allowed),
                        'application/json; charset=utf-8'
                    );

                    return;
                }

                $applied = (new SigningSimulator($store))->apply(
                    $state,
                    $scenario,
                    (int)($_POST['delaySeconds'] ?? 0)
                );
                CallbackSender::afterMemberChange($applied, $store);

                $this->raw(200, Envelope::success([
                    'status' => $applied->status,
                    'scenario' => $applied->scenario,
                    'completedAt' => $applied->completedAt,
                ]), 'application/json; charset=utf-8');

                return;
            }

            $documentUid = (string)($query['documentUid'] ?? '');
            $memberUid = (string)($query['memberUid'] ?? '');
            if ($documentUid === '' || $memberUid === '') {
                $this->raw(400, 'Требуются query-параметры documentUid и memberUid', 'text/plain; charset=utf-8');

                return;
            }

            $state = $store->touch($documentUid, $memberUid);
            $token = (string)($query['token'] ?? '');
            if ($token === '' || $store->verifyToken($token)?->id !== $state->id) {
                $token = $state->token ?? $store->reissue($documentUid, $memberUid);
            }
            $status = $state->status;

            header('Content-Type: text/html; charset=utf-8');
            include dirname(__DIR__, 2) . '/templates/signing.php';

            return;
        }

        if ($action === 'pdf') {
            $documentUid = (string)($query['documentUid'] ?? '');
            $original = DocumentHandler::originalPdf($documentUid);
            header('Content-Type: application/pdf');
            echo $original !== null
                ? $original['content']
                : TestPdf::render($documentUid !== '' ? $documentUid : 'TEST');

            return;
        }

        if ($action === 'icon') {
            $this->raw(200, self::ICON_SVG, 'image/svg+xml');

            return;
        }

        if (in_array($action, self::NOT_IMPLEMENTED_ACTIONS, true)) {
            $this->json(501, ['error' => 'NOT_IMPLEMENTED', 'action' => $action]);

            return;
        }

        $this->raw(404, 'Not Found', 'text/plain; charset=utf-8');
    }

    /**
     * Статус устанавливаем явной статусной строкой, а не http_response_code():
     * диспетчер urlrewrite шлёт header('... 200 OK') ДО включения нашего файла,
     * и после этого http_response_code() не меняет уходящий статус, тогда как
     * повторный header() со статусом - меняет.
     */
    private function setStatus(int $status, ?string $reasonPhrase = null): void
    {
        if ($status === 200) {
            return;
        }

        $reason = $reasonPhrase ?? (self::STATUS_REASONS[$status] ?? 'OK');
        $protocol = (string)($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1');
        header($protocol . ' ' . $status . ' ' . $reason, true, $status);
    }

    /**
     * Отправляет JSON-ответ с указанным HTTP-статусом.
     */
    private function json(int $status, array $data): void
    {
        $this->setStatus($status);
        header('Content-Type: application/json; charset=utf-8');
        echo Main\Web\Json::encode($data);
    }

    /**
     * Отправляет произвольное тело с указанным HTTP-статусом и Content-Type.
     */
    private function raw(int $status, string $body, string $contentType): void
    {
        $this->setStatus($status);
        header('Content-Type: ' . $contentType);
        echo $body;
    }
}
