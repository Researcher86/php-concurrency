<?php

// Unix-сокет (AF_UNIX) — файл в /tmp как точка входа в канал.
// Сервер создаёт сокет по пути, клиенты подключаются к нему.
// Работает только между процессами на одном хосте (в отличие от TCP).
//
// Обмен:
//   server (parent)                 client 1, client 2 (fork)
//     │  stream_socket_server        │
//     ├──────────────────────────────┼─▶ stream_socket_client(path)
//     │  accept (блокирует)          │      fwrite("hello...")
//     │ ◀────────────────────────────┼──── fgets(...)
//     │  fwrite("pong")            ──┼────▶ fgets → pong
//     ▼                              ▼

$socketPath = '/tmp/ipc_example.sock';

if (file_exists($socketPath)) {
    unlink($socketPath);
}

$server = stream_socket_server('unix://' . $socketPath, $errno, $errstr);
if (!$server) {
    die("socket server failed: $errstr ($errno)");
}

// Форкаем 2 клиентов
for ($i = 1; $i <= 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        // Клиент: подключаемся к сокету
        $client = stream_socket_client('unix://' . $socketPath, $errno, $errstr);
        if (!$client) {
            die("client failed: $errstr ($errno)");
        }

        fwrite($client, "hello from client $i (" . getmypid() . ")\n");
        $response = fgets($client);
        echo "client $i: got response: " . trim($response) . "\n";

        fclose($client);
        exit(0);
    }
}

// Сервер: принимаем 2 соединения и отвечаем
for ($i = 1; $i <= 2; $i++) {
    $conn = stream_socket_accept($server);
    $msg = fgets($conn);
    echo 'server: accepted: ' . trim($msg) . "\n";
    fwrite($conn, "pong\n");
    fclose($conn);
}

fclose($server);
unlink($socketPath);

while (pcntl_wait($status) !== -1) {
    // reap зомби
}

// Ожидаемый вывод (порядок клиентов может отличаться):
// client 1: got response: pong
// client 2: got response: pong
// server: accepted: hello from client 1 (<pid>)
// server: accepted: hello from client 2 (<pid>)
