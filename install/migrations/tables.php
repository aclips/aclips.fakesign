<?php

declare(strict_types=1);

use Bitrix\Main\UpdateSystem\Migration;
use Bitrix\Main\UpdateSystem\Migration\CreateTableBuilder;


$migration = Migration::getInstance();

$migration->table('b_aclips_fakesign_state')
    ->create(function (CreateTableBuilder $table) {
    $columns = $table->addColumn();
    $columns->int('ID')->notNull()->autoincrement();
    $columns->varchar('DOCUMENT_UID', 32)->notNull();
    $columns->varchar('MEMBER_UID', 32)->notNull();
    $columns->varchar('TOKEN_HASH', 64)->notNull();
    $columns->varchar('STATUS', 16)->notNull();      // pending|done|refused|error
    $columns->varchar('SCENARIO', 16)->notNull();    // immediate|delay|error
    $columns->int('DELAY_SECONDS')->notNull()->default('0');
    $columns->datetime('REQUESTED_AT');
    $columns->datetime('COMPLETED_AT');
    $columns->datetime('CREATED_AT')->notNull();
    $columns->datetime('UPDATED_AT')->notNull();
    $table->addPrimaryKey('ID');
    $table->addUniqueIndex('ux_ts_state_doc_member', ['DOCUMENT_UID', 'MEMBER_UID']);
    $table->addIndex('ix_ts_state_due', ['STATUS', 'COMPLETED_AT']);
});

$migration->table('b_aclips_fakesign_audit')
    ->create(function (CreateTableBuilder $table) {
    $columns = $table->addColumn();
    $columns->int('ID')->notNull()->autoincrement();
    $columns->varchar('KIND', 50)->notNull();
    $columns->varchar('DOCUMENT_UID', 32);
    $columns->varchar('MEMBER_UID', 32);
    $columns->longText('PAYLOAD');
    $columns->datetime('CREATED_AT')->notNull();
    $table->addPrimaryKey('ID');
    $table->addIndex('ix_ts_audit_kind', ['KIND', 'CREATED_AT']);
    $table->addIndex('ix_ts_audit_doc', ['DOCUMENT_UID']);
});
