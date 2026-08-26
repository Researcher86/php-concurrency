<?php

// Mini Temporal (Temporal-подобный оркестратор):
//   - Workflow Engine (родитель) исполняет Workflow: по шагам (Activity)
//     шлёт задачи в Task Queue и ждёт результат.
//   - Activity Worker (дочерний процесс) берёт задачи из очереди,
//     "исполняет" их и отвечает в Result Queue.
//   - Механики:
//       * Retry  — активность падает, движок переотправляет (до 3 попыток)
//       * Timeout — активность не успела ответить -> шаг провален
//       * Signal  — внешний оператор может отменить workflow
//       * Correlation id — у каждого запроса свой id, ответ сверяется с ним,
//         а не принимается "первым подвернувшимся" (иначе поздний ответ
//         одной активности, пришедший уже после таймаута, можно спутать
//         с ответом на следующую активность)
//   УЧЕБНАЯ МОДЕЛЬ: состояние workflow живёт в памяти движка ($state),
//   не в durable storage. В настоящем Temporal оно персистентно.

const TIMEOUT_US = 400000;        // лимит на выполнение активности (SlowCheck)
const ACTIVITY_DELAY_US = 300000; // сколько worker "работает" над активностью
const MAX_ATTEMPTS = 3;

$taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);
$signalQueue = msg_get_queue(ftok(__FILE__, 's'), 0666);
$controlQueue = msg_get_queue(ftok(__FILE__, 'g'), 0666);

// Activity Worker: исполняет задачи.
// Charge падает на 1-й и 2-й попытке (демо retry), SlowCheck работает дольше
// таймаута (демо timeout), остальные всегда успешны.
$workerPid = pcntl_fork();
if ($workerPid === -1) {
    die('fork failed');
}
if ($workerPid === 0) {
    while (true) {
        $task = '';
        $type = 0;
        $error = null;
        msg_receive($taskQueue, 1, $type, 1024, $task, true, 0, $error);

        if ($task === 'STOP') {
            break;
        }

        $id = $task['id'];
        $name = $task['name'];

        if ($name === 'SlowCheck') {
            usleep(800000); // дольше TIMEOUT_US -> движок откажет по таймауту
            echo "Worker: SlowCheck finished (too late)\n";
            msg_send($resultQueue, 1, ['id' => $id, 'status' => 'OK']);
            continue;
        }

        usleep(ACTIVITY_DELAY_US);

        if ($name === 'Charge') {
            // Номер попытки шлёт движок (у него, а не у воркера, живёт retry-state)
            $attempt = $task['attempt'];
            if ($attempt < MAX_ATTEMPTS) {
                echo "Worker: Charge attempt $attempt FAILED\n";
                msg_send($resultQueue, 1, ['id' => $id, 'status' => 'FAIL']);
            } else {
                echo "Worker: Charge attempt $attempt OK\n";
                msg_send($resultQueue, 1, ['id' => $id, 'status' => 'OK']);
            }
            continue;
        }

        echo "Worker: $name OK\n";
        msg_send($resultQueue, 1, ['id' => $id, 'status' => 'OK']); // Ship / Notify всегда успешны
    }
    exit(0);
}

// Оператор: по команде 'go' от движка шлёт сигнал 'cancel' в signalQueue
$operatorPid = pcntl_fork();
if ($operatorPid === -1) {
    die('fork failed');
}
if ($operatorPid === 0) {
    $cmd = '';
    $type = 0;
    $error = null;
    msg_receive($controlQueue, 1, $type, 1024, $cmd, true, 0, $error); // ждём 'go'

    usleep(100000);
    msg_send($signalQueue, 1, 'cancel');
    echo "Operator: sent cancel signal\n";
    exit(0);
}

// ---- Движок: примитивы ----

// Свой id на каждый запрос — чтобы можно было отличить ответ на ЭТОТ запрос
// от чужого/устаревшего ответа, болтающегося в очереди (см. комментарий про
// Correlation id в начале файла).
function nextId(): int
{
    static $id = 0;
    return ++$id;
}

// Ждём именно ответ на $expectedId; всё остальное — устаревший ответ
// от прошлой активности — вычитываем и отбрасываем, продолжая ждать.
function receiveResult(SysvMessageQueue $resultQueue, int $expectedId): string
{
    while (true) {
        $reply = null;
        $type = 0;
        $error = null;
        msg_receive($resultQueue, 1, $type, 1024, $reply, true, 0, $error);

        if (($reply['id'] ?? null) === $expectedId) {
            return $reply['status'];
        }

        echo 'Engine: discarding stale result (id=' . ($reply['id'] ?? '?') . ")\n";
    }
}

