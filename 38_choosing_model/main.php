<?php

// Choosing the Model: сравниваем два подхода к конкурентности в PHP.
// Процессы (pcntl) дают parallelism (CPU, изоляция), фибры — cooperative
// concurrency на I/O-ожиданиях внутри одного процесса (мало памяти).
// Замеряем: стоимость fork, I/O-bound задачи и CPU-bound задачи.

const TASKS = 100;
const IO_MS = 20;   // симулируемая "латентность" на задачу
const CHUNK = 10;   // процессный I/O-замер: форкаем батчами

// ---------- 1. Сравнительная таблица ----------
echo "=== Модели конкурентности в PHP ===\n";
echo str_pad('', 76, '-') . "\n";
printf("%-24s | %-24s | %-24s\n", 'Аспект', 'pcntl_fork (процесс)', 'Fiber (фибра)');
echo str_pad('', 76, '-') . "\n";
$rows = [
    ['Единица выполнения', 'Process', 'Fiber'],
    ['Адресное пространство', 'отдельное', 'общее (тот же процесс)'],
    ['Планирование', 'ОС (вытесняющее)', 'userland (кооперативное)'],
    ['Parallelism (CPU)', 'да', 'нет'],
    ['I/O concurrency', 'да (блокирующий I/O ок)', 'да, но только через non-blocking + event loop'],
    ['Memory overhead', 'высокий (свой стек+таблицы)', 'низкий (стек фибры)'],
    ['IPC', 'нужен (очереди/сокеты/память)', 'не нужен (общие переменные)'],
    ['Shared state', 'сложно (гонки, синхронизация)', 'естественно (один поток)'],
    ['Failure isolation', 'да (упал ребёнок — жив родитель)', 'нет (упала фибра — упал процесс)'],
    ['CPU-bound', 'подходит', 'не ускоряет (нет параллелизма)'],
    ['I/O-bound (HTTP/DB/socket)', 'можно, но дорого по памяти', 'эффективный вариант (при non-blocking I/O + event loop)'],
];
foreach ($rows as [$a, $b, $c]) {
    printf("%-24s | %-24s | %-24s\n", $a, $b, $c);
}
echo str_pad('', 76, '-') . "\n";

// ---------- 2. Стоимость fork (контекст для замеров) ----------
$t0 = microtime(true);
for ($i = 0; $i < CHUNK; $i++) {
    $p = pcntl_fork();
    if ($p === 0) {
        exit(0);
    }
    pcntl_waitpid($p, $status);
}
$forkCost = (microtime(true) - $t0) / CHUNK;
printf("\nСтоимость 1 fork+wait: ~%.1f ms — процессы на КОРОТКИХ задачах дороги\n", $forkCost * 1000);

// ---------- 3. Simulated I/O wait: 100 задач с ожиданием 20ms ----------
// Это НЕ реальный HTTP/DB/socket I/O — только usleep()/таймеры. Цель:
// показать эффект ПЕРЕКРЫТИЯ ожиданий, а не измерить реальный I/O.
echo "\n=== I/O-wait simulation: " . TASKS . " задач × ожидание " . IO_MS . "ms ===\n";

// 3.1. Последовательно (baseline)
$t0 = microtime(true);
for ($i = 0; $i < TASKS; $i++) {
    usleep(IO_MS * 1000);
}
$seq = microtime(true) - $t0;
printf("Последовательно : %6.2fs (≈ TASKS×IO_MS = %.2fs)\n", $seq, TASKS * IO_MS / 1000);

