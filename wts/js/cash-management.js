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
    monthButtons.forEach(button => {
        button.addEventListener('click', function() {
            changeMonth(this.dataset.month);
        });
    });
    
    // 月選択インプット
    const monthSelector = document.getElementById('month_selector');
    if (monthSelector) {
        monthSelector.addEventListener('change', function() {
            changeMonth(this.value);
        });
    }
    
    // +/-ボタンのイベント
    const countButtons = document.querySelectorAll('.count-btn');
    countButtons.forEach(button => {
        button.addEventListener('click', function() {
            const denomination = this.dataset.denomination;
            const delta = parseInt(this.dataset.delta);
            adjustCount(denomination, delta);
        });
    });
    
    // 入力フィールドの変更
    Object.keys(baseChange).forEach(denomination => {
        const input = document.getElementById(denomination);
        if (input) {
            input.addEventListener('change', calculateTotals);
            input.addEventListener('input', calculateTotals);
        }
    });
    
    // フォーム送信
    const cashCountForm = document.getElementById('cashCountForm');
    if (cashCountForm) {
        cashCountForm.addEventListener('submit', handleFormSubmit);
    }
}

// 運転者変更時の処理
function changeDriver() {
    const selectedDriverId = document.getElementById('driver_select').value;
    const url = new URL(window.location);
    url.searchParams.set('driver_id', selectedDriverId);
    window.location.href = url.toString();
}

// 月変更時の処理
function changeMonth(month) {
    const url = new URL(window.location);
    url.searchParams.set('month', month);
    url.hash = '#monthly'; // 月次統計タブを維持
    window.location.href = url.toString();
}

// 金種枚数調整
function adjustCount(denomination, delta) {
    const input = document.getElementById(denomination);
    if (!input) return;
    
    const currentValue = parseInt(input.value) || 0;
    const newValue = Math.max(0, currentValue + delta);
    input.value = newValue;
    calculateTotals();
    
    // ハプティックフィードバック（モバイル）
    if (navigator.vibrate) {
        navigator.vibrate(10);
    }
}

// 合計計算
function calculateTotals() {
    let totalAmount = 0;
    
    Object.keys(baseChange).forEach(denomination => {
        const input = document.getElementById(denomination);
        if (!input) return;
        
        const count = parseInt(input.value) || 0;
        const baseCount = baseChange[denomination].count;
        const unit = baseChange[denomination].unit;
        const amount = count * unit;
        const diff = count - baseCount;
        
        // 金額表示
        const amountElement = document.getElementById('amount_' + denomination);
        if (amountElement) {
            amountElement.textContent = '¥' + amount.toLocaleString();
        }
        
        // 差異表示
        const diffElement = document.getElementById('diff_' + denomination);
        if (diffElement) {
            const diffText = diff === 0 ? '±0' : (diff > 0 ? '+' + diff : diff.toString());
            diffElement.textContent = diffText + '枚';
            diffElement.className = 'difference ' + (diff > 0 ? 'positive' : diff < 0 ? 'negative' : '');
        }
        
        totalAmount += amount;
    });
    
    // サマリー更新
    const depositAmount = totalAmount - baseTotal;
    const expectedAmount = baseTotal + systemCashSales;
    const actualDifference = totalAmount - expectedAmount;
    
    // 表示更新
    updateSummaryElement('count_total', '¥' + totalAmount.toLocaleString());
    updateSummaryElement('deposit_amount', '¥' + depositAmount.toLocaleString());
    updateSummaryElement('actual_difference', '¥' + actualDifference.toLocaleString());
    
    // 差額の色分け
    const diffElement = document.getElementById('actual_difference');
    if (diffElement) {
        diffElement.style.color = actualDifference === 0 ? '#fff' : actualDifference > 0 ? '#28a745' : '#dc3545';
    }
}

// サマリー要素の更新
function updateSummaryElement(id, text) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = text;
    }
}

// フォーム送信処理
function handleFormSubmit(e) {
    e.preventDefault();
    
    const submitBtn = document.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 保存中...';
    }
    
    const formData = {
        confirmation_date: document.getElementById('confirmation_date').value,
        driver_id: document.getElementById('driver_id').value,
        memo: document.getElementById('memo').value || ''
    };
    
    // 金種データ追加
    Object.keys(baseChange).forEach(denomination => {
        const input = document.getElementById(denomination);
        formData[denomination] = input ? (parseInt(input.value) || 0) : 0;
    });
    
    // 合計金額計算
    let totalAmount = 0;
    Object.keys(baseChange).forEach(denomination => {
        totalAmount += formData[denomination] * baseChange[denomination].unit;
    });
    formData.total_amount = totalAmount;
    
    console.log('送信データ:', formData); // デバッグ用
    
    // 保存処理（Ajax）
    fetch('api/save_cash_count.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(formData)
    })
    .then(response => {
        console.log('レスポンスステータス:', response.status); // デバッグ用
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        return response.text(); // まずテキストで取得
    })
    .then(text => {
        console.log('レスポンステキスト:', text); // デバッグ用
        
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('JSONパースエラー: ' + text.substring(0, 100));
        }
    })
    .then(data => {
        console.log('パース後データ:', data); // デバッグ用
        
        if (data.success) {
            alert('✅ ' + data.message);
            // 成功時にページをリロードして最新データを表示
            window.location.reload();
        } else {
            alert('❌ 保存エラー: ' + (data.message || '不明なエラー'));
            if (data.debug) {
                console.error('詳細デバッグ情報:', data.debug);
            }
        }
    })
    .catch(error => {
        console.error('通信エラー:', error);
        alert('❌ 保存中にエラーが発生しました: ' + error.message);
    })
    .finally(() => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> 保存する';
        }
    });
}
