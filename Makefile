.PHONY: up down shell build lint smoke debug

# В CI нет терминала (GitHub Actions выставляет CI=true), поэтому exec там
# идёт без TTY; локально TTY остаётся — для цветов и интерактива.
TTY := $(if $(CI),-T,)

# Быстрые уроки для smoke-прогона: каждый завершается сам за секунды
# (без демонов и долгих sleep).
SMOKE_LESSONS := 02_process_lifecycle 03_ipc 33_php_fibers 34_cooperative_concurrency 35_event_loop_fibers 36_async_io

up:
	docker compose up -d

down:
	docker compose down

build:
	docker compose build

shell: up
	docker compose exec $(TTY) php bash

# php -l по всем .php курса; печатает только файлы с ошибками.
lint: up
	docker compose exec -T php sh -c '\
		status=0; \
		for f in $$(find . -name "*.php" -not -path "./vendor/*" -not -path "./.draft/*" -not -path "./.idea/*" | sort); do \
			php -l "$$f" > /tmp/lint.out 2>&1 || { cat /tmp/lint.out; status=1; }; \
		done; \
		[ $$status -eq 0 ] && echo "lint: OK"; \
		exit $$status'

# Запускает SMOKE_LESSONS с таймаутом: зависший урок — ошибка, а не вечное ожидание.
smoke: up
	@for l in $(SMOKE_LESSONS); do \
		echo "=== $$l"; \
		docker compose exec -T php timeout -k 5 60 php $$l/main.php > /dev/null \
			|| { echo "smoke: $$l failed (exit $$?)"; exit 1; }; \
	done; \
	echo "smoke: OK"

# Xdebug в образе стоит в start_with_request=trigger: к отладчику подключается
# только процесс с XDEBUG_TRIGGER. Сначала включи в IDE прослушивание порта
# 9003, иначе попытка подключения просто не удастся и урок пойдёт дальше.
#   make debug LESSON=13_scheduler/main.php
debug: up
	docker compose exec $(TTY) php bash -c "XDEBUG_TRIGGER=1 php $(LESSON)"
