<?php

// Backpressure (противодавление): быстрый Producer шлёт задачи быстрее, чем
// медленный Consumer успевает их обрабатывать. Если очередь не ограничена —
// она растёт бесконечно, и память кончается. SysV-очередь ограничена ядром
// (msg_qbytes): когда она полна, msg_send блокируется, и Producer вынужден
// ждать, пока Consumer освободит место. Так producer "притормаживается"
// до скорости самого медленного звена — это стратегия block.

// Backpressure strategies (варианты поведения при полной очереди):
//   1. block    ---> msg_send ждёт место в очереди (реализовано ниже)
//   2. drop     ---> msg_send с MSG_IPC_NOWAIT: полная очередь = потеря сообщения
//   3. latest   ---> держать только последнее сообщение (перезапись старого)
//   4. throttle ---> producer сам проверяет msg_qnum и не шлёт, если очередь полна
//   5. overflow ---> лишние сообщения сбрасываются на диск/в БД

const TASK_COUNT = 60;         // сколько задач шлёт producer
const TARGET_CAPACITY = 10;    // ~ёмкость очереди в сообщениях (cap из диаграммы)
const CONSUMER_DELAY_US = 30000; // consumer обрабатывает задачу 30мс (медленный)
const TERMINATOR = "\0__TERM__\0";

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

// Подбираем размер сообщения так, чтобы в очереди помещалось ~TARGET_CAPACITY штук.
// Так мы явно создаём "bounded queue" (cap = 10), как на диаграмме.
$queueStats = msg_stat_queue($queue);
$messageSize = max(100, (int) floor($queueStats['msg_qbytes'] / TARGET_CAPACITY));

// Быстрый Producer: шлёт задачи подряд, без пауз. Полную очередь он
// не "видит" — msg_send просто блокируется, пока не появится место.
$producerPid = pcntl_fork();
if ($producerPid === 0) {
    echo 'Producer: cap ~' . TARGET_CAPACITY . ' msgs (msg_qbytes=' . $queueStats['msg_qbytes'] . ", msg=$messageSize B)\n";

    $blocked = 0;
    $blockedSamples = [];
    $start = hrtime(true);

    for ($i = 1; $i <= TASK_COUNT; $i++) {
        $payload = "task $i:" . str_repeat('x', $messageSize - 30);

        $t0 = hrtime(true);
        msg_send($queue, 1, $payload); // полная очередь => блокировка (backpressure)
        $waitMs = (hrtime(true) - $t0) / 1e6;

        // Отправка заняла больше 2мс — значит пришлось ждать место в очереди
        if ($waitMs > 2) {
            $blocked++;
            if (count($blockedSamples) < 3) {
                $blockedSamples[] = "#$i +" . round($waitMs) . "ms";
            }
        }
    }

    $total = (hrtime(true) - $start) / 1e6;
    $samples = $blockedSamples
        ? ' (' . implode(', ', $blockedSamples) . ($blocked > 3 ? ', ...' : '') . ')'
        : '';
    echo 'Producer: sent ' . TASK_COUNT . " tasks in " . round($total / 1e3, 2) . "s, blocked $blocked times$samples\n";

    msg_send($queue, 1, TERMINATOR);
    exit(0);
}

// Медленный Consumer: разбирает очередь по одному сообщению (30мс на задачу)
$consumerPid = pcntl_fork();
if ($consumerPid === 0) {
    $received = 0;
    while (true) {
        $msg = '';
        $type = 0;
        $error = null;
        if (msg_receive($queue, 1, $type, $messageSize + 256, $msg, true, 0, $error)) {
            if ($msg === TERMINATOR) {
                break;
            }
            $received++;
            echo 'Consumer: received ' . explode(':', $msg)[0] . "\n";
            usleep(CONSUMER_DELAY_US);
        }
    }
    echo "Consumer: done ($received tasks)\n";
    exit(0);
}

pcntl_waitpid($producerPid, $status);
pcntl_waitpid($consumerPid, $status);

$queueStats = msg_stat_queue($queue);
echo 'Queue left: ' . $queueStats['msg_qnum'] . " msgs\n";

msg_remove_queue($queue);
