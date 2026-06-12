<?php
// Remember Me セルフテスト（CLI専用・検証後に削除してよい一時ツール）
// 使い方: php tools/rm_selftest.php
// 実DBに対して remember_tokens の発行・照合・ローテーション・猶予・破棄を検証する。
// テストで作成した行は最後に必ず削除する。

if (php_sapi_name() !== 'cli') {
    exit("CLI専用\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/remember_me.php';

$pdo = getDBConnection();
$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [OK] {$label}\n"; }
    else     { $fail++; echo "  [NG] {$label}\n"; }
}

// 対象ユーザー（実在のアクティブユーザーを1人借りる。書き込みはremember_tokensのみ）
$user_id = (int)$pdo->query("SELECT id FROM users WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
if (!$user_id) {
    exit("アクティブユーザーが見つかりません\n");
}
echo "テスト対象 user_id={$user_id} (DB: " . DB_NAME . ")\n\n";

$before_count = (int)$pdo->query("SELECT COUNT(*) FROM remember_tokens")->fetchColumn();

// --- 1. 発行 ---
echo "1. wtsIssueRememberToken\n";
wtsIssueRememberToken($pdo, $user_id); // CLIではsetcookieは無効だがDB挿入は実行される
$row = $pdo->query("SELECT * FROM remember_tokens WHERE user_id = {$user_id} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('トークン行が挿入される', (bool)$row);
check('selectorが24桁hex', (bool)preg_match('/^[0-9a-f]{24}$/', $row['selector'] ?? ''));
check('validator_hashが64桁hex', (bool)preg_match('/^[0-9a-f]{64}$/', $row['validator_hash'] ?? ''));
check('有効期限が約90日後', abs(strtotime($row['expires_at']) - time() - 90 * 86400) < 600);
$issued_id = (int)$row['id'];

// --- 2. 照合（手動でトークンを作ってCookie相当をセット） ---
echo "2. wtsAttemptRememberLogin（正常系）\n";
$selector  = bin2hex(random_bytes(12));
$validator = bin2hex(random_bytes(32));
$stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))");
$stmt->execute([$user_id, $selector, hash('sha256', $validator)]);
$_COOKIE[WTS_REMEMBER_COOKIE] = $selector . ':' . $validator;
$user = wtsAttemptRememberLogin($pdo);
check('ユーザーが返る', is_array($user) && (int)$user['id'] === $user_id);
check('必要カラムが揃う', is_array($user) && isset($user['name'], $user['login_id'], $user['permission_level'], $user['is_driver']));

$row2 = $pdo->query("SELECT * FROM remember_tokens WHERE selector = '{$selector}'")->fetch(PDO::FETCH_ASSOC);
check('validatorがローテーションされる', $row2 && !hash_equals($row2['validator_hash'], hash('sha256', $validator)));
check('prev_validator_hashに旧値が入る', $row2 && hash_equals((string)$row2['prev_validator_hash'], hash('sha256', $validator)));
check('last_used_atが記録される', $row2 && $row2['last_used_at'] !== null);

// --- 3. 猶予期間（旧validatorでの並行リクエスト） ---
echo "3. ローテーション直後の旧validator（猶予期間内）\n";
$user3 = wtsAttemptRememberLogin($pdo); // Cookieは旧validatorのまま
check('猶予期間内なら旧validatorでもログイン成功', is_array($user3) && (int)$user3['id'] === $user_id);
$row3 = $pdo->query("SELECT * FROM remember_tokens WHERE selector = '{$selector}'")->fetch(PDO::FETCH_ASSOC);
check('猶予使用ではトークンを変更しない', $row3 && $row3['validator_hash'] === $row2['validator_hash']);

// --- 4. 不正validator（盗難検知） ---
echo "4. validator不一致（盗難の可能性）\n";
$_COOKIE[WTS_REMEMBER_COOKIE] = $selector . ':' . bin2hex(random_bytes(32));
$user4 = wtsAttemptRememberLogin($pdo);
check('ログイン失敗', $user4 === null);
$gone = $pdo->query("SELECT COUNT(*) FROM remember_tokens WHERE selector = '{$selector}'")->fetchColumn();
check('トークンが無効化（削除）される', (int)$gone === 0);

// --- 5. 期限切れトークン ---
echo "5. 期限切れトークン\n";
$selector5  = bin2hex(random_bytes(12));
$validator5 = bin2hex(random_bytes(32));
$stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, DATE_SUB(NOW(), INTERVAL 1 DAY))");
$stmt->execute([$user_id, $selector5, hash('sha256', $validator5)]);
$_COOKIE[WTS_REMEMBER_COOKIE] = $selector5 . ':' . $validator5;
check('期限切れはログイン失敗', wtsAttemptRememberLogin($pdo) === null);

// --- 6. 破棄 ---
echo "6. wtsClearRememberToken\n";
$selector6  = bin2hex(random_bytes(12));
$validator6 = bin2hex(random_bytes(32));
$stmt = $pdo->prepare("INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))");
$stmt->execute([$user_id, $selector6, hash('sha256', $validator6)]);
$_COOKIE[WTS_REMEMBER_COOKIE] = $selector6 . ':' . $validator6;
wtsClearRememberToken($pdo);
$gone6 = $pdo->query("SELECT COUNT(*) FROM remember_tokens WHERE selector = '{$selector6}'")->fetchColumn();
check('トークン行が削除される', (int)$gone6 === 0);

// --- 後始末: テストで作った行を全削除 ---
$pdo->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$issued_id]);
$pdo->prepare("DELETE FROM remember_tokens WHERE selector IN (?, ?)")->execute([$selector, $selector5]);
$after_count = (int)$pdo->query("SELECT COUNT(*) FROM remember_tokens")->fetchColumn();
echo "\n後始末: テスト行削除済み（件数 {$before_count} → {$after_count}）\n";
check('テスト前後で行数が一致', $before_count === $after_count);

echo "\n=== 結果: 成功 {$pass} / 失敗 {$fail} ===\n";
exit($fail > 0 ? 1 : 0);
