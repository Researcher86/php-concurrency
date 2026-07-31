<?php

// Shared memory (sysvshm) — общий сегмент памяти, видимый всем процессам.
// Один процесс пишет, другой читает. Синхронизации здесь НЕТ —
// если читатель стартует раньше писателя, он увидит пустое значение.
//
// Обмен:
//   writer (child)             shared memory            reader (parent)
//     │ shm_put_var(shm, ...)  ┌──────────────────┐
//     ├───────────────────────▶│ "hello from pid" │
//     │                        └──────────────────┘
//     │                               ▲  shm_get_var(shm, ...) (после sleep)
//     ▼                               └──────────┼

$shmKey = ftok(__FILE__, 's');
$shmId = shm_attach($shmKey, 1024, 0644);
if (!$shmId) {
    die('shm_attach failed');
}

$pid = pcntl_fork();

if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    // Writer (child)
    $data = 'hello from ' . getmypid();
    shm_put_var($shmId, 1, $data);
    echo 'writer ' . getmypid() . ": wrote '$data'\n";
    shm_detach($shmId);
    exit(0);
}

// Reader (parent): ждём записи, потом читаем
sleep(1);
$read = shm_get_var($shmId, 1);
echo 'reader ' . getmypid() . ": read '$read'\n";

pcntl_wait($status);
shm_remove($shmId);
shm_detach($shmId);

// Ожидаемый вывод:
// writer <pid>: wrote 'hello from <pid>'
// reader <pid>: read 'hello from <pid>'
