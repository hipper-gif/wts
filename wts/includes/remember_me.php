<?php
// includes/remember_me.php - Remember Me 自動ログイン（ログイン状態の長期保持）
//
// 方式: selector + validator
//   - Cookieには「selector:validator」を保存
//   - DBには validator のSHA-256ハッシュのみ保存（DB漏洩時もCookie偽造は不可）
//   - 使用ごとに validator をローテーション。直前のvalidatorは短時間だけ有効
//     （セッション切れ直後にAjaxが並行して飛ぶケースで誤ログアウトしないための猶予）
//
// 利用箇所:
//   - index.php        ログイン成功時に発行 / ログイン画面表示前に自動ログイン試行
//   - session_check.php セッション切れ時に自動再ログイン試行
//   - logout.php       トークン無効化

const WTS_REMEMBER_COOKIE = 'wts_remember';
const WTS_REMEMBER_LIFETIME_DAYS = 90;
const WTS_REMEMBER_ROTATION_GRACE_SEC = 120;
const WTS_REMEMBER_MAX_TOKENS_PER_USER = 10; // 1ユーザーが保持できる端末（トークン）数の上限

function wtsRememberCookiePath(): string {
    // テナントごとにCookieを分離（同一ドメインに複数テナントが同居するため）
    return defined('APP_BASE_PATH') ? APP_BASE_PATH . '/' : '/';
}

function wtsSetRememberCookie(string $value, int $expires): void {
    setcookie(WTS_REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => wtsRememberCookiePath(),
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * ログイン成功時にトークンを発行してCookieにセットする
 */
function wtsIssueRememberToken(PDO $pdo, int $user_id): void {
    $selector  = bin2hex(random_bytes(12)); // 24文字
    $validator = bin2hex(random_bytes(32)); // 64文字
    $expires   = time() + WTS_REMEMBER_LIFETIME_DAYS * 86400;

    $stmt = $pdo->prepare("
        INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, user_agent)
        VALUES (?, ?, ?, FROM_UNIXTIME(?), ?)
    ");
    $stmt->execute([
        $user_id,
        $selector,
        hash('sha256', $validator),
        $expires,
        mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
    ]);

    // 掃除: 期限切れトークンと、端末数上限を超えた古いトークンを削除
    $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
    $limit = (int)WTS_REMEMBER_MAX_TOKENS_PER_USER;
    $stmt = $pdo->prepare("
        DELETE FROM remember_tokens
        WHERE user_id = ?
          AND id NOT IN (
              SELECT id FROM (
                  SELECT id FROM remember_tokens WHERE user_id = ? ORDER BY id DESC LIMIT {$limit}
              ) AS keep
          )
    ");
    $stmt->execute([$user_id, $user_id]);

    wtsSetRememberCookie($selector . ':' . $validator, $expires);
}

/**
 * Cookieのトークンを照合して自動ログインを試行する
 *
 * @return array|null 成功時はusersの行（セッション設定に必要なカラム一式）、失敗時はnull
 */
function wtsAttemptRememberLogin(PDO $pdo): ?array {
    $cookie = $_COOKIE[WTS_REMEMBER_COOKIE] ?? '';
    if ($cookie === '' || strpos($cookie, ':') === false) {
        return null;
    }
    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[0-9a-f]{24}$/', $selector) || !preg_match('/^[0-9a-f]{64}$/', $validator)) {
        wtsSetRememberCookie('', time() - 3600);
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW()");
        $stmt->execute([$selector]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$token) {
            wtsSetRememberCookie('', time() - 3600);
            return null;
        }

        $hash = hash('sha256', $validator);
        $is_current = hash_equals($token['validator_hash'], $hash);
        // ローテーション直後の並行リクエストは旧validatorを短時間だけ許容
        $is_prev = !$is_current
            && $token['prev_validator_hash'] !== null
            && hash_equals($token['prev_validator_hash'], $hash)
            && $token['rotated_at'] !== null
            && (time() - strtotime($token['rotated_at'])) <= WTS_REMEMBER_ROTATION_GRACE_SEC;

        if (!$is_current && !$is_prev) {
            // selectorは合うがvalidatorが違う = Cookie盗難の可能性。このトークンを無効化
            $pdo->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$token['id']]);
            wtsSetRememberCookie('', time() - 3600);
            error_log("[WTS-REMEMBER] validator mismatch (user_id={$token['user_id']}) - token revoked");
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT id, name, login_id, permission_level,
                   is_driver, is_caller, is_manager, is_admin, is_mechanic, is_inspector
            FROM users
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$token['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            // 退職・無効化されたユーザーのトークンは破棄
            $pdo->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$token['id']]);
            wtsSetRememberCookie('', time() - 3600);
            return null;
        }

        if ($is_current) {
            // validatorローテーション + 有効期限のスライド延長
            $new_validator = bin2hex(random_bytes(32));
            $new_expires   = time() + WTS_REMEMBER_LIFETIME_DAYS * 86400;
            $stmt = $pdo->prepare("
                UPDATE remember_tokens
                SET prev_validator_hash = validator_hash,
                    validator_hash = ?,
                    rotated_at = NOW(),
                    last_used_at = NOW(),
                    expires_at = FROM_UNIXTIME(?)
                WHERE id = ?
            ");
            $stmt->execute([hash('sha256', $new_validator), $new_expires, $token['id']]);
            wtsSetRememberCookie($selector . ':' . $new_validator, $new_expires);
        }
        // $is_prev の場合はDB・Cookieに触らない（新Cookieは別リクエストで配布済み）

        return $user;

    } catch (PDOException $e) {
        // テーブル未作成等。自動ログインは諦めて通常のログインフローへ
        error_log("[WTS-REMEMBER] attempt failed: " . $e->getMessage());
        return null;
    }
}

/**
 * この端末のトークンを無効化してCookieを削除する（ログアウト時）
 */
function wtsClearRememberToken(?PDO $pdo): void {
    $cookie = $_COOKIE[WTS_REMEMBER_COOKIE] ?? '';
    if ($pdo && $cookie !== '' && strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        if (preg_match('/^[0-9a-f]{24}$/', $selector)) {
            try {
                $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$selector]);
            } catch (PDOException $e) {
                error_log("[WTS-REMEMBER] clear failed: " . $e->getMessage());
            }
        }
    }
    wtsSetRememberCookie('', time() - 3600);
    unset($_COOKIE[WTS_REMEMBER_COOKIE]);
}

/**
 * ログインセッションを確立する（index.phpのパスワードログインと同じセッション変数を設定）
 */
function wtsEstablishSession(array $user): void {
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['login_id']  = $user['login_id'];

    $_SESSION['user_role'] = $user['permission_level'] ?? 'User';
    $_SESSION['is_admin']  = ($user['permission_level'] === 'Admin') ? 1 : 0;

    $_SESSION['is_driver']    = (bool)($user['is_driver'] ?? false);
    $_SESSION['is_caller']    = (bool)($user['is_caller'] ?? false);
    $_SESSION['is_manager']   = (bool)($user['is_manager'] ?? false);
    $_SESSION['is_mechanic']  = (bool)($user['is_mechanic'] ?? false);
    $_SESSION['is_inspector'] = (bool)($user['is_inspector'] ?? false);

    $_SESSION['login_time'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
}
