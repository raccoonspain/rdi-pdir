<?php
declare(strict_types=1);

/**
 * REST Debug — тестировщик методов Битрикс24 на живом продакшне.
 * Защищён session-gate (требуется активная сессия администратора Б24).
 *
 * Как открыть:
 *   1. Открой основное приложение из левого меню Битрикс24 — сессия установится.
 *   2. Перейди по адресу: https://<host>/<slug>/api/debug.php
 *
 * Удалить когда не нужен:
 *   rm www/api/debug.php  +  bash deploy.sh
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/env.php';
require_once APP_ROOT . '/api/store.php';
require_once APP_ROOT . '/api/b24.php';
require_once APP_ROOT . '/api/session.php';

$method = trim((string)($_POST['method'] ?? ''));

// ── POST: API-вызов ──────────────────────────────────────────────────────────
if ($method !== '') {
    requireSession();
    header('Content-Type: application/json; charset=utf-8');

    $rawParams = (string)($_POST['params'] ?? '{}');
    $params    = json_decode($rawParams ?: '{}', true);
    if (!is_array($params)) {
        http_response_code(400);
        echo json_encode(['error' => 'params: невалидный JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $b24    = b24();
        $result = $b24->call($method, $params);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (RuntimeException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ── GET: HTML-интерфейс ──────────────────────────────────────────────────────
$session = findSessionFromCookie() ?? findSessionFromHeader();
if (!$session) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8">'
       . '<title>Debug — нет сессии</title>'
       . '<style>body{font-family:system-ui,sans-serif;padding:60px 20px;text-align:center;'
       . 'color:#333;max-width:500px;margin:0 auto}'
       . 'h1{color:#b00;margin:0 0 12px}'
       . 'p{color:#555;line-height:1.6;margin:0 0 10px}'
       . 'code{background:#f4f4f4;padding:2px 6px;border-radius:4px;font-size:.9em}'
       . '</style></head><body>'
       . '<h1>403 — нет активной сессии</h1>'
       . '<p>Сначала открой основное приложение из <strong>левого меню Битрикс24</strong> — '
       . 'сессия установится автоматически.</p>'
       . '<p>Потом вернись сюда: '
       . '<code>' . htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? '/api/debug.php'), ENT_QUOTES) . '</code></p>'
       . '</body></html>';
    exit;
}

$b24portal = b24Portal() ?? '—';
$debugUrl  = (string)($_SERVER['REQUEST_URI'] ?? '/api/debug.php');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>REST Debug</title>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; margin: 0; padding: 20px 24px; background: #f0f2f5; color: #222; min-height: 100vh; }
.header { margin-bottom: 20px; }
.header h1 { margin: 0 0 2px; font-size: 1.15rem; font-weight: 700; }
.header .meta { font-size: .8rem; color: #888; }
.card { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 16px; }
label { display: block; font-size: .8rem; font-weight: 600; color: #555; margin-bottom: 5px; letter-spacing: .02em; text-transform: uppercase; }
input[type=text], textarea {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid #ddd; border-radius: 7px;
    font-size: .92rem; font-family: 'JetBrains Mono', 'Fira Code', monospace;
    outline: none; transition: border-color .15s;
}
input[type=text]:focus, textarea:focus { border-color: #4a90e2; }
textarea { height: 110px; resize: vertical; }
.row { margin-bottom: 14px; }
.actions { display: flex; gap: 10px; align-items: center; }
button {
    padding: 9px 22px; background: #2563eb; color: #fff;
    border: none; border-radius: 7px; font-size: .92rem;
    cursor: pointer; font-weight: 600; transition: background .15s;
}
button:hover { background: #1d4ed8; }
button:disabled { background: #93a3b8; cursor: default; }
.hint { font-size: .78rem; color: #aaa; }
.result-box { margin-top: 4px; }
.result-label { font-size: .78rem; color: #888; margin-bottom: 6px; display: flex; gap: 12px; }
.result-label .status-ok  { color: #16a34a; font-weight: 600; }
.result-label .status-err { color: #dc2626; font-weight: 600; }
pre {
    background: #1a1d23; color: #abb2bf;
    padding: 16px; border-radius: 8px;
    overflow: auto; font-size: .8rem; line-height: 1.55;
    max-height: 55vh; margin: 0;
    white-space: pre-wrap; word-break: break-all;
    font-family: 'JetBrains Mono', 'Fira Code', monospace;
}
pre.err { color: #f87171; }
.examples h2 { font-size: .85rem; color: #666; margin: 0 0 10px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; }
.chips { display: flex; flex-wrap: wrap; gap: 7px; }
.chip {
    padding: 5px 13px; background: #f3f4f6;
    border: 1px solid #e5e7eb; border-radius: 20px;
    font-size: .78rem; font-family: monospace;
    cursor: pointer; color: #374151; transition: all .12s;
}
.chip:hover { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
</style>
</head>
<body>

<div class="header">
  <h1>REST Debug</h1>
  <div class="meta">Портал: <?= htmlspecialchars($b24portal, ENT_QUOTES) ?> &nbsp;·&nbsp; Только для администраторов &nbsp;·&nbsp; <kbd>Ctrl+Enter</kbd> — выполнить</div>
</div>

<div class="card">
  <div class="row">
    <label for="method">Метод</label>
    <input type="text" id="method" placeholder="crm.deal.list" autocomplete="off" spellcheck="false">
  </div>
  <div class="row">
    <label for="params">Параметры (JSON)</label>
    <textarea id="params" placeholder='{"filter": {"STAGE_ID": "NEW"}, "select": ["ID", "TITLE"]}'></textarea>
  </div>
  <div class="actions">
    <button id="btn" onclick="doCall()">Выполнить</button>
    <span class="hint">Ctrl+Enter</span>
  </div>
</div>

<div class="card result-box" id="result-wrap" style="display:none">
  <div class="result-label" id="result-meta"></div>
  <pre id="result-pre"></pre>
</div>

<div class="card examples">
  <h2>Быстрые примеры</h2>
  <div class="chips">
    <?php
    $examples = [
        ['app.info',                    '{}'],
        ['user.current',                '{}'],
        ['profile',                     '{}'],
        ['crm.deal.list',               '{"filter":{},"select":["ID","TITLE","STAGE_ID"],"start":0}'],
        ['crm.contact.list',            '{"filter":{},"select":["ID","NAME","LAST_NAME","PHONE"],"start":0}'],
        ['crm.lead.list',               '{"filter":{},"select":["ID","TITLE","STATUS_ID"],"start":0}'],
        ['crm.item.list',               '{"entityTypeId":2,"filter":{},"select":["id","title"],"start":0}'],
        ['crm.status.list',             '{}'],
        ['placement.list',              '{}'],
        ['user.get',                    '{"FILTER":{"ACTIVE":true},"start":0}'],
        ['department.get',              '{}'],
    ];
    foreach ($examples as [$m, $p]) {
        $mEsc = htmlspecialchars($m, ENT_QUOTES);
        $pEsc = htmlspecialchars($p, ENT_QUOTES);
        echo "<span class=\"chip\" onclick=\"setExample('{$mEsc}','{$pEsc}')\">{$mEsc}</span>\n    ";
    }
    ?>
  </div>
</div>

<script>
const debugUrl = <?= json_encode($debugUrl) ?>;

function setExample(method, params) {
    document.getElementById('method').value = method;
    const p = params === '{}' ? '' : JSON.stringify(JSON.parse(params), null, 2);
    document.getElementById('params').value = p;
    document.getElementById('method').focus();
}

async function doCall() {
    const method = document.getElementById('method').value.trim();
    if (!method) { alert('Укажи метод'); return; }

    const rawParams = document.getElementById('params').value.trim();
    let paramsStr = rawParams || '{}';
    try { JSON.parse(paramsStr); } catch(e) { alert('Ошибка JSON в параметрах:\n' + e.message); return; }

    const btn = document.getElementById('btn');
    btn.disabled = true;
    btn.textContent = 'Запрос…';

    const t0 = Date.now();
    try {
        const fd = new FormData();
        fd.append('method', method);
        fd.append('params', paramsStr);
        const resp = await fetch(debugUrl, { method: 'POST', body: fd });
        const text = await resp.text();
        const ms   = Date.now() - t0;

        let pretty = text;
        try { pretty = JSON.stringify(JSON.parse(text), null, 2); } catch(_) {}

        const wrap = document.getElementById('result-wrap');
        const pre  = document.getElementById('result-pre');
        const meta = document.getElementById('result-meta');

        const statusCls = resp.ok ? 'status-ok' : 'status-err';
        meta.innerHTML = `<span class="${statusCls}">HTTP ${resp.status}</span>`
                       + `<span>${method}</span>`
                       + `<span>${ms} ms</span>`;
        pre.className  = resp.ok ? '' : 'err';
        pre.textContent = pretty;
        wrap.style.display = '';
        wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    } catch(e) {
        alert('Ошибка запроса: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Выполнить';
    }
}

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') doCall();
});

document.getElementById('method').focus();
</script>
</body>
</html>
