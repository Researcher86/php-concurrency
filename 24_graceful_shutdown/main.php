<?php

// Graceful Shutdown (мягкое завершение): по SIGTERM мастер не просто выходит,
// а проходит полный цикл:
//   Stop accepting → Drain queue → Finish active → Close IPC → waitpid → exit.
// Демо: таймер шлёт мастеру SIGTERM посреди потока задач; мастер доедает
// очередь, все воркеры завершаются, очередь удаляется — без потерь и зомби.

pcntl_async_signals(true);

const WORKER_COUNT = 3;
const TASK_COUNT = 50;
const STOP_MSG = "\0STOP\0";

$taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

$isShutdown = false;
pcntl_signal(SIGTERM, function () use (&$isShutdown) {
    $isShutdown = true;
    echo 'Master: SIGTERM received, stop accepting new jobs' . "\n";
});

// Воркеры: разбирают очередь, по STOP — выход
$workerPids = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        while (true) {
            $msg = '';
            $type = 0;
            $error = null;
            $got = msg_receive($taskQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error);

            if ($got && $msg === STOP_MSG) {
                break;
            }
            if ($got) {
                echo "Worker$w: processed $msg\n";
                usleep(20000);
                continue;
            }
            usleep(10000);
        }
        exit(0);
    }
    $workerPids[$w] = $pid;
}

// Таймер: через 200мс имитирует внешний сигнал остановки
$timerPid = pcntl_fork();
if ($timerPid === -1) {
    die('fork failed');
}
if ($timerPid === 0) {
    usleep(200000);
    posix_kill(posix_getppid(), SIGTERM);
    exit(0);
}

// Мастер: принимает задачи, пока не пришёл сигнал
for ($i = 1; $i <= TASK_COUNT && !$isShutdown; $i++) {
    msg_send($taskQueue, 1, "task $i");
}

if (!$isShutdown) {
    echo "Master: all tasks sent, shutting down normally\n";
} else {
    // Drain: ждём, пока очередь опустеет (воркеры доедают)
    while (msg_stat_queue($taskQueue)['msg_qnum'] > 0) {
        usleep(10000);
    }
    echo "Master: queue drained\n";
}

// Останавливаем воркеров и ждём их
foreach ($workerPids as $pid) {
    msg_send($taskQueue, 1, STOP_MSG);
}
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

pcntl_waitpid($timerPid, $status);

$left = msg_stat_queue($taskQueue)['msg_qnum'];
echo "Master: workers done, queue left: $left msgs\n";
msg_remove_queue($taskQueue);
