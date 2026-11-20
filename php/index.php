<?php
// オンシラズアンテナ: PHP版表示ページ（Apache+PHPのCGI実行で動く。常駐プロセス不要）
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';

$user = current_user(); // ログインは必須ではない。ログインしていれば購読設定を反映する

header('Content-Type: text/html; charset=utf-8');

$BASE_DIR = __DIR__;
$NG_WORDS_PATH = $BASE_DIR . '/ng_words.json';
$SOURCES_PATH = $BASE_DIR . '/sources.json';
$MAX_ARTICLES = 300;
$MAX_ARTICLES_SEARCH = 1000;

$CATEGORY_ORDER = ["ニュース", "2ch_VIP", "アニメ・漫画", "ゲーム", "スポーツ", "芸能_YouTube", "生活・おもしろ", "音楽", "アート・映画", "サブカル", "海外カルチャー", "不明"];

function load_ng_words(string $path): array {
    $data = @file_get_contents($path);
    if ($data === false) return [];
    $arr = json_decode($data, true);
    return is_array($arr) ? array_values(array_filter($arr)) : [];
}

function load_source_domains(string $path): array {
    $data = @file_get_contents($path);
    if ($data === false) return [];
    $sources = json_decode($data, true) ?: [];
    $domains = [];
    foreach ($sources as $s) {
        $host = parse_url($s['homepage'] ?? '', PHP_URL_HOST);
        if ($host) $domains[$s['name']] = $host;
    }
    return $domains;
}

function relative_time(?string $isoStr): string {
    if (!$isoStr) return '';
    $ts = strtotime($isoStr);
    if ($ts === false) return '';
    $seconds = time() - $ts;
    if ($seconds < 60) return 'たった今';
    if ($seconds < 3600) return (int)floor($seconds / 60) . '分前';
    if ($seconds < 86400) return (int)floor($seconds / 3600) . '時間前';
    return (int)floor($seconds / 86400) . '日前';
}

