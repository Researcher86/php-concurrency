# 09. Supervisor

Мониторинг воркеров и автоматический перезапуск упавших.

## Задача

Воркеры могут умереть (память, ошибки). Supervisor должен заметить смерть
неблокирующе и перезапустить процесс, чтобы пул оставался полным.

## Как работает

- Мастер запускает воркеров.
- Стратегия **one_for_one**: перезапускается только упавший воркер
  (а не весь пул).
- Мониторинг — `pcntl_waitpid($pid, $status, WNOHANG)`: опрос без блокировки,
  мастер продолжает работать, пока проверяет каждого воркера.
- Классический демон: живёт вечно. В Docker его запускают в фоне и
  останавливают SIGTERM:
  `php main.php & PID=$!; sleep 2; kill -TERM $PID; wait $PID`.

## IPC

Сигналы + `pcntl_waitpid`; System V очереди для задач.

## Паттерн

**Supervisor / Process Watcher**, стратегия перезапуска one_for_one
(из Erlang/OTP).

## Запуск

```bash
docker compose exec -T php sh -c \
  'php /app/09_supervisor/main.php & PID=$!; sleep 3; kill -TERM $PID; wait $PID'
```

## Что попробовать изменить

- Сменить стратегию на one_for_all (упал один — перезапустить весь пул).
- Добавить лимит перезапусков (плавный отказ, а не бесконечный рестарт).
- Сделать exponential backoff между перезапусками.

## Real world

Supervisord, PM2, systemd, kubernetes ReplicaSet.

## Что изучать дальше

30 — mini PHP runtime (supervisor внутри); 23 — mini RoadRunner (respawn).
