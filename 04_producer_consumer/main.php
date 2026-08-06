<?php

pcntl_async_signals(true);

function producer(SysvMessageQueue $queue, int $id, int $msgCount): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        for ($j = 0; $j < $msgCount; $j++) {
            $msg = "Producer $id: Task $j";
            msg_send($queue, 1, $msg);
            echo 'Producer ' . getmypid() . ": sent \"$msg\"\n";
            usleep(rand(10000, 50000));
        }
        exit(0);
    }

    return $pid;
}

function consumer(SysvMessageQueue $queue): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        while (true) {
            $msgType = 0;
            $msg = '';
            $error = null;

            $received = msg_receive($queue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            if ($received) {
                if ($msg === TERMINATOR) {
                    break;
                }
                echo 'Consumer ' . getmypid() . ": received $msg\n";
                usleep(rand(50000, 200000));
                continue;
            }

            usleep(10000);
        }
        exit(0);
    }

    return $pid;
}

const PRODUCER_COUNT = 2;
const CONSUMER_COUNT = 3;
const MSG_PER_PRODUCER = 10;
const TERMINATOR = "\0__TERM__\0";

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

$producerPids = [];
for ($i = 0; $i < PRODUCER_COUNT; $i++) {
    $producerPids[] = producer($queue, $i, MSG_PER_PRODUCER);
}

$consumerPids = [];
for ($i = 0; $i < CONSUMER_COUNT; $i++) {
    $consumerPids[] = consumer($queue);
}

// Хендлер для Ctrl+C / SIGTERM: убиваем всех детей и чистим очередь,
// чтобы при следующем запуске не было мусора (старые terminator'ы).
$cleanup = function () use ($producerPids, $consumerPids, $queue) {
    foreach (array_merge($producerPids, $consumerPids) as $pid) {
        posix_kill($pid, SIGTERM);
    }
    while (pcntl_wait($status) !== -1);
    msg_remove_queue($queue);
    exit(0);
};
pcntl_signal(SIGINT, $cleanup);
pcntl_signal(SIGTERM, $cleanup);

// Ждём, пока все продюсеры закончат
foreach ($producerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

// Все продюсеры завершены — отправляем консюмерам по terminator'у
for ($i = 0; $i < CONSUMER_COUNT; $i++) {
    msg_send($queue, 1, TERMINATOR);
}

// Ждём консюмеров
foreach ($consumerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

msg_remove_queue($queue);
