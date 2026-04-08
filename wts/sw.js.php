<?php
// Service Worker - .envからベースパスを動的に取得
function loadEnvForSW($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $value = trim($parts[1]);
            if (!getenv($key)) putenv("{$key}={$value}");
        }
    }
}
loadEnvForSW(__DIR__ . '/.env');
$basePath = rtrim(getenv('APP_BASE_PATH') ?: '/Smiley/taxi/wts', '/');

header('Content-Type: application/javascript');
header('Service-Worker-Allowed: ' . $basePath . '/');
?>
// 福祉輸送管理システム (WTS) - Service Worker
// Version 1.0.0

const CACHE_VERSION = 'wts-v1.1.0';
const CACHE_STATIC = `${CACHE_VERSION}-static`;
const CACHE_DYNAMIC = `${CACHE_VERSION}-dynamic`;
const CACHE_API = `${CACHE_VERSION}-api`;

// キャッシュする静的リソース
const STATIC_CACHE_URLS = [
    '<?= $basePath ?>/',
    '<?= $basePath ?>/index.php',
    '<?= $basePath ?>/dashboard.php',
    '<?= $basePath ?>/manifest.json',

    // CSS
    '<?= $basePath ?>/css/ui-unified-v3.css',
    '<?= $basePath ?>/css/header-unified.css',
    '<?= $basePath ?>/calendar/css/calendar.css',
    '<?= $basePath ?>/calendar/css/reservation.css',

    // JavaScript
    '<?= $basePath ?>/js/cash-mobile.js',
    '<?= $basePath ?>/js/ui-interactions.js',
    '<?= $basePath ?>/calendar/js/calendar.js',
    '<?= $basePath ?>/calendar/js/reservation.js',
    '<?= $basePath ?>/calendar/js/vehicle_constraints.js',

    // アイコン
    '<?= $basePath ?>/icons/favicon-16x16.png',
    '<?= $basePath ?>/icons/favicon-32x32.png',
    '<?= $basePath ?>/icons/apple-touch-icon.png',
    '<?= $basePath ?>/icons/icon-192x192.png',
    '<?= $basePath ?>/icons/icon-512x512.png',

    // 主要ページ
    '<?= $basePath ?>/daily_inspection.php',
    '<?= $basePath ?>/ride_records.php',
    '<?= $basePath ?>/cash_management.php',
    '<?= $basePath ?>/arrival.php',
    '<?= $basePath ?>/departure.php',

    // CDN（オンラインのみ）
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
];

// オフラインフォールバックページ
const OFFLINE_PAGE = '<?= $basePath ?>/index.php';

// インストールイベント - 静的リソースをキャッシュ
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_STATIC)
            .then((cache) => {
                // 重要なリソースのみ必須キャッシュ、その他は無視
                return cache.addAll(STATIC_CACHE_URLS.slice(0, 5))
                    .then(() => {
                        // 残りのリソースは個別に追加（エラーを無視）
                        return Promise.allSettled(
                            STATIC_CACHE_URLS.slice(5).map(url =>
                                cache.add(url).catch(err => {})
                            )
                        );
                    });
            })
            .then(() => {
                return self.skipWaiting();
            })
            .catch((error) => {
                // エラーを無視
            })
    );
});

// アクティベートイベント - 古いキャッシュを削除
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        // 現在のバージョンでないキャッシュを削除
                        if (!cacheName.startsWith(CACHE_VERSION)) {
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                return self.clients.claim();
            })
    );
});

// フェッチイベント - リクエストの処理
self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // 外部リソース（CDN）の処理
    if (!url.origin.includes(self.location.origin) && !url.hostname.includes('cdnjs.cloudflare.com')) {
        return; // 外部APIなどはService Workerで処理しない
    }

    // APIリクエストの処理（Network First戦略）
    if (url.pathname.includes('/api/')) {
        event.respondWith(networkFirstStrategy(request, CACHE_API));
        return;
    }

    // PHPページの処理（Network First with Cache Fallback）
    if (url.pathname.endsWith('.php') || url.pathname.endsWith('/')) {
        event.respondWith(networkFirstStrategy(request, CACHE_DYNAMIC));
        return;
    }

    // 静的リソース（CSS, JS, 画像）の処理（Cache First戦略）
    if (request.destination === 'style' ||
        request.destination === 'script' ||
        request.destination === 'image' ||
        request.destination === 'font') {
        event.respondWith(cacheFirstStrategy(request, CACHE_STATIC));
        return;
    }

    // その他のリクエスト（Default: Network First）
    event.respondWith(networkFirstStrategy(request, CACHE_DYNAMIC));
});

// キャッシュ優先戦略（静的リソース用）
async function cacheFirstStrategy(request, cacheName) {
    try {
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        const networkResponse = await fetch(request);

        // 成功したレスポンスをキャッシュ
        if (networkResponse && networkResponse.status === 200) {
            const cache = await caches.open(cacheName);
            cache.put(request, networkResponse.clone());
        }

        return networkResponse;
    } catch (error) {
        // キャッシュにもない場合、オフラインページを返す
        if (request.destination === 'document') {
            const cache = await caches.open(CACHE_STATIC);
            return cache.match(OFFLINE_PAGE);
        }

        throw error;
    }
}

// ネットワーク優先戦略（動的コンテンツ用）
async function networkFirstStrategy(request, cacheName) {
    try {
        const networkResponse = await fetch(request);

        // 成功したレスポンスをキャッシュ
        if (networkResponse && networkResponse.status === 200) {
            const cache = await caches.open(cacheName);
            // POSTリクエストはキャッシュしない
            if (request.method === 'GET') {
                cache.put(request, networkResponse.clone());
            }
        }

        return networkResponse;
    } catch (error) {
        // ネットワークが失敗した場合、キャッシュから取得
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            return cachedResponse;
        }

        // キャッシュにもない場合
        if (request.destination === 'document') {
            const cache = await caches.open(CACHE_STATIC);
            const offlinePage = await cache.match(OFFLINE_PAGE);
            if (offlinePage) {
                return offlinePage;
            }
        }

        // 最終的なフォールバック
        return new Response('オフラインです。ネットワーク接続を確認してください。', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: new Headers({
                'Content-Type': 'text/plain; charset=utf-8'
            })
        });
    }
}

// メッセージイベント - クライアントからの指示を処理
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }

    if (event.data && event.data.type === 'CLEAR_CACHE') {
        event.waitUntil(
            caches.keys().then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => caches.delete(cacheName))
                );
            })
        );
    }
});

// プッシュ通知イベント（将来的な拡張用）
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const data = event.data.json();
    const options = {
        body: data.body || '新しい通知があります',
        icon: '<?= $basePath ?>/icons/icon-192x192.png',
        badge: '<?= $basePath ?>/icons/favicon-32x32.png',
        vibrate: [200, 100, 200],
        tag: data.tag || 'wts-notification',
        requireInteraction: false
    };

    event.waitUntil(
        self.registration.showNotification(data.title || 'WTS通知', options)
    );
});

// 通知クリックイベント
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    event.waitUntil(
        clients.openWindow(event.notification.data?.url || '<?= $basePath ?>/')
    );
});
