<?php

declare(strict_types=1);


$module_id = 'aclips.fakesign';

use Aclips\Fakesign\Service\ServiceAddressOverride;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/** @var \CMain $APPLICATION */

if (!Loader::includeModule($module_id))
{
    return;
}

$context = Application::getInstance()->getContext();
$request = $context->getRequest();
$docRoot = $context->getServer()->getDocumentRoot();
$mid = (string)$request->get('mid');
$backUrl = (string)$request->get('back_url_settings');

IncludeModuleLangFile($docRoot . '/bitrix/modules/main/options.php');
Loc::loadMessages(__FILE__);

// Модуль не ставит b_group_right - защита контекстом администратора
// (страница доступна только через админку: Настройки -> Модули -> aclips.fakesign).
if (!$USER->IsAdmin())
{
    return;
}

$allOptions = [
    [
        'header',
        Loc::getMessage('ACLIPS_FAKESIGN_HEADER_MAIN'),
        Loc::getMessage('ACLIPS_FAKESIGN_HEADER_MAIN_NOTE'),
    ],
    [
        'public_base_url',
        Loc::getMessage('ACLIPS_FAKESIGN_OPT_URL') . ':',
        ['text', 80],
    ],
    [
        'use_cloud_converter',
        Loc::getMessage('ACLIPS_FAKESIGN_OPT_CLOUD_CONVERTER') . ':',
        ['checkbox'],
        'Y',
    ],
];

$tabControl = new CAdmintabControl('tabControl', [
    ['DIV' => 'edit1', 'TAB' => Loc::getMessage('ACLIPS_FAKESIGN_OPTIONS_TAB'), 'ICON' => ''],
]);

$Update = (string)$request->getPost('Update');
$Apply = (string)$request->getPost('Apply');

// Сохранение
if (($Update . $Apply) !== '' && check_bitrix_sessid())
{
    foreach ($allOptions as $arOption)
    {
        if ($arOption[0] === 'header' || $arOption[2][0] === 'info')
        {
            continue;
        }

        $name = $arOption[0];
        $postValue = $request->getPost($name);

        if ($arOption[2][0] === 'checkbox')
        {
            $val = $postValue === 'Y' ? 'Y' : 'N';
        }
        else
        {
            $val = trim((string)($postValue ?? ''));
        }

        \COption::setOptionString($module_id, $name, $val);
    }

    // Подмена адреса Sign Service перезаписывается сразу: шаблон и логика живут
    // в модуле (ServiceAddressOverride), файл - по ядровому пути /local/.
    $override = 'ok';
    try
    {
        (new ServiceAddressOverride())->write(
            \COption::getOptionString($module_id, 'public_base_url')
        );
    }
    catch (\Throwable $e)
    {
        $override = 'fail';
        error_log('aclips.fakesign: ' . $e->getMessage());
    }

    if ($backUrl !== '')
    {
        localRedirect($backUrl);
    }

    localRedirect(
        $APPLICATION->GetCurPage()
        . '?mid=' . urlencode($mid)
        . '&lang=' . urlencode(LANGUAGE_ID)
        . '&back_url_settings=' . urlencode($backUrl)
        . '&saved=1&override=' . urlencode($override)
        . '&' . $tabControl->ActiveTabParam()
    );
}

// Сообщения после сохранения
if ($request->get('saved'))
{
    if ((string)$request->get('override') === 'fail')
    {
        CAdminMessage::ShowMessage([
            'MESSAGE' => Loc::getMessage('ACLIPS_FAKESIGN_MSG_SAVED_OVERRIDE_FAIL'),
            'TYPE' => 'ERROR',
        ]);
    }
    else
    {
        CAdminMessage::ShowNote(Loc::getMessage('ACLIPS_FAKESIGN_MSG_SAVED'));
    }
}

?>
<form method="post" action="<?php echo $APPLICATION->GetCurPage() ?>?mid=<?php echo urlencode($mid) ?>&amp;lang=<?php echo LANGUAGE_ID ?>">
    <?php
    $tabControl->Begin();
    $tabControl->BeginNextTab();
    foreach ($allOptions as $Option):
        if ($Option[0] === 'header')
        {
            ?>
            <tr class="heading">
                <td colspan="2">
                    <?php echo $Option[1] ?>
                </td>
            </tr>
            <?php if (isset($Option[2])): ?>
                <tr>
                    <td></td>
                    <td>
                        <?php
                        echo BeginNote();
                        echo $Option[2];
                        echo EndNote();
                        ?>
                    </td>
                </tr>
            <?php endif;
            continue;
        }

        $type = $Option[2];
        $val = \COption::getOptionString($module_id, $Option[0], $Option[3] ?? null);
        ?>
        <tr>
            <td style="width: 40%;"><?php
                if ($type[0] === 'checkbox')
                {
                    echo '<label for="' . htmlspecialcharsbx($Option[0]) . '">' . $Option[1] . '</label>';
                }
                else
                {
                    echo $Option[1];
                }
                ?></td>
            <td><?php
                if ($type[0] === 'checkbox'):
                    ?><input type="checkbox" name="<?php echo htmlspecialcharsbx($Option[0]) ?>"
                             id="<?php echo htmlspecialcharsbx($Option[0]) ?>" value="Y"<?php echo ($val === 'Y') ? ' checked="checked"' : '' ?> /><?php
                elseif ($type[0] === 'text'):
                    ?><input type="text" size="<?php echo $type[1] ?>" maxlength="255"
                             value="<?php echo htmlspecialcharsbx($val) ?>"
                             name="<?php echo htmlspecialcharsbx($Option[0]) ?>" /><?php
                elseif ($type[0] === 'info'):
                    echo '<span style="color:#777">' . htmlspecialcharsbx($val !== '' ? $val : '—') . '</span>';
                endif;
                ?></td>
        </tr>
    <?php
    endforeach;

    $tabControl->Buttons();
    ?>
    <input type="submit" name="Update" value="<?php echo Loc::getMessage('MAIN_SAVE') ?>"
           title="<?php echo Loc::getMessage('MAIN_OPT_SAVE_TITLE') ?>"/>
    <input type="submit" name="Apply" value="<?php echo Loc::getMessage('MAIN_OPT_APPLY') ?>"
           title="<?php echo Loc::getMessage('MAIN_OPT_APPLY_TITLE') ?>"/>
    <?php if ($backUrl !== ''): ?>
        <input type="button" name="Cancel" value="<?php echo Loc::getMessage('MAIN_OPT_CANCEL') ?>"
               title="<?php echo Loc::getMessage('MAIN_OPT_CANCEL_TITLE') ?>"
               onclick="window.location='<?php echo htmlspecialcharsbx(CUtil::addslashes($backUrl)) ?>'"/>
        <input type="hidden" name="back_url_settings" value="<?php echo htmlspecialcharsbx($backUrl) ?>"/>
    <?php endif ?>
    <?php echo bitrix_sessid_post() ?>
    <?php $tabControl->End() ?>
</form>
<?php
