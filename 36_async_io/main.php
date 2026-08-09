<?php

// Async I/O: свой userland-рантайм. await() прячет event loop за фиброй:
// awaitRead($socket) = Fiber::suspend() -> stream_select() -> resume().
// Клиенты (дочерние процессы) шлют запросы по unix socket pair,
// один процесс-сервер с N фибрами-обработчиками отвечает им, не блокируясь.

const CLIENTS = 3;
const REQS_PER_CLIENT = 3;
const SERVER_FIBERS = 2;

// ---- Мини-рантайм: awaitRead + awaitMs + event loop ----
// Каждая фибра сама регистрирует своё ожидание в $waiting перед suspend()
$waiting = [];

function awaitRead($stream): string
{
    global $waiting;
    $waiting[] = [
        'kind' => 'read',
        'stream' => $stream,
        'fiber' => Fiber::getCurrent(),
        'at' => microtime(true) + 1.0,
    ];
    return Fiber::suspend(['read', $stream]); // 'ready' | 'eof' | 'timeout'
}

function awaitMs(int $ms): void
{
    global $waiting;
    $waiting[] = [
        'kind' => 'timer',
        'stream' => null,
        'fiber' => Fiber::getCurrent(),
        'at' => microtime(true) + $ms / 1000,
    ];
    Fiber::suspend(['timer', microtime(true) + $ms / 1000]); // 'tick'
}

function runEventLoop(): void
{
    global $waiting;
    while ($waiting) {
        $read = [];
        $nextAt = null;
        foreach ($waiting as $w) {
            if ($w['kind'] === 'read') {
                $read[] = $w['stream'];
            }
            $nextAt = $nextAt === null ? $w['at'] : min($nextAt, $w['at']);
        }

        $now = microtime(true);
        $sec = 1;
        $usec = 0;
        if ($nextAt !== null) {
            $diff = max(0, $nextAt - $now);
            $sec = (int) $diff;
            $usec = (int) (($diff - $sec) * 1000000);
        }

        if ($read) {
            $write = null;
            $except = null;
            stream_select($read, $write, $except, $sec, $usec);
        } else {
            usleep($sec * 1000000 + $usec);
        }
        $now = microtime(true);

        foreach ($waiting as $i => $w) {
            $event = null;
            if ($w['kind'] === 'read') {
                $meta = stream_get_meta_data($w['stream']);
                if (in_array($w['stream'], $read, true)) {
                    $event = $meta['eof'] ? 'eof' : 'ready';
                } elseif ($now >= $w['at']) {
                    $event = 'timeout';
                }
            } elseif ($now >= $w['at']) {
                $event = 'tick';
            }
            if ($event !== null) {
                $w['fiber']->resume($event);
                unset($waiting[$i]);
            }
        }
        $waiting = array_values($waiting);
    }
}

// ---- Клиенты: дети, каждый шлёт несколько запросов с задержками ----
$serverStreams = [];
$clientPids = [];
for ($c = 1; $c <= CLIENTS; $c++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]); // клиенту не нужна серверная сторона
        for ($r = 1; $r <= REQS_PER_CLIENT; $r++) {
            fwrite($pair[1], "client{$c}-req{$r}\n");
            usleep(rand(30000, 100000));
        }
        fclose($pair[1]); // EOF = клиент закончил
        exit(0);
    }
    fclose($pair[1]);
    $serverStreams[] = $pair[0];
    $clientPids[] = $pid;
}

// Очередь клиентских сокетов, из которой фибры берут следующий "поток"
$queue = $serverStreams;

// ---- Фибра-обработчик: работает с одним потоком до EOF, потом берёт новый ----
function makeHandler(string $name): Fiber
{
    return new Fiber(function () use ($name): void {
        global $queue;
        $stream = array_shift($queue);
        while ($stream !== null) {
            while (true) {
                $event = awaitRead($stream);
                if ($event === 'eof' || $event === 'timeout') {
                    echo "$name: " . ($event === 'eof' ? 'EOF' : 'таймаут')
                        . ", беру следующий поток\n";
                    fclose($stream);
                    break;
                }
                $line = fgets($stream);
                if ($line === false) {
                    echo "$name: EOF, беру следующий поток\n";
                    fclose($stream);
                    break;
                }
                echo "$name: обрабатываю '" . rtrim($line, "\n") . "'...\n";
                awaitMs(rand(50, 150)); // симуляция задержки (таймер, НЕ реальный
                // I/O) — loop продолжает обслуживать другие фибры
                echo "$name: ...готово\n";
            }
            $stream = array_shift($queue);
        }
        echo "$name: очередь пуста, завершаюсь\n";
    });
}

// ---- Сервер: SERVER_FIBERS фибр-обработчиков + event loop ----
echo "Server: старт с " . SERVER_FIBERS . " фибрами-обработчиками, "
    . CLIENTS * REQS_PER_CLIENT . " запросов\n";
$t0 = microtime(true);

for ($f = 0; $f < SERVER_FIBERS; $f++) {
    $fiber = makeHandler("fiber#$f");
    $fiber->start(); // дойдёт до awaitRead и зарегистрирует ожидание
}

runEventLoop(); // крутится, пока есть зарегистрированные ожидания

// Ждём клиентов
foreach ($clientPids as $pid) {
    pcntl_waitpid($pid, $status);
}
printf("Server: все запросы обработаны за %.2fs\n", microtime(true) - $t0);
echo "Итог: I/O-ожидание перекрывалось — " . CLIENTS * REQS_PER_CLIENT
    . " запросов, " . SERVER_FIBERS . " фибры, один процесс, без блокировок\n";
