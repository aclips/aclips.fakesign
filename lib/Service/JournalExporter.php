<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Service;

use Aclips\Fakesign\Model\AuditTable;

/**
 * Выгрузка последних записей журнала входящих запросов.
 *
 * Выборка строится напрямую (AuditTable::query без явного setSelect даёт
 * вырожденный SELECT), а не через getList().
 */
final class JournalExporter
{
    private const LIMIT_MAX = 1000;

    /**
     * @return list<array<string, mixed>>
     */
    public static function latest(int $limit): array
    {
        $limit = max(1, min($limit, self::LIMIT_MAX));

        $rows = [];
        foreach (AuditTable::getList(['order' => ['ID' => 'DESC'], 'limit' => $limit]) as $row)
        {
            $item = [];
            foreach ($row as $key => $value)
            {
                // Bitrix\Main\Type\DateTime в этом ядре НЕ implements \DateTimeInterface
                // (extends Type\Date, только Stringable) - проверяем метод format().
                $item[$key] = is_object($value) && method_exists($value, 'format')
                    ? $value->format(\DateTimeInterface::ATOM)
                    : $value;
            }
            $rows[] = $item;
        }

        return $rows;
    }
}
