<?php

// PHP Fibers: механизм приостановки и возобновления выполнения PHP-кода
// ВНУТРИ одного процесса и одного потока. Fiber НЕ создаёт параллельное
// выполнение — он даёт *кооперативное* переключение: код сам решает, когда
// отдать управление (suspend), и его будит внешний код (resume).

echo "=== 1. start / suspend / resume ===\n";
$fiber = new Fiber(function (): string {
    echo "fiber: начало\n";
    // suspend передаёт значение наружу и останавливается.
    // Значение, переданное в resume(), вернётся как результат suspend.
    $arg = Fiber::suspend('paused-at-1');
    echo "fiber: возобновлён с аргументом '$arg'\n";
    return 'fiber-done';
});

$got = $fiber->start();          // запуск, дойдёт до первого suspend
echo "main: start() вернул '$got'\n";

$got = $fiber->resume('hello!'); // будим; fiber продолжит и закончит
echo "main: resume() вернул '" . var_export($got, true) . "' (завершён)\n";
echo "main: getReturn() = '{$fiber->getReturn()}'\n";

echo "\n=== 2. Состояния фибры ===\n";
$f = new Fiber(function (): void {
    echo "  fiber: isRunning=" . var_export(Fiber::getCurrent()?->isRunning(), true) . "\n";
    Fiber::suspend();
});
echo '  до start():  started=' . var_export($f->isStarted(), true)
    . ' suspended=' . var_export($f->isSuspended(), true)
    . ' terminated=' . var_export($f->isTerminated(), true) . "\n";
$f->start();
echo '  после start(): suspended=' . var_export($f->isSuspended(), true)
    . ' running=' . var_export($f->isRunning(), true) . "\n";
$f->resume();
echo '  после resume(): terminated=' . var_export($f->isTerminated(), true) . "\n";

echo "\n=== 3. Fiber != Parallelism ===\n";
// Пока фибра не вызывает suspend, она выполняется НЕПРЕРЫВНО в одном потоке.
$t0 = microtime(true);
$cpu = new Fiber(function (): void {
    $s = 0;
    for ($i = 0; $i < 2000000; $i++) {
        $s += $i;
    }
    echo "  fiber: CPU-цикл завершён (sum=$s)\n";
});
$cpu->start();
printf("  main: после start() — все 2e6 итераций уже выполнены (%.2f ms)\n", (microtime(true) - $t0) * 1000);

echo "\n=== 4. Кооперативное переключение (по нашему сигналу) ===\n";
$a = new Fiber(function (): void {
    echo "  A1\n";
    Fiber::suspend();
    echo "  A2\n";
});
$b = new Fiber(function (): void {
    echo "  B1\n";
    Fiber::suspend();
    echo "  B2\n";
});
$a->start();
$b->start();
$a->resume();
$b->resume();
echo "  main: никакого планировщика — мы сами решали, кого и когда будить\n";

echo "\n=== 5. Ошибка: suspend вне фибры ===\n";
try {
    Fiber::suspend('no-context');
} catch (\Error $e) {
    echo "  Error: {$e->getMessage()}\n";
}

echo "\nФибры живут в одном процессе (" . getmypid() . "), одна стек-рамка,
разделяют все переменные без IPC — но настоящего параллелизма не дают.\n";
