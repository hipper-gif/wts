// 集金管理JavaScript（CSP準拠版）

// アプリケーションデータの読み込み
let appData = {};
let baseChange = {};
let baseTotal = 0;
let systemCashSales = 0;
let selectedDriverId = 0;

// DOM読み込み完了時の初期化
document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
    setupEventListeners();
    calculateTotals();
});

// アプリケーション初期化
function initializeApp() {
    try {
        const dataScript = document.getElementById('app-data');
        appData = JSON.parse(dataScript.textContent);
        
        baseChange = appData.baseChange;
        baseTotal = appData.baseTotal;
        systemCashSales = appData.systemCashSales;
        selectedDriverId = appData.selectedDriverId;
        
        // 運転者IDを現在選択されたものに更新
        const driverIdInput = document.getElementById('driver_id');
        if (driverIdInput) {
            driverIdInput.value = selectedDriverId;
        }
        
        // URLのハッシュに基づいてタブを表示
        if (window.location.hash === '#monthly') {
            const monthlyTab = document.getElementById('monthly-tab');
            if (monthlyTab) {
                monthlyTab.click();
            }
        }
        
    } catch (error) {
        console.error('アプリケーションデータの読み込みに失敗:', error);
    }
}

// イベントリスナーの設定
function setupEventListeners() {
    // 運転者選択の変更
    const driverSelect = document.getElementById('driver_select');
    if (driverSelect) {
        driverSelect.addEventListener('change', changeDriver);
    }
    
    // 月選択ボタン
    const monthButtons = document.querySelectorAll('button[data-month]');
    monthButtons.
