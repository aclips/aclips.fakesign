<?php

declare(strict_types=1);


/**
 * Шаг 1 удаления модуля: вопрос «Сохранять таблицы и настройки?».
 * Паттерн - bitrix/modules/catalog/install/unstep1.php.
 *
 * @global CMain $APPLICATION
 */

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__DIR__ . '/../lang/ru/install/index.php');

?>
<form action="<?= $APPLICATION->GetCurPage() ?>">
    <?= bitrix_sessid_post() ?>
    <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
    <input type="hidden" name="id" value="aclips.fakesign">
    <input type="hidden" name="uninstall" value="Y">
    <input type="hidden" name="step" value="2">
    <?php
    CAdminMessage::ShowMessage(Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_WARN'));
    ?>
    <p><?= Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_QUESTION') ?></p>
    <p>
        <input type="radio" name="savedata" id="savedata_y" value="Y" checked>
        <label for="savedata_y"><?= Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_KEEP') ?></label><br>
        <input type="radio" name="savedata" id="savedata_n" value="N">
        <label for="savedata_n"><?= Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_DROP') ?></label>
    </p>
    <p class="note"><?= Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_ALWAYS') ?></p>
    <input type="submit" class="adm-btn"
           value="<?= htmlspecialcharsbx(Loc::getMessage('ACLIPS_FAKESIGN_UNINSTALL_SUBMIT')) ?>">
</form>
