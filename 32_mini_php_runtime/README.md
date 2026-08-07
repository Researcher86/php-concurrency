# 32. Mini PHP Runtime

Итоговый урок: мастер + пул persistent-воркеров + IPC-очередь + polling loop +
supervisor — маленькая модель PHP-FPM/RoadRunner.

## Задача

Собрать всё, что было в курсе, в одну работающую модель рантайма: сервер
принимает запросы, пул воркеров их обрабатывает, упавшие перезапускаются.

## Как работает

- **Master** форкает пул из `WORKER_COUNT` воркеров (замыкание `$spawnWorker`).
- Воркеры **персистентны**: живут между запросами, крутят цикл приёма.
- **Polling loop** у мастера: очередь SysV — не файловый дескриптор, поэтому
  мастер *опрашивает* её (`MSG_IPC_NOWAIT`), а не ждёт событий через
  `select()` (сравни с настоящим event loop в уроке 18).
- **Supervisor**: если воркер умер (в демо — по запросу `CRASH`), мастер
  форкает замену (respawn).
- **Metrics**: в конце мастер выводит сводку `requests / completed / crashes /
  respawns / queue_left` — мини-телеграфия рантайма.
- Когда все задачи обработаны — мастер шлёт воркерам STOP, собирает и чистит.

## IPC

System V очереди: request + result. Сигналы отсутствуют (остановка —
сообщениями), `MSG_IPC_NOWAIT` для неблокирующего приёма.

## Паттерн

**Master-Worker + Polling Loop + Supervisor** — сборка 04/12/21/22/23.

## Запуск

```bash
docker compose exec -T php php /app/32_mini_php_runtime/main.php
```

## Что попробовать изменить

- Изменить запрос, на котором воркер "крашится".
- Добавить `pm.max_requests`-рестарт (из 22) поверх respawn.
- Добавить graceful shutdown (из 21) на STOP.

## Complexity / Failure modes / Guarantees

**Complexity**: ⭐⭐⭐⭐⭐ — capstone: всё, что было в курсе, в одном процессе.
**Failure modes**: воркер падает (CRASH) → respawn, задача-CRASH теряется
(считается в metrics); очередь переполняется; гонка между STOP и обработкой
последней задачи (цикл ждёт `completed == requests`).
**Guarantees**: мастер + пул + polling loop + supervisor работают как единый
рантайм; упавший воркер автоматически заменяется; metrics дают видимость
(requests/completed/crashes/respawns/queue_left); корректный graceful stop.

## Real world

PHP-FPM, RoadRunner, FrankenPHP, Swoole, uWSGI.

> Учебная модель: мастер и воркеры общаются через SysV очередь, а не по
> FastCGI/gRPC. Паттерн (master + persistent worker pool + supervisor) —
> настоящий, транспорт в проде другой.

## Что изучать дальше

Это финал курса. Дальше — углубление: очереди реальных брокеров (RabbitMQ),
горизонтальное масштабирование, обзор `parallel`/`pthreads` в PHP.
