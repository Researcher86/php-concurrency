<?php

// Rate Limiter: R воркеров разделяют ОДИН лимит (задач в секунду).
// Общий счётчик в shared memory + семафор = атомарное "бронирование слота".
// Каждый воркер ждёт слот вне блокировки, поэтому суммарно задачи не
// идут быстрее лимита, хотя воркеров несколько.

const WORKER_COUNT = 3;
const TASK_COUNT = 15;
const RATE_PER_SEC = 5;
const SLOT_S = 1 / RATE_PER_SEC;

$sem = sem_get(ftok(__FILE__, 's'), 1, 0666);
$shmId = shm_attach(ftok(__FILE__, 'h'), 1024, 0666);

// В shared memory: [1] = номер следующей задачи, [2] = время следующего слота
shm_put_var($shmId, 1, 0);
shm_put_var($shmId, 2, 0);

$start = microtime(true);

$workerPids = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        while (true) {
            // Берём номер задачи атомарно
            sem_acquire($sem);
            $taskIndex = shm_get_var($shmId, 1);
            $nextSlot = shm_get_var($shmId, 2);
            if ($taskIndex >= TASK_COUNT) {
                sem_release($sem);
                break;
            }
            shm_put_var($shmId, 1, $taskIndex + 1);

            $now = microtime(true);
            $slotStart = max($now, $nextSlot);
            shm_put_var($shmId, 2, $slotStart + SLOT_S);
            sem_release($sem);

            // Ждём слот вне блокировки — остальные воркеры не блокируются
            $sleepUs = (int)(($slotStart - $now) * 1e6);
            if ($sleepUs > 0) {
                usleep($sleepUs);
            }

            printf("Worker%d: task %d at %+.2fs\n", $w, $taskIndex + 1, $slotStart - $start);
        }
        exit(0);
    }
    $workerPids[] = $pid;
}

foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

printf('Elapsed: %.2fs for %d tasks @ %d/sec' . "\n", microtime(true) - $start, TASK_COUNT, RATE_PER_SEC);
shm_remove($shmId);
sem_remove($sem);
