<?php

// Event Loop: один процесс обслуживает НЕСКОЛЬКО источников событий через
// stream_select(). Цикл ждёт, пока хотя бы один канал станет доступен для
// чтения, и обрабатывает только готовые — ни на одном источнике не блокируясь.

const SOURCE_COUNT = 3;
const MSG_PER_SOURCE = 4;
const TERMINATOR = "\0__TERM__\0";

// Каждый источник — pipe-пара; ребёнок пишет в свой канал, родитель читает
$streams = [];
for ($i = 1; $i <= SOURCE_COUNT; $i++) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    if ($pid === -1) {
        die('fork failed');
    }
    if ($pid === 0) {
        // Ребёнок: пишет сообщения и умолкает (EOF)
        fclose($pair[0]);
        for ($m = 1; $m <= MSG_PER_SOURCE; $m++) {
            fwrite($pair[1], "S$i-msg$m\n");
            usleep(20000);
        }
        fclose($pair[1]);
        exit(0);
    }
    fclose($pair[1]); // родителю нужна только читающая сторона
    $streams[] = ['pid' => $pid, 'read' => $pair[0]];
}

// Event loop: select по всем читающим каналам
$live = count($streams);
while ($live > 0) {
    $read = [];
    foreach ($streams as $s) {
        $read[] = $s['read'];
    }
    $write = null;
    $except = null;

    $n = stream_select($read, $write, $except, 1);
    if ($n === false) {
        break;
    }
    if ($n > 0) {
        foreach ($streams as $i => &$s) {
            if (in_array($s['read'], $read, true)) {
                $line = fgets($s['read']);
                if ($line === false) {
                    fclose($s['read']);
                    pcntl_waitpid($s['pid'], $st);
                    unset($streams[$i]);
                    $live--;
                } else {
                    echo 'Loop: event from ' . rtrim($line, "\n") . "\n";
                }
            }
        }
        unset($s);
        $streams = array_values($streams);
    }
}

echo "Event loop: all sources closed, done\n";
