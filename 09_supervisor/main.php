<?php

pcntl_async_signals(true);

// Supervisor: запускает worker'ов и перезапускает их в случае падения.
// Стратегия: one_for_one — перезапускается только упавший worker.
// Мониторинг: pcntl_waitpid с WNOHANG опрашивает каждого воркера без блокировки.

const WORKER_COUNT = 3;

// Worker: имитирует работу, периодически падает с exit(1).
// Супервизор обнаруживает смерть через WNOHANG и перезапускает.
function worker(int $id): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        $iteration = 0;

        while (true) {
            echo "Worker$id (" . getmypid() . "): running iteration $iteration\n";
            usleep(rand(200000, 500000));

            // Случайное падение (шанс 1/10)
            if (rand(1, 10) === 1) {
                echo "Worker$id (" . getmypid() . "): CRASH at iteration $iteration\n";
                exit(1);
            }

            $iteration++;
        }
        exit(0);
    }

    return $pid;
}

// Обработчик для Ctrl+C / SIGTERM: супервизор завершает всех workers и выходит
$isStop = false;
$stopHandler = function () use (&$isStop) {
    $isStop = true;
};
pcntl_signal(SIGINT, $stopHandler);
pcntl_signal(SIGTERM, $stopHandler);

// Supervisor: форкает worker'ов
$workers = [];
for ($i = 1; $i <= WORKER_COUNT; $i++) {
    $pid = worker($i);
    $workers[$i] = $pid;
    echo "Supervisor: started Worker$i (pid $pid)\n";
}

// Supervisor: мониторинг и перезапуск (one_for_one)
// Каждые 100мс опрашиваем каждого воркера через WNOHANG.
// Если воркер умер — fork нового с тем же ID.
while (!$isStop) {
    foreach ($workers as $id => $pid) {
        // WNOHANG: не ждём, а проверяем, не умер ли воркер
        $status = 0;
        $result = pcntl_waitpid($pid, $status, WNOHANG);

        if ($result === $pid) {
            // Воркер умер — перезапускаем
            echo "Supervisor: Worker$id (pid $pid) died, restarting...\n";
            $newPid = worker($id);
            $workers[$id] = $newPid;
            echo "Supervisor: restarted Worker$id (pid $newPid)\n";
        }
    }

    usleep(100000);
}

// Graceful shutdown: отправляем SIGTERM всем воркерам и ждём их
foreach ($workers as $id => $pid) {
    posix_kill($pid, SIGTERM);
}

while (pcntl_wait($status) !== -1) {
    // reap
}
