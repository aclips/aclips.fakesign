<?php

declare(strict_types=1);

namespace Aclips\Fakesign\Model;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;

/**
 * Журнал входящих запросов и событий тестового сценария (без персональных данных).
 * Хранит записи журнала обращений фейк-сервиса.
 */
class AuditTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_aclips_fakesign_audit';
    }

    public static function getMap(): array
    {
        return [
            new IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new StringField('KIND'),
            new StringField('DOCUMENT_UID'),
            new StringField('MEMBER_UID'),
            new TextField('PAYLOAD'),
            new DatetimeField('CREATED_AT'),
        ];
    }
}
