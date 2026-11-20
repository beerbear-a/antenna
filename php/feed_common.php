<?php
// 記事一覧の取得・描画ロジック(index.php / more.php で共有)
declare(strict_types=1);

const ARTICLES_PAGE_SIZE = 25;

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

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function get_user_subscriptions(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT category FROM user_category_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
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

function last_refresh_time(PDO $pdo): ?string {
    $v = $pdo->query("SELECT MAX(fetched_at) FROM articles")->fetchColumn();
    return $v ?: null;
}

function bookmarked_set(PDO $pdo, int $userId, array $articleIds): array {
    if (empty($articleIds)) return [];
    $placeholders = implode(',', array_fill(0, count($articleIds), '?'));
    $stmt = $pdo->prepare("SELECT article_id FROM bookmarks WHERE user_id = ? AND article_id IN ($placeholders)");
    $stmt->execute(array_merge([$userId], $articleIds));
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function toggle_bookmark(PDO $pdo, int $userId, int $articleId): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM bookmarks WHERE user_id = ? AND article_id = ?");
    $stmt->execute([$userId, $articleId]);
    if ($stmt->fetchColumn()) {
        $del = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = ? AND article_id = ?");
        $del->execute([$userId, $articleId]);
        return false;
    }
    $ins = $pdo->prepare("INSERT INTO bookmarks (user_id, article_id, created_at) VALUES (?, ?, ?)");
    $ins->execute([$userId, $articleId, gmdate('c')]);
    return true;
}

// ブックマーク一覧(ブックマークした順)を取得。カテゴリ購読に関わらず全件対象。
function fetch_bookmarked_page(PDO $pdo, int $userId, int $pageSize, int $offset, array $ngWords): array {
    $stmt = $pdo->prepare(
        "SELECT a.* FROM articles a JOIN bookmarks b ON b.article_id = a.id
         WHERE b.user_id = ? ORDER BY b.created_at DESC LIMIT ? OFFSET ?"
    );
    $stmt->execute([$userId, $pageSize + 1, $offset]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $pageSize;
    $rows = array_slice($rows, 0, $pageSize);

    $result = [];
    foreach ($rows as $row) {
        $title = $row['title'] ?? '';
        $skip = false;
        foreach ($ngWords as $ng) {
            if ($ng !== '' && mb_strpos($title, (string)$ng) !== false) { $skip = true; break; }
        }
        if (!$skip) $result[] = $row;
    }
    return ['rows' => $result, 'has_more' => $hasMore];
}

function build_article_where(array $subscribed, ?string $category, ?string $search): array {
    $placeholders = implode(',', array_fill(0, count($subscribed), '?'));
    $clauses = ["category IN ($placeholders)"];
    $params = $subscribed;
    if ($category) { $clauses[] = "category = ?"; $params[] = $category; }
    if ($search) { $clauses[] = "title LIKE ? ESCAPE '\\'"; $params[] = '%' . escape_like($search) . '%'; }
    return ["SELECT * FROM articles WHERE " . implode(" AND ", $clauses), $params];
}

// $pageSize+1件を取得して「次がまだあるか」を判定し、NGワード除外後の$pageSize件を返す
function fetch_articles_page(PDO $pdo, ?string $category, ?string $search, int $pageSize, int $offset, array $ngWords, array $subscribed): array {
    if (empty($subscribed)) return ['rows' => [], 'has_more' => false];

    [$sql, $params] = build_article_where($subscribed, $category, $search);
    $sql .= " ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT ? OFFSET ?";
    $params[] = $pageSize + 1;
    $params[] = $offset;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasMore = count($rows) > $pageSize;
    $rows = array_slice($rows, 0, $pageSize);

    $result = [];
    foreach ($rows as $row) {
        $title = $row['title'] ?? '';
        $skip = false;
        foreach ($ngWords as $ng) {
            if ($ng !== '' && mb_strpos($title, (string)$ng) !== false) { $skip = true; break; }
        }
        if (!$skip) $result[] = $row;
    }
    return ['rows' => $result, 'has_more' => $hasMore];
}

function render_article_item(array $a, array $domains, bool $loggedIn, bool $isBookmarked): string {
    $cat = $a['category'] ?: '不明';
    $domain = $domains[$a['source_name']] ?? '';
    $faviconHtml = $domain
        ? sprintf('<img class="favicon" src="https://%s/favicon.ico" onerror="this.style.visibility=\'hidden\'">', h($domain))
        : '';
    $bookmarkBtn = sprintf(
        '<button type="button" class="bookmark-btn%s" data-article-id="%d" data-logged-in="%s" aria-label="ブックマーク">%s</button>',
        $isBookmarked ? ' active' : '', (int)$a['id'], $loggedIn ? '1' : '0', $isBookmarked ? '★' : '☆'
    );
    return sprintf(
        '<li class="article-item">%s<a class="card" href="%s" target="_blank" rel="noopener"><div class="title">%s</div><div class="meta">%s<span class="cat">%s</span><span class="dot">·</span><span>%s</span><span class="time">%s</span></div></a></li>',
        $bookmarkBtn, h($a['link']), h($a['title']), $faviconHtml, h($cat), h($a['source_name']), relative_time($a['published_at'] ?: $a['fetched_at'])
    );
}