// Запуск активности с retry (до MAX_ATTEMPTS попыток)
function runActivity(SysvMessageQueue $taskQueue, SysvMessageQueue $resultQueue, string $name): bool
{
    for ($attempt = 1; $attempt <= MAX_ATTEMPTS; $attempt++) {
        $id = nextId();
        echo "Engine: dispatch '$name' (attempt $attempt)\n";
        msg_send($taskQueue, 1, ['id' => $id, 'name' => $name, 'attempt' => $attempt]);

        if (receiveResult($resultQueue, $id) === 'OK') {
            echo "Engine: '$name' OK\n";
            return true;
        }
        echo "Engine: '$name' FAILED on attempt $attempt\n";
    }

    echo "Engine: '$name' gave up after " . MAX_ATTEMPTS . " attempts\n";
    return false;
}

// Запуск активности с таймаутом: ждём результат не дольше TIMEOUT_US
function runActivityWithTimeout(SysvMessageQueue $taskQueue, SysvMessageQueue $resultQueue, string $name): bool
{
    $id = nextId();
    msg_send($taskQueue, 1, ['id' => $id, 'name' => $name, 'attempt' => 1]);
    $deadline = hrtime(true) + TIMEOUT_US * 1000;

    while (true) {
        $reply = null;
        $type = 0;
        $error = null;
        if (msg_receive($resultQueue, 1, $type, 1024, $reply, true, MSG_IPC_NOWAIT, $error)) {
            if (($reply['id'] ?? null) === $id) {
                echo "Engine: '$name' OK\n";
                return true;
            }
            // Чужой/устаревший ответ (например, опоздавший SlowCheck) — отбрасываем и ждём дальше
            echo 'Engine: discarding stale result (id=' . ($reply['id'] ?? '?') . ")\n";
            continue;
        }

        if (hrtime(true) >= $deadline) {
            echo "Engine: '$name' TIMED OUT\n";
            return false;
        }
        usleep(10000);
    }
}

// Проверка сигнала отмены (неблокирующая)
function checkCancel(SysvMessageQueue $signalQueue): bool
{
    $msg = '';
    $type = 0;
    $error = null;
    if (msg_receive($signalQueue, 1, $type, 1024, $msg, true, MSG_IPC_NOWAIT, $error)) {
        return $msg === 'cancel';
    }
    return false;
}

// ---- Workflow 1: retry (Charge падает 2 раза, потом успех) ----
echo "\n=== Workflow 'order' (retry demo) ===\n";
$state = 'running';
echo "Engine: workflow 'order' -> state: $state\n";
foreach (['Charge', 'Ship', 'Notify'] as $step) {
    if (!runActivity($taskQueue, $resultQueue, $step)) {
        $state = 'failed';
        break;
    }
}
if ($state === 'running') {
    $state = 'completed';
}
echo "Engine: workflow 'order' -> state: $state\n";

// ---- Workflow 2: timeout (SlowCheck не успевает) ----
echo "\n=== Workflow 'slow' (timeout demo) ===\n";
$state = 'running';
echo "Engine: workflow 'slow' -> state: $state\n";
if (!runActivityWithTimeout($taskQueue, $resultQueue, 'SlowCheck')) {
    $state = 'failed';
}
echo "Engine: workflow 'slow' -> state: $state\n";

// ---- Workflow 3: signal (оператор отменяет workflow) ----
echo "\n=== Workflow 'cancelable' (signal demo) ===\n";
$state = 'running';
echo "Engine: workflow 'cancelable' -> state: $state\n";

runActivity($taskQueue, $resultQueue, 'Charge');
echo "Engine: awaiting cancel signal after 'Charge'...\n";
msg_send($controlQueue, 1, 'go');

// Ждём сигнал отмены с таймаутом
$deadline = hrtime(true) + 1500000 * 1000;
while (hrtime(true) < $deadline) {
    if (checkCancel($signalQueue)) {
        $state = 'CANCELLED';
        break;
    }
    usleep(10000);
}

echo "Engine: workflow 'cancelable' -> state: $state\n";

// ---- Завершение ----
msg_send($taskQueue, 1, 'STOP');
pcntl_waitpid($workerPid, $status);
pcntl_waitpid($operatorPid, $status);

msg_remove_queue($taskQueue);
msg_remove_queue($resultQueue);
msg_remove_queue($signalQueue);
msg_remove_queue($controlQueue);
