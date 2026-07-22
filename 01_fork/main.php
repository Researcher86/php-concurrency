<?php

$pid = pcntl_fork();

if ($pid === -1) {
    die('fork failed');
}

if ($pid === 0) {
    echo "child process: " . getmypid() . "\n";
    sleep(1);
    echo "child done\n";
    exit(0);
}

echo "parent process: " . getmypid() . "\n";
echo "waiting for child...\n";
pcntl_wait($status);
echo "child exited with status: $status\n";
