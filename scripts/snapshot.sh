#!/usr/bin/env bash
# Снимок проекта в git — «коммитим всё, что произошло».
#
# Зачем: история проекта = история коммитов. Чтобы следующий исполнитель мог
# восстановить «что делали, где сейчас и куда идём», каждый осмысленный шаг
# должен оказаться в git вместе с обновлёнными docs/.
#
# Использование:
#   bash scripts/snapshot.sh "что сделали этим шагом"
#
# Что делает:
#   1. Инициализирует git-репозиторий, если его ещё нет.
#   2. git add -A  (всё, кроме того что в .gitignore: www/env.php, www/data/)
#   3. Коммитит с твоим сообщением + датой.
#
set -euo pipefail
cd "$(dirname "$0")/.."

MSG="${1:-}"
if [ -z "$MSG" ]; then
  echo "Укажи сообщение: bash scripts/snapshot.sh \"что сделали\""
  exit 1
fi

if [ ! -d .git ]; then
  echo "Первый снимок — инициализирую git…"
  git init -q
  git config user.name  "b24-dev" 2>/dev/null || true
  git config user.email "dev@b24.local" 2>/dev/null || true
fi

git add -A

if git diff --cached --quiet; then
  echo "Изменений нет — снимок не нужен."
  exit 0
fi

DATE="$(date +%Y-%m-%d)"
git commit -q -m "$MSG" -m "snapshot: $DATE"
echo "✓ Снимок сохранён: $MSG"
echo "  Вся история: git log --oneline"
