#!/usr/bin/env python3
"""オンシラズアンテナ: 取得済み記事をローカルのみ(127.0.0.1)で一覧表示するWebサーバー。"""
import html
import json
import os
import sqlite3
import urllib.parse
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, HTTPServer

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
DB_PATH = os.path.join(BASE_DIR, "antenna.db")
NG_WORDS_PATH = os.path.join(BASE_DIR, "ng_words.json")
SOURCES_PATH = os.path.join(BASE_DIR, "sources.json")
PORT = 8787
MAX_ARTICLES = 300
MAX_ARTICLES_SEARCH = 1000

CATEGORY_ORDER = ["ニュース", "2ch_VIP", "アニメ・漫画", "ゲーム", "スポーツ", "芸能_YouTube", "生活・おもしろ", "音楽", "アート・映画", "サブカル", "海外カルチャー", "不明"]


def load_source_domains():
    try:
        with open(SOURCES_PATH, encoding="utf-8") as f:
            sources = json.load(f)
    except Exception:
        return {}
    domains = {}
    for s in sources:
        netloc = urllib.parse.urlparse(s.get("homepage") or "").netloc
        if netloc:
            domains[s["name"]] = netloc
    return domains


def category_counts():
    conn = sqlite3.connect(DB_PATH)
    rows = conn.execute("SELECT category, COUNT(*) FROM articles GROUP BY category").fetchall()
    conn.close()
    return {r[0]: r[1] for r in rows}


def load_ng_words():
    try:
        with open(NG_WORDS_PATH, encoding="utf-8") as f:
            return [w for w in json.load(f) if w]
    except Exception:
        return []


def relative_time(iso_str):
    if not iso_str:
        return ""
    try:
        dt = datetime.fromisoformat(iso_str)
    except Exception:
        return ""
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    delta = datetime.now(timezone.utc) - dt
    seconds = delta.total_seconds()
    if seconds < 0:
        return "たった今"
    if seconds < 60:
        return "たった今"
    if seconds < 3600:
        return f"{int(seconds // 60)}分前"
    if seconds < 86400:
        return f"{int(seconds // 3600)}時間前"
    return f"{int(seconds // 86400)}日前"


def escape_like(s):
    return s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")


def fetch_articles(category=None, search=None):
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    sql = "SELECT * FROM articles"
    clauses = []
    params = []
    if category:
        clauses.append("category = ?")
        params.append(category)
    if search:
        clauses.append("title LIKE ? ESCAPE '\\'")
        params.append(f"%{escape_like(search)}%")
    if clauses:
        sql += " WHERE " + " AND ".join(clauses)
    sql += " ORDER BY COALESCE(published_at, fetched_at) DESC LIMIT ?"
    params.append(MAX_ARTICLES_SEARCH if search else MAX_ARTICLES)
    rows = conn.execute(sql, params).fetchall()
    conn.close()

    ng_words = load_ng_words()
    result = []
    for row in rows:
        title = row["title"] or ""
        if any(ng in title for ng in ng_words):
            continue
        result.append(row)
    return result


def available_categories():
    conn = sqlite3.connect(DB_PATH)
    rows = conn.execute("SELECT DISTINCT category FROM articles").fetchall()
    conn.close()
    present = {r[0] for r in rows}
    ordered = [c for c in CATEGORY_ORDER if c in present]
    ordered += sorted(present - set(ordered))
    return ordered


