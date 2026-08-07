<?php

// Work Stealing: общая очередь задач + отдельная "домашняя" очередь у каждого
// воркера. Мастер раскладывает первичные задачи неравномерно (перегружает W1),
// но когда воркер опустошил свою очередь и общую — он крадёт задачи из чужих
// очередей. Нагрузка балансируется сама: все заканчивают почти одновременно.

pcntl_async_signals(true);

const WORKER_COUNT = 3;
const TASK_COUNT = 9;

$sharedQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

$homeQueues = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    $homeQueues[$w] = msg_get_queue(ftok(__FILE__, chr(96 + $w)), 0666);
}

$workerPids = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    // Флаг и хендлер объявляем ДО fork: ребёнок рождается уже с правильной
    // диспозицией, и ранний SIGTERM не теряется (см. подробности в 03_worker_pool)
    $stop = false;
    pcntl_signal(SIGTERM, function () use (&$stop) {
        $stop = true;
    });

    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        // W1 перегружен и работает медленно — остальные быстро выдохнутся и украдут у него
        $processDelay = ($w === 1) ? 120000 : 10000;

        while (!$stop) {
            $msg = '';
            $type = 0;
            $error = null;

            // 1) сначала своя очередь (не блокируемся)
            $got = msg_receive($homeQueues[$w], 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            if (!$got) {
                // 2) потом общая
                $got = msg_receive($sharedQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            }
            if (!$got) {
                // 3) иначе крадём из чужих очередей (тоже не блокируясь)
                foreach ($homeQueues as $other => $q) {
                    if ($other === $w) {
                        continue;
                    }
                    $got = msg_receive($q, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
                    if ($got) {
                        echo "Worker$w: STOLE '$msg' from Worker$other\n";
                        break;
                    }
                }
            }
            if (!$got) {
                usleep(20000);
                continue;
            }

            echo "Worker$w: processed $msg\n";
            msg_send($resultQueue, 1, "done:$msg");
            usleep($processDelay);
        }
        exit(0);
    }
    $workerPids[$w] = $pid;
}

// Мастер: неравномерная раскладка — первые задачи валим в очередь W1
for ($i = 1; $i <= TASK_COUNT; $i++) {
    if ($i <= 5) {
        msg_send($homeQueues[1], 1, "task $i");
        echo "Master: task $i -> home of Worker1\n";
    } else {
        msg_send($sharedQueue, 1, "task $i");
        echo "Master: task $i -> shared queue\n";
    }
}

// Ждём все результаты (неблокирующий поллинг)
$results = 0;
while ($results < TASK_COUNT) {
    $msg = '';
    $type = 0;
    $error = null;
    $got = msg_receive($resultQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
    if ($got) {
        $results++;
        continue;
    }
    usleep(10000);
}

// Останавливаем воркеров сигналом и ждём
foreach ($workerPids as $pid) {
    posix_kill($pid, SIGTERM);
}
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

echo "Master: all $results tasks processed\n";

foreach ($homeQueues as $q) {
    msg_remove_queue($q);
}
msg_remove_queue($resultQueue);
msg_remove_queue($sharedQueue);
