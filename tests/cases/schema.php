<?php

declare(strict_types=1);

use Aclips\Fakesign\Model\AuditTable;
use Aclips\Fakesign\Model\StateTable;


return [
    'StateTable знает имя таблицы' => static function (): void {
        expect_eq(StateTable::getTableName(), 'b_aclips_fakesign_state');
    },
    'AuditTable знает имя таблицы' => static function (): void {
        expect_eq(AuditTable::getTableName(), 'b_aclips_fakesign_audit');
    },
];
