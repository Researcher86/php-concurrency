<?php

// Mini RoadRunner: воркеры ПЕРСИСТЕНТНЫ — живут между запросами и крутят свой
// цикл «принять job -> обработать -> вернуть результат» внутри одного процесса
// (в отличие от классического PHP, где процесс умирает после каждого запроса).
// Сервер подаёт jobs через relay-канал; упавший воркер перезапускается.

const WORKER_COUNT = 3;
const JOB_COUNT = 8;

$jobQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

$spawnWorker = function () use ($jobQueue, $resultQueue): int {
    $pid = pcntl_fork();
    if ($pid === 0) {
        // Persistent-цикл: один процесс обслуживает много jobs
        while (true) {
            $job = '';
            $type = 0;
            $error = null;
            msg_receive($jobQueue, 1, $type, 1024, $job, true, 0, $error);

            if ($job === 'STOP') {
                break;
            }
            echo 'Worker(' . getmypid() . '): processing ' . $job . "\n";
            usleep(30000);

            // Имитация падения на "вредном" job
            if (str_contains($job, 'boom')) {
                echo 'Worker(' . getmypid() . "): CRASH on $job\n";
                exit(1);
            }
            msg_send($resultQueue, 1, "done:$job");
        }
        exit(0);
    }
    return $pid;
};

// Сервер поднимает пул persistent-воркеров
$pool = [];
for ($i = 0; $i < WORKER_COUNT; $i++) {
    $pool[] = $spawnWorker();
}

// Подача jobs; третий — "вредный", на нём воркер упадёт
for ($i = 1; $i <= JOB_COUNT; $i++) {
    $job = ($i === 3) ? "job 3 boom" : "job $i";
    msg_send($jobQueue, 1, $job);
    echo "Server: dispatched $job\n";
}

// Сервер собирает результаты и чинит пул
$results = 0;
$restarts = 0;

while ($results < JOB_COUNT - 1) { // "boom"-job результата не даёт
    $msg = '';
    $type = 0;
    $error = null;
    $got = @msg_receive($resultQueue, 1, $type, 1024, $msg, true, 0, $error);
    if ($got) {
        $results++;
        echo "Server: got result '$msg' ($results/$" . (JOB_COUNT - 1) . ")\n";
    }

    foreach ($pool as $i => $pid) {
        $reaped = pcntl_waitpid($pid, $status, WNOHANG);
        if ($reaped > 0) {
            if (pcntl_wexitstatus($status) !== 0) {
                $pool[$i] = $spawnWorker();
                $restarts++;
                echo "Server: worker #$i crashed, respawned\n";
            } else {
                unset($pool[$i]);
            }
        }
    }
    usleep(1000);
}

// Останавливаем оставшихся persistent-воркеров
foreach ($pool as $i => $pid) {
    msg_send($jobQueue, 1, 'STOP');
}
foreach ($pool as $pid) {
    pcntl_waitpid($pid, $status);
}

echo "Server: $results jobs done, $restarts restarts after crash\n";

msg_remove_queue($jobQueue);
msg_remove_queue($resultQueue);
