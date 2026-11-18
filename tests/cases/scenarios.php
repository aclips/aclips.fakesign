<?php

declare(strict_types=1);

use Aclips\Fakesign\Service\DelayAgent;
use Aclips\Fakesign\Service\DocumentStore;
use Aclips\Fakesign\Service\SigningSimulator;

$store = static fn(): DocumentStore => new DocumentStore();

return [
    'неотложенный sign переводит pending в done и ставит completedAt' => static function () use ($store): void {
        $state = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        $result = (new SigningSimulator($store()))->apply($state, SigningSimulator::ACTION_SIGN);

        expect_eq($result->status, 'done', 'status=done');
        expect_true($result->completedAt !== null && $result->completedAt !== '', 'completedAt установлен');
        expect_eq($result->isCompleted(), true, 'состояние завершено');
    },
    'отказ переводит pending в refused' => static function () use ($store): void {
        $state = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        $result = (new SigningSimulator($store()))->apply($state, SigningSimulator::ACTION_REFUSE);

        expect_eq($result->status, 'refused', 'status=refused');
        expect_true($result->completedAt !== null && $result->completedAt !== '', 'completedAt установлен');
    },
    'error не помечает состояние завершённым' => static function () use ($store): void {
        $state = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        $simulator = new SigningSimulator($store());

        $result = $simulator->apply($state, SigningSimulator::ACTION_ERROR);
        expect_eq($result->status, 'error', 'status=error');
        expect_eq($result->completedAt, null, 'completedAt не установлен');
        expect_eq($result->isCompleted(), false, 'НЕ завершено - попытку можно повторить');

        $retry = $simulator->apply($result, SigningSimulator::ACTION_SIGN);
        expect_eq($retry->status, 'done', 'повтор после error возможен');
    },
    'delay оставляет pending до срока, после срока DelayAgent завершает в done' => static function () use ($store): void {
        $doc = 'doc-' . uniqid();
        $mem = 'mem-' . uniqid();
        $simulator = new SigningSimulator($store());

        $delayed = $simulator->apply($store()->touch($doc, $mem), SigningSimulator::ACTION_DELAY, 60);
        expect_eq($delayed->status, 'pending', 'остаётся pending');
        expect_eq($delayed->scenario, 'delay', 'сценарий delay');
        expect_eq($delayed->delaySeconds, 60, 'задержка сохранена');
        expect_true($delayed->requestedAt !== null && $delayed->requestedAt !== '', 'отсчёт запущен');

        $agentRun = DelayAgent::run(new \DateTimeImmutable('@' . strtotime((string)$delayed->requestedAt) + 61));
        // Протокол CAgent::ExecuteAgents: '' -> удаление агента, НЕПУСТОЕ -> переименование
        // записи b_agent.NAME в это значение; периодический агент возвращает СВОЁ имя.
        expect_eq(
            $agentRun,
            'Aclips\Fakesign\Service\DelayAgent::run();',
            'агент возвращает своё имя для перепланирования'
        );

        $final = $store()->touch($doc, $mem);
        expect_eq($final->status, 'done', 'статус done после агента');
        expect_true($final->completedAt !== null && $final->completedAt !== '', 'completedAt от агента');
    },
    'повторное применение sign поверх done не меняет состояние' => static function () use ($store): void {
        $state = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        $simulator = new SigningSimulator($store());

        $first = $simulator->apply($state, SigningSimulator::ACTION_SIGN);
        $second = $simulator->apply($first, SigningSimulator::ACTION_SIGN);

        expect_eq($second->status, 'done', 'status не изменился');
        expect_eq($second->completedAt, $first->completedAt, 'completedAt не сдвинулся');
    },
    'повторный вызов delay не удваивает задержку' => static function () use ($store): void {
        $state = $store()->touch('doc-' . uniqid(), 'mem-' . uniqid());
        $simulator = new SigningSimulator($store());

        $first = $simulator->apply($state, SigningSimulator::ACTION_DELAY, 60);
        $again = $simulator->apply($first, SigningSimulator::ACTION_DELAY, 30);

        expect_eq($again->delaySeconds, 60, 'первоначальная задержка не перезаписана');
        expect_eq($again->requestedAt, $first->requestedAt, 'таймер не сдвинут (без бесконечного отодвигания)');
    },
];
