<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;

/**
 * Состояние тестового сценария подписания (не статусы модуля sign).
 * Уникальность (DOCUMENT_UID, MEMBER_UID) обеспечивает идемпотентность.
 */
class StateTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_aclips_fakesign_state';
    }

    public static function getMap(): array
    {
        return [
            new IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new StringField('DOCUMENT_UID'),
            new StringField('MEMBER_UID'),
            new StringField('TOKEN_HASH'),
            new StringField('STATUS'),
            new StringField('SCENARIO'),
            new IntegerField('DELAY_SECONDS'),
            new DatetimeField('REQUESTED_AT'),
            new DatetimeField('COMPLETED_AT'),
            new DatetimeField('CREATED_AT'),
            new DatetimeField('UPDATED_AT'),
        ];
    }
}
