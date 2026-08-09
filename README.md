# PHP Concurrency

**Практический курс по конкурентности в PHP: от `pcntl` и IPC до Fibers,
event loop и асинхронного I/O.**

Вместо того чтобы сразу использовать высокоуровневые фреймворки, курс
помогает понять, как работают механизмы конкурентности, реализуя их с нуля.

38 уроков: от `pcntl_fork` и процессного IPC до мини-рантайма в духе
PHP-FPM/RoadRunner, затем фиберы и собственный асинхронный runtime (PHP 8.1+).

Каждый урок — папка с `README.md` (мини-учебник), `diagram.txt` (ASCII-схема
паттерна) и работающим `main.php` (или набором файлов), который можно запустить
в Docker. Курс построен по частям (Процессы → Распределение → Координация →
Надёжность → Workflows → Runtime → Fibers & Async → Choosing the Model), но
каждый урок можно запускать отдельно.
Для быстрого повторения — таблицы ниже и блоки **Real world** в каждом README.

## Требования

- Docker + Docker Compose (образ: `php:8.5-cli` с расширениями `pcntl`, `posix`,
  `sysvmsg`, `sysvsem`, `sysvshm`, xdebug).
- Уроки 33–38 требуют PHP ≥ 8.1 (фиберы); в образе уже 8.5.

## Запуск

```bash
make up          # поднять контейнер
make shell       # зайти внутрь
php -l main.php  # проверка синтаксиса (без запуска)
php main.php     # запуск урока
make down        # остановить
```

Проверка урока одним прогоном (лог пишется внутри контейнера):

```bash
docker compose exec -T php sh -c \
  'php /app/13_scheduler/main.php > /tmp/x.log 2>&1; echo "EXIT=$?"; cat /tmp/x.log'
```

Урок 04 (Supervisor) — демон, его запускают с сигнальной остановкой:

```bash
docker compose exec -T php sh -c \
  'php /app/04_supervisor/main.php & PID=$!; sleep 3; kill -TERM $PID; wait $PID'
```

> Очереди System V (`msg_*`) живут дольше процесса. Если урок был прерван и
> `msg_remove_queue()` не отработал, чистят вручную:
> `ipcs -q` → `ipcrm -q <id>`. Зависшие процессы: `pkill -9 -f main.php`.

## Уроки

### Part I — Processes (базовые механизмы)

| # | Папка | Тема | Что внутри |
|---|-------|------|-----------|
| 01 | `01_fork` | Fork | `pcntl_fork`, разделение кода на родителя/ребёнка |
| 02 | `02_process_lifecycle` | Process Lifecycle | exit → SIGCHLD → waitpid → reap, zombie, reap-all |
| 03 | `03_ipc` | IPC | `msg_queue`, `pipe`, `semaphore`, `shmop`, `signals`, `unix_socket` |
| 04 | `04_supervisor` | Supervisor | WNOHANG-мониторинг и перезапуск умерших (демон) |

### Part II — Work Distribution (распределение работы)

| # | Папка | Тема | Паттерн |
|---|-------|------|---------|
| 05 | `05_producer_consumer` | Producer-Consumer | очередь между производителем и потребителем |
| 06 | `06_worker_pool` | Worker Pool | пул воркеров, сигнальная остановка (SIGTERM) |
| 07 | `07_backpressure` | Backpressure | bounded queue, блокировки `msg_send` |
| 08 | `08_priority_queue` | Priority Queue | приоритеты через типы сообщений |
| 09 | `09_fan_out` | Fan-Out | раздача задач по конкурирующим воркерам |
| 10 | `10_fan_in` | Fan-In | сборка результатов от многих источников (shm) |
| 11 | `11_pipeline` | Pipeline | конвейер стадий через `stream_socket_pair` |
| 12 | `12_master_worker` | Master-Worker | мастер управляет воркерами через очереди |
| 13 | `13_scheduler` | Scheduler | round-robin раздача задач по воркерам |

### Part III — Coordination (координация)

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 14 | `14_barrier` | Barrier | все ждут самого медленного между фазами |
| 15 | `15_actor_model` | Actor Model | акторы с собственными mailbox-очередями |
| 16 | `16_pub_sub` | Pub-Sub | издатели/подписчики через общую очередь |
| 17 | `17_rpc` | RPC | вызовы процедур с correlation id |
| 18 | `18_event_loop` | Event Loop | `stream_select` на нескольких источниках |
| 19 | `19_work_stealing` | Work Stealing | перегруженный воркер крадёт из чужих очередей |
| 20 | `20_parallel_map` | Parallel Map | `array_map` в N процессах с сохранением порядка |

