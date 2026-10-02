<?php

// Priority Queue: задачи с разным приоритетом. Worker всегда забирает
// самую приоритетную: msg_receive с отрицательным типом -N отдаёт первое
// сообщение с НАИМЕНЬШИМ типом ≤ N (тип 1 — самый высокий приоритет).
// Terminator имеет тип 99 — его заберут, только когда задач 1..3 не осталось.

const TASK_COUNT = 30;
const TERMINATOR_TYPE = 99;

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

// Producer: шлёт задачи с разным приоритетом
$producerPid = pcntl_fork();
if ($producerPid === -1) {
    die('fork failed');
}
if ($producerPid === 0) {
    for ($i = 1; $i <= TASK_COUNT; $i++) {
        $priority = rand(1, 3);
        msg_send($queue, $priority, "p$priority: task $i");
    }
    msg_send($queue, TERMINATOR_TYPE, 'stop');
    exit(0);
}

// Worker: забирает задачи по приоритету (1 → 2 → 3), блокируясь на пустой очереди
$workerPid = pcntl_fork();
if ($workerPid === -1) {
    die('fork failed');
}
if ($workerPid === 0) {
    while (msg_receive($queue, -TERMINATOR_TYPE, $type, 1024, $msg) && $type !== TERMINATOR_TYPE) {
        echo "Worker: $msg\n";
    }
    exit(0);
}

pcntl_waitpid($producerPid, $status);
pcntl_waitpid($workerPid, $status);

msg_remove_queue($queue);
