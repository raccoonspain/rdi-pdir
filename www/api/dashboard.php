<?php
declare(strict_types=1);

/**
 * Эндпоинт пульта руководителя — отдаёт готовое дерево
 * сделка → этапы → модули одним JSON. См. fetchDashboardData()
 * в dashboard-data.php и docs/superpowers/specs/2026-07-13-pult-rukovoditelya-design.md.
 *
 * GET-параметр filter: active (дефолт) / all / closed.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/env.php';
require_once APP_ROOT . '/api/store.php';
require_once APP_ROOT . '/api/b24.php';
require_once APP_ROOT . '/api/session.php';
require_once APP_ROOT . '/api/dashboard-data.php';

requireSession();
header('Content-Type: application/json; charset=utf-8');

$preset = (string)($_GET['filter'] ?? 'active');
if (!in_array($preset, ['active', 'all', 'closed'], true)) $preset = 'active';

try {
    $data = fetchDashboardData(b24(), $preset);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
