# 17. Mini Temporal

Temporal-подобный оркестратор: workflow + retry + timeout + signal-cancel.

## Задача

Исполнить workflow по шагам (activities), с надёжностью: упавший шаг
перезапускается (retry), зависший — прерывается по таймауту, а внешний сигнал
может отменить весь workflow.

## Как работает

- **Workflow Engine** (родитель) исполняет Workflow по шагам: шлёт Activity в
  Task Queue и ждёт результат.
- **Activity Worker** (дочерний процесс) берёт задачи из очереди, "исполняет"
  их и отвечает в Result Queue.
- Механики:
  - **retry** — при ошибке шаг запускается заново до `MAX_ATTEMPTS`;
  - **timeout** — если результат не пришёл за лимит, шаг считается упавшим;
  - **signal-cancel** — сигнал останавливает workflow между шагами.

## IPC

Две System V очереди (Task / Result) + сигналы.

## Паттерн

**Durable Workflow Orchestration** (Temporal/Cadence): состояние workflow
внешнее, шаги идемпотентные, всё можно перезапускать.

## Запуск

```bash
docker compose exec -T php php /app/17_mini_temporal/main.php
```

## Что попробовать изменить

- Уменьшить `MAX_ATTEMPTS` — workflow завершится failed.
- Уменьшить таймаут — шаг упадёт по времени, а не по ошибке.
- Послать cancel на середине workflow и посмотреть на историю.

## Real world

Temporal, Cadence, AWS Step Functions, Airflow с retries.

## Что изучать дальше

25 — retry backoff (задержки между попытками); 30 — mini runtime.
