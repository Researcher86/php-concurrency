# 02. Process Lifecycle

Жизненный цикл процесса: от `fork()` до `waitpid()`. Фундамент для понимания
supervisor'ов, пулов воркеров и вообще всего курса.

## Задача

После `pcntl_fork()` процесс живёт, завершается `exit()`, но не исчезает
сразу: его "тень" (zombie) висит в таблице процессов, пока родитель не
вызовет `waitpid()`. Урок показывает этот цикл и связанные понятия:
зомби, сироты, SIGCHLD, init/PID 1.

## Как работает

Три мини-демо в одном `main.php`:

- **Демо 1 — нормальный lifecycle**: ребёнок работает и делает `exit(3)`.
  Родитель регистрирует обработчик `SIGCHLD` (ядро шлёт его, когда ребёнок
  умирает), блокирующе ждёт через `pcntl_waitpid` и достаёт код выхода
  `pcntl_wexitstatus`.
- **Демо 2 — zombie**: ребёнок умирает мгновенно, родитель НЕ репит полсекунды.
  В этот момент ребёнок — зомби (в `ps -o stat` у него статус `Z`).
  Затем `pcntl_waitpid` снимает запись.
- **Демо 3 — reap-all**: три ребёнка умирают с разными задержками, родитель
  собирает всех через цикл `pcntl_waitpid(-1, ..., WNOHANG)` — тот самый
  паттерн, который используют supervisor'ы (04).

**Понятия из урока**: `zombie` (ребёнок умер, но не отреплен), `orphan`
(родитель умер раньше ребёнка — ребёнка «усыновляет» init/PID 1, в
контейнерах — tini; именно поэтому в реальном проде PID 1 обязан уметь
репить сирот).

## IPC

Сигнал `SIGCHLD` (`pcntl_signal` + `pcntl_async_signals(true)`), `pcntl_waitpid`,
`pcntl_wexitstatus`, `posix_getppid`.

## Паттерн

**Process Lifecycle Management** — управление рождением и смертью дочерних
процессов. Правило курса: каждый `fork()` балансируется `waitpid()`.

## Запуск

```bash
docker compose exec -T php php /app/02_process_lifecycle/main.php
```

## Что попробовать изменить

- В демо 2 убрать финальный `pcntl_waitpid` и запустить урок: в контейнере
  появится зомби (проверьте `ps -eo stat,cmd`).
- В демо 1 сделать `exit(0)` вместо `exit(3)` — `pcntl_wexitstatus` вернёт 0.
- Убрать `pcntl_async_signals(true)` — SIGCHLD-обработчик может не сработать.

## Complexity / Failure modes / Guarantees

**Complexity**: ⭐ — несколько понятий, но каждое объяснимо за минуту.
**Failure modes**: забытый `waitpid` → зомби; родитель умер раньше ребёнка →
сирота (репит PID 1; если PID 1 не reaper, зомби остаётся навсегда).
**Guarantees**: ядро шлёт `SIGCHLD` при смерти ребёнка; `waitpid` снимает
запись и возвращает код выхода; правило курса — каждый `fork()` балансируется
`waitpid()`.

## Real world (conceptual analogues)

Supervisor (PM2, systemd), PHP-FPM master, kubelet — все они именно так
отслеживают и перезапускают процессы: `waitpid(..., WNOHANG)` + respawn.

## Что изучать дальше

04 — Supervisor (мониторинг lifecycle через WNOHANG); 06 — Worker Pool.
