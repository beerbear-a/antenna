<?php
// オンシラズアンテナ: PHP版表示ページ（Apache+PHPのCGI実行で動く。常駐プロセス不要）
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';
require_once __DIR__ . '/feed_common.php';

$user = current_user(); // ログインは必須ではない。ログインしていれば購読設定・ブックマークを反映する

header('Content-Type: text/html; charset=utf-8');

$BASE_DIR = __DIR__;
$NG_WORDS_PATH = $BASE_DIR . '/ng_words.json';
$SOURCES_PATH = $BASE_DIR . '/sources.json';

$CATEGORY_ORDER = ["ニュース", "2ch_VIP", "アニメ・漫画", "ゲーム", "スポーツ", "芸能_YouTube", "生活・おもしろ", "音楽", "アート・映画", "サブカル", "海外カルチャー", "不明"];

$pdo = get_pdo();

if ($user) {
    $subscribed = get_user_subscriptions($pdo, (int)$user['id']);
} else {
    // 未ログイン時は購読フィルタなし(全カテゴリ対象=従来通りの挙動)
    $subscribed = $pdo->query("SELECT DISTINCT category FROM articles")->fetchAll(PDO::FETCH_COLUMN);
}

$view = (isset($_GET['view']) && $_GET['view'] === 'bookmarks' && $user) ? 'bookmarks' : 'feed';

$category = (!empty($_GET['category'])) ? (string)$_GET['category'] : null;
if ($category !== null && !in_array($category, $subscribed, true)) {
    $category = null;
}
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$search = $search !== '' ? $search : null;

$ngWords = load_ng_words($NG_WORDS_PATH);
$domains = load_source_domains($SOURCES_PATH);
$categories = available_categories($pdo, $CATEGORY_ORDER, $subscribed);

if ($view === 'bookmarks') {
    $page = fetch_bookmarked_page($pdo, (int)$user['id'], ARTICLES_PAGE_SIZE, 0, $ngWords);
} else {
    $page = fetch_articles_page($pdo, $category, $search, ARTICLES_PAGE_SIZE, 0, $ngWords, $subscribed);
}
$articles = $page['rows'];
$refreshedAt = last_refresh_time($pdo);

$bookmarkedIds = $user ? bookmarked_set($pdo, (int)$user['id'], array_column($articles, 'id')) : [];

$qSuffix = $search ? ('&q=' . urlencode($search)) : '';

$navItems = [];
if ($user) {
    $navItems[] = sprintf(
        '<a href="/?view=bookmarks" data-view="bookmarks" class="bookmarks-pill%s">★ ブックマーク</a>',
        $view === 'bookmarks' ? ' active' : ''
    );
}
foreach ($categories as $c) {
    $navItems[] = sprintf(
        '<a href="/?category=%s%s" data-category="%s" class="%s">%s</a>',
        urlencode($c), $qSuffix, h($c), ($view === 'feed' && $c === $category) ? 'active' : '', h($c)
    );
}
$navHtml = implode('', $navItems);

$categoryHidden = $category ? sprintf('<input type="hidden" name="category" value="%s">', h($category)) : '';
$clearLink = ($search || $category || $view === 'bookmarks') ? '<a class="clear" href="/">クリア</a>' : '';

if (empty($articles)) {
    $itemsHtml = '';
    $emptyMessage = $view === 'bookmarks'
        ? 'まだブックマークがありません。'
        : ($search ? '「' . h($search) . '」に一致する記事がありません。' : '記事がありません。まだ取得が走っていないか、フィルタ対象のカテゴリに記事がありません。');
} else {
    $items = [];
    foreach ($articles as $a) {
        $items[] = render_article_item($a, $domains, (bool)$user, isset($bookmarkedIds[$a['id']]));
    }
    $itemsHtml = implode('', $items);
    $emptyMessage = null;
}

