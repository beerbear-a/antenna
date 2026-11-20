#!/bin/bash
# オンシラズアンテナ本番デプロイ: oncr-ant.flop.jp へ一式配置
set -e

LOCAL_PHP_DIR="/Users/arita/Desktop/ツール/オンシラズアンテナ/php"
CLEAN_DB="/Users/arita/Documents/backup/ungratefuldog.flop.jp/udg-antenna_20261120/antenna.db"
REMOTE_DIR="~/www/oncr-ant.flop.jp"

echo "--- ディレクトリ存在確認 ---"
ssh udglab "test -d ~/www/oncr-ant.flop.jp" || { echo "まだディレクトリがありません。中止します。"; exit 1; }

echo "--- PHPファイル一式をアップロード ---"
scp "$LOCAL_PHP_DIR"/config.php "$LOCAL_PHP_DIR"/db.php "$LOCAL_PHP_DIR"/auth_common.php \
    "$LOCAL_PHP_DIR"/feed_common.php "$LOCAL_PHP_DIR"/migrate.php "$LOCAL_PHP_DIR"/register.php \
    "$LOCAL_PHP_DIR"/login.php "$LOCAL_PHP_DIR"/logout.php "$LOCAL_PHP_DIR"/settings.php \
    "$LOCAL_PHP_DIR"/index.php "$LOCAL_PHP_DIR"/more.php "$LOCAL_PHP_DIR"/bookmark_toggle.php \
    "$LOCAL_PHP_DIR"/fetch.php \
    "$LOCAL_PHP_DIR"/sources.json "$LOCAL_PHP_DIR"/ng_words.json \
    udglab:$REMOTE_DIR/

echo "--- クリーンな(テストユーザーを含まない)articlesデータを配置 ---"
scp "$CLEAN_DB" udglab:$REMOTE_DIR/antenna.db

echo "--- マイグレーション実行(users / user_category_subscriptions 作成) ---"
ssh udglab "bash -c 'cd $REMOTE_DIR && php migrate.php'"

echo "--- 最新記事を取得(RSS再取得) ---"
ssh udglab "bash -c 'cd $REMOTE_DIR && php fetch.php'" | tail -5

echo "--- .htaccess配置(DirectoryIndex) ---"
cat <<'EOF' > /tmp/oncr_ant_htaccess
DirectoryIndex index.php
EOF
scp /tmp/oncr_ant_htaccess udglab:$REMOTE_DIR/.htaccess

echo "--- cron登録(15分おきfetch.php) ---"
REMOTE_ABS="/home/future-city/www/oncr-ant.flop.jp"
ssh udglab "bash -c '(crontab -l 2>/dev/null | grep -v oncr-ant; echo \"*/15 * * * * /usr/local/bin/php $REMOTE_ABS/fetch.php >> $REMOTE_ABS/fetch.log 2>&1\") | crontab -'"
ssh udglab "crontab -l"

echo "--- 疎通確認 ---"
curl -s -o /dev/null -w "HTTP %{http_code}\n" https://www.oncr-ant.flop.jp/ --max-time 15

echo "完了"
