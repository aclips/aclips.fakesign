<?php

declare(strict_types=1);


/**
 * Шаг 2 удаления модуля: итог.
 *
 * @global CMain $APPLICATION
 */

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__ . '/../lang/ru/install/index.php');

$keepData = (string)($_REQUEST['savedata'] ?? '') === 'Y';

?>
<p>
    <?= $keepData
        ? Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_DONE_KEPT')
        : Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_DONE_DROPPED') ?>
</p>
<p><?= Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_DONE_ALWAYS') ?></p>
<p><a href="<?= $APPLICATION->GetCurPage() ?>"><?= Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_BACK') ?></a></p>
