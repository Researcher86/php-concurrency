# PHP Concurrency

Шпаргалка по многопроцессной (IPC) разработке на PHP.
Каждый урок — папка с `diagram.txt` (ASCII-схема паттерна) и работающим
`main.php` (или набором файлов), который можно запустить в Docker.

## Требования

- Docker + Docker Compose (образ: `php:8.5-cli` с расширениями `pcntl`, `posix`,
  `sysvmsg`, `sysvsem`, `sysvshm`, xdebug).

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
  'php /app/18_scheduler/main.php > /tmp/x.log 2>&1; echo "EXIT=$?"; cat /tmp/x.log'
```

> Очереди System V (`msg_*`) живут дольше процесса. Если урок был прерван и
> `msg_remove_queue()` не отработал, чистят вручную:
> `ipcs -q` → `ipcrm -q <id>`. Зависшие процессы: `pkill -9 -f main.php`.

## Уроки

### 01–02. Базовые механизмы

| # | Папка | Тема | Что внутри |
|---|-------|------|-----------|
| 01 | `01_fork` | Fork | `pcntl_fork`, разделение кода на родителя/ребёнка |
| 02 | `02_ipc` | IPC | `msg_queue`, `pipe`, `semaphore`, `shmop`, `signals`, `unix_socket` |

### 03–12. Классические паттерны

| # | Папка | Тема | Паттерн |
|---|-------|------|---------|
| 03 | `03_worker_pool` | Worker Pool | пул воркеров, сигнальная остановка (SIGTERM) |
| 04 | `04_producer_consumer` | Producer-Consumer | очередь между производителем и потребителем |
| 05 | `05_pipeline` | Pipeline | конвейер стадий через `stream_socket_pair` |
| 06 | `06_fan_out` | Fan-Out | раздача задач по конкурирующим воркерам |
| 07 | `07_fan_in` | Fan-In | сборка результатов от многих источников |
| 08 | `08_master_worker` | Master-Worker | мастер управляет воркерами через очереди |
| 09 | `09_supervisor` | Supervisor | WNOHANG-мониторинг и перезапуск умерших |
| 10 | `10_pub_sub` | Pub-Sub | издатели/подписчики через общую очередь |
| 11 | `11_priority_queue` | Priority Queue | приоритеты через типы сообщений |
| 12 | `12_backpressure` | Backpressure | bounded queue, блокировки `msg_send` |

### 13–17. Надёжность и оркестрация

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 13 | `13_circuit_breaker` | Circuit Breaker | CLOSED / OPEN / HALF_OPEN |
| 14 | `14_actor_model` | Actor Model | акторы с собственными mailbox-очередями |
| 15 | `15_workflow_engine` | Workflow Engine | activity в forked-процессах, состояния |
| 16 | `16_mini_messenger` | Mini Messenger | broker-маршрутизация (general/random/dm) |
| 17 | `17_mini_temporal` | Mini Temporal | retry / timeout / signal-cancel |

### 18–23. Продвинутые системы

| # | Папка | Тема | Идея |
|---|-------|------|------|
| 18 | `18_scheduler` | Scheduler | round-robin раздача задач по воркерам |
| 19 | `19_rpc` | RPC | вызовы процедур с correlation id |
| 20 | `20_work_stealing` | Work Stealing | перегруженный воркер крадёт из чужих очередей |
| 21 | `21_event_loop` | Event Loop | `stream_select` на нескольких источниках |
| 22 | `22_mini_php_fpm` | Mini PHP-FPM | пул воркеров + `pm.max_requests`-рестарт |
| 23 | `23_mini_roadrunner` | Mini RoadRunner | persistent-воркеры, crash + respawn |

В каждом `diagram.txt` есть блоки **Сложность** ⭐, **Syscalls** и **Real world**
(аналоги в реальном мире: nginx, PHP-FPM, ReactPHP, Go runtime, RoadRunner и т.д.).
