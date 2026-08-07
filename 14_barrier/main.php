<?php

// Barrier: фаза 2 ни у кого не начнётся, пока ВСЕ процессы не завершат фазу 1.
// Счётчик в shared memory + семафор: каждый пришедший увеличивает счётчик,
// остальные ждут (spin), пока счётчик не достигнет N.

const WORKER_COUNT = 4;

$sem = sem_get(ftok(__FILE__, 's'), 1, 0666);
$shmId = shm_attach(ftok(__FILE__, 'h'), 1024, 0666);
shm_put_var($shmId, 1, 0);

$workerPids = [];
for ($w = 1; $w <= WORKER_COUNT; $w++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        $workTime = mt_rand(20, 120) * 1000;

        // Фаза 1: каждый работает своё время
        usleep($workTime);
        echo "Worker$w: phase 1 done (" . (int)($workTime / 1000) . "ms work)\n";

        // Барьер: увеличиваем счётчик; последний разблокирует всех
        sem_acquire($sem);
        $arrived = shm_get_var($shmId, 1) + 1;
        shm_put_var($shmId, 1, $arrived);
        sem_release($sem);

        while (shm_get_var($shmId, 1) < WORKER_COUNT) {
            usleep(1000);
        }

        // Фаза 2: начинается у всех одновременно
        echo "Worker$w: phase 2 started (all " . WORKER_COUNT . " arrived)\n";
        exit(0);
    }
    $workerPids[] = $pid;
}

foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

echo "Master: barrier released, all workers finished\n";
shm_remove($shmId);
sem_remove($sem);
