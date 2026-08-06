<?php

// Priority Queue: задачи с разным приоритетом. Worker всегда забирает
// самую приоритетную (msg_receive с type=1 — самый высокий приоритет).

const TASK_COUNT = 30;
const TERMINATOR = "\0__TERM__\0";

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

// Producer: шлёт задачи с разным приоритетом
$producerPid = pcntl_fork();
if ($producerPid === 0) {
    $taskId = 1;
    for ($i = 1; $i <= TASK_COUNT; $i++) {
        $priority = rand(1, 3);
        msg_send($queue, $priority, "p$priority: task $taskId");
        $taskId++;
    }
    msg_send($queue, 99, TERMINATOR);
    exit(0);
}

// Worker: забирает задачи по приоритету (1 → 2 → 3)
$workerPid = pcntl_fork();
if ($workerPid === 0) {
    while (true) {
        $msg = '';
        $type = 0;
        $error = null;

        if (msg_receive($queue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
            echo "Worker: $msg\n";
            continue;
        }

        if (msg_receive($queue, 2, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
            echo "Worker: $msg\n";
            continue;
        }

        if (msg_receive($queue, 3, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
            echo "Worker: $msg\n";
            continue;
        }

        if (msg_receive($queue, 99, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
            break;
        }

        usleep(10000);
    }
    exit(0);
}

pcntl_waitpid($producerPid, $status);
pcntl_waitpid($workerPid, $status);

msg_remove_queue($queue);
