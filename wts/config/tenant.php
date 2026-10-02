<?php
/**
 * テナント設定 - 他社展開用の設定集約ポイント
 *
 * .env の APP_BASE_PATH からベースパスを取得し、
 * system_settings テーブルからシステム名等を動的に取得する。
 * 全ページから参照可能な定数・関数を提供。
 */

// .env は database.php で既に読み込み済み前提
// APP_BASE_PATH: アプリケーションのベースパス（例: /Smiley/taxi/wts）
define('APP_BASE_PATH', rtrim(getenv('APP_BASE_PATH') ?: '/Smiley/taxi/wts', '/'));

/**
 * テナント設定をDBから取得（キャッシュ付き）
 */
function getTenantSettings() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $defaults = [
        'system_name' => '福祉輸送管理システム',
        'theme_color' => '#00C896',
    ];

    try {
        global $pdo;
        if (!$pdo) {
            return $defaults;
        }
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('system_name', 'theme_color')");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $cache = array_merge($defaults, $rows);
    } catch (Exception $e) {
        $cache = $defaults;
    }

    return $cache;
}

/**
 * アプリのベースパス（末尾スラッシュ付き）を取得
 * 例: /Smiley/taxi/wts/
 */
function getAppBasePath() {
    return APP_BASE_PATH . '/';
}

/**
 * アプリのベースパス（末尾スラッシュなし）を取得
 * 例: /Smiley/taxi/wts
 */
function getAppBasePathNoSlash() {
    return APP_BASE_PATH;
}

/**
 * 陸運局提出（第4号様式・第21号様式）の提出先「○○運輸支局」の○○。
 * 様式自体は全国共通（関東・近畿の配布ファイルで一字一句同じ・2026-10-02確認）＝会社ごとに変わるのはここだけ。
 * system_settings.transport_branch（会社情報画面で設定）。未設定は Smiley/Lino の「大阪」。
 */
function getTransportBranch(PDO $pdo): string {
    try {
        $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'transport_branch'")->fetchColumn();
        if (is_string($v) && trim($v) !== '') return trim($v);
    } catch (Exception $e) {
        // 取れなければ既定
    }
    return '大阪';
}