$categoryParam = h($category ?? '');
$searchParam = h($search ?? '');
$categoriesJson = json_encode(array_values($categories), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>オンシラズアンテナ</title>
<style>
  * { box-sizing: border-box; }
  html {
    scrollbar-color: rgba(255,255,255,0.16) #15151a;
    scrollbar-width: thin;
  }
  ::-webkit-scrollbar { width: 10px; height: 10px; }
  ::-webkit-scrollbar-track { background: #15151a; }
  ::-webkit-scrollbar-thumb {
    background-color: rgba(255,255,255,0.14);
    border-radius: 6px;
    border: 2px solid #15151a;
    background-clip: padding-box;
  }
  ::-webkit-scrollbar-thumb:hover { background-color: rgba(255,255,255,0.24); }
  body {
    margin: 0; padding: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
    background: #15151a; color: #e7e7ea;
    -webkit-font-smoothing: antialiased;
  }
  a { color: inherit; }
  header {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 24px;
    background: rgba(21,21,26,0.82);
    backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
    border-bottom: 1px solid rgba(255,255,255,0.06);
  }
  header .brand { display: flex; align-items: baseline; gap: 10px; min-width: 0; cursor: pointer; }
  header h1 {
    margin: 0; font-size: 13px; font-weight: 600; letter-spacing: 0.12em;
    color: #9a9aa3; text-transform: uppercase;
  }
  header .refresh { font-size: 11px; color: #52525c; white-space: nowrap; }
  .userbar { font-size: 12px; color: #6a6a73; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
  .userbar a { text-decoration: none; color: #8a8a93; }
  .userbar a:hover { color: #e7e7ea; }
  nav {
    display: flex; flex-wrap: wrap; gap: 8px;
    padding: 14px 24px 4px; max-width: 720px; margin: 0 auto;
  }
  nav a {
    color: #9a9aa3; text-decoration: none; font-size: 12.5px; letter-spacing: 0.01em;
    white-space: nowrap; padding: 6px 13px; border-radius: 999px;
    background: rgba(255,255,255,0.045);
    border: 1px solid rgba(255,255,255,0.05);
    transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
  }
  nav a:hover { background: rgba(255,255,255,0.08); color: #e7e7ea; }
  nav a.active { color: #fff; background: #d9603b; border-color: #d9603b; }
  nav a.bookmarks-pill { color: #d9a23b; border-color: rgba(217,162,59,0.25); }
  nav a.bookmarks-pill.active { color: #fff; background: #d9a23b; border-color: #d9a23b; }
  form.search {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 24px 16px; max-width: 720px; margin: 0 auto;
  }
  form.search input[type="text"] {
    flex: 1; max-width: 360px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.07);
    border-radius: 10px; color: #eee;
    padding: 9px 13px; font-size: 13px;
  }
  form.search input[type="text"]::placeholder { color: #5c5c66; }
  form.search input[type="text"]:focus { outline: none; border-color: #d9603b; }
  form.search button {
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.08); color: #c7c7ce;
    border-radius: 10px;
    padding: 9px 18px; font-size: 12.5px; letter-spacing: 0.02em; cursor: pointer;
  }
  form.search button:hover { border-color: #d9603b; color: #d9603b; }
  form.search a.clear { color: #5c5c66; font-size: 12px; text-decoration: none; }
  form.search a.clear:hover { color: #9a9aa3; }
  main { max-width: 720px; margin: 0 auto; padding: 4px 16px 80px; touch-action: pan-y; }
  ul.articles {
    list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px;
    transition: opacity 0.12s ease;
  }
  ul.articles.transitioning { opacity: 0.4; }
  ul.articles li { margin: 0; position: relative; }
  ul.articles a.card {
    display: block; text-decoration: none;
    padding: 16px 18px 16px 44px; border-radius: 14px;
    background: rgba(255,255,255,0.035);
    border: 1px solid rgba(255,255,255,0.045);
    transition: background 0.15s ease, border-color 0.15s ease, transform 0.15s ease;
  }
  ul.articles a.card:hover {
    background: rgba(255,255,255,0.065); border-color: rgba(255,255,255,0.09);
    transform: translateY(-1px);
  }
  ul.articles .title {
    color: #eeeef0; font-size: 14.5px; font-weight: 500;
    margin-bottom: 9px; line-height: 1.55;
  }
  ul.articles a.card:hover .title { color: #e98a62; }
  .meta { font-size: 11px; color: #6a6a73; display: flex; align-items: center; gap: 7px; }
  .meta .favicon {
    width: 13px; height: 13px; border-radius: 3px; vertical-align: middle;
    flex-shrink: 0; filter: saturate(0.5) brightness(0.95);
  }
  .meta .cat {
    color: #c48a6f; background: rgba(217,96,59,0.14);
    padding: 2px 8px; border-radius: 999px;
    text-transform: uppercase; letter-spacing: 0.05em; font-size: 9.5px;
  }
  .meta .dot { color: #3a3a40; }
  .meta .time { margin-left: auto; color: #52525c; white-space: nowrap; }
  .bookmark-btn {
    position: absolute; top: 14px; left: 10px; z-index: 1;
    width: 26px; height: 26px; border: none; background: transparent; cursor: pointer;
    color: #4a4a52; font-size: 16px; line-height: 1; border-radius: 50%;
    transition: color 0.15s ease, transform 0.1s ease;
  }
  .bookmark-btn:hover { color: #d9a23b; transform: scale(1.12); }
  .bookmark-btn.active { color: #d9a23b; }
  #scroll-sentinel { height: 1px; }
  .loading-indicator {
    text-align: center; color: #52525c; font-size: 12px; padding: 24px 0;
  }
  footer { text-align: center; color: #44444c; font-size: 11px; padding: 10px 0 36px; letter-spacing: 0.03em; }
  .empty { text-align: center; color: #5c5c66; padding: 60px 0; font-size: 13px; }
  @media (max-width: 480px) {
    header { padding: 12px 16px; }
    nav, form.search { padding-left: 16px; padding-right: 16px; }
  }
</style>
</head>
<body>
<header>
  <div class="brand" id="brand-reset">
    <h1>オンシラズアンテナ</h1>
    <?php if ($refreshedAt): ?><div class="refresh">更新 <?= h(relative_time($refreshedAt)) ?></div><?php endif; ?>
  </div>
  <div class="userbar">
    <?php if ($user): ?>
      <?= h($user['display_name'] ?: $user['username']) ?>
      <a href="/settings.php">設定</a>
      <a href="/logout.php">ログアウト</a>
    <?php else: ?>
      <a href="/login.php">ログイン</a>
    <?php endif; ?>
  </div>
</header>
<nav id="category-nav"><?= $navHtml ?></nav>
<form class="search" method="get" action="/" id="search-form">
  <?= $categoryHidden ?>
  <input type="text" name="q" value="<?= h($search ?? '') ?>" placeholder="キーワード検索">
  <button type="submit">検索</button>
  <?= $clearLink ?>
</form>
<main id="main-content">
<ul class="articles" id="article-list"><?= $itemsHtml ?></ul>
<div class="empty" id="empty-state" style="<?= $emptyMessage === null ? 'display:none;' : '' ?>"><?= $emptyMessage ?? '' ?></div>
<div id="scroll-sentinel"
     data-offset="<?= ARTICLES_PAGE_SIZE ?>"
     data-has-more="<?= $page['has_more'] ? '1' : '0' ?>"
     data-category="<?= $categoryParam ?>"
     data-q="<?= $searchParam ?>"
     data-view="<?= $view ?>"></div>
<div class="loading-indicator" id="loading-indicator" style="display:none;">読み込み中…</div>
</main>
<footer>オンシラズアンテナ</footer>
<script id="categories-data" type="application/json"><?= $categoriesJson ?></script>
<script>
(function () {
  var sentinel = document.getElementById('scroll-sentinel');
  var list = document.getElementById('article-list');
  var emptyState = document.getElementById('empty-state');
  var indicator = document.getElementById('loading-indicator');
  var nav = document.getElementById('category-nav');
  var searchForm = document.getElementById('search-form');
  var searchInput = searchForm.querySelector('input[name="q"]');
  var main = document.getElementById('main-content');
  var categories = JSON.parse(document.getElementById('categories-data').textContent);
  var loading = false;

  function buildUrl(state) {
    var p = new URLSearchParams();
    if (state.view === 'bookmarks') p.set('view', 'bookmarks');
    if (state.category) p.set('category', state.category);
    if (state.q) p.set('q', state.q);
    var qs = p.toString();
    return '/' + (qs ? '?' + qs : '');
  }

  function setActiveNav(state) {
    Array.prototype.forEach.call(nav.querySelectorAll('a'), function (a) {
      var isActive = state.view === 'bookmarks'
        ? a.dataset.view === 'bookmarks'
        : (a.dataset.category === (state.category || '') && !a.dataset.view);
      a.classList.toggle('active', isActive);
    });
  }

  function loadView(state, pushHistory) {
    if (loading) return;
    loading = true;
    list.classList.add('transitioning');
    fetch('/more.php?' + new URLSearchParams({
      offset: 0,
      category: state.category || '',
      q: state.q || '',
      view: state.view || ''
    }).toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        list.innerHTML = data.html || '';
        emptyState.style.display = data.empty_message ? '' : 'none';
        emptyState.textContent = data.empty_message || '';
        sentinel.dataset.offset = data.next_offset;
        sentinel.dataset.hasMore = data.has_more ? '1' : '0';
        sentinel.dataset.category = state.category || '';
        sentinel.dataset.q = state.q || '';
        sentinel.dataset.view = state.view || 'feed';
        setActiveNav(state);
        main.scrollIntoView({ block: 'start' });
        if (pushHistory) history.pushState(state, '', buildUrl(state));
        loading = false;
        list.classList.remove('transitioning');
      })
      .catch(function () {
        loading = false;
        list.classList.remove('transitioning');
      });
  }

  nav.addEventListener('click', function (e) {
    var a = e.target.closest('a');
    if (!a) return;
    e.preventDefault();
    var state = a.dataset.view === 'bookmarks'
      ? { view: 'bookmarks', category: null, q: null }
      : { view: 'feed', category: a.dataset.category || null, q: searchInput.value || null };
    searchInput.value = '';
    loadView(state, true);
  });

  document.getElementById('brand-reset').addEventListener('click', function () {
    searchInput.value = '';
    loadView({ view: 'feed', category: null, q: null }, true);
  });

  searchForm.addEventListener('submit', function (e) {
    e.preventDefault();
    var currentCategory = sentinel.dataset.view === 'feed' ? (sentinel.dataset.category || null) : null;
    loadView({ view: 'feed', category: currentCategory, q: searchInput.value.trim() || null }, true);
  });

  document.addEventListener('click', function (e) {
    var a = e.target.closest('a.clear');
    if (!a) return;
    e.preventDefault();
    searchInput.value = '';
    loadView({ view: 'feed', category: null, q: null }, true);
  });

  window.addEventListener('popstate', function (e) {
    var p = new URLSearchParams(location.search);
    var state = {
      view: p.get('view') === 'bookmarks' ? 'bookmarks' : 'feed',
      category: p.get('category') || null,
      q: p.get('q') || null
    };
    searchInput.value = state.q || '';
    loadView(state, false);
  });

  // ブックマークボタン(カード内リンクのクリックとは独立させる)
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bookmark-btn');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    if (btn.dataset.loggedIn !== '1') {
      location.href = '/login.php';
      return;
    }
    var articleId = btn.dataset.articleId;
    btn.disabled = true;
    fetch('/bookmark_toggle.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'article_id=' + encodeURIComponent(articleId)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.classList.toggle('active', !!data.bookmarked);
        btn.textContent = data.bookmarked ? '★' : '☆';
        btn.disabled = false;
      })
      .catch(function () { btn.disabled = false; });
  });

  // 無限スクロール
  function loadMore() {
    if (loading || sentinel.dataset.hasMore !== '1') return;
    loading = true;
    indicator.style.display = 'block';
    fetch('/more.php?' + new URLSearchParams({
      offset: sentinel.dataset.offset,
      category: sentinel.dataset.category,
      q: sentinel.dataset.q,
      view: sentinel.dataset.view
    }).toString())
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.html) list.insertAdjacentHTML('beforeend', data.html);
        sentinel.dataset.hasMore = data.has_more ? '1' : '0';
        sentinel.dataset.offset = data.next_offset;
        loading = false;
        indicator.style.display = 'none';
      })
      .catch(function () {
        loading = false;
        indicator.style.display = 'none';
      });
  }

  new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (entry.isIntersecting) loadMore();
    });
  }, { rootMargin: '600px' }).observe(sentinel);

  // 横スワイプでカテゴリ切替(フィード表示中のみ、ブックマーク表示中は対象外)
  var touchStartX = 0, touchStartY = 0, swiping = false;
  main.addEventListener('touchstart', function (e) {
    if (e.touches.length !== 1) return;
    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
    swiping = true;
  }, { passive: true });

  main.addEventListener('touchend', function (e) {
    if (!swiping) return;
    swiping = false;
    if (sentinel.dataset.view !== 'feed' || categories.length === 0) return;
    var dx = e.changedTouches[0].clientX - touchStartX;
    var dy = e.changedTouches[0].clientY - touchStartY;
    if (Math.abs(dx) < 70 || Math.abs(dx) < Math.abs(dy) * 1.5) return;

    var current = sentinel.dataset.category || '';
    var idx = categories.indexOf(current); // 未選択(すべて混在)状態は-1
    var nextIdx;
    if (dx < 0) { // 左スワイプ: 次のカテゴリへ
      nextIdx = idx + 1 >= categories.length ? categories.length - 1 : idx + 1;
    } else { // 右スワイプ: 前のカテゴリへ(先頭で離すと未選択状態に戻る)
      nextIdx = idx - 1;
    }
    var nextCategory = (nextIdx < 0) ? null : categories[nextIdx];
    if (nextCategory === current || (nextCategory === null && current === '')) return;
    loadView({ view: 'feed', category: nextCategory, q: sentinel.dataset.q || null }, true);
  }, { passive: true });
})();
</script>
</body>
</html>
