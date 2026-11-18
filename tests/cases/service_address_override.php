<?php

declare(strict_types=1);

use Aclips\Fakesign\Service\ServiceAddressOverride;
use Bitrix\Main\Config\Configuration;

return [
    'write создаёт файл со ВСЕМИ регионами sign и адресом из настроек' => static function (): void {
        $target = $_SERVER['DOCUMENT_ROOT'] . '/upload/aclips.fakesign/override-test/settings_extra.php';
        @unlink($target);
        @mkdir(dirname($target), 0775, true);

        try
        {
            $regions = Configuration::getInstance('sign')->get('service.address');
            expect_true(is_array($regions) && count($regions) > 0, 'регионы знакомы из конфига sign');

            (new ServiceAddressOverride($target))->write('http://example.test');

            expect_true(is_file($target), 'файл создан');
            $content = (string)file_get_contents($target);
            expect_true(str_contains($content, 'aclips.fakesign'), 'маркер происхождения в шапке');
            $data = include $target;
            $written = $data['sign']['value']['service.address'] ?? [];
            expect_eq(count($written), count($regions), 'все регионы перенесены');
            foreach ($written as $region => $address)
            {
                expect_eq($address, 'http://example.test/fakesign', "регион $region получил адрес из настроек");
            }
        }
        finally
        {
            @unlink($target);
        }
    },
    'write идемпотентен (повторная запись перезаписывает, а не дублирует)' => static function (): void {
        $target = $_SERVER['DOCUMENT_ROOT'] . '/upload/aclips.fakesign/override-test/settings_extra2.php';
        @unlink($target);
        @mkdir(dirname($target), 0775, true);

        try
        {
            $writer = new ServiceAddressOverride($target);
            $writer->write('http://a.test');
            $writer->write('http://b.test');
            $data = include $target;
            $written = $data['sign']['value']['service.address'] ?? [];
            expect_true(count($written) > 0, 'регионы на месте после повторной записи');
            expect_eq(reset($written), 'http://b.test/fakesign', 'адрес из последней настройки');
        }
        finally
        {
            @unlink($target);
        }
    },
    'чужой существующий файл: write сохраняет его ключи и делает резервную копию' => static function (): void {
        $target = $_SERVER['DOCUMENT_ROOT'] . '/upload/fakesign-test/override-foreign.php';
        @unlink($target . '.bak');
        @mkdir(dirname($target), 0775, true);
        $foreign = "<?php\nreturn array(\n"
            . "  'crm' => array('value' => array('x' => '1')),\n"
            . "  'sign' => array(\n"
            . "    'value' => array('service.address' => array('ru' => 'https://old.test'), 'other_key' => 'keep'),\n"
            . "    'readonly' => true,\n"
            . "  ),\n);\n";
        file_put_contents($target, $foreign);

        try {
            (new ServiceAddressOverride($target))->write('http://new.test');

            $data = include $target;
            expect_eq($data['crm']['value']['x'] ?? null, '1', 'чужая секция crm сохранена');
            expect_eq($data['sign']['value']['other_key'] ?? null, 'keep', 'чужой ключ sign сохранён');
            expect_eq($data['sign']['readonly'] ?? null, true, 'чужой readonly сохранён');
            $addresses = $data['sign']['value']['service.address'] ?? [];
            expect_true(count($addresses) > 0, 'регионы записаны');
            expect_eq(reset($addresses), 'http://new.test/fakesign', 'наш адрес подмены применён');

            expect_true(is_file($target . '.bak'), 'резервная копия создана');
            expect_eq((string)file_get_contents($target . '.bak'), $foreign, 'копия - исходное содержимое байт-в-байт');
        } finally {
            @unlink($target);
            @unlink($target . '.bak');
        }
    },

    'чужой существующий файл: remove восстанавливает исходное содержимое из копии' => static function (): void {
        $target = $_SERVER['DOCUMENT_ROOT'] . '/upload/fakesign-test/override-restore.php';
        @unlink($target . '.bak');
        @mkdir(dirname($target), 0775, true);
        $foreign = "<?php\nreturn array(\n  'main' => array('value' => array('k' => 'v')),\n);\n";
        file_put_contents($target, $foreign);

        try {
            $writer = new ServiceAddressOverride($target);
            $writer->write('http://new.test');
            expect_true(is_file($target . '.bak'), 'копия есть');

            expect_eq($writer->remove(), true, 'remove выполнен');
            expect_eq((string)file_get_contents($target), $foreign, 'файл восстановлен байт-в-байт');
            expect_true(!is_file($target . '.bak'), 'копия после восстановления израсходована');
        } finally {
            @unlink($target);
            @unlink($target . '.bak');
        }
    },

    'наш файл с чужими ключами: remove вырезает только service.address' => static function (): void {
        $target = $_SERVER['DOCUMENT_ROOT'] . '/upload/fakesign-test/override-surgical.php';
        @unlink($target . '.bak');
        @mkdir(dirname($target), 0775, true);
        $ours = "<?php\n\n// Подмена адреса Sign Service на тестовый провайдер aclips.fakesign.\n"
            . "return array(\n"
            . "  'crm' => array('value' => array('y' => '2')),\n"
            . "  'sign' => array('value' => array('service.address' => array('ru' => 'http://x.test/testsign'), 'other_key' => 'keep')),\n);\n";
        file_put_contents($target, $ours);

        try {
            expect_eq((new ServiceAddressOverride($target))->remove(), true, 'remove выполнен');
            $data = include $target;
            expect_true(!isset($data['sign']['value']['service.address']), 'наш ключ вырезан');
            expect_eq($data['crm']['value']['y'] ?? null, '2', 'чужая секция осталась');
            expect_eq($data['sign']['value']['other_key'] ?? null, 'keep', 'чужой ключ sign остался');
            expect_true(!str_contains((string)file_get_contents($target), 'aclips.fakesign'), 'маркер подмены исчез');
        } finally {
            @unlink($target);
            @unlink($target . '.bak');
        }
    },

    'remove удаляет файл; повторное удаление не фатально' => static function (): void {
        $target = $_SERVER['DOCUMENT_ROOT'] . '/upload/aclips.fakesign/override-test/settings_extra3.php';
        @unlink($target);
        @mkdir(dirname($target), 0775, true);

        $writer = new ServiceAddressOverride($target);
        $writer->write('http://c.test');
        expect_true(is_file($target), 'файл есть');

        expect_eq($writer->remove(), true, 'remove сообщает об удалении');
        expect_true(!is_file($target), 'файла больше нет');
        expect_eq($writer->remove(), false, 'повторное remove - честный false, без исключения');
    },
];
