<?php

// RPC (Remote Procedure Call): клиент вызывает "функцию", которая реально
// исполняется в другом процессе (сервере). Запрос и ответ передаются через
// очереди. Каждый вызов получает id — по нему ответ сопоставляется с запросом
// (correlation id). Выглядит как локальный вызов, работает удалённо.

$requestQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$responseQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

// RPC-сервер: регистрирует процедуры и исполняет вызовы
$serverPid = pcntl_fork();
if ($serverPid === -1) {
    die('fork failed');
}
if ($serverPid === 0) {
    $procedures = [
        'add' => fn($a, $b) => $a + $b,
        'multiply' => fn($a, $b) => $a * $b,
        'upper' => fn($s) => strtoupper($s),
        'strlen' => fn($s) => strlen($s),
        'divide' => fn($a, $b) => $a / $b,
    ];

    while (true) {
        $req = '';
        $type = 0;
        $error = null;
        msg_receive($requestQueue, 1, $type, 1024, $req, true, 0, $error);

        $call = json_decode($req, true);
        if ($call['proc'] === 'shutdown') {
            break;
        }
        $id = $call['id'];
        echo "Server: call #$id -> {$call['proc']}(" . implode(',', $call['args']) . ")\n";

        if (!isset($procedures[$call['proc']])) {
            $resp = ['id' => $id, 'error' => "unknown procedure '{$call['proc']}'"];
        } else {
            try {
                $result = call_user_func_array($procedures[$call['proc']], $call['args']);
                $resp = ['id' => $id, 'result' => $result];
            } catch (Throwable $e) {
                $resp = ['id' => $id, 'error' => $e->getMessage()];
            }
        }
        msg_send($responseQueue, 1, json_encode($resp));
    }
    exit(0);
}

// RPC-клиент: отправляет вызов и ждёт ответ со СВОИМ id
function rpcCall(SysvMessageQueue $reqQueue, SysvMessageQueue $resQueue, string $proc, array $args): array
{
    static $callId = 0;
    $callId++;

    msg_send($reqQueue, 1, json_encode(['id' => $callId, 'proc' => $proc, 'args' => $args]));

    while (true) {
        $reply = '';
        $type = 0;
        $error = null;
        msg_receive($resQueue, 1, $type, 1024, $reply, true, 0, $error);
        $data = json_decode($reply, true);

        // Чужие ответы с другим id игнорируем
        if ($data['id'] === $callId) {
            return $data;
        }
    }
}

// Демо: обычные вызовы, ошибка в процедуре, неизвестная процедура
$calls = [
    ['add', [2, 3]],
    ['multiply', [4, 5]],
    ['upper', ['hello rpc']],
    ['strlen', ['rpc']],
    ['divide', [10, 0]],
    ['no_such_proc', [1]],
];

foreach ($calls as [$proc, $args]) {
    $resp = rpcCall($requestQueue, $responseQueue, $proc, $args);

    if (isset($resp['result'])) {
        echo 'Client: ' . $proc . '(' . implode(',', $args) . ') = ' . var_export($resp['result'], true) . "\n";
    } else {
        echo 'Client: ' . $proc . '(...) ERROR: ' . $resp['error'] . "\n";
    }
}

msg_send($requestQueue, 1, json_encode(['proc' => 'shutdown']));
pcntl_waitpid($serverPid, $status);

msg_remove_queue($requestQueue);
msg_remove_queue($responseQueue);
