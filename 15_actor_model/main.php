<?php

// Actor Model (акторная модель):
//   - Каждый актор — изолированный процесс со своим состоянием и mailbox'ом
//     (отдельная SysV-очередь сообщений).
//   - Общение ТОЛЬКО через сообщения. Общего состояния нет.
//   - Актор может спавнить дочерних акторов (Actor B спавнит Actor C).
//   - Mailbox буферизует сообщения: можно отправить ещё не существующему
//     актору — сообщение дождётся его в очереди.

// Три актора и их "адреса" (ключи очередей):
//   Actor A (counter)   — состояние: счётчик; шлёт tick'и в B, здоровается с C
//   Actor B (collector) — принимает tick'и, спавнит Actor C
//   Actor C (child)     — отвечает "yo" в B на приветствие из A

$queueA = msg_get_queue(ftok(__FILE__, 'a'), 0666);
$queueB = msg_get_queue(ftok(__FILE__, 'b'), 0666);
$queueC = msg_get_queue(ftok(__FILE__, 'c'), 0666);

// Actor C: дочерний актор, которого спавнит B. Отвечает на приветствие.
function actorC(SysvMessageQueue $queueB, SysvMessageQueue $queueC): void
{
    while (true) {
        $msg = '';
        $type = 0;
        $error = null;

        if (msg_receive($queueC, 1, $type, 1024, $msg, true, 0, $error)) {
            if ($msg === 'stop') {
                break;
            }
            if ($msg === 'hello') {
                echo 'Actor C: hello from A, replying to B' . "\n";
                msg_send($queueB, 1, 'yo');
            }
        }
    }
}

// Actor A: состояние = счётчик. На каждый чётный inc шлёт tick актору B.
$pidA = pcntl_fork();
if ($pidA === -1) {
    die('fork failed');
}
if ($pidA === 0) {
    $counter = 0;

    while (true) {
        $msg = '';
        $type = 0;
        $error = null;

        if (msg_receive($queueA, 1, $type, 1024, $msg, true, 0, $error)) {
            if ($msg === 'stop') {
                break;
            }
            if ($msg === 'inc') {
                $counter++;
                echo "Actor A: counter=$counter\n";
                if ($counter % 2 === 0) {
                    msg_send($queueB, 1, "tick:$counter");
                }
            }
            if ($msg === 'hello') {
                msg_send($queueC, 1, 'hello');
            }
        }
    }
    exit(0);
}

// Actor B: копит tick'и, на первый спавнит Actor C.
$pidB = pcntl_fork();
if ($pidB === -1) {
    die('fork failed');
}
if ($pidB === 0) {
    $items = [];
    $childPid = null;

    while (true) {
        $msg = '';
        $type = 0;
        $error = null;

        if (msg_receive($queueB, 1, $type, 1024, $msg, true, 0, $error)) {
            if ($msg === 'stop') {
                break;
            }
            if (str_starts_with($msg, 'tick:')) {
                $items[] = $msg;
                echo 'Actor B: received ' . $msg . ' (items=' . count($items) . ")\n";

                // Спавн дочернего актора при первом tick'е
                if ($childPid === null) {
                    $childPid = pcntl_fork();
                    if ($childPid === -1) {
                        die('fork failed');
                    }
                    if ($childPid === 0) {
                        actorC($queueB, $queueC);
                        exit(0);
                    }
                }
            }
            if ($msg === 'yo') {
                echo "Actor B: yo from C\n";
            }
        }
    }

    // Graceful shutdown дочернего актора
    if ($childPid !== null) {
        msg_send($queueC, 1, 'stop');
        pcntl_waitpid($childPid, $status);
    }
    exit(0);
}

// Родитель: отправляет команды актору A (почтовая очередь буферизует их),
// ждёт завершения A, затем завершает B.
for ($i = 0; $i < 6; $i++) {
    msg_send($queueA, 1, 'inc');
}
msg_send($queueA, 1, 'hello');
msg_send($queueA, 1, 'stop');
pcntl_waitpid($pidA, $status);

// Даём B/C дообработать tick'и и yo
usleep(200000);

msg_send($queueB, 1, 'stop');
pcntl_waitpid($pidB, $status);

msg_remove_queue($queueA);
msg_remove_queue($queueB);
msg_remove_queue($queueC);
