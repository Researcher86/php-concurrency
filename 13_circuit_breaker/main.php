<?php

// Circuit Breaker (предохранитель): защищает вызовы к "зависимости" (сервису).
//   - CLOSED    — запросы проходят; FAILURE_THRESHOLD ошибок подряд -> OPEN
//   - OPEN      — запросы НЕ отправляются (fail fast), ждём cooldown -> HALF_OPEN
//   - HALF_OPEN — пропускается 1 пробный запрос: успех -> CLOSED (reset),
//                 ошибка -> снова OPEN
// Сервис в демо: первые 12 запросов отвечают ошибкой (авария), дальше — успехом.

const CALL_COUNT = 120;
const FAILURE_THRESHOLD = 3; // ошибок подряд до перехода в OPEN
const COOLDOWN_US = 300000;  // сколько OPEN "висит", прежде чем пробовать HALF_OPEN
const CALL_DELAY_US = 30000; // пауза между запросами

$taskQueue = msg_get_queue(ftok(__FILE__, 'm'), 0666);
$resultQueue = msg_get_queue(ftok(__FILE__, 'r'), 0666);

// Сервис (зависимость): отвечает на каждый запрос. Первые 12 — ошибкой.
$servicePid = pcntl_fork();
if ($servicePid === 0) {
    $request = 0;

    while (true) {
        $req = '';
        $type = 0;
        $error = null;
        msg_receive($taskQueue, 1, $type, 1024, $req, true, 0, $error);

        if ($req === 'STOP') {
            break;
        }
        $request++;
        $ok = $request > 12; // с 13-го запроса сервис "оживает"
        echo 'Service: request #' . $request . ' -> ' . ($ok ? 'OK' : 'ERROR') . "\n";
        msg_send($resultQueue, 1, $ok ? 'OK' : 'ERROR');
    }
    exit(0);
}

// Caller (родитель): делает запросы через предохранитель
$state = 'CLOSED';
$failures = 0;
$openedAt = 0;

for ($i = 1; $i <= CALL_COUNT; $i++) {
    // OPEN: прошёл cooldown -> пробуем HALF_OPEN
    if ($state === 'OPEN' && (hrtime(true) - $openedAt) / 1e3 >= COOLDOWN_US) {
        $state = 'HALF_OPEN';
        echo "Breaker: OPEN -> HALF_OPEN (cooldown over)\n";
    }

    if ($state === 'OPEN') {
        // fail fast: запрос к сервису не отправляется вообще
        echo "Breaker: call #$i -> FAILED FAST (state=OPEN, no request sent)\n";
        usleep(CALL_DELAY_US);
        continue;
    }

    // Запрос к сервису
    msg_send($taskQueue, 1, "req $i");
    $reply = '';
    $type = 0;
    $error = null;
    msg_receive($resultQueue, 1, $type, 1024, $reply, true, 0, $error);
    $ok = $reply === 'OK';
    echo "Breaker: call #$i -> " . ($ok ? 'OK' : 'ERROR') . " (state=$state)\n";

    if ($state === 'HALF_OPEN') {
        // Пробный запрос решил судьбу предохранителя
        if ($ok) {
            $state = 'CLOSED';
            $failures = 0;
            echo "Breaker: HALF_OPEN -> CLOSED (trial OK, reset)\n";
        } else {
            $state = 'OPEN';
            $openedAt = hrtime(true);
            echo "Breaker: HALF_OPEN -> OPEN (trial FAIL)\n";
        }
        usleep(CALL_DELAY_US);
        continue;
    }

    // CLOSED: считаем ошибки подряд
    if ($ok) {
        $failures = 0;
    } else {
        $failures++;
        if ($failures >= FAILURE_THRESHOLD) {
            $state = 'OPEN';
            $openedAt = hrtime(true);
            echo "Breaker: CLOSED -> OPEN ($failures errors in a row)\n";
        }
    }
    usleep(CALL_DELAY_US);
}

msg_send($taskQueue, 1, 'STOP');
pcntl_waitpid($servicePid, $status);

msg_remove_queue($taskQueue);
msg_remove_queue($resultQueue);
