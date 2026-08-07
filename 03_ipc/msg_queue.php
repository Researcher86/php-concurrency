<?php

// System V очередь сообщений — очередь в ядре, не привязана к процессу-создателю.
// В PHP это функции msg_get_queue/msg_send/msg_receive (SysV IPC).
// POSIX message queues (mq_open/mq_send) — другой механизм, у PHP нет
// встроенной поддержки (требуется внешнее расширение).
// msg_send кладёт сообщение с типом, msg_receive забирает (тип 0 = любой).
// Producer и Consumer могут жить в разных процессах и даже не знать друг о друге.
//
// Обмен:
//   producer (child)          queue (kernel)            consumer (parent)
//     │ msg_send(q, "task1")  ┌───────┐
//     ├──────────────────────▶│ task1 │                  msg_receive(q)
//     │ msg_send(q, "task2")  │ task2 │◀──────────────────┼
//     ├──────────────────────▶│ task3 │                  msg_receive(q)
//     │ ...                   │ ...   │◀──────────────────┼
//     ▼                       └───────┘                   ▼

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

$pid = pcntl_fork();

if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    // Producer (child): кладёт 5 сообщений
    for ($i = 1; $i <= 5; $i++) {
        $msg = "task $i";
        msg_send($queue, 1, $msg);
        echo 'producer ' . getmypid() . ": sent $msg\n";
        usleep(200000);
    }
    exit(0);
}

// Consumer (parent): читает 5 сообщений
for ($i = 1; $i <= 5; $i++) {
    $msgType = 0;
    $msg = '';
    msg_receive($queue, 0, $msgType, 1024, $msg);
    echo 'consumer ' . getmypid() . ": received [$msgType] $msg\n";
}

pcntl_wait($status);
msg_remove_queue($queue);

// Ожидаемый вывод:
// producer <pid>: sent task 1
// consumer <pid>: received [1] task 1
// producer <pid>: sent task 2
// consumer <pid>: received [1] task 2
// ...
