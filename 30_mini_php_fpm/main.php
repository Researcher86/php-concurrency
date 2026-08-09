<?php

// Mini PHP-FPM: мастер держит пул воркеров, каждый воркер принимает запросы
// из общей очереди и обрабатывает до pm.max_requests, после чего умирает и
// перезапускается мастером (защита от утечек, как в настоящем FPM).

const POOL_SIZE = 3;
const REQUEST_COUNT = 7;
const MAX_REQUESTS = 3;

$requestQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

// Воркер: крутится, пока не увидит STOP или не упрётся в max_requests
$spawnWorker = function () use ($requestQueue, $resultQueue): int {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        $handled = 0;
        while (true) {
            $req = '';
            $type = 0;
            $error = null;
            msg_receive($requestQueue, 1, $type, 1024, $req, true, 0, $error);

            if ($req === 'STOP') {
                break;
            }
            $handled++;
            $res = strtoupper($req);
            echo "Worker(" . getmypid() . "): $req -> $res\n";
            msg_send($resultQueue, 1, $res);
            usleep(30000);

            // Достигнут pm.max_requests — самоуничтожение
            if ($handled >= MAX_REQUESTS) {
                echo "Worker(" . getmypid() . "): reached max_requests=$handled, restarting\n";
                exit(0);
            }
        }
        exit(0);
    }
    return $pid;
};

// Мастер: запускаем пул
$pool = [];
for ($i = 0; $i < POOL_SIZE; $i++) {
    $pool[] = $spawnWorker();
}

// Подаём запросы
for ($i = 1; $i <= REQUEST_COUNT; $i++) {
    msg_send($requestQueue, 1, "request $i");
}

// Мастер-цикл: принимает запросы результата и следит за пулом.
// Упавший (из-за max_requests) воркер тут же перезапускается.
$results = 0;
$restarts = 0;

while ($results < REQUEST_COUNT) {
    // подбираем результаты (неблокирующе)
    $msg = '';
    $type = 0;
    $error = null;
    $got = msg_receive($resultQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
    if ($got) {
        $results++;
    }

    // перезапуск умерших
    foreach ($pool as $i => $pid) {
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped > 0) {
            $pool[$i] = $spawnWorker();
            $restarts++;
            echo "Master: respawned dead worker #$i\n";
        }
    }
    usleep(1000);
}

// Все запросы обработаны. Последний воркер, дошедший до max_requests, мог
// умереть сразу после отправки результата — даём ему выйти (его usleep 30ms)
// и засчитываем рестарт, иначе "worker restarts" покажет 0 при живом демо.
usleep(60000);

do {
    $reaped = false;
    foreach ($pool as $i => $pid) {
        $r = pcntl_waitpid($pid, $status, WNOHANG);
        if ($r > 0) {
            $pool[$i] = $spawnWorker();
            $restarts++;
            echo "Master: respawned dead worker #$i\n";
            $reaped = true;
        }
    }
} while ($reaped);

// Останавливаем пул
foreach ($pool as $i => $pid) {
    msg_send($requestQueue, 1, 'STOP');
}

foreach ($pool as $pid) {
    pcntl_waitpid($pid, $status);
}

echo "Master: $results requests handled, $restarts worker restarts (pm.max_requests)\n";

msg_remove_queue($requestQueue);
msg_remove_queue($resultQueue);
