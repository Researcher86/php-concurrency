<?php

// Dead Letter Queue: worker обрабатывает задачу с несколькими попытками.
// Задачи, исчерпавшие все ретраи, не выбрасываются — кладутся в отдельную
// очередь (DLQ) с причиной отказа для ручного разбора.

const MAX_RETRIES = 3;
const RETRY_DELAY_US = 50000;
const STOP_MSG = "\0STOP\0";

$taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$dlqQueue = msg_get_queue(ftok(__FILE__, 'd'), 0666);

// Worker: ретраит каждую задачу; после MAX_RETRIES неудач — в DLQ
$workerPid = pcntl_fork();
if ($workerPid === -1) {
    die('fork failed');
}
if ($workerPid === 0) {
    while (true) {
        $task = '';
        $type = 0;
        $error = null;
        msg_receive($taskQueue, 1, $type, 1024, $task, true, 0, $error);

        if ($task === STOP_MSG) {
            break;
        }

        // poison-task всегда падает — ей суждено попасть в DLQ
        $ok = false;
        $attempt = 0;
        while (!$ok && $attempt < MAX_RETRIES) {
            $attempt++;
            echo "Worker: '$task' attempt $attempt\n";
            usleep(20000);

            if ($task !== 'poison-task') {
                $ok = true;
            }
            if (!$ok) {
                usleep(RETRY_DELAY_US);
            }
        }

        if ($ok) {
            echo "Worker: '$task' DONE\n";
        } else {
            $reason = 'gave up after ' . MAX_RETRIES . ' attempts';
            echo "Worker: '$task' -> DEAD LETTER ($reason)\n";
            msg_send($dlqQueue, 1, "task='$task' reason='$reason'");
        }
    }
    exit(0);
}

// Производитель задач; одна — "ядовитая"
foreach (['order-1', 'poison-task', 'order-2'] as $task) {
    msg_send($taskQueue, 1, $task);
}

// Останавливаем воркера после того, как он доест очередь
$deadline = hrtime(true) + 2000000 * 1000;
while (hrtime(true) < $deadline) {
    if (msg_stat_queue($taskQueue)['msg_qnum'] === 0) {
        break;
    }
    usleep(10000);
}
msg_send($taskQueue, 1, STOP_MSG);
pcntl_waitpid($workerPid, $status);

// Разбираем DLQ
echo 'DLQ contents:' . "\n";
while (true) {
    $msg = '';
    $type = 0;
    $error = null;
    if (!msg_receive($dlqQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
        break;
    }
    echo '  ' . $msg . "\n";
}

msg_remove_queue($taskQueue);
msg_remove_queue($dlqQueue);
