<?php

// Process Lifecycle: fork → run → exit → SIGCHLD → waitpid → reap.
// Три мини-демо: нормальный жизненный цикл со сбором статуса, зомби
// (ребёнок умер, родитель ещё не отрепил), сирота (родитель умер раньше).

pcntl_async_signals(true);

// ── Демо 1: нормальный lifecycle + SIGCHLD + статус выхода ──────────────
echo "== Demo 1: normal lifecycle, SIGCHLD, exit status ==\n";

$gotSigchld = false;
pcntl_signal(SIGCHLD, function () use (&$gotSigchld) {
    $gotSigchld = true;
});

$pid = pcntl_fork();
if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    echo "child " . getmypid() . ": running, then exit(3)\n";
    exit(3);
}

$status = 0;
pcntl_waitpid($pid, $status); // блокирующе ждём именно этого ребёнка
echo "parent: SIGCHLD was delivered=" . ($gotSigchld ? "yes" : "no") . "\n";
echo "parent: reaped, exit code = " . pcntl_wexitstatus($status) . "\n";

// ── Демо 2: zombie — ребёнок умер, родитель не репит ────────────────────
echo "\n== Demo 2: zombie (unreaped child) ==\n";

$zombie = pcntl_fork();
if ($zombie === -1) {
    die('fork failed');
}

if ($zombie === 0) {
    exit(0); // мгновенная смерть
}

// Пока не репим: ребёнок сейчас зомби в таблице процессов.
// В этот момент `ps -o stat` покажет у него статус Z.
usleep(500000);
echo "parent: child $zombie is dead but NOT reaped yet — it's a zombie\n";

pcntl_waitpid($zombie, $status); // reap
echo "parent: zombie reaped, exit code = " . pcntl_wexitstatus($status) . "\n";

// ── Демо 3: orphan — родитель умирает раньше ребёнка ────────────────────
echo "\n== Demo 3: orphan (parent dies first) ==\n";

$orphan = pcntl_fork();
if ($orphan === -1) {
    die('fork failed');
}

if ($orphan === 0) {
    echo "child " . getmypid() . ": PPID before parent death = " . posix_getppid() . "\n";
    usleep(400000); // даём родителю умереть
    echo "child " . getmypid() . ": PPID after  = " . posix_getppid()
        . " (init/PID1 adopted me, will reap me)\n";
    exit(0);
}

// Родитель умирает первым. После этого ребёнка "усыновит" init (в Docker — tini)
// и отрепит его. Здесь программа завершается, waitpid больше не делаем —
// это и есть сиротство.
echo "parent: dying before child $orphan\n";
exit(0);
