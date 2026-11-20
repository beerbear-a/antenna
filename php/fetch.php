<?php
// オンシラズアンテナ: PHP版フェッチャー（Sakuraレンタルサーバーのcronで実行する想定）
declare(strict_types=1);

$BASE_DIR = __DIR__;
$SOURCES_PATH = $BASE_DIR . '/sources.json';
$DB_PATH = $BASE_DIR . '/antenna.db';
$USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) OnshirazuAntenna/1.0 (personal use)';
$TIMEOUT = 10;
$CANDIDATE_PATHS = ['index.rdf', 'feed/', 'feed', 'rss/', 'rss', 'atom.xml', '?feed=rss2'];

function log_msg(string $msg): void {
    echo '[' . date('c') . "] $msg\n";
}

function fetch_url(string $url, string $userAgent, int $timeout): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $data = curl_exec($ch);
    if ($data === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($err);
    }
    curl_close($ch);
    return $data;
}

function looks_like_feed(string $data): bool {
    $head = strtolower(substr($data, 0, 500));
    return str_contains($head, '<rss') || str_contains($head, '<rdf:rdf') || str_contains($head, '<feed');
}

function resolve_url(string $base, string $rel): string {
    if (preg_match('#^https?://#i', $rel)) {
        return $rel;
    }
    $parts = parse_url($base);
    if (str_starts_with($rel, '/')) {
        return $parts['scheme'] . '://' . $parts['host'] . $rel;
    }
    return $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($rel, '/');
}

function discover_feed_url(string $homepage, string $userAgent, int $timeout, array $candidatePaths): ?string {
    try {
        $html = fetch_url($homepage, $userAgent, $timeout);
    } catch (Throwable $e) {
        log_msg("  discover: homepage取得失敗 $homepage: " . $e->getMessage());
        $html = null;
    }

    if ($html) {
        if (preg_match('/<link[^>]+type=["\']application\/(?:rss|atom)\+xml["\'][^>]+href=["\']([^"\']+)["\']/i', $html, $m)
            || preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+type=["\']application\/(?:rss|atom)\+xml["\']/i', $html, $m)) {
            return resolve_url($homepage, $m[1]);
        }
    }

    foreach ($candidatePaths as $path) {
        $candidate = resolve_url($homepage, $path);
        try {
            $data = fetch_url($candidate, $userAgent, $timeout);
            if (looks_like_feed($data)) {
                return $candidate;
            }
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

function parse_date(?string $s): ?string {
    if (!$s) {
        return null;
    }
    $ts = strtotime(trim($s));
    if ($ts === false) {
        return null;
    }
    return gmdate('c', $ts);
}

/** RSS2.0 / RSS1.0(RDF) / Atom を雑に解釈して [title, link, published_at] のリストを返す */
function parse_feed(string $data): array {
    $items = [];
    $dom = new DOMDocument();
    $prevErrors = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($data);
    libxml_use_internal_errors($prevErrors);
    if (!$ok || !$dom->documentElement) {
        return $items;
    }

    $xpath = new DOMXPath($dom);
    $rootName = strtolower($dom->documentElement->localName);

    if ($rootName === 'feed') { // Atom
        foreach ($xpath->query('//*[local-name()="entry"]') as $entry) {
            $title = null; $link = null; $pub = null;
            foreach ($entry->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) continue;
                $local = strtolower($child->localName);
                if ($local === 'title') {
                    $title = trim($child->textContent);
                } elseif ($local === 'link') {
                    $href = $child->getAttribute('href');
                    $rel = $child->getAttribute('rel') ?: 'alternate';
                    if ($href && ($link === null || $rel === 'alternate')) {
                        $link = $href;
                    }
                } elseif ($local === 'published' || $local === 'updated') {
                    if ($pub === null || $local === 'published') {
                        $pub = trim($child->textContent);
                    }
                }
            }
            if ($title && $link) {
                $items[] = [$title, $link, parse_date($pub)];
            }
        }
        return $items;
    }

    // RSS 2.0 (<rss><channel><item>) or RSS 1.0 RDF (<rdf:RDF><item>)
    foreach ($xpath->query('//*[local-name()="item"]') as $item) {
        $title = null; $link = null; $pub = null;
        foreach ($item->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            $local = strtolower($child->localName);
            if ($local === 'title') {
                $title = trim($child->textContent);
            } elseif ($local === 'link') {
                $link = trim($child->textContent);
            } elseif (($local === 'date' || $local === 'pubdate') && $pub === null) {
                $pub = trim($child->textContent);
            }
        }
        if ($title && $link) {
            $items[] = [$title, $link, parse_date($pub)];
        }
    }
    return $items;
}

function ensure_db(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS articles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        source_name TEXT,
        category TEXT,
        title TEXT,
        link TEXT UNIQUE,
        published_at TEXT,
        fetched_at TEXT
    )");
}

function main(): void {
    global $SOURCES_PATH, $DB_PATH, $USER_AGENT, $TIMEOUT, $CANDIDATE_PATHS;

    $sources = json_decode(file_get_contents($SOURCES_PATH), true);

    $pdo = new PDO('sqlite:' . $DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensure_db($pdo);

    $nowIso = gmdate('c');
    $sourcesChanged = false;
    $totalNew = 0;

    $insertStmt = $pdo->prepare(
        "INSERT OR IGNORE INTO articles (source_name, category, title, link, published_at, fetched_at) VALUES (?, ?, ?, ?, ?, ?)"
    );

    foreach ($sources as &$src) {
        $name = $src['name'];
        $category = $src['category'] ?? '不明';
        $feedUrl = $src['feed_url'] ?? null;

        if (!$feedUrl) {
            log_msg("$name: feed_url未設定、自動検出を試みます");
            $feedUrl = discover_feed_url($src['homepage'], $USER_AGENT, $TIMEOUT, $CANDIDATE_PATHS);
            if ($feedUrl) {
                log_msg("  -> 発見: $feedUrl");
                $src['feed_url'] = $feedUrl;
                $sourcesChanged = true;
            } else {
                log_msg("  -> 発見できず、スキップ");
                continue;
            }
        }

        try {
            $data = fetch_url($feedUrl, $USER_AGENT, $TIMEOUT);
        } catch (Throwable $e) {
            log_msg("$name: フィード取得失敗 $feedUrl: " . $e->getMessage());
            continue;
        }

        $entries = parse_feed($data);
        $newCount = 0;
        foreach ($entries as [$title, $link, $pub]) {
            $insertStmt->execute([$name, $category, $title, $link, $pub, $nowIso]);
            if ($insertStmt->rowCount() > 0) {
                $newCount++;
            }
        }
        $totalNew += $newCount;
        log_msg("$name: " . count($entries) . "件中 新規{$newCount}件");
    }
    unset($src);

    $pdo->exec("DELETE FROM articles WHERE fetched_at < datetime('now', '-14 days')");

    if ($sourcesChanged) {
        file_put_contents($SOURCES_PATH, json_encode($sources, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        log_msg("sources.json を自動検出結果で更新しました");
    }

    log_msg("完了: 新規{$totalNew}件");
}

main();
