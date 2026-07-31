<?php

// Сигналы — простейший IPC: только событие, без полезных данных.
// posix_kill шлёт сигнал, pcntl_signal ставит обработчик.
// Здесь сигнал — триггер, а данные передаются уже через pipe.
//
// Обмен:
//   parent                              child
//     │  fgets(parent)                 ◀──┼────── fwrite(child, "ready") — handshake
//     │ posix_kill(pid, SIGUSR1)          │ обработчик SIGUSR1 → handled=1
//     ├──────────────────────────────────▶│
//     │ posix_kill(pid, SIGUSR2)          │ обработчик SIGUSR2 → handled=2
//     ├──────────────────────────────────▶│
//     │  fgets(parent)                 ◀──┼──────── fwrite(child, "done")
//     ▼                                  ▼
//
// Handshake нужен: без него родитель шлёт сигнал раньше, чем ребёнок
// успевает поставить pcntl_signal(), и дефолтное действие сигнала убивает его.

[$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

$pid = pcntl_fork();

if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    fclose($parent);

    $handled = 0;

    pcntl_signal(SIGUSR1, function ($signo) use (&$handled) {
        $handled++;
        echo 'child ' . getmypid() . ": got SIGUSR1 ($signo)\n";
    });

    pcntl_signal(SIGUSR2, function ($signo) use (&$handled) {
        $handled++;
        echo 'child ' . getmypid() . ": got SIGUSR2 ($signo)\n";
    });

    // Сообщаем родителю, что обработчики готовы
    fwrite($child, "ready\n");

    // Ждём, пока не обработаем 2 сигнала
    while ($handled < 2) {
        pcntl_signal_dispatch();
        usleep(100000);
    }

    // Отвечаем через pipe
    fwrite($child, "done\n");
    fclose($child);
    exit(0);
}

fclose($child);

// Ждём готовность ребёнка
fgets($parent);

posix_kill($pid, SIGUSR1);
sleep(1);
posix_kill($pid, SIGUSR2);

$response = fgets($parent);
echo 'parent ' . getmypid() . ": child replied '" . trim($response) . "'\n";

fclose($parent);
pcntl_wait($status);

// Ожидаемый вывод:
// child <pid>: got SIGUSR1 (10)
// child <pid>: got SIGUSR2 (12)
// parent <pid>: child replied 'done'
