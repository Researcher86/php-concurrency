<?php

// Cooperative Concurrency: несколько фибер переключаются КООПЕРАТИВНО —
// каждая добровольно отдаёт управление в точке suspend(), а не вытесняется
// планировщиком ОС. Контраст с pcntl_fork(): процессы планирует ядро.

echo "=== 1. Переключение между фибрами (по их желанию) ===\n";
// Три фибры-задачи; каждая делает N "шагов", отдавая управление между шагами.
$fibers = [];
foreach (['A', 'B', 'C'] as $name) {
    $fibers[$name] = new Fiber(function () use ($name): void {
        for ($step = 1; $step <= 3; $step++) {
            echo "  fiber $name: step $step\n";
            Fiber::suspend(); // добровольно отдаём управление
        }
    });
    $fibers[$name]->start();
}
// Пока все не завершились — будим по кругу
$alive = true;
while ($alive) {
    $alive = false;
    foreach ($fibers as $fiber) {
        if (!$fiber->isTerminated()) {
            $fiber->resume();
            $alive = true;
        }
    }
}

echo "\n=== 2. Блокирующий вызов ВНУТРИ фибры блокирует ВСЁ ===\n";
echo "  (анти-паттерн: sleep() приостанавливает весь процесс, а не фибру)\n";
$blocker = new Fiber(function (): void {
    echo "  fiber 'blocker': усну на 1s\n";
    sleep(1);   // НЕ Fiber::suspend()! Это блокирует процесс целиком
    echo "  fiber 'blocker': проснулся\n";
});
$t0 = microtime(true);
$blocker->start(); // start() дойдёт до конца, т.к. sleep() не кооперируется
printf("  main: start() вернулся только через %.2fs — процесс был заблокирован\n",
    microtime(true) - $t0);

echo "\n=== 3. Контраст с pcntl_fork(): процессы вытесняет ОС ===\n";
$pid = pcntl_fork();
if ($pid === 0) {
    for ($i = 1; $i <= 3; $i++) {
        echo "  child " . getmypid() . ": tick $i\n";
        usleep(rand(20000, 60000)); // ОС переключит на родителя САМА
    }
    exit(0);
}
for ($i = 1; $i <= 3; $i++) {
    echo "  parent " . getmypid() . ": tick $i\n";
    usleep(rand(20000, 60000));
}
pcntl_waitpid($pid, $status);
echo "\nПорядок parent/child произволен — ядро планирует процессы,
а фибры переключаются только в точках suspend().\n";
