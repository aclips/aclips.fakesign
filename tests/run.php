<?php

declare(strict_types=1);

/**
 * Запуск из каталога Bitrix: php local/modules/aclips.fakesign/tests/run.php
 * Каждый файл tests/cases/*.php возвращает array<string, callable(): void>.
 * Код выхода = число провалов.
 */

require __DIR__ . '/bootstrap.php';

$failures = 0;
$passed = 0;

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $caseFile)
{
    $tests = require $caseFile;
    if (!is_array($tests))
    {
        fwrite(STDERR, 'FAIL: ' . basename($caseFile) . " не вернул массив тестов\n");
        $failures++;
        continue;
    }

    foreach ($tests as $name => $test)
    {
        try
        {
            $test();
            $passed++;
            echo 'OK: ' . $name . "\n";
        }
        catch (Throwable $e)
        {
            $failures++;
            echo 'FAIL: ' . $name . ' | ' . $e->getMessage() . "\n";
        }
    }
}

echo sprintf("Итог: %d passed, %d failed\n", $passed, $failures);

exit(min($failures, 127));
