<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$user = require_login();
$pdo = get_pdo();

$allCategories = $pdo->query("SELECT DISTINCT category FROM articles ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected = $_POST['categories'] ?? [];
    $selected = array_values(array_intersect($selected, $allCategories));

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM user_category_subscriptions WHERE user_id = ?")->execute([$user['id']]);
        $insert = $pdo->prepare("INSERT INTO user_category_subscriptions (user_id, category) VALUES (?, ?)");
        foreach ($selected as $cat) {
            $insert->execute([$user['id'], $cat]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    header('Location: /index.php');
    exit;
}

$subscribedStmt = $pdo->prepare("SELECT category FROM user_category_subscriptions WHERE user_id = ?");
$subscribedStmt->execute([$user['id']]);
$subscribedSet = array_flip($subscribedStmt->fetchAll(PDO::FETCH_COLUMN));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>購読設定 - オンシラズアンテナ</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 0;
    font-family: -apple-system, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
    background: #121212; color: #e6e6e3;
  }
  header { padding: 28px 24px 16px; border-bottom: 1px solid rgba(255,255,255,0.08); }
  header h1 { margin: 0; font-size: 18px; font-weight: 600; letter-spacing: 0.06em; color: #fff; }
  main { max-width: 480px; margin: 0 auto; padding: 24px; }
  .cat-list { list-style: none; margin: 0 0 24px; padding: 0; }
  .cat-list li { border-bottom: 1px solid rgba(255,255,255,0.06); }
  .cat-list label {
    display: flex; align-items: center; gap: 12px; padding: 14px 2px; font-size: 14px; cursor: pointer;
  }
  .cat-list input[type="checkbox"] { width: 16px; height: 16px; accent-color: #d9603b; }
  button {
    background: transparent; border: 1px solid rgba(255,255,255,0.18); color: #bbb;
    padding: 9px 22px; font-size: 12.5px; letter-spacing: 0.04em; cursor: pointer;
  }
  button:hover { border-color: #d9603b; color: #d9603b; }
  .back { display: inline-block; margin-bottom: 20px; font-size: 12px; color: #888; text-decoration: none; }
  .back:hover { color: #ccc; }
</style>
</head>
<body>
<header><h1>オンシラズアンテナ</h1></header>
<main>
  <a class="back" href="/index.php">&larr; 一覧に戻る</a>
  <form method="post" action="/settings.php">
    <ul class="cat-list">
      <?php foreach ($allCategories as $cat): ?>
      <li>
        <label>
          <input type="checkbox" name="categories[]" value="<?= h($cat) ?>" <?= isset($subscribedSet[$cat]) ? 'checked' : '' ?>>
          <?= h($cat) ?>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>
    <button type="submit">保存する</button>
  </form>
</main>
</body>
</html>
