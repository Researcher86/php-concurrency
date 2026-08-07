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

// At-least-once: воркер ACK'ает ПОСЛЕ обработки. Если он умирает в середине
// обработки (ДО ACK), мастер не получает подтверждение и после visibility
// timeout переотправляет задачу — итог: задача выполнится дважды.
$firstPid = pcntl_fork();
if ($firstPid === -1) {
    die('fork failed');
}
if ($firstPid === 0) {
    msg_receive($q2, 1, $t, 1024, $task, true, 0);
    usleep(50000); // "начал обрабатывать"
    echo "  at-least-once worker #1: got '$task', crashed mid-processing (no ACK)\n";
    exit(1);       // ACK так и не отправлен
}
msg_send($q2, 1, 'task-B');
pcntl_waitpid($firstPid, $status);

// Мастер ждёт ACK, но его нет — visibility timeout истекает
$deadline = hrtime(true) + 300000 * 1000;
while (hrtime(true) < $deadline) {
    if (msg_receive($q2, 2, $t, 1024, $msg, true, MSG_IPC_NOWAIT, $e)) {
        break; // теоретически ACK прийти не должен — воркер #1 умер до него
    }
    usleep(10000);
}
echo "  at-least-once: no ACK within visibility window -> redelivering task-B\n";
usleep(100000); // имитация истечения visibility timeout
msg_send($q2, 1, 'task-B'); // переотправка той же задачи

// Второй воркер получает переотправленную задачу и обрабатывает УСПЕШНО
$secondPid = pcntl_fork();
if ($secondPid === -1) {
    die('fork failed');
}
if ($secondPid === 0) {
    msg_receive($q2, 1, $t, 1024, $task, true, 0);
    usleep(50000); // обработал
    msg_send($q2, 2, "ack:$task"); // ACK ПОСЛЕ обработки
    echo "  at-least-once worker #2: got '$task' again, processed + acked\n";
    exit(0);
}
pcntl_waitpid($secondPid, $status);
msg_receive($q2, 2, $t, 1024, $msg, true, MSG_IPC_NOWAIT, $e); // забрать ACK
echo "  at-least-once: task-B executed TWICE (duplicate effect — цена at-least-once)\n";

// ── 4. IDEMPOTENCY ───────────────────────────────────────────────────────
echo "\n== 4. Idempotency ==\n";

// Обработанные ключи храним в shared memory. check + mark — под семафором:
// иначе два воркера могут одновременно пройти проверку и применить эффект
// дважды (race check-then-act).
$shm = shm_attach(ftok(__FILE__, 'h'), 1024, 0666);
$sem = sem_get(ftok(__FILE__, 'i'), 1, 0666);
shm_put_var($shm, 1001, 0); // счётчик эффектов (общий между процессами)

function charge(string $key, $shm, $sem): void
{
    sem_acquire($sem);                          // критическая секция
    $slot = crc32($key) % 1000 + 1;
    if (shm_has_var($shm, $slot)) {
        echo "  idempotency: key '$key' already processed, SKIPPING\n";
        sem_release($sem);
        return;
    }
    shm_put_var($shm, $slot, true);
    shm_put_var($shm, 1001, shm_get_var($shm, 1001) + 1);
    sem_release($sem);
    echo "  idempotency: charging '$key' (effect applied)\n";
}

// Задачу #42 доставили дважды (at-least-once из секции 3)
charge('charge:42', $shm, $sem);
charge('charge:42', $shm, $sem);
charge('charge:43', $shm, $sem);
echo "  idempotency: 3 deliveries, " . shm_get_var($shm, 1001) . " effects (not 3)\n";

// Конкурентная доставка: два воркера ОДНОВРЕМЕННО применяют один ключ.
// Без семафора оба прошли бы проверку; с ним — эффект применится один раз.
$before = shm_get_var($shm, 1001);
$pids = [];
for ($i = 0; $i < 2; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        charge('charge:44', $shm, $sem);
        exit(0);
    }
    $pids[] = $pid;
}
foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}
$delta = shm_get_var($shm, 1001) - $before;
echo "  idempotency: 2 concurrent deliveries of #44 -> $delta effect (semaphore guards check+mark)\n";

shm_remove($shm);
sem_remove($sem);
msg_remove_queue($q2);
