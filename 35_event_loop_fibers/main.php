<?php

// Event Loop + Fibers: фибры НЕ ждут "сами". Они запрашивают событие через
// Fiber::suspend(['read', $stream]) / suspend(['timer', $at]), а event loop
// (stream_select) решает, КОГДА их будить. Это связывает урок 18 с 33/34.

const SOURCE_COUNT = 2;
const LINES_PER_SOURCE = 3;
const HB_INTERVAL = 0.25; // сек
const HB_TICKS = 4;

// ---- Писатели: дети, которые с задержками шлют строки в свои каналы ----
$sources = [];
for ($i = 1; $i <= SOURCE_COUNT; $i++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        fclose($pair[0]); // ребёнку не нужна читающая сторона
        for ($m = 1; $m <= LINES_PER_SOURCE; $m++) {
            fwrite($pair[1], "src{$i}-line{$m}\n");
            usleep(rand(30000, 120000));
        }
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]); // родителю нужна только читающая сторона
    $sources[] = ['stream' => $pair[0], 'pid' => $pid];
}

// ---- Фибра-читатель: просит loop "буди меня, когда stream готов" ----
function makeReader(int $idx, $stream): Fiber
{
    return new Fiber(function () use ($idx, $stream): void {
        while (true) {
            // Запрос на ожидание: suspend отдаёт ['read', $stream], loop вернёт 'ready'
            $req = Fiber::suspend(['read', $stream]);
            if ($req !== 'ready') {
                continue;
            }
            $line = fgets($stream);
            if ($line === false) {
                echo "fiber#{$idx}: EOF\n";
                return;
            }
            echo "fiber#{$idx}: " . rtrim($line, "\n") . "\n";
        }
    });
}

// ---- Фибра-таймер: "буди меня в момент времени $at" (heartbeat) ----
function makeHeartbeat(float $intervalSec, int $max): Fiber
{
    return new Fiber(function () use ($intervalSec, $max): void {
        for ($i = 1; $i <= $max; $i++) {
            $req = Fiber::suspend(['timer', microtime(true) + $intervalSec]);
            if ($req !== 'tick') {
                continue;
            }
            printf("heartbeat: tick %d @ %.3fs\n", $i, microtime(true) - START_T);
        }
    });
}

define('START_T', microtime(true));

// ---- Регистрируем фибры в event loop ----
// Протокол: после start()/resume() фибра возвращает (через suspend) свой
// СЛЕДУЮЩИЙ запрос — loop не хранит интерес сам, фибра его заявляет.
$pending = [];
foreach ($sources as $idx => $src) {
    $fiber = makeReader($idx, $src['stream']);
    $pending[] = ['fiber' => $fiber, 'req' => $fiber->start()];
}
$hb = makeHeartbeat(HB_INTERVAL, HB_TICKS);
$pending[] = ['fiber' => $hb, 'req' => $hb->start()];

echo "Event loop: " . count($pending) . " фибр в ожидании\n";
while ($pending) {
    $read = [];
    $nextAt = null;
    foreach ($pending as $w) {
        if ($w['req'][0] === 'read') {
            $read[] = $w['req'][1];
        } elseif ($w['req'][0] === 'timer') {
            $nextAt = $nextAt === null ? $w['req'][1] : min($nextAt, $w['req'][1]);
        }
    }

    // Таймаут select = до ближайшего таймера (или 1s, если таймеров нет)
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
        // Только таймеры: stream_select с пустым read-набором недопустим,
        // поэтому просто спим до ближайшего события
        usleep($sec * 1000000 + $usec);
    }
    $now = microtime(true);

    foreach ($pending as $i => $w) {
        $ready = false;
        $payload = null;
        if ($w['req'][0] === 'read' && in_array($w['req'][1], $read, true)) {
            $ready = true;
            $payload = 'ready';
        } elseif ($w['req'][0] === 'timer' && $now >= $w['req'][1]) {
            $ready = true;
            $payload = 'tick';
        }
        if (!$ready) {
            continue;
        }
        $nextReq = $w['fiber']->resume($payload); // будим; фибра вернёт новый запрос
        if ($w['fiber']->isTerminated()) {
            unset($pending[$i]);
            continue;
        }
        $pending[$i]['req'] = $nextReq;
    }
    $pending = array_values($pending);
}
echo "Event loop: все фибры завершены\n";

foreach ($sources as $src) {
    pcntl_waitpid($src['pid'], $status);
}
echo "Done\n";
