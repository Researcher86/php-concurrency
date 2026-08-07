<?php

// Mini PHP Runtime: мастер + пул persistent-воркеров + очередь запросов +
// event loop + supervisor (перезапуск упавших). Модель PHP-FPM/RoadRunner.

const WORKER_COUNT = 3;
const REQUEST_COUNT = 10;
const STOP_MSG = "\0STOP\0";
const CRASH_MSG = "\0CRASH\0";

$requestQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

$spawnWorker = function () use ($requestQueue, $resultQueue): int {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        while (true) {
            $req = '';
            $type = 0;
            $error = null;
            $got = msg_receive($requestQueue, 1, $type, 1024, $req, true, MSG_IPC_NOWAIT, $error);
            if (!$got) {
                usleep(5000);
                continue;
            }
            if ($req === STOP_MSG) {
                exit(0);
            }
            if ($req === CRASH_MSG) {
                echo "Worker " . getmypid() . ": CRASH (simulated)\n";
                exit(2);
            }
            usleep(20000);
            echo "Worker " . getmypid() . ": handled '$req'\n";
            msg_send($resultQueue, 1, "done:$req");
        }
    }
    return $pid;
};

// Стартовый пул
$workerPids = [];
for ($i = 0; $i < WORKER_COUNT; $i++) {
    $workerPids[] = $spawnWorker();
}

// Отправляем запросы, один из которых "роняет" воркера
for ($i = 1; $i <= REQUEST_COUNT; $i++) {
    msg_send($requestQueue, 1, $i === 5 ? CRASH_MSG : "req-$i");
}
echo 'Master: ' . REQUEST_COUNT . " requests queued\n";

// Event loop + supervisor
$deadline = hrtime(true) + 10000000000;
while (true) {
    // Ответы
    $msg = '';
    $type = 0;
    $error = null;
    if (msg_receive($resultQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
        echo "Master: got result '$msg'\n";
    }

    // Перезапуск упавших
    foreach ($workerPids as $i => $pid) {
        $status = 0;
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped === $pid) {
            echo "Master: worker $pid died, respawning\n";
            $workerPids[$i] = $spawnWorker();
        }
    }

    $pending = msg_stat_queue($requestQueue)['msg_qnum'];
    if ($pending === 0) {
        break;
    }
    if (hrtime(true) > $deadline) {
        echo "Master: TIMEOUT\n";
        break;
    }
    usleep(10000);
}

// Останавливаем пул и ждём
foreach ($workerPids as $pid) {
    msg_send($requestQueue, 1, STOP_MSG);
}
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

echo "Master: pool stopped\n";
msg_remove_queue($requestQueue);
msg_remove_queue($resultQueue);
