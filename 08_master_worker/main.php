<?php

pcntl_async_signals(true);

// Master-Worker: мастер раздаёт задачи через taskQueue, управляет через
// ctrlQueue, воркеры отправляют результаты через resultQueue.

const TASK_COUNT = 12;
const WORKER_COUNT = 3;
const TERMINATOR_CTRL = "\0__CTRL_STOP__\0";
const TERMINATOR_RESULT = '__TERM_RESULT__';

function initQueue(string $proj): SysvMessageQueue
{
    return msg_get_queue(ftok(__FILE__, $proj), 0666);
}

function removeQueue(SysvMessageQueue $queue): bool
{
    return msg_remove_queue($queue);
}

// Worker: забирает задачи из taskQueue (доедает), проверяет ctrlQueue на останов
function worker(SysvMessageQueue $ctrlQueue, SysvMessageQueue $taskQueue, SysvMessageQueue $resultQueue, int $id): int
{
    $pid = pcntl_fork();

    if ($pid === 0) {
        while (true) {
            $msgType = 0;
            $msg = '';
            $error = null;

            $received = msg_receive($taskQueue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            if ($received) {
                $result = (int)$msg * 2;
                echo "Worker$id (" . getmypid() . "): $msg -> $result\n";
                msg_send($resultQueue, 1, $result);
                usleep(rand(50000, 200000));
                continue;
            }

            $received = msg_receive($ctrlQueue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            if ($received && $msg === TERMINATOR_CTRL) {
                msg_send($resultQueue, 1, TERMINATOR_RESULT);
                break;
            }

            usleep(10000);
        }
        exit(0);
    }

    return $pid;
}

$ctrlQueue = initQueue('c');
$taskQueue = initQueue('t');
$resultQueue = initQueue('r');

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
    $msgType = 0;
    $msg = '';
    $error = null;

    $received = msg_receive($resultQueue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
    if ($received) {
        if ($msg === TERMINATOR_RESULT) {
            $terminatorsBack++;
            echo "Master: terminators back ($terminatorsBack/" . WORKER_COUNT . ")\n";
        } else {
            echo "Master: collected result [$msg]\n";
        }
        continue;
    }

    usleep(10000);
}

foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

removeQueue($ctrlQueue);
removeQueue($taskQueue);
removeQueue($resultQueue);
