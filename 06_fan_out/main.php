<?php

// Fan-Out: source раздаёт задачи N worker'ам через общую очередь.
// Каждый worker забирает следующую свободную задачу (competing consumers).

pcntl_async_signals(true);

const TASK_COUNT = 12;
const WORKER_COUNT = 3;
const TERMINATOR = "\0__TERM__\0";

// Source: отправляет задачи и terminator'ы (по одному на каждого worker'а)
function source(SysvMessageQueue $queue, int $taskCount): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        for ($i = 1; $i <= $taskCount; $i++) {
            msg_send($queue, 1, $i);
            usleep(rand(10000, 50000));
        }
        for ($i = 0; $i < WORKER_COUNT; $i++) {
            msg_send($queue, 1, TERMINATOR);
        }
        exit(0);
    }

    return $pid;
}

// Worker: забирает следующую свободную задачу из очереди (competing consumers)
function worker(SysvMessageQueue $queue, int $id): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        while (true) {
            $msgType = 0;
            $msg = '';
            $error = null;

            $received = msg_receive($queue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            if ($received) {
                if ($msg === TERMINATOR) {
                    break;
                }
                $result = (int)$msg * 2;
                echo "Worker$id (" . getmypid() . "): $msg -> $result\n";
                usleep(rand(50000, 200000));
                continue;
            }

            usleep(10000);
        }
        exit(0);
    }

    return $pid;
}

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

// Source: раздаёт задачи в очередь
$sourcePid = source($queue, TASK_COUNT);

// Workers: каждый забирает следующую свободную задачу
$workerPids = [];
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    $workerPids[] = worker($queue, $i);
}

// Ждём source (он завершится, когда отправит все задачи и terminator'ы)
pcntl_waitpid($sourcePid, $status);

// Ждём worker'ов (каждый выйдет по terminator'у)
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

msg_remove_queue($queue);
