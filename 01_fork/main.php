<?php

for ($i = 1; $i <= 2; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        echo "child $i process: " . getmypid() . "\n";

        for ($j = 0; $j < 10; $j++) {
            echo "child $i process: " . $j . "\n";
            usleep(2000000);
        }
        echo "child $i done\n";
        exit(0);
    }
}

echo "parent process: " . getmypid() . "\n";

for ($i = 0; $i < 10; $i++) {
    echo "parent process: " . $i . "\n";
    usleep(1000000);
}

echo "waiting for child...\n";

// Если не сделать, то все дочерние процессы станут зомби, и их не убить, без перезагрузки контейнера
//
// Полезно еще добавить на всякий случай это
//
// init: true в docker-compose.yml запускает tini как PID 1 вместо вашей команды.
//
// Обычно PID 1 в контейнере — это php/bash/shell. У PID 1 в Linux уникальные обязанности:
// 1. Reap зомби — вызывать wait() для процессов-сирот (с PPID 1).
//    Ни php, ни bash этого не делают → копятся зомби.
// 2. Forward сигналов — SIGTERM/SIGINT приходят на PID 1, а он должен передать их
//    дочерним процессам. Без tini docker stop ждёт таймаут и киляет -9.
//
// tini (минимальный init, ~7KB) висит в цикле wait(), репнит сирот мгновенно
// и корректно прокидывает сигналы. Решает обе проблемы одной строкой.
while (pcntl_wait($status) !== -1) {
    // optional: логика на каждый вышедший процесс
    echo "child exited with status: $status\n";
}
