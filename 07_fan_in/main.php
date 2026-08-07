<?php

// Fan-In: N worker'ов пишут результат в shared memory, родитель собирает.

const WORKER_COUNT = 3;

function initSharedMemory(): SysvSharedMemory
{
    $shmKey = ftok(__FILE__, 's');
    $shmId = shm_attach($shmKey, 1024, 0644);
    if (!$shmId) {
        die('shm_attach failed');
    }

    return $shmId;
}

// Worker: пишет результат в shared memory под своим ключом $wId
function worker(SysvSharedMemory $shmId, int $wId): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        usleep(rand(50000, 200000));

        shm_put_var($shmId, $wId, "Worker$wId");
        shm_detach($shmId);

        exit(0);
    }

    return $pid;
}

$sharedMemory = initSharedMemory();

$workerPids = [];
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    $workerPids[] = worker($sharedMemory, $i);
}

// Ждём worker'ов
foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Читаем результаты из shared memory по ключам 1..N
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    echo shm_get_var($sharedMemory, $i) . "\n";
}

shm_remove($sharedMemory);
shm_detach($sharedMemory);