PAGE_TEMPLATE = """<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>オンシラズアンテナ</title>
<style>
  * {{ box-sizing: border-box; }}
  body {{
    margin: 0; padding: 0;
    font-family: -apple-system, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
    background: #121212; color: #e6e6e3;
    -webkit-font-smoothing: antialiased;
  }}
  header {{
    padding: 28px 24px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
  }}
  header h1 {{ margin: 0; font-size: 18px; font-weight: 600; letter-spacing: 0.06em; color: #fff; }}
  header .sub {{ font-size: 11px; color: #666; margin-top: 5px; letter-spacing: 0.03em; }}
  nav {{
    display: flex; flex-wrap: wrap; gap: 20px;
    padding: 14px 24px; border-bottom: 1px solid rgba(255,255,255,0.06);
  }}
  nav a {{
    color: #777; text-decoration: none; font-size: 12.5px; letter-spacing: 0.02em;
    white-space: nowrap; padding-bottom: 3px; border-bottom: 1px solid transparent;
    transition: color 0.12s ease;
  }}
  nav a:hover {{ color: #ccc; }}
  nav a .cnt {{ color: #444; font-size: 10.5px; margin-left: 2px; }}
  nav a.active {{ color: #fff; border-bottom-color: #d9603b; }}
  nav a.active .cnt {{ color: #d9603b; }}
  form.search {{
    display: flex; align-items: center; gap: 12px; padding: 14px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
  }}
  form.search input[type="text"] {{
    flex: 1; max-width: 360px; background: transparent; border: none;
    border-bottom: 1px solid rgba(255,255,255,0.14); color: #eee;
    padding: 5px 1px; font-size: 13px;
  }}
  form.search input[type="text"]::placeholder {{ color: #555; }}
  form.search input[type="text"]:focus {{ outline: none; border-bottom-color: #d9603b; }}
  form.search button {{
    background: transparent; border: 1px solid rgba(255,255,255,0.18); color: #bbb;
    padding: 6px 16px; font-size: 11.5px; letter-spacing: 0.04em; cursor: pointer;
  }}
  form.search button:hover {{ border-color: #d9603b; color: #d9603b; }}
  form.search a.clear {{
    color: #555; font-size: 11px; text-decoration: none;
  }}
  form.search a.clear:hover {{ color: #999; }}
  main {{ max-width: 700px; margin: 0 auto; padding: 4px 24px 60px; }}
  ul.articles {{ list-style: none; margin: 0; padding: 0; }}
  ul.articles li {{ border-bottom: 1px solid rgba(255,255,255,0.06); }}
  ul.articles li:last-child {{ border-bottom: none; }}
  ul.articles a.card {{
    display: block; color: inherit; text-decoration: none;
    padding: 18px 2px; transition: background 0.12s ease;
  }}
  ul.articles a.card:hover {{ background: rgba(255,255,255,0.025); }}
  ul.articles .title {{
    color: #eaeaea; font-size: 14.5px; font-weight: 500;
    margin-bottom: 7px; line-height: 1.55;
  }}
  ul.articles a.card:hover .title {{ color: #e0754f; }}
  .meta {{
    font-size: 11px; color: #666; display: flex; align-items: center; gap: 7px;
  }}
  .meta .favicon {{
    width: 13px; height: 13px; border-radius: 2px; vertical-align: middle;
    flex-shrink: 0; filter: saturate(0.5) brightness(0.95);
  }}
  .meta .cat {{ color: #888; text-transform: uppercase; letter-spacing: 0.06em; font-size: 10px; }}
  .meta .dot {{ color: #3a3a3a; }}
  .meta .time {{ margin-left: auto; color: #4a4a4a; white-space: nowrap; }}
  footer {{ text-align: center; color: #444; font-size: 11px; padding: 30px 0; letter-spacing: 0.03em; }}
  .empty {{ text-align: center; color: #555; padding: 60px 0; font-size: 13px; }}
</style>
</head>
<body>
<header>
  <h1>オンシラズアンテナ</h1>
  <div class="sub">{count}件表示中</div>
</header>
<nav>{nav_html}</nav>
<form class="search" method="get" action="/">
  {category_hidden}
  <input type="text" name="q" value="{query_value}" placeholder="キーワード検索">
  <button type="submit">検索</button>
  {clear_link}
</form>
<main>
{body_html}
</main>
<footer>オンシラズアンテナ</footer>
</body>
</html>
"""


def render_page(category, search=None):
    categories = available_categories()
    counts = category_counts()
    total_count = sum(counts.values())
    q_suffix = f"&q={urllib.parse.quote(search)}" if search else ""

    nav_items = ['<a href="/?{q}" class="{cls}">すべて <span class="cnt">{n}</span></a>'.format(
        q=f"q={urllib.parse.quote(search)}" if search else "",
        cls="active" if not category else "",
        n=total_count,
    )]
    for c in categories:
        cls = "active" if c == category else ""
        nav_items.append(
            '<a href="/?category={cat_q}{q_suffix}" class="{cls}">{label} <span class="cnt">{n}</span></a>'.format(
                cat_q=urllib.parse.quote(c), q_suffix=q_suffix, cls=cls,
                label=html.escape(c), n=counts.get(c, 0),
            )
        )
    nav_html = "".join(nav_items)

    category_hidden = f'<input type="hidden" name="category" value="{html.escape(category, quote=True)}">' if category else ""
    clear_link = '<a class="clear" href="/">クリア</a>' if (search or category) else ""

    articles = fetch_articles(category, search)
    if not articles:
        if search:
            body_html = f'<div class="empty">「{html.escape(search)}」に一致する記事がありません。</div>'
        else:
            body_html = '<div class="empty">記事がありません。まだ取得が走っていないか、フィルタ対象のカテゴリに記事がありません。</div>'
    else:
        domains = load_source_domains()
        items = []
        for a in articles:
            cat = a["category"] or "不明"
            domain = domains.get(a["source_name"], "")
            favicon_html = (
                f'<img class="favicon" src="https://{domain}/favicon.ico" '
                f"onerror=\"this.style.visibility='hidden'\">"
                if domain else ""
            )
            items.append(
                '<li><a class="card" href="{link}" target="_blank" rel="noopener">'
                '<div class="title">{title}</div>'
                '<div class="meta">{favicon}<span class="cat">{cat}</span><span class="dot">·</span>'
                '<span>{source}</span><span class="time">{time}</span></div>'
                "</a></li>".format(
                    link=html.escape(a["link"], quote=True),
                    title=html.escape(a["title"]),
                    favicon=favicon_html,
                    cat=html.escape(cat),
                    source=html.escape(a["source_name"]),
                    time=relative_time(a["published_at"] or a["fetched_at"]),
                )
            )
        body_html = '<ul class="articles">' + "".join(items) + "</ul>"

    return PAGE_TEMPLATE.format(
        count=len(articles),
        nav_html=nav_html,
        body_html=body_html,
        category_hidden=category_hidden,
        query_value=html.escape(search, quote=True) if search else "",
        clear_link=clear_link,
    )


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass  # ログは静かに（必要ならここでファイル出力に変更可）

    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        if parsed.path != "/":
            self.send_response(404)
            self.end_headers()
            return
        qs = urllib.parse.parse_qs(parsed.query)
        category = qs.get("category", [None])[0] or None
        search = (qs.get("q", [None])[0] or "").strip() or None

        body = render_page(category, search).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def main():
    server = HTTPServer(("127.0.0.1", PORT), Handler)
    print(f"オンシラズアンテナ: http://127.0.0.1:{PORT}/ で起動中")
    server.serve_forever()


if __name__ == "__main__":
    main()
