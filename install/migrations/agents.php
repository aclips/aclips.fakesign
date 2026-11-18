<?php

declare(strict_types=1);

use Aclips\Fakesign\Service\DelayAgent;
use Bitrix\Main\UpdateSystem\Migration;



$migration = Migration::getInstance();

// Периодический: доработка отложенных подписаний каждые 60 секунд.
// isPeriod=true обязателен - одноразовый запуск не доработал бы поздние сроки.
// Агент возвращает СВОЁ имя (протокол CAgent::ExecuteAgents: непустое значение =
// переименование/перепланирование, '' = удаление записи).
$migration->agent()->add(
    [DelayAgent::class, 'run'],
    60,
    true
);
