<?php

// Mini Messenger (мини-мессенджер):
//   - Клиенты (User A/B/C) с собственными inbox'ами (отдельные очереди).
//   - Message Broker (родитель) маршрутизирует сообщения:
//       * комнаты (general, random) — broadcast каждому подписчику комнаты
//       * direct (dm) — доставка только конкретному клиенту
//   - Клиенты шлют в brokerQueue, брокер рассылает по inbox'ам и закрывает
//     их сообщением END.

const BROKER_MESSAGE_COUNT = 4; // A: room+dm, B: room, C: room

$brokerQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$inboxA = msg_get_queue(ftok(__FILE__, 'a'), 0666);
$inboxB = msg_get_queue(ftok(__FILE__, 'b'), 0666);
$inboxC = msg_get_queue(ftok(__FILE__, 'c'), 0666);

$inboxes = ['A' => $inboxA, 'B' => $inboxB, 'C' => $inboxC];
$rooms = ['general' => ['A', 'B'], 'random' => ['C']];

// Клиент: шлёт сообщения брокеру, затем читает свой inbox до END
function client(SysvMessageQueue $brokerQueue, SysvMessageQueue $inbox, array $messages): int
{
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        foreach ($messages as $message) {
            msg_send($brokerQueue, 1, $message);
        }

        while (true) {
            $msg = '';
            $type = 0;
            $error = null;
            msg_receive($inbox, 1, $type, 1024, $msg, true, 0, $error);

            if ($msg === 'END') {
                break;
            }
            echo "Client: received '$msg'\n";
        }
        exit(0);
    }

    return $pid;
}

// User A: в комнате general + личное сообщение B
$pidA = client($brokerQueue, $inboxA, [
    'general:hello from A',
    'dm:B:psst, secret for B',
]);

// User B: в комнате general
$pidB = client($brokerQueue, $inboxB, [
    'general:hi from B',
]);

// User C: в комнате random
$pidC = client($brokerQueue, $inboxC, [
    'random:hey there',
]);

// Broker: принимает сообщения и маршрутизирует
for ($i = 0; $i < BROKER_MESSAGE_COUNT; $i++) {
    $msg = '';
    $type = 0;
    $error = null;
    msg_receive($brokerQueue, 1, $type, 1024, $msg, true, 0, $error);

    if (str_starts_with($msg, 'dm:')) {
        [, $to, $body] = explode(':', $msg, 3);
        msg_send($inboxes[$to], 1, $body);
        echo "Broker: DM $to <- $body\n";
    } else {
        [$room, $body] = explode(':', $msg, 2);
        foreach ($rooms[$room] as $sub) {
            msg_send($inboxes[$sub], 1, "[$room] $body");
        }
        echo 'Broker: broadcast [' . $room . '] to ' . implode('+', $rooms[$room]) . ": $body\n";
    }
}

// Broker: закрывает всех клиентов
foreach ($inboxes as $inbox) {
    msg_send($inbox, 1, 'END');
}

pcntl_waitpid($pidA, $status);
pcntl_waitpid($pidB, $status);
pcntl_waitpid($pidC, $status);

foreach ($inboxes as $inbox) {
    msg_remove_queue($inbox);
}
msg_remove_queue($brokerQueue);