// 3.2. Процессы: батчами по CHUNK (реальное перекрытие внутри батча)
$t0 = microtime(true);
for ($start = 0; $start < TASKS; $start += CHUNK) {
    $children = [];
    $count = min(CHUNK, TASKS - $start);
    for ($j = 0; $j < $count; $j++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            usleep(IO_MS * 1000);
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
}
$proc = microtime(true) - $t0;
printf("Процессы (батч %d): %6.2fs (перекрытие внутри батча, но fork ×%d = дорого)\n",
    CHUNK, $proc, TASKS);

// 3.3. Фибры: 100 фибер + event loop на таймерах
$waiting = [];
$t0 = microtime(true);
for ($i = 0; $i < TASKS; $i++) {
    $f = new Fiber(function (): void {
        Fiber::suspend();
    });
    $f->start();
    $waiting[] = ['at' => microtime(true) + IO_MS / 1000, 'fiber' => $f];
}
while ($waiting) {
    $now = microtime(true);
    foreach ($waiting as $i => $w) {
        if ($now >= $w['at']) {
            $w['fiber']->resume();
            unset($waiting[$i]);
        }
    }
    $waiting = array_values($waiting);
    if ($waiting) {
        usleep(1000); // 1ms — тик таймеров
    }
}
$fiberTime = microtime(true) - $t0;
printf("Фибры  (event loop): %6.2fs (пик памяти ЭТОГО процесса ~%d KB)\n",
    $fiberTime, memory_get_peak_usage(true) / 1024);
// Примечание: memory_get_peak_usage() меряет только один PHP-процесс. В
// процессном замере это НЕ агрегат RSS всех детей — его надо суммировать
// из /proc, поэтому память процессов и фибер тут нельзя сравнивать напрямую.

// ---------- 4. CPU-bound: процессы (N ядер) vs фибры (1 ядро) ----------
// Задачи ДЛИННЫЕ (800ms): fork (~100ms) не маскирует параллелизм.
// Число процессов = числу ядер (nproc): ускорение зависит от железа. Если
// задач больше, чем ядер — oversubscription: процессы делят ядра, и замер
// приближается к последовательному.
$nproc = max(2, (int) (shell_exec('nproc') ?: 4));
$CPU_TASKS = $nproc;
$CPU_MS = 800;
echo "\n=== CPU-bound: " . $CPU_TASKS . " задач × " . $CPU_MS . "ms вычислений (nproc=" . $nproc . ") ===\n";

$burn = function (int $ms): void {
    $end = hrtime(true) + $ms * 1000000;
    while (hrtime(true) < $end) {
        // "тяжёлые" вычисления — просто крутим CPU
    }
};

// 4.1. Последовательно
$t0 = microtime(true);
for ($i = 0; $i < $CPU_TASKS; $i++) {
    $burn($CPU_MS);
}
$cpuSeq = microtime(true) - $t0;

// 4.2. Процессы (все $CPU_TASKS сразу — параллельно на ядрах)
$t0 = microtime(true);
$children = [];
for ($j = 0; $j < $CPU_TASKS; $j++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        $burn($CPU_MS);
        exit(0);
    }
    $children[] = $pid;
}
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
}
$cpuProc = microtime(true) - $t0;

// 4.3. Фибры (НЕ дают parallelism: последовательное исполнение, кооперация не помогает CPU)
$fibers = [];
for ($i = 0; $i < $CPU_TASKS; $i++) {
    $fibers[] = new Fiber(function () use ($burn, $CPU_MS): void {
        $burn($CPU_MS);
    });
}
$t0 = microtime(true);
foreach ($fibers as $f) {
    $f->start(); // каждая выполняется ДО КОНЦА (нет suspend) — последовательно
}
$cpuFiber = microtime(true) - $t0;

printf("Последовательно : %6.2fs\n", $cpuSeq);
printf("Процессы (пар-но): %6.2fs (≈ %dms + fork; ускорение ≈ число ядер %d)\n", $cpuProc, $CPU_MS, $nproc);
printf("Фибры           : %6.2fs (≈ %dms×%d — НОЛЬ parallelism)\n", $cpuFiber, $CPU_MS, $CPU_TASKS);

// ---------- 5. Вердикт ----------
echo "\n=== Вывод ===\n";
printf("I/O-wait : последовательно %.2fs | процессы %.2fs | фибры %.2fs\n", $seq, $proc, $fiberTime);
printf("CPU-bound : последовательно %.2fs | процессы %.2fs | фибры %.2fs\n", $cpuSeq, $cpuProc, $cpuFiber);
echo "
- I/O-bound + много задач (HTTP, DB, сокеты, таймеры) → ФИБРЫ:
  100 фибер в 1 процессе почти мгновенно и почти без памяти.
- CPU-bound (изображения, компрессия, расчёты) → ПРОЦЕССЫ:
  только они дают параллелизм на нескольких ядрах.
- Изоляция падений / безопасность → ПРОЦЕССЫ:
  упавшая фибра кладёт весь процесс, упавший ребёнок — нет.
- Фибры НЕ заменяют процессы: они решают другую задачу.\n";
