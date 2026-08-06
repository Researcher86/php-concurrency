<?php

// Pub-Sub: два publisher'а шлют сообщения по темам alpha и beta.
// S1 подписан на alpha, S2 — на alpha + beta. Каждый subscriber
// получает свою копию через отдельный pipe.

const MSG_COUNT = 5;

[$sub1Read, $sub1Write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
[$sub2Read, $sub2Write] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

$sub1Pid = pcntl_fork();
if ($sub1Pid === 0) {
    fclose($sub1Write);
    fclose($sub2Read);
    fclose($sub2Write);

    while (($line = fgets($sub1Read)) !== false) {
        echo "S1 (alpha): " . rtrim($line, "\n") . "\n";
    }
    exit(0);
}

$sub2Pid = pcntl_fork();
if ($sub2Pid === 0) {
    fclose($sub2Write);
    fclose($sub1Read);
    fclose($sub1Write);

    while (($line = fgets($sub2Read)) !== false) {
        echo "S2 (alpha+beta): " . rtrim($line, "\n") . "\n";
    }
    exit(0);
}

$pub1Pid = pcntl_fork();
if ($pub1Pid === 0) {
    fclose($sub1Read);
    fclose($sub2Read);

    for ($i = 1; $i <= MSG_COUNT; $i++) {
        fwrite($sub1Write, "P1 alpha: msg $i\n");
        fwrite($sub2Write, "P1 alpha: msg $i\n");
        usleep(rand(10000, 50000));
    }
    fclose($sub1Write);
    fclose($sub2Write);
    exit(0);
}

$pub2Pid = pcntl_fork();
if ($pub2Pid === 0) {
    fclose($sub1Read);
    fclose($sub1Write);
    fclose($sub2Read);

    for ($i = 1; $i <= MSG_COUNT; $i++) {
        fwrite($sub2Write, "P2 beta: msg $i\n");
        usleep(rand(10000, 50000));
    }
    fclose($sub2Write);
    exit(0);
}

fclose($sub1Read);
fclose($sub1Write);
fclose($sub2Read);
fclose($sub2Write);

pcntl_waitpid($pub1Pid, $status);
pcntl_waitpid($pub2Pid, $status);
pcntl_waitpid($sub1Pid, $status);
pcntl_waitpid($sub2Pid, $status);
