#!/usr/bin/env bash
# Деплой single-tenant B24 local-app.
# Синхронизирует www/ → deploy@45.91.55.178:/var/www/b24/<slug>/
# data/, init.php — не трогает (стор; init.php — bootstrap для 1%-кейса
# другого хостинга, см. how-to-link-ALL.md).
#
# Slug = имя папки проекта. При первом деплое (env.php на сервере ещё нет)
# скрипт сам генерирует env.php из www/env.example: APP_URL/APP_PATH/DATA_ROOT
# детерминированы этим конвентом (VPS rub24 + Caddy, домен фиксирован
# rub24.blackboxbegin.space — см. D-005 в docs/decisions.md).
# Остаётся вписать B24_CLIENT_ID/SECRET после регистрации приложения в Б24 —
# см. how-to-link.md.

# Настройки безопасности скрипта:
# `-e` — остановиться при первой же ошибке (ненулевой код возврата команды)
# `-u` — падать, если используется необъявленная переменная
# `-o  pipefail` — если в конвейере (cmd1 | cmd2) упала любая команда, весь конвейер считается упавшим
set -euo pipefail

# Переходит в директорию, где лежит сам скрипт ($0)
# чтобы дальнейшие относительные пути (www/) работали независимо от того, откуда скрипт запустили.
cd "$(dirname "$0")"

# Берёт имя текущей папки (после cd это папка проекта) — это и есть slug
SLUG="$(basename "$(pwd)")"

# Safety: slug должен быть простым идентификатором.
if ! [[ "$SLUG" =~ ^[a-zA-Z0-9_-]+$ ]]; then
  echo "Ошибка: slug '${SLUG}' содержит недопустимые символы. Только [a-zA-Z0-9_-]." >&2
  exit 1
fi

# Приложение живёт на отдельном VPS (rub24), не на этой машине — деплой идёт по SSH.
REMOTE_HOST="deploy@45.91.55.178"
REMOTE_DOMAIN="rub24.blackboxbegin.space"
SSH_KEY="/home/deploy/.ssh/id_ed25519_rub24"
SSH_OPTS=(-i "$SSH_KEY" -o BatchMode=yes)

DEPLOY_DIR="/var/www/b24/${SLUG}"
ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "mkdir -p '$DEPLOY_DIR'"

# Синхронизирует папку www/ в DEPLOY_DIR на удалённом сервере:
# `-a` (archive) — сохранять права, симлинки, время модификации, рекурсивно
# `-v` — подробный вывод
# `-z` — сжатие при передаче
# `--delete` — удалять на сервере файлы, которых больше нет в www/ (чтобы не копились мусорные старые файлы)
# `--exclude` — не трогать env.php (прод-конфиг — генерируется отдельно ниже, если его ещё нет),
#               data/ (стор с рантайм-данными), init.php (bootstrap для 1%-кейса другого хостинга), .git/, файлы *.example
rsync -avz --delete \
  --exclude 'env.php' \
  --exclude 'data/' \
  --exclude 'init.php' \
  --exclude '.git/' \
  --exclude '*.example' \
  -e "ssh ${SSH_OPTS[*]}" \
  www/ "${REMOTE_HOST}:${DEPLOY_DIR}/"

# Первый деплой этого slug'а: env.php на сервере ещё нет.
# APP_URL/APP_PATH/DATA_ROOT детерминированы конвентом (домен фиксирован,
# путь = DEPLOY_DIR) — генерируем env.php из env.example локально и заливаем.
# B24_CLIENT_ID/SECRET оставляем как в env.example — их можно узнать только
# из карточки local-app в Б24, вписываются вручную после регистрации.
if ! ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "test -f '$DEPLOY_DIR/env.php'"; then
  APP_URL="https://${REMOTE_DOMAIN}/${SLUG}"
  TMP_ENV="$(mktemp)"
  sed -E \
    -e "s#(define\\('APP_URL',[[:space:]]*)''#\\1'${APP_URL}'#" \
    -e "s#(define\\('APP_PATH',[[:space:]]*)''#\\1'${DEPLOY_DIR}'#" \
    -e "s#(define\\('DATA_ROOT',[[:space:]]*)''#\\1'${DEPLOY_DIR}/data'#" \
    www/env.example > "$TMP_ENV"
  scp "${SSH_OPTS[@]}" "$TMP_ENV" "${REMOTE_HOST}:${DEPLOY_DIR}/env.php"
  rm -f "$TMP_ENV"
  ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "chmod 0644 '$DEPLOY_DIR/env.php'"
  ENV_JUST_CREATED=1
else
  ENV_JUST_CREATED=0
fi

# data/ должна быть доступна для записи PHP-FPM (www-data).
# Содержимое защищено <?php exit;?>, не правами ФС.
ssh "${SSH_OPTS[@]}" "$REMOTE_HOST" "mkdir -p '$DEPLOY_DIR/data' && chmod 0777 '$DEPLOY_DIR/data'"

echo "✓ Деплой завершён: https://${REMOTE_DOMAIN}/${SLUG}/"
if [ "$ENV_JUST_CREATED" = "1" ]; then
  echo "  env.php создан автоматически (APP_URL/APP_PATH/DATA_ROOT уже верные)."
  echo "  Осталось: зарегистрировать local-app в Б24 (см. how-to-link.md) и"
  echo "  вписать B24_CLIENT_ID/B24_CLIENT_SECRET в ${DEPLOY_DIR}/env.php"
fi