function escape_like(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

function get_user_subscriptions(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT category FROM user_category_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function fetch_articles(PDO $pdo, ?string $category, ?string $search, int $maxArticles, int $maxArticlesSearch, array $ngWords, array $subscribed): array {
    if (empty($subscribed)) return [];

    $placeholders = implode(',', array_fill(0, count($subscribed), '?'));
    $clauses = ["category IN ($placeholders)"];
    $params = $subscribed;
    if ($category) { $clauses[] = "category = ?"; $params[] = $category; }
    if ($search) { $clauses[] = "title LIKE ? ESCAPE '\\'"; $params[] = '%' . escape_like($search) . '%'; }
    $sql = "SELECT * FROM articles WHERE " . implode(" AND ", $clauses);
    $sql .= " ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT ?";
    $params[] = $search ? $maxArticlesSearch : $maxArticles;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $title = $row['title'] ?? '';
        $skip = false;
        foreach ($ngWords as $ng) {
            if ($ng !== '' && mb_strpos($title, (string)$ng) !== false) { $skip = true; break; }
        }
        if (!$skip) $result[] = $row;
    }
    return $result;
}

function category_counts(PDO $pdo, array $subscribed): array {
    if (empty($subscribed)) return [];
    $placeholders = implode(',', array_fill(0, count($subscribed), '?'));
    $stmt = $pdo->prepare("SELECT category, COUNT(*) as c FROM articles WHERE category IN ($placeholders) GROUP BY category");
    $stmt->execute($subscribed);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $counts[$r['category']] = (int)$r['c'];
    return $counts;
}

function available_categories(PDO $pdo, array $order, array $subscribed): array {
    $subscribedSet = array_flip($subscribed);
    $rows = $pdo->query("SELECT DISTINCT category FROM articles")->fetchAll(PDO::FETCH_COLUMN);
    $present = array_values(array_filter($rows, fn($c) => isset($subscribedSet[$c])));
    $presentSet = array_flip($present);
    $ordered = array_values(array_filter($order, fn($c) => isset($presentSet[$c])));
    $rest = array_values(array_diff($present, $ordered));
    sort($rest);
    return array_merge($ordered, $rest);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$pdo = get_pdo();

if ($user) {
    $subscribed = get_user_subscriptions($pdo, (int)$user['id']);
} else {
    // 未ログイン時は購読フィルタなし(全カテゴリ対象=従来通りの挙動)
    $subscribed = $pdo->query("SELECT DISTINCT category FROM articles")->fetchAll(PDO::FETCH_COLUMN);
}

$category = (!empty($_GET['category'])) ? (string)$_GET['category'] : null;
if ($category !== null && !in_array($category, $subscribed, true)) {
    $category = null;
}
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$search = $search !== '' ? $search : null;

$ngWords = load_ng_words($NG_WORDS_PATH);
$domains = load_source_domains($SOURCES_PATH);
$categories = available_categories($pdo, $CATEGORY_ORDER, $subscribed);
$counts = category_counts($pdo, $subscribed);
$totalCount = array_sum($counts);
$articles = fetch_articles($pdo, $category, $search, $MAX_ARTICLES, $MAX_ARTICLES_SEARCH, $ngWords, $subscribed);

$qSuffix = $search ? ('&q=' . urlencode($search)) : '';

$navItems = [];
$navItems[] = sprintf(
    '<a href="/?%s" class="%s">すべて <span class="cnt">%d</span></a>',
    $search ? ('q=' . urlencode($search)) : '',
    $category ? '' : 'active',
    $totalCount
);
foreach ($categories as $c) {
    $navItems[] = sprintf(
        '<a href="/?category=%s%s" class="%s">%s <span class="cnt">%d</span></a>',
        urlencode($c), $qSuffix, $c === $category ? 'active' : '', h($c), $counts[$c] ?? 0
    );
}
$navHtml = implode('', $navItems);

$categoryHidden = $category ? sprintf('<input type="hidden" name="category" value="%s">', h($category)) : '';
$clearLink = ($search || $category) ? '<a class="clear" href="/">クリア</a>' : '';

if (empty($articles)) {
    $bodyHtml = $search
        ? '<div class="empty">「' . h($search) . '」に一致する記事がありません。</div>'
        : '<div class="empty">記事がありません。まだ取得が走っていないか、フィルタ対象のカテゴリに記事がありません。</div>';
} else {
    $items = [];
    foreach ($articles as $a) {
        $cat = $a['category'] ?: '不明';
        $domain = $domains[$a['source_name']] ?? '';
        $faviconHtml = $domain
            ? sprintf('<img class="favicon" src="https://%s/favicon.ico" onerror="this.style.visibility=\'hidden\'">', h($domain))
            : '';
        $items[] = sprintf(
            '<li><a class="card" href="%s" target="_blank" rel="noopener"><div class="title">%s</div><div class="meta">%s<span class="cat">%s</span><span class="dot">·</span><span>%s</span><span class="time">%s</span></div></a></li>',
            h($a['link']), h($a['title']), $faviconHtml, h($cat), h($a['source_name']), relative_time($a['published_at'] ?: $a['fetched_at'])
        );
    }
    $bodyHtml = '<ul class="articles">' . implode('', $items) . '</ul>';
}

$count = count($articles);
$queryValue = $search ? h($search) : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>オンシラズアンテナ</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 0;
    font-family: -apple-system, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
    background: #121212; color: #e6e6e3;
    -webkit-font-smoothing: antialiased;
  }
  header {
    position: relative;
    padding: 28px 24px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
  }
  header h1 { margin: 0; font-size: 18px; font-weight: 600; letter-spacing: 0.06em; color: #fff; }
  header .sub { font-size: 11px; color: #666; margin-top: 5px; letter-spacing: 0.03em; }
  .userbar { position: absolute; top: 24px; right: 24px; font-size: 11px; color: #666; }
  .userbar a { color: #888; text-decoration: none; margin-left: 10px; }
  .userbar a:hover { color: #ccc; }
  nav {
    display: flex; flex-wrap: wrap; gap: 20px;
    padding: 14px 24px; border-bottom: 1px solid rgba(255,255,255,0.06);
  }
  nav a {
    color: #777; text-decoration: none; font-size: 12.5px; letter-spacing: 0.02em;
    white-space: nowrap; padding-bottom: 3px; border-bottom: 1px solid transparent;
    transition: color 0.12s ease;
  }
  nav a:hover { color: #ccc; }
  nav a .cnt { color: #444; font-size: 10.5px; margin-left: 2px; }
  nav a.active { color: #fff; border-bottom-color: #d9603b; }
  nav a.active .cnt { color: #d9603b; }
  form.search {
    display: flex; align-items: center; gap: 12px; padding: 14px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
  }
  form.search input[type="text"] {
    flex: 1; max-width: 360px; background: transparent; border: none;
    border-bottom: 1px solid rgba(255,255,255,0.14); color: #eee;
    padding: 5px 1px; font-size: 13px;
  }
  form.search input[type="text"]::placeholder { color: #555; }
  form.search input[type="text"]:focus { outline: none; border-bottom-color: #d9603b; }
  form.search button {
    background: transparent; border: 1px solid rgba(255,255,255,0.18); color: #bbb;
    padding: 6px 16px; font-size: 11.5px; letter-spacing: 0.04em; cursor: pointer;
  }
  form.search button:hover { border-color: #d9603b; color: #d9603b; }
  form.search a.clear { color: #555; font-size: 11px; text-decoration: none; }
  form.search a.clear:hover { color: #999; }
  main { max-width: 700px; margin: 0 auto; padding: 4px 24px 60px; }
  ul.articles { list-style: none; margin: 0; padding: 0; }
  ul.articles li { border-bottom: 1px solid rgba(255,255,255,0.06); }
  ul.articles li:last-child { border-bottom: none; }
  ul.articles a.card {
    display: block; color: inherit; text-decoration: none;
    padding: 18px 2px; transition: background 0.12s ease;
  }
  ul.articles a.card:hover { background: rgba(255,255,255,0.025); }
  ul.articles .title {
    color: #eaeaea; font-size: 14.5px; font-weight: 500;
    margin-bottom: 7px; line-height: 1.55;
  }
  ul.articles a.card:hover .title { color: #e0754f; }
  .meta { font-size: 11px; color: #666; display: flex; align-items: center; gap: 7px; }
  .meta .favicon {
    width: 13px; height: 13px; border-radius: 2px; vertical-align: middle;
    flex-shrink: 0; filter: saturate(0.5) brightness(0.95);
  }
  .meta .cat { color: #888; text-transform: uppercase; letter-spacing: 0.06em; font-size: 10px; }
  .meta .dot { color: #3a3a3a; }
  .meta .time { margin-left: auto; color: #4a4a4a; white-space: nowrap; }
  footer { text-align: center; color: #444; font-size: 11px; padding: 30px 0; letter-spacing: 0.03em; }
  .empty { text-align: center; color: #555; padding: 60px 0; font-size: 13px; }
</style>
</head>
<body>
<header>
  <div class="userbar">
    <?php if ($user): ?>
      <?= h($user['display_name'] ?: $user['username']) ?>
      <a href="/settings.php">設定</a>
      <a href="/logout.php">ログアウト</a>
    <?php else: ?>
      <a href="/login.php">ログイン</a>
    <?php endif; ?>
  </div>
  <h1>オンシラズアンテナ</h1>
  <div class="sub"><?= $count ?>件表示中</div>
</header>
<nav><?= $navHtml ?></nav>
<form class="search" method="get" action="/">
  <?= $categoryHidden ?>
  <input type="text" name="q" value="<?= $queryValue ?>" placeholder="キーワード検索">
  <button type="submit">検索</button>
  <?= $clearLink ?>
</form>
<main>
<?= $bodyHtml ?>
</main>
<footer>オンシラズアンテナ</footer>
</body>
</html>
