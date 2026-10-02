<?php

// Retry + Exponential Backoff: задача выполняется во вложенном процессе.
// Первые попытки падают (сервис "перегружен"), родитель ждёт растущую задержку
// (100 → 200 → 400 → 800 мс) и пробует снова — до MAX_ATTEMPTS.

const MAX_ATTEMPTS = 5;
const BASE_DELAY_MS = 100;

$taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

// Worker: выполняет задачу. Первые 4 раза — падает (имитация аварии сервиса),
// чтобы демо реально прошло всю прогрессию задержек 100->200->400->800мс
$workerPid = pcntl_fork();
if ($workerPid === -1) {
    die('fork failed');
}
if ($workerPid === 0) {
    $fails = 4;
    while (true) {
        $task = '';
        msg_receive($taskQueue, 1, $type, 1024, $task);

        if ($task === 'STOP') {
            break;
        }
        if ($fails > 0) {
            $fails--;
            echo "Worker: '$task' FAILED\n";
            msg_send($resultQueue, 1, 'FAIL');
        } else {
            echo "Worker: '$task' OK\n";
            msg_send($resultQueue, 1, 'OK');
        }
    }
    exit(0);
}

// Отправка задачи с экспоненциальной паузой между попытками
$attempt = 1;
while ($attempt <= MAX_ATTEMPTS) {
    echo "Caller: attempt $attempt -> dispatch\n";
    msg_send($taskQueue, 1, 'flaky-task');

    $reply = '';
    msg_receive($resultQueue, 1, $type, 1024, $reply);

    if ($reply === 'OK') {
        echo "Caller: SUCCESS on attempt $attempt\n";
        break;
    }

    $delayMs = BASE_DELAY_MS * (2 ** ($attempt - 1));
    echo "Caller: FAILED, backing off {$delayMs}ms\n";
    usleep($delayMs * 1000);
    $attempt++;
}

if ($attempt > MAX_ATTEMPTS) {
    echo "Caller: gave up after " . MAX_ATTEMPTS . " attempts\n";
}

msg_send($taskQueue, 1, 'STOP');
pcntl_waitpid($workerPid, $status);

msg_remove_queue($taskQueue);
msg_remove_queue($resultQueue);
