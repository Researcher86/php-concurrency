<?php

// Worker Pool (пул воркеров): N воркеров-процессов разбирают общую очередь
// задач. Остановка — сигналом: мастер шлёт SIGTERM, воркер доедает очередь
// (drain) и выходит. Сигнальную диспозицию наследуют при fork, поэтому
// сигналы не теряются.

pcntl_async_signals(true);

const WORKER_COUNT = 6;
const TASK_COUNT = 50;

function worker(SysvMessageQueue $queue, callable $handle, bool &$isStopWorker): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        // $isStopWorker приходит по ссылке: fork копирует память вместе со связями
        // ссылок, так что хендлер $workerStopHandler (use (&$isStopWorker)) пишет
        // в тот же zval, который читает цикл ниже. НЕ сбрасываем флаг здесь: сброс
        // после fork открыл бы окно, в котором SIGTERM от мастера поставил бы true,
        // а затем обнулился — воркер бы навсегда завис («hang»).

        // SIGTERM-хендлер унаследован от мастера и правилен с самого рождения.
        // Перекрываем только SIGINT: Ctrl+C терминал шлёт всей группе, но
        // остановкой детей занимается мастер.
        pcntl_signal(SIGINT, SIG_IGN);

        while (true) {
            // Неблокирующий приём (MSG_IPC_NOWAIT): сообщение или сразу false.
            // Сигналы при pcntl_async_signals лишь выставляют флаг — никакой
            // зависимости от EINTR в блокирующем вызове, флаг читаем на каждой
            // итерации. Drain: после SIGTERM доедаем очередь и выходим, когда она пуста.
            if (msg_receive($queue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT)) {
                $handle($msgType, $msg);
                continue; // очередь ещё может быть непуста — опрашиваем без паузы
            }

            if ($isStopWorker) {
                break; // drain завершён — все отправленные задачи обработаны
            }

            usleep(10000);
        }

        exit(0);
    }

    return $pid;
}

function stopWorkers(array $workersPids): void
{
    foreach ($workersPids as $pid) {
        posix_kill($pid, SIGTERM);
    }
}

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

$workersPids = [];
$isStopMaster = false;
// Флаг воркера объявляем ДО fork(): дети наследуют его уже равным false.
$isStopWorker = false;

// Хендлер воркера вешаем ДО fork: каждый ребёнок рождается с правильной
// диспозицией, и ранний SIGTERM не съедается хендлером мастера.
$workerStopHandler = function ($signo) use (&$isStopWorker) {
    $isStopWorker = true;
    echo 'Worker ' . getmypid() . ": got stop signal ($signo)\n";
};
pcntl_signal(SIGTERM, $workerStopHandler);
pcntl_signal(SIGINT, $workerStopHandler);

$handle = function ($msgType, $msg) {
    echo 'Worker ' . getmypid() . ": received [$msgType] $msg\n";
    usleep(500000);
};

for ($i = 0; $i < WORKER_COUNT; $i++) {
    $workersPids[] = worker($queue, $handle, $isStopWorker);
}

// Все дети созданы — перевешиваем хендлеры ТОЛЬКО у мастера (на детей это не
// влияет): его SIGTERM/SIGINT теперь = «прекратить отправку», а не «выйти самому».
$masterStopHandler = function ($signo) use (&$isStopMaster) {
    $isStopMaster = true;
    echo 'Master ' . getmypid() . ": got $signo, stopping workers with SIGTERM\n";
};
pcntl_signal(SIGTERM, $masterStopHandler);
pcntl_signal(SIGINT, $masterStopHandler);

// Producer кладёт задачи, пока не пришёл внешний сигнал
for ($i = 1; $i <= TASK_COUNT && !$isStopMaster; $i++) {
    $msg = "Task $i";
    msg_send($queue, 1, $msg);
    echo 'Producer ' . getmypid() . ": sent $msg\n";
}

// Штатно или по сигналу — воркерам один раз шлём SIGTERM: они доедают очередь
stopWorkers($workersPids);

while (pcntl_wait($status) !== -1) {
    // reap
}
msg_remove_queue($queue);
