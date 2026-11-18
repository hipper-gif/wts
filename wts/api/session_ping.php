<?php
/**
 * 開いたままの画面のログインを保ち、いまの CSRF トークンを返す（js/session-keepalive.js が呼ぶ）。
 *
 * 背景（2026-11-18 全画面点検・全画面共通の所見）:
 *   画面を開いたまま時間が経つと、保存を押した時にログイン画面へ飛ばされ、入力が全部消えていた。
 *   自動で再ログインできた場合でも、画面に埋めたトークンが古いままなので同じことが起きた。
 *
 * ログインが切れていて自動の再ログインもできない時は、session_check.php がログイン画面へ移す
 * （＝この応答は JSON にならない）。呼ぶ側はそれを「ログインが切れた」と読み取り、入力を残したまま知らせる。
 * トークンは同じサイトのログイン済みの画面にだけ返る（他サイトからは読めない）。
 */
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'ok' => true,
    'csrf_token' => $_SESSION['csrf_token'] ?? '',
]);
