<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function ensure_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name(SESSION_NAME);
        session_start();
    }
}

function current_user(): ?array {
    ensure_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

function require_login(): array {
    $user = current_user();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function login_as(int $userId): void {
    ensure_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function logout(): void {
    ensure_session();
    $_SESSION = [];
    session_destroy();
}
