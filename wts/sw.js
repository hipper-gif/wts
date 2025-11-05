// 福祉輸送管理システム (WTS) - Service Worker
// Version 1.0.0

const CACHE_VERSION = 'wts-v1.0.0';
const CACHE_STATIC = `${CACHE_VERSION}-static`;
const CACHE_DYNAMIC = `${CACHE_VERSION}-dynamic`;
const CACHE_API = `${CACHE_VERSION}-api`;

// キャッシュする静的リソース
const STATIC_CACHE_URLS = [
    '/Smiley/taxi/wts/',
    '/Smiley/taxi/wts/index.php',
    '/Smiley/taxi/wts/dashboard.php',
    '/Smiley/taxi/wts/manifest.json',

    // CSS
    '/Smiley/taxi/wts/css/ui-unified-v3.css',
    '/Smiley/taxi/wts/css/header-unified.css',
    '/Smiley/taxi/wts/calendar/css/calendar.css',
    '/Smiley/taxi/wts/calendar/css/reservation.css',

    // JavaScript
    '/Smiley/taxi/wts/js/cash-management.js',
    '/Smiley/taxi/wts/js/cash-mobile.js',
    '/Smiley/taxi/wts/js/ui-interactions.js',
    '/Smiley/taxi/wts/js/mobile-ride-access.js',
    '/Smiley/taxi/wts/calendar/js/calendar.js',
    '/Smiley/taxi/wts/calendar/js/reservation.js',
    '/Smiley/taxi/wts/calendar/js/vehicle_constraints.js',

    // アイコン
    '/Smiley/taxi/wts/icons/favicon-16x16.png',
    '/Smiley/taxi/wts/icons/favicon-32x32.png',
    '/Smiley/taxi/wts/icons/apple-touch-icon.png',
    '/Smiley/taxi/wts/icons/icon-192x192.png',
    '/Smiley/taxi/wts/icons/icon-512x512.png',

    // 主要ページ
    '/Smiley/taxi/wts/daily_inspection.php',
    '/Smiley/taxi/wts/ride_records.php',
    '/Smiley/taxi/wts/cash_management.php',
    '/Smiley/taxi/wts/arrival.php',
    '/Smiley/taxi/wts/departure.php',

    // CDN（オンラインのみ）
    'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
];

// オフラインフォールバックページ
const OFFLINE_PAGE = '/Smiley/taxi/wts/index.php';

// インストールイベント - 静的リソースをキャッシュ
self.addEventListener('install', (event) => {
    console.log('[Service Worker] インストール中...', CACHE_VERSION);

    event.waitUntil(
        caches.open(CACHE_STATIC)
            .then((cache) => {
                console.log('[Service Worker] 静的リソースをキャッシュ中...');
                // 重要なリソースのみ必須キャッシュ、その他は無視
                return cache.addAll(STATIC_CACHE_URLS.slice(0, 5))
                    .then(() => {
                        // 残りのリソースは個別に追加（エラーを無視）
                        return Promise.allSettled(
                            STATIC_CACHE_URLS.slice(5).map(url =>
                                cache.add(url).catch(err =>
                                    console.warn('[Service Worker] キャッシュ失敗:', url, err)
                                )
                            )
                        );
                    });
            })
            .then(() => {
                console.log('[Service Worker] インストール完了');
                return self.skipWaiting();
            })
            .catch((error) => {
                console.error('[Service Worker] インストール失敗:', error);
            })
    );
});

// アクティベートイベント - 古いキャッシュを削除
self.addEventListener('activate', (event) => {
    console.log('[Service Worker] アクティベート中...', CACHE_VERSION);

    event.waitUntil(
        caches.keys()
            .then((cacheNames) => {
                return Promise.all(
                    cacheNames.map((cacheName) => {
                        // 現在のバージョンでないキャッシュを削除
                        if (!cacheName.startsWith(CACHE_VERSION)) {
                            console.log('[Service Worker] 古いキャッシュを削除:', cacheName);
                            return caches.delete(cacheName);
                        }
                    })
                );
            })
            .then(() => {
                console.log('[Service Worker] アクティベート完了');
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
        console.error('[Service Worker] Cache First エラー:', error);

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
        console.warn('[Service Worker] ネットワークエラー、キャッシュを確認:', error);

        // ネットワークが失敗した場合、キャッシュから取得
        const cachedResponse = await caches.match(request);
        if (cachedResponse) {
            console.log('[Service Worker] キャッシュから返却:', request.url);
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
            }).then(() => {
                console.log('[Service Worker] 全キャッシュをクリアしました');
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
        icon: '/Smiley/taxi/wts/icons/icon-192x192.png',
        badge: '/Smiley/taxi/wts/icons/favicon-32x32.png',
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
        clients.openWindow(event.notification.data?.url || '/Smiley/taxi/wts/')
    );
});

console.log('[Service Worker] スクリプト読み込み完了', CACHE_VERSION);
