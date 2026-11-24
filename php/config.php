<?php
declare(strict_types=1);

define('BASE_DIR', __DIR__);
define('DB_PATH', BASE_DIR . '/antenna.db');
define('SESSION_NAME', 'onshirazu_session');
// ログイン機能を一旦無効化(falseにするだけで全ページから非表示・current_user()は常にnullになる)
define('LOGIN_ENABLED', false);
