<?php
// オンシラズアンテナ: ブックマークのトグル(要ログイン)
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/feed_common.php';

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'login_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$articleId = isset($_POST['article_id']) ? (int)$_POST['article_id'] : 0;
if ($articleId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_article_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = get_pdo();
$bookmarked = toggle_bookmark($pdo, (int)$user['id'], $articleId);

echo json_encode(['bookmarked' => $bookmarked], JSON_UNESCAPED_UNICODE);
