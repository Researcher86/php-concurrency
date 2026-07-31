<?php

// Pipe (STREAM_SOCK_PAIR) — двусторонний канал между процессами.
// stream_socket_pair создаёт пару соединённых сокетов: [parent, child].
// После fork() каждый процесс закрывает чужой конец и работает со своим.
//
// Обмен:
//   parent                          child
//     │  fwrite(parent, "21\n")      │
//     ├──────────────────────────────▶  fgets($child)   → 21
//     │                              │  * 2 = 42
//     │  fgets(parent)            ◀──┼────────────────  fwrite(child, "42\n")
//     ▼                              ▼

[$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

$pid = pcntl_fork();

if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    // Child: закрываем родительский конец
    fclose($parent);

    // Читаем задачу
    $task = fgets($child);
    echo 'child ' . getmypid() . ': got task: ' . trim($task) . "\n";

    // Считаем
    $result = (int) trim($task) * 2;
    usleep(500000);

    // Отправляем результат
    fwrite($child, $result . "\n");
    fclose($child);
    exit(0);
}

// Parent: закрываем детский конец
fclose($child);

// Отправляем задачу
fwrite($parent, "21\n");

// Ждём ответ
$result = fgets($parent);
echo 'parent ' . getmypid() . ': got result: ' . trim($result) . "\n";

fclose($parent);
pcntl_wait($status);

// Ожидаемый вывод:
// child  <pid>: got task: 21
// parent <pid>: got result: 42
