<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main;
use Aclips\Fakesign\Item\State;

/**
 * Применение тестовых сценариев подписания к состоянию участника.
 * Идемпотентно: поверх завершённого состояния (done/refused) изменения не вносятся.
 */
final class SigningSimulator
{
    public const ACTION_SIGN = 'sign';
    public const ACTION_REFUSE = 'refuse';
    public const ACTION_ERROR = 'error';
    public const ACTION_DELAY = 'delay';

    public const ACTIONS = [
        self::ACTION_SIGN,
        self::ACTION_REFUSE,
        self::ACTION_ERROR,
        self::ACTION_DELAY,
    ];

    public function __construct(private readonly DocumentStore $store)
    {
    }

    /**
     * Применяет сценарий (sign/refuse/error/delay) к состоянию участника и сохраняет
     * результат; поверх завершённого состояния возвращает его без изменений.
     *
     * @throws Main\ArgumentException неизвестное действие
     */
    public function apply(State $state, string $action, int $delaySeconds = 0): State
    {
        if (!in_array($action, self::ACTIONS, true))
        {
            throw new Main\ArgumentException('Неизвестное действие: ' . $action);
        }

        // Идемпотентность: поверх завершённого состояние не трогаем.
        if ($state->isCompleted())
        {
            return $state;
        }

        $now = date(DATE_ATOM);

        if ($action === self::ACTION_DELAY && $delaySeconds > 0)
        {
            // Таймер уже стоит - не сдвигаем (повторные вызовы не отодвигают срок бесконечно).
            if ($state->scenario === 'delay' && $state->requestedAt !== null)
            {
                return $state;
            }

            $state->scenario = self::ACTION_DELAY;
            $state->delaySeconds = $delaySeconds;
            $state->requestedAt = $now;
            $state->updatedAt = $now;
            $this->store->save($state);

            return $state;
        }

        // sign (и delay с нулевой задержкой ведёт себя как sign), refuse, error
        $state->status = match ($action)
        {
            self::ACTION_REFUSE => 'refused',
            self::ACTION_ERROR => 'error',
            default => 'done',
        };
        $state->completedAt = $state->status === 'error' ? null : $now;
        $state->updatedAt = $now;
        $this->store->save($state);

        return $state;
    }
}