### Part IV — Reliability (надёжность)

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 21 | `21_graceful_shutdown` | Graceful Shutdown | drain очереди и мягкая остановка по SIGTERM |
| 22 | `22_retry_backoff` | Retry + Backoff | экспоненциальная задержка между попытками |
| 23 | `23_circuit_breaker` | Circuit Breaker | CLOSED / OPEN / HALF_OPEN |
| 24 | `24_rate_limiter` | Rate Limiter | общий лимит через shm + семафор |
| 25 | `25_dead_letter_queue` | Dead Letter Queue | неразрешимые задачи → отдельная очередь |
| 26 | `26_reliability_fundamentals` | Reliability Fundamentals | Timeout / Cancellation / Delivery Semantics / Idempotency |

### Part V — Distributed Workflows (распределённые workflow)

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 27 | `27_workflow_engine` | Workflow Engine | activity в forked-процессах, состояния |
| 28 | `28_mini_messenger` | Mini Messenger | broker-маршрутизация (general/random/dm) |
| 29 | `29_mini_temporal` | Mini Temporal | retry / timeout / signal-cancel |

### Part VI — Process-based PHP Runtime (рантайм)

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 30 | `30_mini_php_fpm` | Mini PHP-FPM | пул воркеров + `pm.max_requests`-рестарт |
| 31 | `31_mini_roadrunner` | Mini RoadRunner | persistent-воркеры, crash + respawn |
| 32 | `32_mini_php_runtime` | Mini PHP Runtime | capstone: мастер + пул + polling loop + supervisor |

Урок 32 — итоговый (capstone) первой половины: собирает весь процессный курс
в одну модель рантайма, с уровнями самостоятельной доработки в его README.

### Part VII — Fibers & Async PHP (фиберы и асинхронность)

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 33 | `33_php_fibers` | PHP Fibers | `start/suspend/resume`, Fiber ≠ Thread/Process/Parallelism |
| 34 | `34_cooperative_concurrency` | Cooperative Concurrency | кооперативное переключение; блокирующий вызов блокирует процесс |
| 35 | `35_event_loop_fibers` | Event Loop + Fibers | фибры ждут событие через `suspend`, loop будит через `select` |
| 36 | `36_async_io` | Async I/O | свой `await()` = `Fiber::suspend` + `stream_select` (unix socket pair) |
| 37 | `37_mini_async_runtime` | Mini Async Runtime | capstone: event loop + фибры + таймеры + async I/O + scheduler |

### Part VIII — Choosing the Model (выбор модели)

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 38 | `38_choosing_model` | Choosing the Model | процессы vs фибры: таблица, замеры I/O-wait и CPU-bound |

Урок 37 — итоговый (capstone) второй половины: асинхронный рантайм в одном
процессе (без fork и IPC), аналог урока 32 в фиберном мире. Урок 38
сравнивает обе модели и помогает выбрать инструмент под задачу.

В каждом `diagram.txt` есть блоки **Сложность** ⭐, **Syscalls** и **Real world**
(аналоги в реальном мире: nginx, PHP-FPM, ReactPHP, Go runtime, RoadRunner и т.д.).
В каждом `README.md` — разделы **Задача / Как работает / IPC / Паттерн / Запуск /
Что попробовать изменить / Real world / Что изучать дальше**.

## Финальная архитектура курса

```
                    PHP Concurrency
                          │
        ┌─────────────────┴─────────────────┐
        │                                   │
   Processes (уроки 01–32)            Fibers (уроки 33–37)
        │                                   │
   parallelism (OS)                  concurrency (userland)
        │                                   │
   CPU / изоляция                     I/O-bound / event loop
        │                                   │
   IPC: очереди/сокеты/память         общая память, без IPC
        │                                   │
   Runtime (урок 32)                  Async Runtime (урок 37)
        │                                   │
        └─────────────────┬─────────────────┘
                          │
                          ▼
            Choosing the Model (урок 38)
   I/O + много задач → фибры | CPU / изоляция → процессы
```

Процессы и фибры — не конкуренты, а два инструмента: процессы дают
параллелизм и изоляцию, фибры — лёгкую кооперативную конкурентность для
I/O-bound задач. Урок 38 сравнивает их с замерами (memory / throughput /
latency) и показывает границы каждой модели.
