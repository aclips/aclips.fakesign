<?php

declare(strict_types=1);

/**
 * Страница тестового подписания.
 *
 * @var string $documentUid
 * @var string $memberUid
 * @var string $token
 * @var string $status
 */
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ТЕСТ: подписание документа</title>
    <style>
        body { font-family: sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem; color: #222; }
        .test-mark { background: #fff3cd; border: 1px solid #ffc107; padding: .75rem 1rem; border-radius: 6px; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: 1rem 0; }
        dt { color: #666; }
        dd { margin: 0; font-family: monospace; word-break: break-all; }
        .actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: 1rem; }
        button { padding: .6rem 1.1rem; border-radius: 6px; border: 1px solid #999; background: #f5f5f5; cursor: pointer; font-size: 1rem; }
        button.primary { background: #2fc15c; border-color: #25a04c; color: #fff; }
        #result { margin-top: 1rem; font-family: monospace; white-space: pre-wrap; }
    </style>
</head>
<body>
<h1>Тестовое подписание</h1>

<p class="test-mark">
    <b>ТЕСТ.</b> Подписание эмулируется тестовым провайдером. Подпись не является электронной
    и не имеет юридической силы.
</p>

<dl>
    <dt>Документ</dt><dd><?= htmlspecialcharsbx($documentUid) ?></dd>
    <dt>Участник</dt><dd><?= htmlspecialcharsbx($memberUid) ?></dd>
    <dt>Статус</dt><dd id="status"><?= htmlspecialcharsbx($status) ?></dd>
</dl>

<form method="post" id="sign-form">
    <input type="hidden" name="token" value="<?= htmlspecialcharsbx($token) ?>">
    <input type="hidden" name="delaySeconds" value="10">
    <div class="actions">
        <button type="submit" name="scenario" value="sign" class="primary">Подписать</button>
        <button type="submit" name="scenario" value="refuse">Отказать</button>
        <button type="submit" name="scenario" value="delay">Подписать с задержкой</button>
        <button type="submit" name="scenario" value="error">Ошибка</button>
    </div>
</form>

<pre id="result"></pre>
<script>
    document.getElementById('sign-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        // FormData(form) БЕЗ второго аргумента не включает кнопку-отправитель:
        // поле scenario не доходило до сервера -> 400 BAD_SCENARIO.
        const data = new FormData(form, event.submitter);
        if (event.submitter && event.submitter.name && !data.has(event.submitter.name))
        {
            data.append(event.submitter.name, event.submitter.value);
        }
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: data,
        });
        document.getElementById('result').textContent =
            response.status + ' ' + await response.text();
    });
</script>
</body>
</html>
