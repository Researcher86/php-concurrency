# 15. Workflow Engine

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

**Workflow Engine / Saga-подобная оркестрация** — центральный оркестратор шагов.

## Запуск

```bash
docker compose exec -T php php /app/15_workflow_engine/main.php
```

## Что попробовать изменить

- Добавить шаг, который возвращает ненулевой exit code → workflow станет failed.
- Добавить условный шаг (if) в определение workflow.
- Добавить историю событий в файл/очередь для аудита.

## Real world

Temporal, AWS Step Functions, Airflow (DAG), Symfony Messenger (Middleware).

## Что изучать дальше

17 — mini Temporal (retry/timeout/cancel); 26 — dead letter queue.
