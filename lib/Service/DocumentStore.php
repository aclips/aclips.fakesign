<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Bitrix\Main;
use Aclips\Fakesign\Item\State;
use Aclips\Fakesign\Model\StateTable;

/**
 * Стор состояния участника (b_aclips_fakesign_state): источник данных для
 * страницы подписания, API статусов фейк-сервиса и сценариев подписания.
 */
final class DocumentStore
{
    public const STATUS_PENDING = State::PENDING;
    public const SCENARIO_IMMEDIATE = 'immediate';

    /**
     * Create-if-absent: идемпотентен, токен выдаётся ТОЛЬКО при создании записи.
     */
    public function touch(string $documentUid, string $memberUid): State
    {
        $row = $this->findRow($documentUid, $memberUid);
        if ($row !== null)
        {
            return $this->fromRow($row);
        }

        $token = bin2hex(random_bytes(32));
        $now = new Main\Type\DateTime();

        try
        {
            $addResult = StateTable::add([
                'DOCUMENT_UID' => $documentUid,
                'MEMBER_UID' => $memberUid,
                'TOKEN_HASH' => hash('sha256', $token),
                'STATUS' => self::STATUS_PENDING,
                'SCENARIO' => self::SCENARIO_IMMEDIATE,
                'DELAY_SECONDS' => 0,
                'CREATED_AT' => $now,
                'UPDATED_AT' => $now,
            ]);
        }
        catch (\Throwable $e)
        {
            // Гонка по уникальному индексу: чужая запись уже создана - берём её без токена.
            $row = $this->findRow($documentUid, $memberUid);
            if ($row === null)
            {
                throw $e;
            }

            return $this->fromRow($row);
        }

        $state = $this->fromRow((array)StateTable::getRowById((int)$addResult->getId()));
        $state->token = $token;

        return $state;
    }

    /**
     * Выпуск СВЕЖЕГО токена (используется фейк-сервисом при построении ссылки):
     * прежний токен перестаёт действовать.
     */
    public function reissue(string $documentUid, string $memberUid): string
    {
        $state = $this->touch($documentUid, $memberUid);
        $token = bin2hex(random_bytes(32));

        StateTable::update($state->id, [
            'TOKEN_HASH' => hash('sha256', $token),
            'UPDATED_AT' => new Main\Type\DateTime(),
        ]);

        return $token;
    }

    /**
     * @return State|null null - токен чужой/пустой/неизвестный
     */
    public function verifyToken(string $token): ?State
    {
        if ($token === '')
        {
            return null;
        }

        $row = StateTable::getRow(['filter' => ['=TOKEN_HASH' => hash('sha256', $token)]]);

        return $row ? $this->fromRow($row) : null;
    }

    /**
     * Поиск состояния участника без создания записи.
     */
    public function findByUids(string $documentUid, string $memberUid): ?State
    {
        $row = $this->findRow($documentUid, $memberUid);

        return $row ? $this->fromRow($row) : null;
    }

    /**
     * Сохранение изменяемых полей состояния (статус/сценарий/даты).
     * Даты: строка ATOM из State -> Main\Type\DateTime (ядро отклоняет ISO8601-строки
     * для datetime-полей: "Неправильное значение").
     */
    public function save(State $state): void
    {
        StateTable::update($state->id, [
            'STATUS' => $state->status,
            'SCENARIO' => $state->scenario,
            'DELAY_SECONDS' => $state->delaySeconds,
            'REQUESTED_AT' => self::toDbDateTime($state->requestedAt),
            'COMPLETED_AT' => self::toDbDateTime($state->completedAt),
            'UPDATED_AT' => self::toDbDateTime($state->updatedAt) ?? new Main\Type\DateTime(),
        ]);
    }

    private static function toDbDateTime(?string $atom): ?Main\Type\DateTime
    {
        if ($atom === null || $atom === '')
        {
            return null;
        }

        return new Main\Type\DateTime(
            (new \DateTimeImmutable($atom))->format('Y-m-d H:i:s'),
            'Y-m-d H:i:s'
        );
    }

    /**
     * Все состояния участников одного документа (для решения о завершении документа).
     *
     * @return list<State>
     */
    public function listByDocument(string $documentUid): array
    {
        $rows = StateTable::getList([
            'filter' => ['=DOCUMENT_UID' => $documentUid],
            'order' => ['ID' => 'ASC'],
        ]);

        $states = [];
        foreach ($rows as $row)
        {
            $states[] = $this->fromRow($row);
        }

        return $states;
    }

    /**
     * Выборка по статусу и сценарию (используется DelayAgent).
     *
     * @return list<State>
     */
    public function listByStatus(string $status, string $scenario, int $limit = 500): array
    {
        $rows = StateTable::getList([
            'filter' => [
                '=STATUS' => $status,
                '=SCENARIO' => $scenario,
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => $limit,
        ]);

        $states = [];
        foreach ($rows as $row)
        {
            $states[] = $this->fromRow($row);
        }

        return $states;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $documentUid, string $memberUid): ?array
    {
        $row = StateTable::getRow([
            'filter' => [
                '=DOCUMENT_UID' => $documentUid,
                '=MEMBER_UID' => $memberUid,
            ],
        ]);

        return $row ?: null;
    }

    private function fromRow(array $row): State
    {
        $format = static function ($value): ?string {
            if ($value === null)
            {
                return null;
            }
            if (is_object($value) && method_exists($value, 'format'))
            {
                return $value->format(\DateTimeInterface::ATOM);
            }

            return (string)$value;
        };

        return new State(
            id: (int)($row['ID'] ?? 0),
            documentUid: (string)($row['DOCUMENT_UID'] ?? ''),
            memberUid: (string)($row['MEMBER_UID'] ?? ''),
            tokenHash: (string)($row['TOKEN_HASH'] ?? ''),
            status: (string)($row['STATUS'] ?? self::STATUS_PENDING),
            scenario: (string)($row['SCENARIO'] ?? self::SCENARIO_IMMEDIATE),
            delaySeconds: (int)($row['DELAY_SECONDS'] ?? 0),
            requestedAt: $format($row['REQUESTED_AT'] ?? null),
            completedAt: $format($row['COMPLETED_AT'] ?? null),
            createdAt: $format($row['CREATED_AT'] ?? null),
            updatedAt: $format($row['UPDATED_AT'] ?? null),
        );
    }
}
