/**
 * 集金管理システム JavaScript
 */

// 差額計算（日次管理用）
document.addEventListener('DOMContentLoaded', function() {
    const confirmedAmountInput = document.getElementById('confirmed_amount');
    const differenceDisplay = document.getElementById('difference_display');
    const differenceInput = document.getElementById('difference');
    
    if (confirmedAmountInput && differenceDisplay && differenceInput) {
        const calculatedAmount = parseInt(confirmedAmountInput.dataset.calculated || 0);
        
        confirmedAmountInput.addEventListener('input', function() {
            const confirmedAmount = parseInt(this.value) || 0;
            const difference = confirmedAmount - calculatedAmount;
            
            differenceInput.value = difference;
            
            if (difference > 0) {
                differenceDisplay.innerHTML = '+¥' + difference.toLocaleString();
                differenceDisplay.className = 'fs-4 difference-positive';
            } else if (difference < 0) {
                differenceDisplay.innerHTML = '¥' + difference.toLocaleString();
                differenceDisplay.className = 'fs-4 difference-negative';
            } else {
                differenceDisplay.innerHTML = '¥0';
                differenceDisplay.className = 'fs-4';
            }
        });
    }
});

// 現金確認修正
function editConfirmation() {
    if (confirm('現金確認記録を修正しますか？')) {
        location.reload();
    }
}

// フォーム送信確認（日次管理用）
document.addEventListener('DOMContentLoaded', function() {
    const cashConfirmForm = document.getElementById('cashConfirmForm');
    if (cashConfirmForm) {
        cashConfirmForm.addEventListener('submit', function(e) {
            const differenceInput = document.getElementById('difference');
            if (differenceInput) {
                const difference = parseInt(differenceInput.value);
                if (Math.abs(difference) > 0) {
                    if (!confirm(`差額が${difference > 0 ? '+' : ''}¥${difference.toLocaleString()}あります。\n記録してよろしいですか？`)) {
                        e.preventDefault();
                    }
                }
            }
        });
    }
});

// レポート出力機能
function exportMonthlyExcel() {
    const month = new URLSearchParams(window.location.search).get('month') || new Date().toISOString().slice(0, 7);
    window.open(`export.php?type=monthly_excel&month=${month}`, '_blank');
}

function exportMonthlyPDF() {
    const month = new URLSearchParams(window.location.search).get('month') || new Date().toISOString().slice(0, 7);
    window.open(`export.php?type=monthly_pdf&month=${month}`, '_blank');
}

function exportDetailedPDF() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get('start_date') || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10);
    const endDate = urlParams.get('end_date') || new Date().toISOString().slice(0, 10);
    window.open(`export.php?type=detailed_pdf&start_date=${startDate}&end_date=${endDate}`, '_blank');
}

function exportDetailedExcel() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get('start_date') || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10);
    const endDate = urlParams.get('end_date') || new Date().toISOString().slice(0, 10);
    window.open(`export.php?type=detailed_excel&start_date=${startDate}&end_date=${endDate}`, '_blank');
}

function exportSummaryReport() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get('start_date') || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10);
    const endDate = urlParams.get('end_date') || new Date().toISOString().slice(0, 10);
    window.open(`export.php?type=summary_report&start_date=${startDate}&end_date=${endDate}`, '_blank');
}

function exportCashReport() {
    const urlParams = new URLSearchParams(window.location.search);
    const startDate = urlParams.get('start_date') || new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10);
    const endDate = urlParams.get('end_date') || new Date().toISOString().slice(0, 10);
    window.open(`export.php?type=cash_report&start_date=${startDate}&end_date=${endDate}`, '_blank');
}

