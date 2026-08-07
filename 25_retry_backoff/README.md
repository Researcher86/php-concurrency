# 25. Retry + Exponential Backoff

Повторные попытки с растущей задержкой — чтобы не долбить упавший сервис.

## Задача

Вызов внешнего сервиса упал. Вместо мгновенных повторных попыток — ждать
всё дольше: 100 → 200 → 400 → 800 мс. Так упавший сервис получает время
восстановиться, а наша система не создаёт лавину запросов.

## Как работает

- Задача выполняется в дочернем процессе (worker), результат — через очередь.
- Первые 2 попытки падают (имитация перегруженного сервиса).
- Родитель между попытками ждёт `BASE_DELAY_MS * 2^(attempt-1)` —
  экспоненциальный рост.
- После `MAX_ATTEMPTS` — сдаёмся (give up).

## IPC

Две System V очереди (task / result) — request-reply как в 19_rpc.

## Паттерн

**Exponential Backoff + Retry** — база всех надёжных клиентов.

## Запуск

```bash
docker compose exec -T php php /app/25_retry_backoff/main.php
```

## Что попробовать изменить

- Добавить jitter: `$delay * (0.5 + mt_rand()/getrandmax()*0.5)` — защита от
  thundering herd.
- Сделать сервис всегда падающим — увидеть give up после MAX_ATTEMPTS.
- Уменьшить `MAX_ATTEMPTS` до 2 — успех не наступит.

## Real world

AWS SDK (retry modes), RabbitMQ retry policies, Stripe, Temporal retries,
gRPC/HTTP client backoff.

## Что изучать дальше

26 — dead letter queue (куда уходит после give up); 17 — mini Temporal (retry
в workflow).
