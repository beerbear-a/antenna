<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_common.php';

if (!LOGIN_ENABLED) {
    header('Location: /index.php');
    exit;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$error = null;
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $usernameValue = $username;
    $password = (string)($_POST['password'] ?? '');

    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        login_as((int)$user['id']);
        header('Location: /index.php');
        exit;
    }
    $error = 'ユーザー名またはパスワードが違います。';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ログイン - オンシラズアンテナ</title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
    font-family: -apple-system, "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif;
    background: #121212; color: #e6e6e3;
  }
  form {
    width: 100%; max-width: 320px; padding: 32px 28px;
    border: 1px solid rgba(255,255,255,0.08); border-radius: 6px;
  }
  h1 { font-size: 16px; font-weight: 600; letter-spacing: 0.04em; margin: 0 0 24px; color: #fff; }
  label { display: block; font-size: 11px; color: #888; margin-bottom: 5px; letter-spacing: 0.03em; }
  input[type="text"], input[type="password"] {
    width: 100%; background: transparent; border: none;
    border-bottom: 1px solid rgba(255,255,255,0.14); color: #eee;
    padding: 8px 2px; font-size: 14px; margin-bottom: 18px;
  }
  input:focus { outline: none; border-bottom-color: #d9603b; }
  button {
    width: 100%; background: transparent; border: 1px solid rgba(255,255,255,0.18); color: #bbb;
    padding: 9px; font-size: 12.5px; letter-spacing: 0.04em; cursor: pointer; margin-top: 8px;
  }
  button:hover { border-color: #d9603b; color: #d9603b; }
  .error { color: #e0754f; font-size: 12px; margin-bottom: 16px; }
  .link { text-align: center; margin-top: 16px; font-size: 12px; }
  .link a { color: #888; text-decoration: none; }
  .link a:hover { color: #ccc; }
</style>
</head>
<body>
<form method="post" action="/login.php">
  <h1>オンシラズアンテナ</h1>
  <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
  <label for="username">ユーザー名</label>
  <input type="text" id="username" name="username" value="<?= h($usernameValue) ?>" required autofocus>
  <label for="password">パスワード</label>
  <input type="password" id="password" name="password" required>
  <button type="submit">ログイン</button>
  <div class="link"><a href="/register.php">新規登録はこちら</a></div>
</form>
</body>
</html>
