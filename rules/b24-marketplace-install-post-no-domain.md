# DOMAIN не приходит в install POST

## Проблема

На части порталов (особенно cloud) install POST от Б24 не всегда содержит
`DOMAIN` явным полем. Без домена невозможно собрать `client_endpoint`
(`https://<domain>/rest/`) для последующих REST-вызовов.

## Решение — цепочка резолва

`B24::saveTokensFromInstall()` в `www/api/b24.php`:

1. `DOMAIN` из тела POST — если есть, используем его.
2. Если пусто — берём `domain` из уже сохранённых токенов (повторная установка
   на уже известном портале).
3. Если и там пусто — парсим `HTTP_REFERER` и проверяем через
   `isExternalB24Host()` (`www/api/store.php`), что это **не** self-referer
   (не наш собственный `APP_URL` — см. двухфазный reload в
   [b24-local-app-two-phase-install](b24-local-app-two-phase-install.md), где
   referer тоже может быть self).
4. Если все три источника пусты — `RuntimeException`, установка не может
   продолжиться корректно.

## Почему нельзя просто доверять REFERER всегда

Self-referer возникает штатно при reload после `installFinish()` — если бы
`isExternalB24Host()` не фильтровал свой же `APP_URL`, второй POST (open #2 из
двухфазной установки) мог бы попытаться резолвить домен как `APP_URL`-хост,
что в корне неверно.
