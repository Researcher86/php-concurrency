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
const ACCEPT_DELAY_US = 5000; // темп приёма: 50 × 5мс = 250мс > 200мс до SIGTERM

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
            msg_receive($taskQueue, 1, $type, 1024, $msg);

            if ($msg === STOP_MSG) {
                break;
            }
            echo "Worker$w: processed $msg\n";
            usleep(20000);
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

// Мастер: принимает задачи (по одной раз в ACCEPT_DELAY_US), пока не пришёл сигнал.
// Без паузы все msg_send() отработали бы мгновенно, и SIGTERM пришёл бы уже
// после приёма — drain-ветка никогда бы не выполнилась.
for ($i = 1; $i <= TASK_COUNT && !$isShutdown; $i++) {
    msg_send($taskQueue, 1, "task $i");
    usleep(ACCEPT_DELAY_US);
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
