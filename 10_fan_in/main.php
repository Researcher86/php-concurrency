<?php

// Fan-In: N worker'ов пишут результат в shared memory, родитель собирает.
// Каждый пишет в свой слот, но shm_put_var() правит общий заголовок сегмента
// (смещение конца данных) — две одновременные записи даже в разные ключи
// могут затереть друг друга. Поэтому запись — под семафором.

const WORKER_COUNT = 3;

$shmId = shm_attach(ftok(__FILE__, 's'), 1024, 0644);
$sem = sem_get(ftok(__FILE__, 'l'), 1, 0666);

// Worker: пишет результат в shared memory под своим ключом $wId
function worker(SysvSharedMemory $shmId, SysvSemaphore $sem, int $wId): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        usleep(rand(50000, 200000));

        sem_acquire($sem);
        shm_put_var($shmId, $wId, "Worker$wId");
        sem_release($sem);

        exit(0);
    }

    return $pid;
}

$workerPids = [];
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    $workerPids[] = worker($shmId, $sem, $i);
}

// Ждём worker'ов
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Читаем результаты из shared memory по ключам 1..N
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    echo shm_get_var($shmId, $i) . "\n";
}

shm_remove($shmId);
sem_remove($sem);
