<?php
/**
 * PWAマニフェスト（動的生成）
 *
 * テナント設定に基づいてアプリ名・テーマカラー・パスを動的に出力する。
 * 元の manifest.json はフォールバック用として残置。
 */
require_once __DIR__ . '/config/database.php';

// getTenantSettings() が $pdo を参照するため、接続を確立
try {
    $pdo = getDBConnection();
} catch (Exception $e) {
    // DB接続失敗時はデフォルト値で続行
    $pdo = null;
}

$settings = getTenantSettings();
$basePath = APP_BASE_PATH;
$appName = $settings['system_name'];
$themeColor = $settings['theme_color'];

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'name' => $appName . ' - 介護タクシー業務管理',
    'short_name' => $appName,
    'description' => '介護タクシー業務をまるっと管理。予約・配車カレンダーと運行記録。',
    'start_url' => $basePath . '/?utm_source=pwa',
    'scope' => $basePath . '/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'theme_color' => $themeColor,
    'background_color' => '#FFFFFF',
    'lang' => 'ja',
    'dir' => 'ltr',
    'icons' => [
        [
            'src' => 'icons/favicon-16x16.png',
            'sizes' => '16x16',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => 'icons/favicon-32x32.png',
            'sizes' => '32x32',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => 'icons/apple-touch-icon.png',
            'sizes' => '180x180',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => 'icons/icon-192x192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'maskable any',
        ],
        [
            'src' => 'icons/icon-512x512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable any',
        ],
    ],
    'shortcuts' => [
        [
            'name' => '日常点検',
            'short_name' => '点検',
            'description' => '日常点検を開始',
            'url' => $basePath . '/daily_inspection.php',
            'icons' => [
                ['src' => 'icons/icon-192x192.png', 'sizes' => '192x192'],
            ],
        ],
        [
            'name' => '乗車記録',
            'short_name' => '乗車記録',
            'description' => '新しい乗車記録を追加',
            'url' => $basePath . '/ride_records.php?action=new',
            'icons' => [
                ['src' => 'icons/icon-192x192.png', 'sizes' => '192x192'],
            ],
        ],
        [
            'name' => '売上金確認',
            'short_name' => '売上確認',
            'description' => '売上金確認を実施',
            'url' => $basePath . '/cash_management.php',
            'icons' => [
                ['src' => 'icons/icon-192x192.png', 'sizes' => '192x192'],
            ],
        ],
    ],
    'categories' => ['business', 'productivity', 'utilities'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
