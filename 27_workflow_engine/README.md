# 27. Workflow Engine

Движок, исполняющий workflow по шагам-activity в дочерних процессах.

## Задача

Описать многошаговый процесс (workflow) и выполнить его: шаги идут по порядку,
каждый шаг — отдельный activity, результат проверяется, история ведётся.

## Как работает

- **Workflow Definition** — описание шагов: Start → Step1 → Step2 → End.
- Каждый шаг выполняется как **Activity** в отдельном дочернем процессе.
- Движок (родитель) оркестрирует: запускает шаги по очереди, проверяет
  результат (exit code) и ведёт **History** событий.
- Состояния workflow: `running → completed | failed` (paused — не реализовано).

## IPC

System V очередь для передачи шагов; exit-код дочернего процесса как результат
activity; `pcntl_waitpid` для получения статуса.

## Паттерн

**Workflow Engine (оркестрация)** — центральный оркестратор выполняет шаги
по порядку (activity orchestration). Это оркестрация, а не saga: у павшего
шага нет компенсирующих действий.

## Запуск

```bash
docker compose exec -T php php /app/27_workflow_engine/main.php
```

## Что попробовать изменить

- Добавить шаг, который возвращает ненулевой exit code → workflow станет failed.
- Добавить условный шаг (if) в определение workflow.
- Добавить историю событий в файл/очередь для аудита.

## Complexity / Failure modes / Guarantees

**Complexity**: ⭐⭐⭐⭐ — оркестрация шагов, состояние workflow.
**Failure modes**: шаг падает (ненулевой exit) → workflow failed; состояние
workflow не персистентно → при крахе оркестратора всё теряется; шаг завис →
workflow висит без таймаута.
**Guarantees**: центральный оркестратор управляет порядком шагов; явные
состояния workflow (pending/running/failed/done); при отказе шага — переход в
failed (без компенсаций — в отличие от saga) или обработка ошибки.

## Real world

Temporal, AWS Step Functions, Airflow (DAG), Symfony Messenger (Middleware).

## Что изучать дальше

29 — mini Temporal (retry/timeout/cancel); 25 — dead letter queue.
