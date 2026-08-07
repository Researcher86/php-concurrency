<?php

// Reliability Fundamentals: четыре базовые темы надёжности в одном уроке.
//   1. Timeout          — ждём результат до дедлайна, поздний ответ игнорируем
//   2. Cancellation     — cooperative (флаг/SIGTERM) против forced (SIGKILL)
//   3. Delivery Semantics — at-most-once (потеря) против at-least-once (дубль)
//   4. Idempotency      — повторная доставка с idempotency_key = один эффект

pcntl_async_signals(true);

// ── 1. TIMEOUT ────────────────────────────────────────────────────────────
echo "== 1. Timeout ==\n";
$q = msg_get_queue(ftok(__FILE__, 'a'), 0666);

$slowPid = pcntl_fork();
if ($slowPid === -1) {
    die('fork failed');
}
if ($slowPid === 0) {
    // Медленный "внешний сервис": отвечает за 500мс
    msg_receive($q, 1, $t, 1024, $task, true, 0);
    usleep(500000);
    msg_send($q, 2, "done:$task");
    exit(0);
}

msg_send($q, 1, 'request-1');
$deadline = hrtime(true) + 200000 * 1000; // ждём максимум 200мс
$reply = null;
while (hrtime(true) < $deadline) {
    if (msg_receive($q, 2, $t, 1024, $reply, true, MSG_IPC_NOWAIT, $e)) {
        break;
    }
    usleep(10000);
}
if ($reply === null) {
    echo "TIMEOUT after 200ms: request-1 considered failed\n";
}
pcntl_waitpid($slowPid, $status);
$late = '';
msg_receive($q, 2, $t, 1024, $late, true, MSG_IPC_NOWAIT, $e);
echo "late reply after timeout: " . var_export($late, true) . " (ignored by caller)\n";
msg_remove_queue($q);

// ── 2. CANCELLATION ──────────────────────────────────────────────────────
echo "\n== 2. Cancellation ==\n";

// Cooperative: воркер проверяет флаг, сигнал лишь ставит его
$flag = false;
pcntl_signal(SIGTERM, function () use (&$flag) {
    $flag = true;
});

$coop = pcntl_fork();
if ($coop === -1) {
    die('fork failed');
}
if ($coop === 0) {
    while (!$flag) {
        usleep(30000);
    }
    echo "  cooperative worker: saw flag, exiting cleanly\n";
    exit(0);
}
usleep(100000);
posix_kill($coop, SIGTERM);
pcntl_waitpid($coop, $status);
echo "  cooperative: SIGTERM -> flag -> clean exit (code " . pcntl_wexitstatus($status) . ")\n";

// Forced: воркер не проверяет ничего, SIGKILL не перехватить
$force = pcntl_fork();
if ($force === -1) {
    die('fork failed');
}
if ($force === 0) {
    while (true) {
        usleep(30000);
    }
}
usleep(100000);
echo "  forced worker " . $force . " not responding, sending SIGKILL\n";
posix_kill($force, SIGKILL);
pcntl_waitpid($force, $status);
echo "  forced: SIGKILL -> immediate death, no cleanup possible\n";

// ── 3. DELIVERY SEMANTICS ────────────────────────────────────────────────
echo "\n== 3. Delivery Semantics ==\n";

// At-most-once: воркер крашится при обработке, задачу никто не переотправит
$q2 = msg_get_queue(ftok(__FILE__, 'b'), 0666);
$fragile = pcntl_fork();
if ($fragile === -1) {
    die('fork failed');
}
if ($fragile === 0) {
    msg_receive($q2, 1, $t, 1024, $task, true, 0);
    echo "  at-most-once worker: got '$task', crashing mid-processing\n";
    exit(1);
}
msg_send($q2, 1, 'task-A');
pcntl_waitpid($fragile, $status);
// мастер ничего не переотправляет
echo "  at-most-once: task-A lost forever (no ACK, no requeue)\n";

// At-least-once: воркер ACK'ает до обработки; если умрёт после ACK —
// мастер переотправит, задача выполнится дважды
$dupe = 0;
$requeuePid = pcntl_fork();
if ($requeuePid === -1) {
    die('fork failed');
}
if ($requeuePid === 0) {
    msg_receive($q2, 1, $t, 1024, $task, true, 0);
    msg_send($q2, 2, "ack:$task");   // ACK ДО обработки
    echo "  at-least-once worker: acked '$task', then crashing after ACK\n";
    exit(1);                          // обработка так и не завершилась
}
msg_send($q2, 1, 'task-B');
// ждём ACK, но не результат
$deadline = hrtime(true) + 300000 * 1000;
while (hrtime(true) < $deadline) {
    if (msg_receive($q2, 2, $t, 1024, $msg, true, MSG_IPC_NOWAIT, $e)) {
        if (str_starts_with($msg, 'ack:')) {
            break;
        }
    }
    usleep(10000);
}
pcntl_waitpid($requeuePid, $status);
echo "  at-least-once: no result after ACK -> requeueing task-B\n";
msg_send($q2, 1, 'task-B'); // повторная доставка -> возможен двойной эффект

// ── 4. IDEMPOTENCY ───────────────────────────────────────────────────────
echo "\n== 4. Idempotency ==\n";

// Обработанные ключи храним в shared memory: повторная доставка не двойной эффект
$shm = shm_attach(ftok(__FILE__, 'h'), 1024, 0666);
$effects = 0;

function charge(string $key, $shm, int &$effects): void
{
    if (shm_has_var($shm, crc32($key) % 1000 + 1)) {
        echo "  idempotency: key '$key' already processed, SKIPPING\n";
        return;
    }
    shm_put_var($shm, crc32($key) % 1000 + 1, true);
    $effects++;
    echo "  idempotency: charging '$key' (effect applied)\n";
}

// Задачу #42 доставили дважды (at-least-once из секции 3)
charge('charge:42', $shm, $effects);
charge('charge:42', $shm, $effects);
charge('charge:43', $shm, $effects);

echo "  idempotency: 3 deliveries, $effects effects (not 3)\n";
shm_remove($shm);
msg_remove_queue($q2);
