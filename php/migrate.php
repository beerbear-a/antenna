<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

$pdo = get_pdo();

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS user_category_subscriptions (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category TEXT NOT NULL,
    PRIMARY KEY (user_id, category)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS bookmarks (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    article_id INTEGER NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, article_id)
)");

echo "マイグレーション完了: users, user_category_subscriptions, bookmarks テーブルを作成しました。\n";
