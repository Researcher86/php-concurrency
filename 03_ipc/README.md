# 03. IPC — способы обмена данными между процессами

Обзор всех механизмов IPC, которые используются дальше в курсе.
Шесть маленьких файлов, каждый — один механизм.

## Задача

Показать каждый примитив IPC на минимальном примере: как создать, как
передать данные, как почистить.

## Как работает

В папке 6 независимых файлов:

| Файл | Механизм | Суть |
|------|----------|------|
| `msg_queue.php` | System V очередь | сообщения в ядре, не привязаны к процессу-создателю |
| `pipe.php` | Unix socket pair | пара соединённых двунаправленных сокетов |
| `semaphore.php` | System V семафор | атомарный доступ к критической секции |
| `shmop.php` | Shared memory | общий сегмент памяти для всех процессов |
| `signals.php` | Сигналы | только событие, без данных (`posix_kill`) |
| `unix_socket.php` | AF_UNIX сокет | файл в /tmp как точка входа в канал |

## IPC

- `msg_queue`: `msg_get_queue` + `ftok`, `msg_send`, `msg_receive`,
  `msg_remove_queue`. Это **System V** очереди; POSIX (`mq_open`/`mq_send`) в PHP
  нет встроенной поддержки.
- `pipe.php`: `stream_socket_pair` — это **не** классический `pipe()`: socket pair
  двунаправленный, а `pipe()` однонаправленный (read-конец + write-конец).
- `semaphore`: `sem_get`, `sem_acquire`, `sem_release`, `sem_remove`.
- `shmop`: `shm_attach`, `shm_put_var`, `shm_get_var`, `shm_remove`.
- `signals`: `pcntl_signal`, `pcntl_async_signals`, `posix_kill`.
- `unix_socket`: `stream_socket_server`/`stream_socket_client` на `unix://` пути.

## Паттерн

Каталог примитивов. Дальше каждый урок комбинирует 1–3 из них.

## Запуск

```bash
for f in msg_queue pipe semaphore shmop signals unix_socket; do
  docker compose exec -T php php /app/03_ipc/$f.php
done
```

## Что попробовать изменить

- В `semaphore.php` убрать `sem_acquire`/`sem_release` и убедиться, что счётчик
  перестаёт быть 15 (гонка).
- В `shmop.php` поменять местами читателя и писателя — читатель увидит пустоту.
- В `msg_queue.php` не вызывать `msg_remove_queue` и проверить `ipcs -q`.

## Complexity / Failure modes / Guarantees

**Complexity**: ⭐⭐ — много примитивов, у каждого своя модель.
**Failure modes**: ресурсы IPC (очереди/shm) живут дольше процесса — без
очистки накапливаются (`ipcs -q`); гонки при записи в shm без семафора;
`msg_send` на переполненную очередь блокируется или падает.
**Guarantees**: очередь — упорядоченные сообщения, переживают процесс; pipe —
поток «1 писатель → 1 читатель», требует оба конца; семафор — атомарный
счётчик; shm — общая память без встроенной синхронизации.

## Real world

Очереди — очереди задач (RabbitMQ, SQS); shared memory — кэши и счётчики;
сокеты — межпроцессные соединения веб-серверов.

## Что изучать дальше

06 — первый паттерн (Worker Pool) на System V очередях.