// 設定関連機能
function backupData() {
    if (confirm('現金確認データをバックアップしますか？')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'backup.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'backup_cash_data';
        form.appendChild(actionInput);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
}

function restoreData() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.sql,.csv';
    input.onchange = function(e) {
        const file = e.target.files[0];
        if (file && confirm(`ファイル "${file.name}" からデータを復元しますか？\n既存のデータは上書きされます。`)) {
            const formData = new FormData();
            formData.append('backup_file', file);
            formData.append('action', 'restore_cash_data');
            
            fetch('backup.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('データの復元が完了しました。');
                    location.reload();
                } else {
                    alert('復元に失敗しました: ' + data.error);
                }
            })
            .catch(error => {
                alert('復元中にエラーが発生しました: ' + error);
            });
        }
    };
    input.click();
}

function cleanupData() {
    const months = prompt('何ヶ月以前のデータを削除しますか？（数字のみ入力）', '12');
    if (months && !isNaN(months) && parseInt(months) > 0) {
        if (confirm(`${months}ヶ月以前のデータを削除します。\nこの操作は取り消せません。実行しますか？`)) {
            fetch('maintenance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'cleanup_old_data',
                    months: parseInt(months)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`${data.deleted_count}件のレコードを削除しました。`);
                    location.reload();
                } else {
                    alert('クリーンアップに失敗しました: ' + data.error);
                }
            })
            .catch(error => {
                alert('クリーンアップ中にエラーが発生しました: ' + error);
            });
        }
    }
}

// 診断・修復ツール
function checkDataIntegrity() {
    if (confirm('データ整合性チェックを実行しますか？')) {
        fetch('maintenance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'check_data_integrity'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let message = 'データ整合性チェック完了\n\n';
                message += `チェック項目: ${data.checks_performed}\n`;
                message += `エラー件数: ${data.errors_found}\n`;
                if (data.errors_found > 0) {
                    message += '\n詳細:\n' + data.errors.join('\n');
                }
                alert(message);
            } else {
                alert('チェックに失敗しました: ' + data.error);
            }
        })
        .catch(error => {
            alert('チェック中にエラーが発生しました: ' + error);
        });
    }
}

function rebuildIndexes() {
    if (confirm('インデックスを再構築しますか？\n処理に時間がかかる場合があります。')) {
        fetch('maintenance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'rebuild_indexes'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('インデックスの再構築が完了しました。');
            } else {
                alert('再構築に失敗しました: ' + data.error);
            }
        })
        .catch(error => {
            alert('再構築中にエラーが発生しました: ' + error);
        });
    }
}

function optimizeTables() {
    if (confirm('テーブル最適化を実行しますか？\n処理に時間がかかる場合があります。')) {
        fetch('maintenance.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'optimize_tables'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`${data.optimized_tables}個のテーブルを最適化しました。`);
            } else {
                alert('最適化に失敗しました: ' + data.error);
            }
        })
        .catch(error => {
            alert('最適化中にエラーが発生しました: ' + error);
        });
    }
}

function generateTestData() {
    const days = prompt('何日分のテストデータを生成しますか？', '30');
    if (days && !isNaN(days) && parseInt(days) > 0) {
        if (confirm(`${days}日分のテストデータを生成します。\n既存のデータには影響しません。`)) {
            fetch('maintenance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'generate_test_data',
                    days: parseInt(days)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`${data.generated_records}件のテストデータを生成しました。`);
                    location.reload();
                } else {
                    alert('テストデータ生成に失敗しました: ' + data.error);
                }
            })
            .catch(error => {
                alert('生成中にエラーが発生しました: ' + error);
            });
        }
    }
}

// タブ変更時の処理
document.addEventListener('DOMContentLoaded', function() {
    const tabLinks = document.querySelectorAll('[data-bs-toggle="tab"]');
    tabLinks.forEach(link => {
        link.addEventListener('shown.bs.tab', function(e) {
            const target = e.target.getAttribute('data-bs-target');
            const tabName = target.replace('#', '');
            
            // URLパラメータを更新（ページリロードなし）
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.replaceState({}, '', url);
        });
    });
});

// 数値フォーマット用ヘルパー関数
function formatCurrency(amount) {
    return '¥' + parseInt(amount).toLocaleString();
}

function formatNumber(number) {
    return parseInt(number).toLocaleString();
}
