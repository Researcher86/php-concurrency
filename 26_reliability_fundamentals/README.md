# 26. Reliability Fundamentals

Четыре базовые темы надёжности конкурентных систем: **Timeout**, **Cancellation**,
**Delivery Semantics** и **Idempotency**. Это не отдельные паттерны, а
фундаментальные проблемы, которые в реальных системах встречаются вместе.

## Задача

Конкурентная система может: зависнуть, получить кучу работы, которую некому
доделать, потерять или продублировать задачу. Урок разбирает четыре базовых
механизма защиты.

## Как работает

Один `main.php` с четырьмя секциями:

1. **Timeout** — родитель ждёт результат с дедлайном (200мс), медленный
   воркер отвечает за 500мс. По таймауту задача считается failed; поздний
   ответ, пришедший после таймаута, игнорируется.
2. **Cancellation** — два способа остановить воркера:
   - *cooperative*: SIGTERM только ставит флаг, воркер сам выходит из цикла —
     можно сделать cleanup;
   - *forced*: `SIGKILL` — мгновенная смерть, не перехватить, cleanup невозможен.
3. **Delivery Semantics** — два сценария:
   - *at-most-once*: воркер крашится при обработке → задача потеряна навсегда;
   - *at-least-once*: воркер крашится во время обработки, **до отправки ACK** →
     мастер не получает подтверждения → переотправляет по visibility timeout →
     задача выполнится дважды (двойной эффект — цена at-least-once).
4. **Idempotency** — задача `#42` доставлена дважды (из-за at-least-once),
   но `idempotency_key` в shared memory даёт один эффект. `check + mark`
   защищены семафором: два воркера, конкурирующие за один ключ, применят
   эффект ровно один раз (демо гонки на `#44`).

## IPC

System V очереди (обмен + ACK), сигналы SIGTERM/SIGKILL, shared memory
(словарь обработанных ключей + счётчик эффектов), семафор (атомарность
check+mark).

## Паттерн

**Timeout / Cooperative vs Forced Cancellation / At-most-once vs At-least-once
/ Idempotency** — фундамент надёжности поверх любых паттернов.

## Запуск

```bash
docker compose exec -T php php /app/26_reliability_fundamentals/main.php
```

## Что попробовать изменить

- Сократить дедлайн в секции 1 — таймаут наступит раньше.
- Секция 3: сделать воркер, который не крашится — дубля не будет.
- Секция 4: убрать `sem_acquire`/`sem_release` вокруг check+mark — при
  конкуренции двух воркеров эффект может примениться дважды.
- Объединить: timeout → cancel → retry — полный цикл восстановления.

## Complexity / Failure modes / Guarantees

**Complexity**: ⭐⭐⭐ — четыре темы, каждая — самостоятельная дисциплина.
**Failure modes**: timeout (зависший сервис), cancellation (застрявший или
некооперативный воркер), delivery (потеря задачи при at-most-once или дубль
при at-least-once), повтор без идемпотентности (двойной эффект).
**Guarantees**: timeout — не ждать вечно; cooperative cancel — чистый выход,
forced (SIGKILL) — мгновенный без cleanup; at-least-once + idempotency
(= один эффект на ключ) даёт «эффективно один раз»; поздние ответы после
таймаута игнорируются.

## Real world (conceptual analogues)

HTTP client timeouts, Kubernetes `terminationGracePeriodSeconds`, RabbitMQ
manual-ack, SQS visibility timeout, Stripe idempotency keys.

## Что изучать дальше

21 — Graceful Shutdown (cancellation в масштабе пула); 22 — Retry Backoff
(что делать после таймаута).
