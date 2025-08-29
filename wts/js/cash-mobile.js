// js/cash-mobile.js
// 集金管理モバイル最適化スクリプト
// 作成日: 2025年8月28日

class CashMobileManager {
    constructor() {
        this.isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent);
        this.isAndroid = /Android/.test(navigator.userAgent);
        this.isTouchDevice = 'ontouchstart' in window || navigator.maxTouchPoints > 0;
        this.screenWidth = window.innerWidth;
        this.vibrationSupported = 'vibrate' in navigator;
        
        this.init();
    }
    
    init() {
        this.setupTouchOptimization();
        this.setupKeyboardHandling();
        this.setupSwipeGestures();
        this.setupHapticFeedback();
        this.setupNotifications();
        this.setupOfflineSupport();
        
        console.log('集金管理モバイル機能初期化完了');
    }
    
    // タッチ操作最適化
    setupTouchOptimization() {
        // ダブルタップズーム防止（iOS）
        document.addEventListener('gesturestart', (e) => {
            e.preventDefault();
        });
        
        // タッチ遅延防止
        document.addEventListener('touchstart', () => {}, { passive: true });
        
        // 長押しメニュー防止（金種別ボタン）
        document.querySelectorAll('.count-btn, .count-input').forEach(element => {
            element.addEventListener('contextmenu', (e) => {
                e.preventDefault();
            });
            
            // 長押し時のバイブレーション
            let pressTimer;
            element.addEventListener('touchstart', (e) => {
                pressTimer = setTimeout(() => {
                    this.vibrate([10]);
                }, 500);
            });
            
            element.addEventListener('touchend', () => {
                clearTimeout(pressTimer);
            });
        });
        
        // スクロール最適化
        this.optimizeScrolling();
    }
    
    // キーボード処理（数値入力最適化）
    setupKeyboardHandling() {
        // 数値入力フィールドの最適化
        document.querySelectorAll('.count-input').forEach(input => {
            // iOSの数値キーボード表示
            if (this.isIOS) {
                input.setAttribute('inputmode', 'numeric');
                input.setAttribute('pattern', '[0-9]*');
            }
            
            // Android数値キーパッド
            if (this.isAndroid) {
                input.type = 'tel';
            }
            
            // キーボード表示/非表示時の画面調整
            input.addEventListener('focus', () => {
                this.handleKeyboardShow(input);
            });
            
            input.addEventListener('blur', () => {
                this.handleKeyboardHide();
            });
            
            // 数値以外の入力を防止
            input.addEventListener('input', (e) => {
                let value = e.target.value.replace(/[^0-9]/g, '');
                if (e.target.value !== value) {
                    e.target.value = value;
                    this.vibrate([5]); // 無効入力時のフィードバック
                }
            });
        });
    }
    
    // スワイプジェスチャー
    setupSwipeGestures() {
        let startX, startY, startTime;
        
        document.addEventListener('touchstart', (e) => {
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            startTime = Date.now();
        }, { passive: true });
        
        document.addEventListener('touchend', (e) => {
            if (!startX || !startY) return;
            
            const endX = e.changedTouches[0].clientX;
            const endY = e.changedTouches[0].clientY;
            const endTime = Date.now();
            
            const deltaX = endX - startX;
            const deltaY = endY - startY;
            const deltaTime = endTime - startTime;
            
            // スワイプ判定（100px以上、500ms以内、横方向優勢）
            if (Math.abs(deltaX) > 100 && deltaTime < 500 && Math.abs(deltaX) > Math.abs(deltaY)) {
                if (deltaX > 0) {
                    this.handleSwipeRight();
                } else {
                    this.handleSwipeLeft();
                }
            }
            
            startX = startY = null;
        }, { passive: true });
    }
    
    // ハプティックフィードバック
    setupHapticFeedback() {
        // 成功時のフィードバック
        this.successFeedback = () => {
            this.vibrate([10, 50, 10]);
        };
        
        // エラー時のフィードバック
        this.errorFeedback = () => {
            this.vibrate([100, 50, 100, 50, 100]);
        };
        
        // ボタン押下時のフィードバック
        document.querySelectorAll('.btn').forEach(button => {
            button.addEventListener('touchstart', () => {
                this.vibrate([5]);
            }, { passive: true });
        });
    }
    
    // プッシュ通知
    setupNotifications() {
        if ('Notification' in window && 'serviceWorker' in navigator) {
            // 通知許可要求
            if (Notification.permission === 'default') {
                setTimeout(() => {
                    this.requestNotificationPermission();
                }, 3000);
            }
        }
    }
    
    // オフライン対応
    setupOfflineSupport() {
        // オンライン/オフライン状態監視
        window.addEventListener('online', () => {
            this.showConnectionStatus('オンラインに復帰しました', 'success');
            this.syncOfflineData();
        });
        
        window.addEventListener('offline', () => {
            this.showConnectionStatus('オフラインモードです', 'warning');
        });
        
        // ページビジビリティ対応
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                // アプリに戻った時の処理
                this.refreshData();
            }
        });
    }
    
    // スワイプハンドラー
    handleSwipeRight() {
        // 右スワイプ：前のタブ
        const tabs = document.querySelectorAll('.nav-link');
        const activeTab = document.querySelector('.nav-link.active');
        const currentIndex = Array.from(tabs).indexOf(activeTab);
        
        if (currentIndex > 0) {
            tabs[currentIndex - 1].click();
            this.vibrate([10]);
        }
    }
    
    handleSwipeLeft() {
        // 左スワイプ：次のタブ
        const tabs = document.querySelectorAll('.nav-link');
        const activeTab = document.querySelector('.nav-link.active');
        const currentIndex = Array.from(tabs).indexOf(activeTab);
        
        if (currentIndex < tabs.length - 1) {
            tabs[currentIndex + 1].click();
            this.vibrate([10]);
        }
    }
    
    // キーボード表示時の調整
    handleKeyboardShow(input) {
        setTimeout(() => {
            // 入力フィールドを画面中央に
            input.scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center',
                inline: 'nearest'
            });
        }, 300);
    }
    
    handleKeyboardHide() {
        // 画面を元の位置に
        setTimeout(() => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 300);
    }
    
    // スクロール最適化
    optimizeScrolling() {
        // iOS Safari のバウンススクロール防止
        if (this.isIOS) {
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
            
            const scrollContainer = document.createElement('div');
            scrollContainer.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
            `;
            
            const content = document.body.innerHTML;
            document.body.innerHTML = '';
            scrollContainer.innerHTML = content;
            document.body.appendChild(scrollContainer);
        }
    }
    
    // バイブレーション
    vibrate(pattern) {
        if (this.vibrationSupported && navigator.vibrate) {
            navigator.vibrate(pattern);
        }
    }
    
    // 通知許可要求
    async requestNotificationPermission() {
        try {
            const permission = await Notification.requestPermission();
            if (permission === 'granted') {
                this.showNotification('通知が有効になりました', '集金完了時にお知らせします');
            }
        } catch (error) {
            console.log('通知許可要求エラー:', error);
        }
    }
    
    // 通知表示
    showNotification(title, body, options = {}) {
        if (Notification.permission === 'granted') {
            const notification = new Notification(title, {
                body: body,
                icon: '/favicon-192.png',
                badge: '/favicon-96.png',
                tag: 'cash-management',
                requireInteraction: false,
                silent: false,
                ...options
            });
            
            setTimeout(() => {
                notification.close();
            }, 5000);
        }
    }
    
    // 接続状態表示
    showConnectionStatus(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `alert alert-${type} position-fixed`;
        toast.style.cssText = `
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 9999;
            min-width: 250px;
            text-align: center;
        `;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
    
    // オフラインデータ同期
    syncOfflineData() {
        // LocalStorageからオフラインデータを取得して同期
        const offlineData = localStorage.getItem('cash_offline_data');
        if (offlineData) {
            try {
                const data = JSON.parse(offlineData);
                // サーバーに送信
                fetch('api/sync_offline_cash.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                }).then(response => {
                    if (response.ok) {
                        localStorage.removeItem('cash_offline_data');
                        this.showNotification('オフラインデータを同期しました');
                    }
                });
            } catch (error) {
                console.error('オフライン同期エラー:', error);
            }
        }
    }
    
    // データ更新
    refreshData() {
        // アクティブタブに応じてデータ更新
        const activeTab = document.querySelector('.nav-link.active');
        if (activeTab && activeTab.id === 'monthly-tab') {
            if (typeof loadCashHistory === 'function') {
                loadCashHistory();
            }
        }
    }
    
    // 集金保存成功時のフィードバック
    onSaveSuccess() {
        this.successFeedback();
        this.showNotification(
            '集金データ保存完了',
            '入金額: ¥' + document.getElementById('deposit-amount').textContent.replace(/[¥,]/g, '').toLocaleString()
        );
    }
    
    // エラー時のフィードバック
    onError(message) {
        this.errorFeedback();
        this.showConnectionStatus(message, 'danger');
    }
}

// モバイル最適化機能の初期化
document.addEventListener('DOMContentLoaded', () => {
    if (window.innerWidth <= 430) { // モバイルサイズの場合のみ
        window.cashMobile = new CashMobileManager();
    }
});

// 集金保存関数の拡張（モバイル対応）
const originalSaveCashCount = window.saveCashCount;
window.saveCashCount = function() {
    if (originalSaveCashCount) {
        // 元の保存処理を実行し、結果に応じてフィードバック
        return originalSaveCashCount().then(result => {
            if (window.cashMobile) {
                if (result && result.success) {
                    window.cashMobile.onSaveSuccess();
                } else {
                    window.cashMobile.onError('保存に失敗しました');
                }
            }
            return result;
        }).catch(error => {
            if (window.cashMobile) {
                window.cashMobile.onError('通信エラーが発生しました');
            }
            throw error;
        });
    }
};

// PWA用のService Worker登録
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(registration => {
                console.log('SW registered: ', registration);
            })
            .catch(registrationError => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}
