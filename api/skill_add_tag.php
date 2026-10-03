<?php
session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['permission'] ?? 0) < 2) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Permission denied']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$skillCode = trim($input['skill_code'] ?? '');
$tag = trim($input['tag'] ?? '');

if ($skillCode === '' || $tag === '') {
    echo json_encode(['ok' => false, 'error' => 'Invalid input']);
    exit;
}

// 防重複
$stmt = $db->prepare(
    'SELECT 1 FROM unlight_skill_tag WHERE skill_code = ? AND tag = ?'
);
$stmt->execute([$skillCode, $tag]);
if ($stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => 'Tag already exists']);
    exit;
}

$tag = trim($tag);
$tag = str_replace('：', ':', $tag);
$tag = mb_strtoupper($tag, 'UTF-8');

// 寫入 skill_tag
$stmt = $db->prepare(
    'INSERT IGNORE INTO unlight_skill_tag (skill_code, tag) VALUES (?, ?)'
);
$stmt->execute([$skillCode, $tag]);

// lazy build dictionary
$stmt = $db->prepare(
    'INSERT IGNORE INTO unlight_skill_tag_dictionary (tag) VALUES (?)'
);
$stmt->execute([$tag]);


echo json_encode([
    'ok'  => true,
    'tag' => $tag   // ★ 關鍵就在這
]);
