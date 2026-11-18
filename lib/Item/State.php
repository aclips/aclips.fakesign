<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Item;

/**
 * Состояние участника в тестовом сервисе (строка b_aclips_fakesign_state).
 */
final class State
{
    public const PENDING = 'pending';

    public function __construct(
        public int $id,
        public string $documentUid,
        public string $memberUid,
        public string $tokenHash,
        public string $status,
        public string $scenario,
        public int $delaySeconds,
        public ?string $requestedAt = null,
        public ?string $completedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {
    }

    /**
     * Раскрытое значение токена: заполняется ТОЛЬКО при выдаче (touch/reissue).
     * В БД хранится лишь sha256; повторный touch токен не возвращает.
     */
    public ?string $token = null;

    /**
     * done/refused - завершено; pending - ожидание; error - управляющая ошибка
     * (попытку подписания можно повторить).
     */
    public function isCompleted(): bool
    {
        return !in_array($this->status, ['pending', 'error'], true);
    }

    /**
     * Статус участника в формате SignWebStatus (контракт data.status портала).
     */
    public function toWebStatus(): string
    {
        return match ($this->status)
        {
            'done' => 'done',
            'refused' => 'refused',
            'error' => 'stopped',
            default => 'wait',
        };
    }
}
