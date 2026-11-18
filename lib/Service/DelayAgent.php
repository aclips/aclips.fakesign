<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Aclips\Fakesign\FakeService\Callback\CallbackSender;
use Aclips\Fakesign\Item\State;

/**
 * Агент доработки отложенных подписаний: завершает состояния,
 * у которых now >= requestedAt + delaySeconds.
 *
 * Метод статический: агент Bitrix вызывает его как Class::run().
 *
 * Протокол CAgent::ExecuteAgents: '' -> удаление записи агента, непустое значение ->
 * переименование b_agent.NAME в это значение; периодический агент возвращает СВОЁ
 * имя, иначе next_exec += interval перепланируется под новым именем.
 */
final class DelayAgent
{
    public const AGENT_NAME = 'Aclips\Fakesign\Service\DelayAgent::run();';

    /**
     * Завершает состояния с наступившим сроком подписания; возвращает имя агента
     * для перепланирования записи в b_agent.
     */
    public static function run(?\DateTimeInterface $now = null): string
    {
        $store = new DocumentStore();
        $timestamp = ($now ?? new \DateTimeImmutable())->getTimestamp();

        foreach ($store->listByStatus(State::PENDING, 'delay') as $state)
        {
            if ($state->requestedAt === null || $state->delaySeconds <= 0)
            {
                continue;
            }
            if (strtotime($state->requestedAt) + $state->delaySeconds > $timestamp)
            {
                continue;
            }

            $state->status = 'done';
            $state->completedAt = date(DATE_ATOM);
            $state->updatedAt = $state->completedAt;
            $store->save($state);
            CallbackSender::afterMemberChange($state, $store);
        }

        return self::AGENT_NAME;
    }
}
