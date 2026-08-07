<?php

// Worker Pool (пул воркеров): N воркеров-процессов разбирают общую очередь
// задач. Остановка — сигналом: мастер шлёт SIGTERM, воркер доедает очередь
// (drain) и выходит. Сигнальную диспозицию наследуют при fork, поэтому
// сигналы не теряются.

pcntl_async_signals(true);

function worker(SysvMessageQueue $queue, callable $callback, bool &$isStopWorker): int
{
    $pid = pcntl_fork();

    if ($pid === -1) {
        die('fork failed');
    }

    if ($pid === 0) {
        // $isStopWorker передаём в worker() по ссылке: до fork параметр привязан к одноимённой
        // переменной мастера, а после fork ребёнок наследует собственный экземпляр — fork копирует
        // всю память вместе со связями ссылок, и хендлер $workerStopHandler (use (&$isStopWorker))
        // пишет в тот же zval, который читает цикл ниже. НЕ присваиваем $isStopWorker = false здесь:
        // сброс после fork открыл бы окно, в котором SIGTERM от stopWorkers() поставил бы true,
        // а затем обнулился — сигнал терялся бы, и воркер навсегда вис бы в msg_receive(). Это и был «hang».

        // SIGTERM-хендлер ребёнок наследует от мастера ($workerStopHandler) — он правильный с
        // самого рождения, окна default-диспозиции нет вовсе. Перекрываем только SIGINT: Ctrl+C
        // терминал шлёт всей группе, но воркер его игнорирует — остановкой детей занимается мастер.
        pcntl_signal(SIGINT, SIG_IGN);

        while (true) {
            $msgType = 0;
            $msg = '';
            $error = null;

            // Полностью неблокирующий приём (MSG_IPC_NOWAIT): msg_receive никогда не ждёт,
            // а мгновенно возвращает сообщение или false. Если задач нет — спим 10мс (usleep)
            // и опрашиваем снова. Сигналы при pcntl_async_signals только выставляют флаги:
            // никакого EINTR и зависимости от прерывания блокирующего вызова — флаг читаем
            // на каждой итерации. Режим drain ($isStopWorker): продолжаем опрашивать и доедаем
            // очередь, выходим, когда она пуста.
            $received = msg_receive($queue, 0, $msgType, 1024, $msg, true, MSG_IPC_NOWAIT, $error);
            if ($received) {
                $callback($msgType, $msg, $error);
                continue; // очередь ещё может быть непуста — опрашиваем без паузы
            }

            // Очередь пуста.
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

function wait(): void
{
    // Ждём всех детей
    while (pcntl_wait($status) !== -1) {
        // reap
    }
}

$queue = msg_get_queue(ftok(__FILE__, 'm'), 0666);

$workersPids = [];
$isStopMaster = false;
// Флаг воркера объявляем ДО fork(): дети наследуют его уже равным false. Сброс в ветке ребёнка
// после fork снова открыл бы окно (SIGTERM ставит true, потом сброс обнуляет) — из-за него висло.
$isStopWorker = false;

// Оба хендлера объявляем ДО worker(): диспозиции наследуются при fork.
// На SIGTERM/SIGINT сначала вешаем worker-хендлер: тогда каждый ребёнок рождается уже с правильной
// диспозицией и флагом false, и не остаётся окна, где ранний SIGTERM от stopWorkers() съедался бы
// «плейсхолдером» (унаследованным хендлером мастера) и не выставлял бы $isStopWorker.
$workerStopHandler = function ($signo) use (&$isStopWorker) {
    $isStopWorker = true;
    echo 'Worker ' . getmypid() . ": got stop signal ($signo)\n";
};
$masterStopHandler = function ($signo) use (&$isStopMaster, &$workersPids) {
    $isStopMaster = true;
    echo 'Master ' . getmypid() . ": got $signo, stopping workers with SIGTERM\n";
    foreach ($workersPids as $pid) {
        posix_kill($pid, SIGTERM);
    }
};
pcntl_signal(SIGTERM, $workerStopHandler);
pcntl_signal(SIGINT, $workerStopHandler);

$workerCallback = function ($msgType, $msg, $error) {
    echo 'Worker ' . getmypid() . ": received [$msgType] $msg\n";
    usleep(500000);
};

$workersPids[] = worker($queue, $workerCallback, $isStopWorker);
$workersPids[] = worker($queue, $workerCallback, $isStopWorker);
$workersPids[] = worker($queue, $workerCallback, $isStopWorker);
$workersPids[] = worker($queue, $workerCallback, $isStopWorker);
$workersPids[] = worker($queue, $workerCallback, $isStopWorker);
$workersPids[] = worker($queue, $workerCallback, $isStopWorker);

// Все дети уже созданы. Перевешиваем хендлеры ТОЛЬКО у мастера (на детей это не влияет):
// его SIGTERM/SIGINT теперь = «разослать SIGTERM воркерам и выйти», а не «выйти самому».
pcntl_signal(SIGTERM, $masterStopHandler);
pcntl_signal(SIGINT, $masterStopHandler);

// Producer кладёт n сообщений
for ($i = 1; $i <= 50; $i++) {
    if ($isStopMaster) {
        break;
    }

    $msg = "Task $i";
    msg_send($queue, 1, $msg);
    echo 'Producer ' . getmypid() . ": sent $msg\n";
}

// Если пришёл внешний сигнал (SIGTERM/SIGINT), $masterStopHandler уже отправил воркерам SIGTERM
// и выставит $isStopMaster. Повторно стопать не нужно, иначе воркер получит сигнал дважды.
if (!$isStopMaster) {
    stopWorkers($workersPids);
}

wait();
msg_remove_queue($queue);
