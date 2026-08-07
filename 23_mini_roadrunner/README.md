# 23. Mini RoadRunner

Persistent-воркеры: процесс живёт между запросами и крутит цикл
принять job → обработать → вернуть результат.

## Задача

В классическом PHP процесс умирает после каждого запроса. RoadRunner держит
воркеров живыми — состояние и горячий код переживают запрос.

## Как работает

- Воркеры **персистентны**: живут между запросами, крутят цикл «принять job →
  обработать → вернуть результат» внутри одного процесса.
- Сервер подаёт jobs через relay-канал.
- Упавший воркер **перезапускается** (respawn) — пул восстанавливается.

## IPC

System V очередь/relay-канал jobs; `pcntl_waitpid` (WNOHANG) + respawn;
`MSG_IPC_NOWAIT`.

## Паттерн

**Persistent Worker Pool** + Supervisor (respawn).

## Запуск

```bash
docker compose exec -T php php /app/23_mini_roadrunner/main.php
```

## Что попробовать изменить

- Сымитировать краш воркера на конкретном job'е (как в 30).
- Уменьшить пул до 1 воркера.
- Передавать в job payload и возвращать результат, а не только echo.

## Real world

RoadRunner, FrankenPHP, Swoole, Go+PHP гибриды.

## Что изучать дальше

30 — mini PHP runtime (мастер+пул+event loop+supervisor в одном); 24 —
graceful shutdown.
