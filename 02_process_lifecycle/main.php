<?php

// Process Lifecycle: fork → run → exit → SIGCHLD → waitpid → reap.
// Три мини-демо: нормальный жизненный цикл со сбором статуса, зомби
// (ребёнок умер, родитель ещё не отрепил), reap-all через waitpid(-1)
// (как это делают supervisor'ы). Понятие "сирота" описано в README.

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

// ── Демо 3: reap-all — собираем НЕСКОЛЬКИХ детей через waitpid(-1) ───────
echo "\n== Demo 3: reap multiple children (waitpid(-1) loop) ==\n";

$kids = [];
for ($i = 1; $i <= 3; $i++) {
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        usleep(($i % 3) * 100000); // дети умирают с разными задержками
        exit($i);
    }
    $kids[] = $pid;
}

// Собираем всех детей, как это делают supervisor'ы: waitpid(-1) + WNOHANG,
// пока остались неотрепленные дети.
while (count($kids) > 0) {
    $reaped = pcntl_waitpid(-1, $status, WNOHANG);
    if ($reaped > 0) {
        echo "parent: reaped $reaped, exit code = " . pcntl_wexitstatus($status) . "\n";
        $kids = array_values(array_filter($kids, fn($k) => $k !== $reaped));
    } else {
        usleep(50000); // никто ещё не умер — опрашиваем снова
    }
}
echo "parent: all children reaped, no zombies left\n";
