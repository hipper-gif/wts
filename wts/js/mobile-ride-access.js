<?php
/**
 * スマホ対応・右下フローティングヘッダー式乗務記録アクセス
 * モバイル最優先設計での乗務記録アクセス改善案
 */
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード - モバイル最適化版</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #2c3e50;
            --success: #27ae60;
            --info: #3498db;
            --warning: #f39c12;
            --danger: #e74c3c;
            --ride-primary: #11998e;
            --ride-secondary: #38ef7d;
        }
        
        body {
            padding-bottom: 100px; /* フローティングヘッダー分の余白 */
        }
        
        /* メインフローティングヘッダー（右下固定） */
        .mobile-ride-header {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 15px;
        }
        
        /* 乗務記録専用ヘッダーバー */
        .ride-header-bar {
            background: linear-gradient(135deg, var(--ride-primary) 0%, var(--ride-secondary) 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 25px;
            box-shadow: 0 4px 20px rgba(17, 153, 142, 0.3);
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 280px;
            transform: translateX(220px);
            transition: transform 0.3s ease;
        }
        
        .ride-header-bar.expanded {
            transform: translateX(0);
        }
        
        .ride-toggle-btn {
            background: var(--ride-primary);
            color: white;
            border: none;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            font-size: 20px;
            box-shadow: 0 4px 15px rgba(17, 153, 142, 0.4);
            cursor: pointer;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }
        
        .ride-toggle-btn:hover {
            background: var(--ride-secondary);
            transform: scale(1.1);
        }
        
        .ride-header-content {
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .ride-header-bar.expanded .ride-header-content {
            opacity: 1;
        }
        
        .ride-quick-action {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 8px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        
        .ride-quick-action:hover {
            background: rgba(255,255,255,0.3);
            color: white;
            transform: translateY(-1px);
        }
        
        /* ステータス表示 */
        .ride-status-indicator {
            background: white;
            color: var(--ride-primary);
            border-radius: 20px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 200px;
            transform: translateX(140px);
            transition: transform 0.3s ease;
        }
        
        .ride-status-indicator.expanded {
            transform: translateX(0);
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .status-text {
            font-size: 13px;
            font-weight: bold;
            flex: 1;
        }
        
        /* 今日の記録カウンター */
        .ride-counter {
            background: white;
            color: var(--primary);
            border-radius: 20px;
            padding: 10px 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            min-width: 120px;
            transform: translateX(60px);
            transition: transform 0.3s ease;
        }
        
        .ride-counter.expanded {
            transform: translateX(0);
        }
        
        .counter-number {
            font-size: 24px;
            font-weight: bold;
            color: var(--ride-primary);
            line-height: 1;
        }
        
        .counter-label {
            font-size: 11px;
            opacity: 0.7;
        }
        
        /* メインコンテンツの調整 */
        .main-content {
            margin-bottom: 120px;
        }
        
        .content-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .today-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
        }
        
        .summary-stat {
            padding: 10px;
        }
        
        .stat-value {
            font-size: 20px;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 11px;
            opacity: 0.8;
        }
        
        /* ハプティックフィードバック効果 */
        .haptic-feedback {
            animation: haptic 0.1s ease;
        }
        
        @keyframes haptic {
            0% { transform: scale(1); }
            50% { transform: scale(0.95); }
            100% { transform: scale(1); }
        }
        
        /* 緊急アクション用ボタン */
        .emergency-ride-btn {
            background: var(--danger);
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 16px;
            box-shadow: 0 3px 12px rgba(231, 76, 60, 0.4);
            margin-bottom: 10px;
        }
        
        /* 展開時のオーバーレイ */
        .ride-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.3);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .ride-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* スマホ画面サイズでの最適化 */
        @media (max-width: 430px) {
            .ride-header-bar {
                min-width: 250px;
                transform: translateX(190px);
                padding: 10px 15px;
            }
            
            .ride-status-indicator {
                min-width: 180px;
                transform: translateX(120px);
            }
            
            .ride-counter {
                min-width: 100px;
                transform: translateX(40px);
            }
        }
        
        @media (max-width: 375px) {
            .mobile-ride-header {
                bottom: 15px;
                right: 15px;
            }
            
            .ride-header-bar {
                min-width: 220px;
                transform: translateX(160px);
            }
        }
        
        /* スワイプジェスチャーのヒント */
        .swipe-hint {
            position: fixed;
            bottom: 90px;
            right: 25px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 8px 12px;
            border-radius: 15px;
            font-size: 11px;
            opacity: 0;
            animation: fadeInOut 3s ease-in-out;
            z-index: 1001;
        }
        
        @keyframes fadeInOut {
            0%, 100% { opacity: 0; }
            50% { opacity: 1; }
        }
    </style>
</head>
<body class="bg-light">
    <!-- メインヘッダー（簡素化） -->
    <nav class="navbar navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <i class="fas fa-taxi"></i> スマイリーケア
            </span>
            <span class="navbar-text">
                <i class="fas fa-user"></i> 杉原星
            </span>
        </div>
    </nav>

    <div class="container-fluid py-3">
        <!-- 今日のサマリー -->
        <div class="content-card today-summary">
            <h6 class="mb-3">📅 今日の実績 - 2025年9月4日</h6>
            <div class="row">
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value">12</div>
                        <div class="stat-label">乗車</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value">¥36K</div>
                        <div class="stat-label">売上</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value">180</div>
                        <div class="stat-label">km</div>
                    </div>
                </div>
                <div class="col-3">
                    <div class="summary-stat">
                        <div class="stat-value">¥3K</div>
                        <div class="stat-label">平均</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 簡略化された業務メニュー -->
        <div class="content-card">
            <h6><i class="fas fa-tasks"></i> 今日の業務</h6>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="badge bg-success">✓ 日常点検</span>
                <span class="badge bg-success">✓ 乗務前点呼</span>
                <span class="badge bg-success">✓ 出庫</span>
                <span class="badge bg-warning">🚕 運行中</span>
                <span class="badge bg-secondary">入庫待ち</span>
                <span class="badge bg-secondary">点呼待ち</span>
            </div>
        </div>

        <!-- その他の機能へのショートカット -->
        <div class="content-card">
            <h6><i class="fas fa-bolt"></i> クイックアクション</h6>
            <div class="row g-2 mt-2">
                <div class="col-6">
                    <button class="btn btn-outline-primary w-100 btn-sm">
                        <i class="fas fa-yen-sign"></i> 売上確認
                    </button>
                </div>
                <div class="col-6">
                    <button class="btn btn-outline-info w-100 btn-sm">
                        <i class="fas fa-chart-line"></i> 統計
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- 右下フローティング乗務記録ヘッダー -->
    <div class="mobile-ride-header">
        <!-- 緊急アクション（復路作成等） -->
        <button class="emergency-ride-btn" onclick="location.href='ride_records.php?action=return'" title="復路作成">
            <i class="fas fa-sync-alt"></i>
        </button>
        
        <!-- 今日の記録カウンター -->
        <div class="ride-counter" id="rideCounter">
            <div class="counter-number">12</div>
            <div class="counter-label">今日の記録</div>
        </div>
        
        <!-- ステータス表示 -->
        <div class="ride-status-indicator" id="rideStatus">
            <div class="status-dot"></div>
            <div class="status-text">運行中 - 記録待ち</div>
            <button class="btn btn-sm btn-outline-success" onclick="quickAddRide()">
                <i class="fas fa-plus"></i>
            </button>
        </div>
        
        <!-- メイン乗務記録ヘッダー -->
        <div class="ride-header-bar" id="rideHeaderBar">
            <button class="ride-toggle-btn" onclick="toggleRideHeader()">
                <i class="fas fa-car" id="toggleIcon"></i>
            </button>
            <div class="ride-header-content">
                <button class="ride-quick-action" onclick="location.href='ride_records.php'">
                    <i class="fas fa-list"></i> 一覧
                </button>
                <button class="ride-quick-action" onclick="location.href='ride_records.php?action=add'">
                    <i class="fas fa-plus"></i> 新規
                </button>
                <button class="ride-quick-action" onclick="location.href='ride_records.php?action=stats'">
                    <i class="fas fa-chart-bar"></i> 統計
                </button>
            </div>
        </div>
    </div>

    <!-- オーバーレイ -->
    <div class="ride-overlay" id="rideOverlay" onclick="closeRideHeader()"></div>
    
    <!-- スワイプヒント（初回のみ表示） -->
    <div class="swipe-hint" id="swipeHint" style="display: none;">
        👈 スワイプで乗務記録
    </div>

    <script>
        let isRideHeaderExpanded = false;
        let lastTouchX = 0;
        let isFirstVisit = !localStorage.getItem('ride_header_used');

        // 初回訪問時のヒント表示
        if (isFirstVisit) {
            setTimeout(() => {
                document.getElementById('swipeHint').style.display = 'block';
            }, 2000);
        }

        // 乗務記録ヘッダーの展開/収納
        function toggleRideHeader() {
            isRideHeaderExpanded = !isRideHeaderExpanded;
            const headerBar = document.getElementById('rideHeaderBar');
            const counter = document.getElementById('rideCounter');
            const status = document.getElementById('rideStatus');
            const overlay = document.getElementById('rideOverlay');
            const icon = document.getElementById('toggleIcon');

            if (isRideHeaderExpanded) {
                headerBar.classList.add('expanded');
                counter.classList.add('expanded');
                status.classList.add('expanded');
                overlay.classList.add('active');
                icon.className = 'fas fa-times';
                
                // ハプティックフィードバック
                if (navigator.vibrate) {
                    navigator.vibrate(50);
                }
                
                localStorage.setItem('ride_header_used', 'true');
            } else {
                closeRideHeader();
            }
        }

        function closeRideHeader() {
            isRideHeaderExpanded = false;
            document.getElementById('rideHeaderBar').classList.remove('expanded');
            document.getElementById('rideCounter').classList.remove('expanded');
            document.getElementById('rideStatus').classList.remove('expanded');
            document.getElementById('rideOverlay').classList.remove('active');
            document.getElementById('toggleIcon').className = 'fas fa-car';
        }

        // クイック新規記録
        function quickAddRide() {
            // ハプティックフィードバック
            if (navigator.vibrate) {
                navigator.vibrate([50, 50, 50]);
            }
            
            // 新規記録画面へ
            location.href = 'ride_records.php?action=quick_add';
        }

        // スワイプジェスチャー対応
        let startX = 0;
        let startY = 0;

        document.addEventListener('touchstart', function(e) {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        });

        document.addEventListener('touchend', function(e) {
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;
            const diffX = startX - endX;
            const diffY = Math.abs(startY - endY);
            
            // 右下エリアからの左スワイプで展開
            if (startX > window.innerWidth - 100 && 
                startY > window.innerHeight - 200 &&
                diffX > 50 && diffY < 50) {
                if (!isRideHeaderExpanded) {
                    toggleRideHeader();
                }
            }
        });

        // 記録カウンターのリアルタイム更新
        function updateRideCounter() {
            fetch('api/get_today_ride_count.php')
                .then(response => response.json())
                .then(data => {
                    document.querySelector('.counter-number').textContent = data.count;
                    
                    // ステータス更新
                    const statusText = document.querySelector('.status-text');
                    if (data.count > 0) {
                        statusText.textContent = `運行中 - ${data.count}件記録済み`;
                    } else {
                        statusText.textContent = '運行中 - 記録待ち';
                    }
                })
                .catch(error => console.log('カウンター更新エラー:', error));
        }

        // 定期的な更新
        setInterval(updateRideCounter, 30000); // 30秒ごと

        // 長押しでメニュー表示
        let pressTimer;
        
        document.getElementById('rideCounter').addEventListener('touchstart', function(e) {
            pressTimer = setTimeout(() => {
                // 長押しメニュー表示
                if (confirm('乗務記録の詳細メニューを表示しますか？')) {
                    location.href = 'ride_records.php?action=menu';
                }
            }, 1000);
        });

        document.getElementById('rideCounter').addEventListener('touchend', function(e) {
            clearTimeout(pressTimer);
        });

        // キーボードショートカット（開発用）
        document.addEventListener('keydown', function(e) {
            if (e.key === 'r' && e.ctrlKey) {
                e.preventDefault();
                toggleRideHeader();
            }
        });

        // PWA的な動作（オフライン対応の準備）
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js')
                .then(registration => console.log('SW registered'))
                .catch(error => console.log('SW registration failed'));
        }

        // バッテリー節約モード（非アクティブ時の更新停止）
        let isActive = true;
        
        document.addEventListener('visibilitychange', function() {
            isActive = !document.hidden;
            if (isActive) {
                updateRideCounter(); // ページがアクティブになったら即座更新
            }
        });
    </script>
</body>
</html>
