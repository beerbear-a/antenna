#!/usr/bin/env python3
"""オンシラズアンテナ: 登録まとめブログのRSS/Atomフィードを巡回取得してDBに溜める。"""
import email.utils
import json
import os
import re
import sqlite3
import sys
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from xml.etree import ElementTree

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
SOURCES_PATH = os.path.join(BASE_DIR, "sources.json")
DB_PATH = os.path.join(BASE_DIR, "antenna.db")
USER_AGENT = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) OnshirazuAntenna/1.0 (personal use)"
TIMEOUT = 10

FEED_LINK_RE = re.compile(
    r'<link[^>]+type=["\']application/(?:rss|atom)\+xml["\'][^>]+href=["\']([^"\']+)["\']',
    re.IGNORECASE,
)
FEED_LINK_RE_ALT = re.compile(
    r'<link[^>]+href=["\']([^"\']+)["\'][^>]+type=["\']application/(?:rss|atom)\+xml["\']',
    re.IGNORECASE,
)
CANDIDATE_PATHS = ["index.rdf", "feed/", "feed", "rss/", "rss", "atom.xml", "?feed=rss2"]


def log(msg):
    print(f"[{datetime.now().isoformat(timespec='seconds')}] {msg}", flush=True)


def fetch_url(url):
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
        return resp.read()


def looks_like_feed(data):
    head = data[:500].lower()
    return b"<rss" in head or b"<rdf:rdf" in head or b"<feed" in head or (b"<?xml" in head and (b"item" in data.lower()[:3000] or b"entry" in data.lower()[:3000]))


def discover_feed_url(homepage):
    try:
        html = fetch_url(homepage).decode("utf-8", errors="ignore")
    except Exception as e:
        log(f"  discover: homepage取得失敗 {homepage}: {e}")
        html = None

    if html:
        for pattern in (FEED_LINK_RE, FEED_LINK_RE_ALT):
            m = pattern.search(html)
            if m:
                return urllib.parse.urljoin(homepage, m.group(1))

    for path in CANDIDATE_PATHS:
        candidate = urllib.parse.urljoin(homepage, path)
        try:
            data = fetch_url(candidate)
            if looks_like_feed(data):
                return candidate
        except Exception:
            continue
    return None


def strip_ns(tag):
    return tag.split("}")[-1] if "}" in tag else tag


def parse_date(s):
    if not s:
        return None
    s = s.strip()
    try:
        dt = email.utils.parsedate_to_datetime(s)
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt
    except Exception:
        pass
    try:
        dt = datetime.fromisoformat(s.replace("Z", "+00:00"))
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)
        return dt
    except Exception:
        return None


def parse_feed(data):
    """RSS2.0 / RSS1.0(RDF) / Atom を雑に解釈して (title, link, published_at) のリストを返す"""
    items = []
    try:
        root = ElementTree.fromstring(data)
    except ElementTree.ParseError as e:
        log(f"  parse失敗: {e}")
        return items

    root_tag = strip_ns(root.tag).lower()

    if root_tag == "feed":  # Atom
        for entry in root:
            if strip_ns(entry.tag) != "entry":
                continue
            title, link, pub = None, None, None
            for child in entry:
                ctag = strip_ns(child.tag)
                if ctag == "title":
                    title = (child.text or "").strip()
                elif ctag == "link":
                    href = child.get("href")
                    if href and (link is None or child.get("rel", "alternate") == "alternate"):
                        link = href
                elif ctag in ("published", "updated"):
                    if pub is None or ctag == "published":
                        pub = (child.text or "").strip()
            if title and link:
                items.append((title, link, parse_date(pub)))
        return items

    # RSS 2.0 (<rss><channel><item>) or RSS 1.0 RDF (<rdf:RDF><item>)
    for item in root.iter():
        if strip_ns(item.tag) != "item":
            continue
        title, link, pub = None, None, None
        for child in item:
            ctag = strip_ns(child.tag)
            if ctag == "title":
                title = (child.text or "").strip()
            elif ctag == "link":
                link = (child.text or "").strip()
            elif ctag in ("date", "pubDate") and pub is None:
                pub = (child.text or "").strip()
        if title and link:
            items.append((title, link, parse_date(pub)))
    return items


def ensure_db(conn):
    conn.execute(
        """CREATE TABLE IF NOT EXISTS articles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_name TEXT,
            category TEXT,
            title TEXT,
            link TEXT UNIQUE,
            published_at TEXT,
            fetched_at TEXT
        )"""
    )
    conn.commit()


def main():
    with open(SOURCES_PATH, encoding="utf-8") as f:
        sources = json.load(f)

    conn = sqlite3.connect(DB_PATH)
    ensure_db(conn)

    now_iso = datetime.now(timezone.utc).isoformat()
    sources_changed = False
    total_new = 0

    for src in sources:
        name = src["name"]
        category = src.get("category") or "不明"
        feed_url = src.get("feed_url")

        if not feed_url:
            log(f"{name}: feed_url未設定、自動検出を試みます")
            feed_url = discover_feed_url(src["homepage"])
            if feed_url:
                log(f"  -> 発見: {feed_url}")
                src["feed_url"] = feed_url
                sources_changed = True
            else:
                log(f"  -> 発見できず、スキップ")
                continue

        try:
            data = fetch_url(feed_url)
        except Exception as e:
            log(f"{name}: フィード取得失敗 {feed_url}: {e}")
            continue

        entries = parse_feed(data)
        new_count = 0
        for title, link, pub_dt in entries:
            pub_str = pub_dt.isoformat() if pub_dt else None
            cur = conn.execute(
                "INSERT OR IGNORE INTO articles (source_name, category, title, link, published_at, fetched_at) VALUES (?, ?, ?, ?, ?, ?)",
                (name, category, title, link, pub_str, now_iso),
            )
            if cur.rowcount:
                new_count += 1
        conn.commit()
        total_new += new_count
        log(f"{name}: {len(entries)}件中 新規{new_count}件")

    # 2週間より古い記事は削除（肥大化防止）
    conn.execute("DELETE FROM articles WHERE fetched_at < datetime('now', '-14 days')")
    conn.commit()
    conn.close()

    if sources_changed:
        with open(SOURCES_PATH, "w", encoding="utf-8") as f:
            json.dump(sources, f, ensure_ascii=False, indent=2)
        log("sources.json を自動検出結果で更新しました")

    log(f"完了: 新規{total_new}件")


if __name__ == "__main__":
    main()
