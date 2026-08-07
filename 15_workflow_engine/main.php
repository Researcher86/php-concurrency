<?php

// Workflow Engine (движок рабочих процессов):
//   - Workflow Definition — описание шагов (Start → Step1 → Step2 → End).
//   - Каждый шаг выполняется как Activity в отдельном дочернем процессе.
//   - Движок (родитель) оркестрирует: запускает шаги по очереди, проверяет
//     результат (exit code) и ведёт History событий.
//   - Состояния workflow: running → completed | failed (paused — не реализовано).

// Активность "charge_payment": с параметром $fails можно смоделировать ошибку.
function makeChargePayment(bool $fails): Closure
{
    return function () use ($fails) {
        echo 'Activity charge_payment (pid ' . getmypid() . '): charging card...' . "\n";
        usleep(300000);

        if ($fails) {
            echo "Activity charge_payment: card DECLINED\n";
            return false;
        }

        return true;
    };
}

// Запуск workflow: пошагово форкает Activity и записывает события в history.
// Возвращает итоговую историю.
function runWorkflow(array $definition): array
{
    $state = 'running';
    $history = [];

    echo "\nEngine: workflow '" . $definition['name'] . "' -> state: $state\n";
    $history[] = "state:$state";

    foreach ($definition['steps'] as $step) {
        echo "Engine: step '" . $step['name'] . "' started\n";
        $history[] = "step_started:{$step['name']}";

        // Activity выполняется в отдельном процессе
        $pid = pcntl_fork();
        if ($pid === 0) {
            $ok = call_user_func($step['work']);
            exit($ok ? 0 : 1);
        }

        pcntl_waitpid($pid, $status);
        $ok = pcntl_wexitstatus($status) === 0;

        if ($ok) {
            echo "Engine: step '" . $step['name'] . "' completed\n";
            $history[] = "step_completed:{$step['name']}";
        } else {
            echo "Engine: step '" . $step['name'] . "' FAILED\n";
            $history[] = "step_failed:{$step['name']}";
            $state = 'failed';
            break;
        }
    }

    if ($state === 'running') {
        $state = 'completed';
    }

    echo "Engine: workflow '" . $definition['name'] . "' -> state: $state\n";
    $history[] = "state:$state";

    return $history;
}

$validateOrder = function () {
    echo 'Activity validate_order (pid ' . getmypid() . '): validating order...' . "\n";
    usleep(300000);
    return true;
};

$shipOrder = function () {
    echo 'Activity ship_order (pid ' . getmypid() . '): shipping...' . "\n";
    usleep(300000);
    return true;
};

// Workflow 1: счастливый путь — все шаги успешны
$happyOrder = [
    'name' => 'happy_order',
    'steps' => [
        ['name' => 'validate_order', 'work' => $validateOrder],
        ['name' => 'charge_payment', 'work' => makeChargePayment(false)],
        ['name' => 'ship_order', 'work' => $shipOrder],
    ],
];

// Workflow 2: оплата падает — движок должен перевести workflow в состояние failed
$failedOrder = [
    'name' => 'failed_order',
    'steps' => [
        ['name' => 'validate_order', 'work' => $validateOrder],
        ['name' => 'charge_payment', 'work' => makeChargePayment(true)],
    ],
];

foreach ([$happyOrder, $failedOrder] as $definition) {
    $history = runWorkflow($definition);
    echo 'Engine: history: ' . implode(' | ', $history) . "\n";
}
