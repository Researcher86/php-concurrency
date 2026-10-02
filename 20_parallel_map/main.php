<?php

// Parallel Map: array_map(), но элементы обрабатываются в N процессах.
// Fan-Out: задачи (с индексом) уходят в очередь, воркеры параллельно считают,
// Fan-In: результаты собираются в исходном порядке по индексу.

const WORKER_COUNT = 3;
const STOP_MSG = "\0STOP\0";

// "Функция" обработки, "тяжёлая" для демонстрации распараллеливания
$fn = function (int $x): int {
    usleep(30000);
    return $x * $x;
};

function parallelMap(array $items, callable $fn, int $workers): array
{
    $taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
    $resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

    $workerPids = [];
    for ($w = 1; $w <= $workers; $w++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            die('fork failed');
        }
        if ($pid === 0) {
            // json + serialize=false: msg_send/msg_receive по умолчанию гоняют
            // payload через serialize()/unserialize(), а unserialize() данных из
            // очереди — риск PHP object injection, если payload когда-то придёт
            // не только от доверенного родителя
            while (msg_receive($taskQueue, 1, $type, 1024, $task, false) && $task !== STOP_MSG) {
                $parsed = json_decode($task, true);
                $result = $fn($parsed['value']);
                msg_send($resultQueue, 1, json_encode(['index' => $parsed['index'], 'result' => $result]), false);
            }
            exit(0);
        }
        $workerPids[] = $pid;
    }

    // Fan-Out
    foreach ($items as $index => $value) {
        msg_send($taskQueue, 1, json_encode(['index' => $index, 'value' => $value]), false);
    }

    // Fan-In: собираем и раскладываем по индексу (порядок сохраняется)
    $results = [];
    foreach ($items as $_) {
        msg_receive($resultQueue, 1, $type, 1024, $msg, false);
        $parsed = json_decode($msg, true);
        $results[$parsed['index']] = $parsed['result'];
    }

    foreach ($workerPids as $pid) {
        msg_send($taskQueue, 1, STOP_MSG, false);
    }
    foreach ($workerPids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    ksort($results);
    msg_remove_queue($taskQueue);
    msg_remove_queue($resultQueue);

    return $results;
}

$items = [1, 2, 3, 4, 5, 6];

$t0 = hrtime(true);
$squared = parallelMap($items, $fn, WORKER_COUNT);
$elapsed = round((hrtime(true) - $t0) / 1e9, 2);

echo 'Result: ' . implode(', ', $squared) . "\n";
echo "Elapsed: {$elapsed}s (6 items x 30ms work / " . WORKER_COUNT . " workers)\n";
