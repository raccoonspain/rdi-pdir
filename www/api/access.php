<?php
declare(strict_types=1);

/**
 * Настройка доступа к приложению для не-администраторов: список ID отделов
 * Б24, чьи сотрудники допускаются в Пульт наравне с админами (см. Admin-gate
 * в session.php, allowedDepartmentIds()). Админ-only эндпоинт.
 *
 * GET  → { departments: [{id,name,parent}], allowed: [id,...] }
 * POST { allowed: [id,...] } → сохраняет список, отдаёт то же, что GET.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/env.php';
require_once APP_ROOT . '/api/store.php';
require_once APP_ROOT . '/api/b24.php';
require_once APP_ROOT . '/api/session.php';

requireAdminSession();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    $raw   = file_get_contents('php://input');
    $body  = json_decode((string)$raw, true);
    $ids   = is_array($body['allowed'] ?? null) ? $body['allowed'] : [];
    $clean = array_values(array_unique(array_map('intval', $ids)));

    $settings = loadSettings();
    $settings['allowedDepartments'] = $clean;
    saveSettings($settings);
}

try {
    $departments = b24()->call('department.get', []);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$list = array_map(function ($d) {
    return [
        'id'     => (int)$d['ID'],
        'name'   => (string)$d['NAME'],
        'parent' => isset($d['PARENT']) ? (int)$d['PARENT'] : null,
    ];
}, $departments['result'] ?? []);

echo json_encode([
    'departments' => $list,
    'allowed'     => allowedDepartmentIds(),
], JSON_UNESCAPED_UNICODE);
