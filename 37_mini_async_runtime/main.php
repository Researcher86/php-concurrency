<?php

// Mini Async Runtime: capstone второй половины курса. Один процесс, один
// поток, но много задач. Компоненты:
//   - event loop (stream_select + таймеры),
//   - фибры (кооперативное переключение),
//   - таймеры (timer queue),
//   - async I/O (awaitRead/awaitWrite через suspend/resume),
//   - scheduler (делегирование работы в фоне).
// В отличие от урока 32 (процессный рантайм) здесь НЕТ fork: всё в одном
// процессе, а "параллельность" — только кооперативная на I/O-ожиданиях.

const WORKERS = 3;
const TASKS = 6;
const TASK_IO_MS = 300;

// ---------- 1. Реестр ожиданий и event loop ----------
$waiting = [];
$timers = []; // [due_ms, callback]

function scheduleEvent(array $ev, ?callable $onResume = null): void
{
    global $waiting;
    $waiting[] = [
        'kind' => $ev[0],
        'stream' => $ev[1] ?? null,
        'at' => $ev[2] ?? null,
        'fiber' => Fiber::getCurrent(),
        'onResume' => $onResume,
    ];
}

// ---------- 2. Фибры: awaitRead / awaitWrite / awaitMs ----------
function awaitRead($stream, float $timeoutSec = 2.0): string
{
    scheduleEvent(['read', $stream, microtime(true) + $timeoutSec]);
    return Fiber::suspend(); // 'ready' | 'timeout'
}

function awaitWrite($stream, float $timeoutSec = 2.0): string
{
    scheduleEvent(['write', $stream, microtime(true) + $timeoutSec]);
    return Fiber::suspend(); // 'ready' | 'timeout'
}

function awaitMs(int $ms): void
{
    scheduleEvent(['timer', null, microtime(true) + $ms / 1000]);
    Fiber::suspend(); // 'tick'
}

// Таймер без фибры (callback) — для планировщика
function addTimer(int $ms, callable $cb): void
{
    global $timers;
    $timers[] = ['due' => microtime(true) + $ms / 1000, 'cb' => $cb];
}

// ---------- 3. Scheduler: "запустить задачу в фоне" ----------
// Упрощение: в PHP нет встроенных фоновых потоков. Scheduler исполняет
// callback кооперативно внутри фибры — это честная модель "userland task".
function runInBackground(callable $cb, string $name): void
{
    $fiber = new Fiber(function () use ($cb, $name): void {
        echo "  [$name] старт\n";
        $cb();
        echo "  [$name] завершён\n";
    });
    $fiber->start(); // выполнит код до первого suspend (awaitRead/awaitMs)
}

// ---------- 4. Event loop ----------
function runLoop(int $maxMs = 10000): void
{
    global $waiting, $timers;
    $deadline = microtime(true) + $maxMs / 1000;

    while ($waiting || $timers) {
        $read = [];
        $write = [];
        $nextAt = null;

        foreach ($waiting as $w) {
            if ($w['kind'] === 'read') {
                $read[] = $w['stream'];
            } elseif ($w['kind'] === 'write') {
                $write[] = $w['stream'];
            }
            $nextAt = $nextAt === null ? $w['at'] : min($nextAt, $w['at']);
        }
        foreach ($timers as $t) {
            $nextAt = $nextAt === null ? $t['due'] : min($nextAt, $t['due']);
        }

        $now = microtime(true);
        $timeout = $nextAt !== null ? max(0, $nextAt - $now) : 1.0;
        $sec = (int) $timeout;
        $usec = (int) (($timeout - $sec) * 1000000);

        if ($read || $write) {
            $except = null;
            stream_select($read, $write, $except, $sec, $usec);
        } elseif ($timeout > 0) {
            usleep($sec * 1000000 + $usec);
        }
        $now = microtime(true);

        // Callback-таймеры
        foreach ($timers as $i => $t) {
            if ($now >= $t['due']) {
                ($t['cb'])();
                unset($timers[$i]);
            }
        }
        $timers = array_values($timers);

        // Фибры
        foreach ($waiting as $i => $w) {
            $event = null;
            if ($w['kind'] === 'read') {
                if (in_array($w['stream'], $read, true)) {
                    $event = 'ready';
                } elseif ($now >= $w['at']) {
                    $event = 'timeout';
                }
            } elseif ($w['kind'] === 'write') {
                if (in_array($w['stream'], $write, true)) {
                    $event = 'ready';
                } elseif ($now >= $w['at']) {
                    $event = 'timeout';
                }
            } elseif ($w['kind'] === 'timer') {
                if ($now >= $w['at']) {
                    $event = 'tick';
                }
            }
            if ($event !== null) {
                $w['fiber']->resume($event);
                unset($waiting[$i]);
            }
        }
        $waiting = array_values($waiting);

        if ($now > $deadline) {
            echo "Runtime: TIMEOUT\n";
            break;
        }
    }
}

// ---------- 5. Демо: "веб-сервер" + фоновые задачи ----------
$t0 = microtime(true);

// 5.1. Пул воркеров-фибер: каждый "обрабатывает" несколько задач с I/O
echo "=== Async Worker Pool (" . WORKERS . " фибры, " . TASKS . " задач) ===\n";
$workerStreams = [];
$workerPids = [];
for ($w = 1; $w <= WORKERS; $w++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($pair[0]);
        usleep(rand(100000, 400000)); // воркер "загружается"
        for ($j = 0; $j < 2; $j++) {
            fwrite($pair[1], "task-{$w}-{$j}\n");
            usleep(rand(100000, 300000));
        }
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]);
    $workerStreams[] = $pair[0];
    $workerPids[] = $pid;
}

foreach ($workerStreams as $i => $stream) {
    runInBackground(function () use ($i, $stream): void {
        global $waiting;
        $done = 0;
        while (true) {
            $ev = awaitRead($stream, 1.0);
            if ($ev === 'timeout' || $ev === 'eof') {
                break;
            }
            $line = fgets($stream);
            if ($line === false) {
                break;
            }
            echo "  [worker{$i}] получил '" . rtrim($line, "\n") . "', обрабатываю\n";
            awaitMs(rand((int)(TASK_IO_MS * 0.5), TASK_IO_MS)); // эмулируем CPU+I/O
            echo "  [worker{$i}] готово\n";
            $done++;
        }
        echo "  [worker{$i}] закрылся\n";
    }, "worker{$i}");
}

// 5.2. Таймер (callback) — heartbeat планировщика
addTimer(500, function (): void {
    printf("  [heartbeat] тик на %.2fs\n", microtime(true) - $GLOBALS['t0']);
});

// 5.3. Фоновая "задача-демон": считает что-то периодически
runInBackground(function (): void {
    for ($i = 1; $i <= 3; $i++) {
        awaitMs(400);
        printf("  [daemon] проход %d на %.2fs\n", $i, microtime(true) - $GLOBALS['t0']);
    }
}, "daemon");

echo "Runtime: запускаю event loop\n";
runLoop();

foreach ($workerPids as $pid) {
    pcntl_waitpid($pid, $status);
}

printf("\n=== Итог: весь рантайм отработал за %.2fs ===\n", microtime(true) - $t0);
echo "Компоненты: event loop + фибры + таймеры + async I/O + scheduler
в ОДНОМ процессе — без fork, без IPC, без блокировок (кооперативно).\n";
