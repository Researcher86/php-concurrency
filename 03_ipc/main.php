<?php

declare(strict_types=1);

// Точка входа урока 03: запускает шесть демо по очереди.
//
// Каждое демо — отдельный процесс PHP, а не require: демо форкают детей,
// ставят обработчики сигналов и создают IPC-ресурсы под свой ftok(__FILE__)
// ключ. В одном процессе они мешали бы друг другу; по отдельности каждое
// стартует с чистого состояния, как при ручном запуске.
//
//   php main.php              все шесть подряд
//   php main.php sysvshm      только одно (имя файла без .php)

$demos = [
    'pipe'        => 'Unix socket pair (stream_socket_pair)',
    'unix_socket' => 'AF_UNIX сокет через файл в /tmp',
    'msg_queue'   => 'System V очередь сообщений',
    'sysvshm'     => 'System V shared memory (shm_put_var/shm_get_var)',
    'semaphore'   => 'System V семафор',
    'signals'     => 'сигналы + pipe для данных',
];

$only = $argv[1] ?? null;

if ($only !== null) {
    if (!isset($demos[$only])) {
        fwrite(STDERR, "неизвестное демо '$only'; есть: " . implode(', ', array_keys($demos)) . "\n");
        exit(1);
    }

    $demos = [$only => $demos[$only]];
}

$failed = [];

foreach ($demos as $name => $title) {
    echo "=== $name.php — $title ===\n";

    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/$name.php"), $code);

    if ($code !== 0) {
        $failed[] = "$name (exit $code)";
    }

    echo "\n";
}

if ($failed !== []) {
    fwrite(STDERR, 'с ошибкой: ' . implode(', ', $failed) . "\n");
    exit(1);
}
