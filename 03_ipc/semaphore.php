<?php

// Семафор — примитив синхронизации (sysvsem).
// sem_acquire блокирует критическую секцию, sem_release освобождает её.
//
// Задача: 3 процесса × 5 инкрементов общего счётчика = должно стать 15.
// Без семафора read-modify-write "рассыпается": два процесса читают одно
// и то же значение и теряют одно обновление.
//
// Схема (counter в shared memory, lock — семафор):
//   proc1         proc2         proc3            shm: counter
//    │             │             │
//    │ sem_acquire               │
//    ├─── read 0 ──┬─────────────┼──────────────▶ read 0
//    ├─── +1  = 1  │             │
//    ├─── write 1 ─┼─────────────┼──────────────▶ write 1
//    │ sem_release │             │
//    │             │ sem_acquire │
//    │             ├── read 1 ───┼──────────────▶ read 1   (не конкурирует с proc1)
//    │             └─────────────┴──────────────── ...     (итог 15)
//    ▼             ▼             ▼

$sem = sem_get(ftok(__FILE__, 'a'), 1, 0666, 1);
$shmKey = ftok(__FILE__, 'b');
$shmId = shm_attach($shmKey, 1024, 0644);
shm_put_var($shmId, 1, 0);

// 3 процесса, каждый инкрементит 5 раз
for ($i = 1; $i <= 3; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        for ($j = 0; $j < 5; $j++) {
            // Критическая секция: читать-увеличить-записать атомарно
            sem_acquire($sem);

            $val = shm_get_var($shmId, 1);
            $val++;
            shm_put_var($shmId, 1, $val);

            sem_release($sem);
        }
        shm_detach($shmId);
        exit(0);
    }
}

// Ждём всех детей
while (pcntl_wait($status) !== -1) {
    // reap
}

$result = shm_get_var($shmId, 1);
echo "counter = $result (ожидается 15)\n";

shm_remove($shmId);
shm_detach($shmId);
sem_remove($sem);

// Ожидаемый вывод:
// counter = 15 (ожидается 15)
