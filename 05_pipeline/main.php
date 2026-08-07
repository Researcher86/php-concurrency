<?php

// Pipeline (конвейер): source -> stage1 -> stage2, как | в шелле.
// Каждая стадия — отдельный процесс; данные идут по socket-парам,
// очередная стадия читает из своего in и пишет в out.

pcntl_async_signals(true);

const DATA_COUNT = 5;

// Создаём pipe-пары (как | в шелле): [readEnd, writeEnd]
[$in1, $out1] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
[$in2, $out2] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

// Source: пишет строки в $out1
$sourcePid = pcntl_fork();
if ($sourcePid === -1) {
    die('fork failed');
}
if ($sourcePid === 0) {
    fclose($in1);
    fclose($in2);
    fclose($out2);

    for ($i = 1; $i <= DATA_COUNT; $i++) {
        fwrite($out1, "data-$i\n");
        echo 'Source ' . getmypid() . ": sent data-$i\n";
        usleep(rand(10000, 50000));
    }
    fclose($out1);
    exit(0);
}

// Stage 1: читает из $in1 (как stdin), пишет uppercase в $out2 (как stdout)
$pid1 = pcntl_fork();
if ($pid1 === -1) {
    die('fork failed');
}
if ($pid1 === 0) {
    fclose($out1);
    fclose($in2);

    while (($line = fgets($in1)) !== false) {
        $line = trim($line);
        $result = strtoupper($line);
        echo 'Stage1 (' . getmypid() . "): $line -> $result\n";
        fwrite($out2, "$result\n");
        usleep(rand(50000, 100000));
    }
    fclose($in1);
    fclose($out2);
    exit(0);
}

// Stage 2: читает из $in2 (как stdin), реверсит и выводит в консоль
$pid2 = pcntl_fork();
if ($pid2 === -1) {
    die('fork failed');
}
if ($pid2 === 0) {
    fclose($out1);
    fclose($in1);
    fclose($out2);

    while (($line = fgets($in2)) !== false) {
        $line = trim($line);
        $result = strrev($line);
        echo 'Stage2 (' . getmypid() . "): $line -> $result\n";
        usleep(rand(50000, 100000));
    }
    fclose($in2);
    exit(0);
}

// Родитель: закрывает свои копии, чтобы EOF корректно пробрасывался
fclose($in1);
fclose($out1);
fclose($in2);
fclose($out2);

// Ждём всех детей
pcntl_waitpid($sourcePid, $status);
pcntl_waitpid($pid1, $status);
pcntl_waitpid($pid2, $status);
