<?php

// Master-Worker: мастер раздаёт задачи через taskQueue, управляет через
// ctrlQueue, воркеры отправляют результаты через resultQueue.

const TASK_COUNT = 12;
const WORKER_COUNT = 3;
const TERMINATOR_CTRL = "\0__CTRL_STOP__\0";
const TERMINATOR_RESULT = '__TERM_RESULT__';

// Worker: забирает задачи из taskQueue; получив STOP из ctrlQueue, доедает
// taskQueue и только потом выходит. Мастер шлёт STOP после всех задач, поэтому
// пустая taskQueue ПОСЛЕ STOP значит «задач больше не будет». Выход сразу по
// STOP терял бы задачу, отправленную между проверкой taskQueue и ctrlQueue.
function worker(SysvMessageQueue $ctrlQueue, SysvMessageQueue $taskQueue, SysvMessageQueue $resultQueue, int $id): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        $stopping = false;
        while (true) {
            if (msg_receive($taskQueue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT)) {
                $result = (int)$msg * 2;
                echo "Worker$id (" . getmypid() . "): $msg -> $result\n";
                msg_send($resultQueue, 1, $result);
                usleep(rand(50000, 200000));
                continue;
            }

            if ($stopping) {
                msg_send($resultQueue, 1, TERMINATOR_RESULT);
                break;
            }

            if (msg_receive($ctrlQueue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT) && $msg === TERMINATOR_CTRL) {
                $stopping = true;
                continue;
            }

            usleep(10000);
        }
        exit(0);
    }

    return $pid;
}

$ctrlQueue = msg_get_queue(ftok(__FILE__, 'c'), 0666);
$taskQueue = msg_get_queue(ftok(__FILE__, 't'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

$workerPids = [];
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    $workerPids[] = worker($ctrlQueue, $taskQueue, $resultQueue, $i);
}

// Master: отправляет задачи
for ($i = 1; $i <= TASK_COUNT; $i++) {
    msg_send($taskQueue, 1, $i);
    echo 'Master: sent task ' . $i . "\n";
    usleep(rand(10000, 50000));
}

// Master: сигнал остановки через ctrlQueue
for ($i = 0; $i < WORKER_COUNT; $i++) {
    msg_send($ctrlQueue, 1, TERMINATOR_CTRL);
}

// Master: собирает результаты
$terminatorsBack = 0;
while ($terminatorsBack < WORKER_COUNT) {
    msg_receive($resultQueue, 0, $msgType, 1024, $msg);
    if ($msg === TERMINATOR_RESULT) {
        $terminatorsBack++;
        echo "Master: terminators back ($terminatorsBack/" . WORKER_COUNT . ")\n";
    } else {
        echo "Master: collected result [$msg]\n";
    }
}

foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

msg_remove_queue($ctrlQueue);
msg_remove_queue($taskQueue);
msg_remove_queue($resultQueue);
