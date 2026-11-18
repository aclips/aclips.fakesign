<?php

declare(strict_types=1);

namespace Aclips\Fakesign\FakeService\Handler;

use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\FakeService\Envelope;
use Aclips\Fakesign\Item\State;
use Aclips\Fakesign\Service\DocumentStore;
use Aclips\Fakesign\Service\SigningSimulator;

/**
 * Подписание: ссылка, статусы, действия. Контракт - потребители модуля sign:
 * Service\Api\Member\WebStatusService (data.status: wait|done|refused|stopped...),
 * Service\Sign\MemberService::getLinkForSigning (data.uri),
 * Service\Api\MobileService (sign/refuse/reviewer/batch - data не читаются, важен успех).
 */
final class MemberHandler
{
    private const PATH_UID_OFFSET = 2;

    /**
     * POST v1/b2e.member.getlinkforsigning/{d}/{m}: data.uri -> наша страница с токеном.
     * Токен выпускается здесь; повторное построение ссылки выдаёт свежий (reissue).
     */
    public static function linkForSigning(string $path, string $method, array $query, string $body): array
    {
        [$documentUid, $memberUid] = self::pathUids($path);
        if ($documentUid === '' || $memberUid === '') {
            return ['status' => 400, 'body' => Envelope::error('BAD_PATH', 'Ожидались documentUid/memberUid в пути')];
        }

        $store = new DocumentStore();
        $state = $store->touch($documentUid, $memberUid);
        $token = $state->token ?? $store->reissue($documentUid, $memberUid);

        $uri = '/fakesign/sign/?' . http_build_query([
                'documentUid' => $documentUid,
                'memberUid' => $memberUid,
                'token' => $token,
            ]);

        return ['status' => 200, 'body' => Envelope::success(['uri' => $uri])];
    }

    /**
     * GET v1/document.member.status.get/{d}/{m}: data.status в формате SignWebStatus.
     * Неизвестный участник - wait (без создания записи).
     */
    public static function statusGet(string $path, string $method, array $query, string $body): array
    {
        [$documentUid, $memberUid] = self::pathUids($path);
        $state = ($documentUid === '' || $memberUid === '')
            ? null
            : (new DocumentStore())->findByUids($documentUid, $memberUid);

        if ($state === null) {
            $status = DocumentHandler::isStarted($documentUid) ? 'ready' : 'wait';
        } else {
            $status = match ($state->status) {
                'done' => 'done',
                'refused' => 'refused',
                'error' => 'stopped',
                default => $state->scenario === 'delay' ? 'wait' : 'ready',
            };
        }

        return [
            'status' => 200,
            'body' => Envelope::success(['status' => $status]),
        ];
    }

    /**
     * POST v1/b2e.document.signing.sign/{d}/{m}: подписывает участника (sign -> done)
     * и рассылает колбэки готовности/статуса.
     */
    public static function sign(string $path, string $method, array $query, string $body): array
    {
        return self::applyByPath($path, SigningSimulator::ACTION_SIGN);
    }

    /**
     * POST v1/b2e.document.signing.refuse/{d}/{m}: отказывает участника (refuse -> refused)
     * и рассылает колбэки.
     */
    public static function refuse(string $path, string $method, array $query, string $body): array
    {
        return self::applyByPath($path, SigningSimulator::ACTION_REFUSE);
    }

    /**
     * POST v1/b2e.document.reviewer.accept/{d}/{m}: одобрение рецензентом
     * (приравнивается к подписанию) с колбэками.
     */
    public static function reviewAccept(string $path, string $method, array $query, string $body): array
    {
        return self::applyByPath($path, SigningSimulator::ACTION_SIGN);
    }

    /**
     * POST v1/b2e.document.signing.refuse.batch: применяем отказ каждому и эхом
     * items -> results[{documentId,memberId,status:'success'}] (формат BatchItemStatus).
     */
    public static function refuseBatch(string $path, string $method, array $query, string $body): array
    {
        return self::batchApply($body, SigningSimulator::ACTION_REFUSE);
    }

    /**
     * POST v1/b2e.document.reviewer.accept.batch: одобрение каждому участнику, эхо results.
     */
    public static function reviewAcceptBatch(string $path, string $method, array $query, string $body): array
    {
        return self::batchApply($body, SigningSimulator::ACTION_SIGN);
    }

    private static function applyByPath(string $path, string $action): array
    {
        [$documentUid, $memberUid] = self::pathUids($path);
        if ($documentUid === '' || $memberUid === '') {
            return ['status' => 400, 'body' => Envelope::error('BAD_PATH', 'Ожидались documentUid/memberUid в пути')];
        }

        $store = new DocumentStore();
        $state = $store->touch($documentUid, $memberUid);
        $applied = (new SigningSimulator($store))->apply($state, $action);
        CallbackSender::afterMemberChange($applied, $store);

        return ['status' => 200, 'body' => Envelope::success([])];
    }

    private static function batchApply(string $body, string $action): array
    {
        $request = json_decode($body, true) ?: [];
        $store = new DocumentStore();
        $simulator = new SigningSimulator($store);

        $results = [];
        $documentUids = [];
        foreach ((array)($request['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $documentUid = (string)($item['documentId'] ?? '');
            $memberUid = (string)($item['memberId'] ?? '');
            if ($documentUid !== '' && $memberUid !== '') {
                $applied = $simulator->apply($store->touch($documentUid, $memberUid), $action);
                CallbackSender::afterMemberChange($applied, $store, false);
                $documentUids[$documentUid] = true;
            }

            $results[] = [
                'documentId' => $documentUid,
                'memberId' => $memberUid,
                'status' => 'success',
                'message' => null,
            ];
        }

        foreach (array_keys($documentUids) as $documentUid) {
            CallbackSender::afterDocumentPossiblyCompleted((string)$documentUid, $store);
        }

        return ['status' => 200, 'body' => Envelope::success(['results' => $results])];
    }

    /**
     * @return array{0: string, 1: string} [documentUid, memberUid]
     */
    private static function pathUids(string $path): array
    {
        $parts = array_values(array_filter(explode('/', rtrim($path, '/')), static fn($part): bool => $part !== ''));

        return [
            (string)($parts[self::PATH_UID_OFFSET] ?? ''),
            (string)($parts[self::PATH_UID_OFFSET + 1] ?? ''),
        ];
    }

    private static function toWebStatus(?State $state): string
    {
        if ($state === null) {
            return 'wait';
        }

        return match ($state->status) {
            'done' => 'done',
            'refused' => 'refused',
            'error' => 'stopped',
            default => 'wait',
        };
    }
}
