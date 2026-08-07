# 16. Mini Messenger

Брокер, маршрутизирующий сообщения по комнатам и в личку.

## Задача

Клиенты общаются через брокера: сообщения в комнаты (broadcast всем
подписчикам комнаты) и личные сообщения (dm конкретному клиенту).

## Как работает

- Клиенты (User A/B/C) имеют собственные inbox'ы — отдельные очереди.
- Message Broker (родитель) маршрутизирует сообщения:
  - комнаты (`general`, `random`) — broadcast каждому подписчику комнаты;
  - direct (`dm`) — доставка только конкретному клиенту.
- Клиенты шлют в `brokerQueue`, брокер рассылает по inbox'ам.

## IPC

System V очереди: `brokerQueue` (входящие) + по одной очереди на клиента
(inbox).

## Паттерн

**Message Broker / Router** поверх mailbox-модели (14).

## Запуск

```bash
docker compose exec -T php php /app/16_mini_messenger/main.php
```

## Что попробовать изменить

- Добавить комнату `offtopic` и подписку.
- Добавить offline-накопление: сообщения остаются в inbox до прочтения.
- Добавить команду "покинуть комнату".

## Real world

Messaging-платформы (Slack, Telegram bot API), WebSocket-брокеры, RabbitMQ
exchanges.

## Что изучать дальше

10 — pub-sub (базовый broadcast); 14 — actor model (inbox).
