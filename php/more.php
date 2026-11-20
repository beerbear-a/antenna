<?php
// オンシラズアンテナ: 無限スクロール/カテゴリ切替・ブックマーク一覧の追加記事取得エンドポイント(JSON)
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/feed_common.php';

header('Content-Type: application/json; charset=utf-8');

$BASE_DIR = __DIR__;
$NG_WORDS_PATH = $BASE_DIR . '/ng_words.json';
$SOURCES_PATH = $BASE_DIR . '/sources.json';

$user = current_user();
$pdo = get_pdo();

if ($user) {
    $subscribed = get_user_subscriptions($pdo, (int)$user['id']);
} else {
    $subscribed = $pdo->query("SELECT DISTINCT category FROM articles")->fetchAll(PDO::FETCH_COLUMN);
}

$view = isset($_GET['view']) ? (string)$_GET['view'] : '';
$category = (!empty($_GET['category'])) ? (string)$_GET['category'] : null;
if ($category !== null && !in_array($category, $subscribed, true)) {
    $category = null;
}
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$search = $search !== '' ? $search : null;
$offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

$ngWords = load_ng_words($NG_WORDS_PATH);
$domains = load_source_domains($SOURCES_PATH);

if ($view === 'bookmarks') {
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'login_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $page = fetch_bookmarked_page($pdo, (int)$user['id'], ARTICLES_PAGE_SIZE, $offset, $ngWords);
} else {
    $page = fetch_articles_page($pdo, $category, $search, ARTICLES_PAGE_SIZE, $offset, $ngWords, $subscribed);
}

$bookmarkedIds = $user
    ? bookmarked_set($pdo, (int)$user['id'], array_column($page['rows'], 'id'))
    : [];

$html = '';
foreach ($page['rows'] as $a) {
    $html .= render_article_item($a, $domains, (bool)$user, isset($bookmarkedIds[$a['id']]));
}

$emptyMessage = null;
if ($html === '' && $offset === 0) {
    $emptyMessage = $view === 'bookmarks'
        ? 'まだブックマークがありません。'
        : ($search ? '「' . $search . '」に一致する記事がありません。' : '記事がありません。');
}

echo json_encode([
    'html' => $html,
    'has_more' => $page['has_more'],
    'next_offset' => $offset + ARTICLES_PAGE_SIZE,
    'empty_message' => $emptyMessage,
], JSON_UNESCAPED_UNICODE);
