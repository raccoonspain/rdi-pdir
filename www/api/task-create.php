<?php
declare(strict_types=1);

/**
 * Создание задачи по кнопке 🎯 в Пульте руководителя. Всегда привязывается
 * к Сделке (entityTypeId 1050), независимо от того, на каком уровне
 * аккордеона (Сделка/Этап/Модуль) нажата кнопка — см.
 * docs/superpowers/specs/2026-07-14-sozdanie-zadachi-design.md.
 */

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/env.php';
require_once APP_ROOT . '/api/store.php';
require_once APP_ROOT . '/api/b24.php';
require_once APP_ROOT . '/api/session.php';

const TASK_CREATE_DEAL_ENTITY_TYPE_ID = 1050;
const TASK_CREATE_OBJECT_NAME_FIELD   = 'UF_AUTO_824402759720';
const TASK_CREATE_TAG                 = 'Задача из пульта';

/**
 * Привязка задачи к элементу CRM для смарт-процессов: PREFIX = 'T' + hex(entityTypeId).
 * См. /home/deploy/refs/b24-rest-docs/api-reference/crm/data-types.md#crm-binding-format.
 * Для Сделки (entityTypeId=1050): dechex(1050) = '41a' → 'T41a_{id}'.
 */
function taskCreateCrmBinding(int $entityTypeId, int $id): string {
    return 'T' . dechex($entityTypeId) . '_' . $id;
}

$session = requireSession();
header('Content-Type: application/json; charset=utf-8');

if (($session['userId'] ?? null) === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Не удалось определить текущего пользователя Битрикс24 для этой сессии — переоткрой приложение из левого меню.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Невалидный JSON в теле запроса.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$dealId           = (int)($input['entityDealId'] ?? 0);
$title            = trim((string)($input['title'] ?? ''));
$description      = (string)($input['description'] ?? '');
$responsibleId    = (int)($input['responsibleId'] ?? 0);
$deadline         = $input['deadline'] ?? null;
$objectShortName  = trim((string)($input['objectShortName'] ?? ''));

if ($dealId <= 0 || $title === '' || $responsibleId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Не хватает обязательных полей: сделка, название или исполнитель.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fields = [
    'TITLE'          => $title,
    'DESCRIPTION'    => $description,
    'RESPONSIBLE_ID' => $responsibleId,
    'CREATED_BY'     => (int)$session['userId'],
    'UF_CRM_TASK'    => [taskCreateCrmBinding(TASK_CREATE_DEAL_ENTITY_TYPE_ID, $dealId)],
    'TAGS'           => [TASK_CREATE_TAG],
    'ALLOW_CHANGE_DEADLINE' => 'N',
    'TASK_CONTROL'          => 'Y',
];
if ($objectShortName !== '') {
    $fields[TASK_CREATE_OBJECT_NAME_FIELD] = $objectShortName;
}
if (is_string($deadline) && $deadline !== '') {
    $fields['DEADLINE'] = $deadline . 'T23:59:59';
}

try {
    $res = b24()->call('tasks.task.add', ['fields' => $fields]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($res['error'])) {
    http_response_code(502);
    echo json_encode(['error' => 'tasks.task.add: ' . ($res['error_description'] ?? $res['error'])], JSON_UNESCAPED_UNICODE);
    exit;
}

$taskId = $res['result']['task']['id'] ?? null;
if (!$taskId) {
    http_response_code(502);
    echo json_encode(['error' => 'tasks.task.add: ответ без id задачи.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['taskId' => (int)$taskId], JSON_UNESCAPED_UNICODE);
