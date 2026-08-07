<?php

// Scheduler (планировщик): получает задачи из очереди и распределяет их по
// воркерам по политике round-robin — каждому следующему по кругу.
// В отличие от fan-out (competing consumers: кто первый схватил), здесь
// мастер сам решает, какому воркеру достанется задача.

const TASK_COUNT = 9;
const WORKER_COUNT = 3;
const TERMINATOR = "\0__TERM__\0";

$taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

// Инбоксы воркеров — по одной очереди на каждого ('a', 'b', 'c')
$workerQueues = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    $workerQueues[$w] = msg_get_queue(ftok(__FILE__, chr(96 + $w)), 0666);
}

// Источник задач
$sourcePid = pcntl_fork();
if ($sourcePid === 0) {
    for ($i = 1; $i <= TASK_COUNT; $i++) {
        msg_send($taskQueue, 1, "task $i");
    }
    msg_send($taskQueue, 1, TERMINATOR);
    exit(0);
}

// Воркеры: читают только СВОЮ очередь
$workerPids = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        while (true) {
            $msg = '';
            $type = 0;
            $error = null;
            msg_receive($workerQueues[$w], 1, $type, 1024, $msg, true, 0, $error);

            if ($msg === 'STOP') {
                break;
            }
            echo "Worker$w: processed $msg\n";
            usleep(50000);
        }
        exit(0);
    }
    $workerPids[$w] = $pid;
}

// Scheduler (родитель): round-robin раздача
$rr = 0;
while (true) {
    $msg = '';
    $type = 0;
    $error = null;
    msg_receive($taskQueue, 1, $type, 1024, $msg, true, 0, $error);

    if ($msg === TERMINATOR) {
        break;
    }
    $w = ($rr % WORKER_COUNT) + 1;
    $rr++;
    msg_send($workerQueues[$w], 1, $msg);
    echo "Scheduler: $msg -> Worker$w (round-robin)\n";
}

// Завершение
foreach ($workerQueues as $queue) {
    msg_send($queue, 1, 'STOP');
}

pcntl_waitpid($sourcePid, $status);
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

foreach ($workerQueues as $queue) {
    msg_remove_queue($queue);
}
msg_remove_queue($taskQueue);
