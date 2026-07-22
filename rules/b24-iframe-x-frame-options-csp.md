# Пустой iframe на коробочных серверах клиента

## Проблема

На Netangels и большинстве shared-хостингов веб-сервер **не** ставит
`X-Frame-Options`, поэтому Б24 спокойно встраивает приложение в iframe без
дополнительных действий.

На коробке клиента (свой сервер с Bitrix Env / Apache / nginx) часто стоит
`X-Frame-Options: SAMEORIGIN` глобально для безопасности — и тогда iframe в
Б24 становится пустым, в консоли браузера — «refused to connect»
(или аналогичная ошибка CSP `frame-ancestors`).

## Решение (закомментировано в шаблоне по умолчанию)

В `www/index.php` есть готовый, но закомментированный блок:

```php
// header_remove('X-Frame-Options');
// header("Content-Security-Policy: frame-ancestors "
//      . "https://*.bitrix24.ru https://*.bitrix24.com https://*.bitrix24.kz "
//      . "https://*.bitrix24.by https://*.bitrix24.ua https://*.bitrix24.de "
//      . "https://*.bitrix24.eu https://*.bitrix24.com.br "
//      . "'self'");
```

Раскомментировать при столкновении с этой проблемой. Список TLD Б24
скорректировать, если у клиента кастомный домен на коробке (self-hosted
портал под собственным доменом, не `*.bitrix24.*`).

## На этом VPS (b24.blackboxbegin.space, Caddy)

Не актуально — Caddy по умолчанию не проставляет `X-Frame-Options`. Раздел
актуален только если шаблон когда-нибудь деплоится на инфраструктуру клиента,
а не на этот VPS.
