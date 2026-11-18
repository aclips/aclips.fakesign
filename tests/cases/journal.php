<?php

declare(strict_types=1);

use Aclips\Fakesign\Model\AuditTable;
use Aclips\Fakesign\Service\JournalExporter;
use Aclips\Fakesign\Service\RequestJournal;

return [
    'record пишет и возвращает id' => static function (): void {
        $id = (new RequestJournal())->record('probe', 'doc-uid', 'member-uid', ['method' => 'POST']);
        expect_true($id > 0, 'id > 0');
    },
    'запись читается обратно по id' => static function (): void {
        $journal = new RequestJournal();
        $id = $journal->record(
            'probe',
            'doc-back',
            'member-back',
            ['method' => 'POST', 'headers' => ['Authorization' => 'Bearer secret-value']]
        );

        $row = AuditTable::getRowById($id);
        expect_true(is_array($row), 'строка найдена');
        expect_eq($row['KIND'], 'probe', 'KIND');
        expect_eq($row['DOCUMENT_UID'], 'doc-back', 'DOCUMENT_UID');
        expect_eq($row['MEMBER_UID'], 'member-back', 'MEMBER_UID');

        $payload = json_decode((string)$row['PAYLOAD'], true);
        expect_eq($payload['method'] ?? null, 'POST', 'payload method');
        expect_true(
            !str_contains((string)$row['PAYLOAD'], 'secret-value'),
            'значение заголовка аутентификации замаскировано'
        );
    },
    'JournalExporter.latest отдаёт полные строки, а не ?COLUMN?' => static function (): void {
        $id = (new RequestJournal())->record('probe', 'export-doc', 'export-member', ['method' => 'EXPORT']);

        $rows = JournalExporter::latest(5);
        expect_true(count($rows) >= 1, 'есть строки');
        $found = null;
        foreach ($rows as $row)
        {
            if (($row['ID'] ?? null) == $id)
            {
                $found = $row;
                break;
            }
        }
        expect_true(is_array($found), 'записанная строка найдена по id');
        expect_eq($found['KIND'] ?? null, 'probe', 'KIND - нормальное имя столбца');
        expect_eq($found['DOCUMENT_UID'] ?? null, 'export-doc', 'DOCUMENT_UID');
        expect_true(isset($found['PAYLOAD']), 'PAYLOAD на месте');
        expect_true(
            is_string($found['CREATED_AT'] ?? null) && $found['CREATED_AT'] !== '',
            'CREATED_AT отформатирован в строку'
        );
        expect_true(!array_key_exists('?COLUMN?', $found), 'нет вырожденного ?COLUMN?');
    },
];
