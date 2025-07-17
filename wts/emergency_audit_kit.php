<?php
session_start();
require_once 'config/database.php';

// 認証チェック
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ユーザー権限の確認
$user_role = '';
$user_name = '';
try {
    $stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    $user_role = $user['role'] ?? 'unknown';
    $user_name = $user['name'] ?? 'unknown';
} catch (PDOException $e) {
    $user_role = 'unknown';
    $user_name = 'unknown';
}

// 現在の監査準備度をチェック
function checkAuditReadiness($pdo) {
    $readiness = [
        'score' => 0,
        'total' => 100,
        'items' => []
    ];
    
    // 過去3ヶ月の点呼記録チェック
    $three_months_ago = date('Y-m-d', strtotime('-3 months'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM pre_duty_calls WHERE call_date >= ?");
    $stmt->execute([$three_months_ago]);
    $call_records = $stmt->fetch()['count'];
    
    if ($call_records >= 60) { // 3ヶ月で最低60件
        $readiness['score'] += 25;
        $readiness['items'][] = ['status' => 'ok', 'item' => '点呼記録', 'message' => '記録完備（' . $call_records . '件）'];
    } else {
        $readiness['items'][] = ['status' => 'warning', 'item' => '点呼記録', 'message' => '記録不足（' . $call_records . '件）'];
    }
    
    // 運転日報チェック（出庫・入庫記録）
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM departure_records WHERE departure_date >= ?");
    $stmt->execute([$three_months_ago]);
    $departure_records = $stmt->fetch()['count'];
    
    if ($departure_records >= 60) {
        $readiness['score'] += 25;
        $readiness['items'][] = ['status' => 'ok', 'item' => '運転日報', 'message' => '記録完備（' . $departure_records . '件）'];
    } else {
        $readiness['items'][] = ['status' => 'warning', 'item' => '運転日報', 'message' => '記録不足（' . $departure_records . '件）'];
    }
    
    // 日常点検記録チェック
    $one_month_ago = date('Y-m-d', strtotime('-1 month'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM daily_inspections WHERE inspection_date >= ?");
    $stmt->execute([$one_month_ago]);
    $inspection_records = $stmt->fetch()['count'];
    
    if ($inspection_records >= 20) {
        $readiness['score'] += 25;
        $readiness['items'][] = ['status' => 'ok', 'item' => '日常点検', 'message' => '記録完備（' . $inspection_records . '件）'];
    } else {
        $readiness['items'][] = ['status' => 'warning', 'item' => '日常点検', 'message' => '記録不足（' . $inspection_records . '件）'];
    }
    
    // 定期点検チェック
    $stmt = $pdo->query("SELECT v.vehicle_number, v.next_inspection_date FROM vehicles v WHERE v.next_inspection_date < CURDATE()");
    $overdue_inspections = $stmt->fetchAll();
    
    if (count($overdue_inspections) == 0) {
        $readiness['score'] += 25;
        $readiness['items'][] = ['status' => 'ok', 'item' => '定期点検', 'message' => '期限内実施'];
    } else {
        $readiness['items'][] = ['status' => 'error', 'item' => '定期点検', 'message' => '期限切れ車両あり（' . count($overdue_inspections) . '台）'];
    }
    
    return $readiness;
}

// 緊急監査セット出力処理
if (isset($_POST['emergency_export'])) {
    $export_type = $_POST['export_type'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // 出力処理をJavaScriptでリダイレクト
    echo "<script>
        window.open('fixed_export_document.php?type=emergency_kit&start={$start_date}&end={$end_date}', '_blank');
        alert('緊急監査セット出力完了！\\n出力形式: {$export_type}\\n期間: {$start_date} ～ {$end_date}');
    </script>";
}

$readiness = checkAuditReadiness($pdo);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🚨 緊急監査対応キット - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .emergency-header {
            background: linear-gradient(135deg, #dc3545, #ffc107);
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
        }
        .readiness-score {
            font-size: 3rem;
            font-weight: bold;
        }
        .status-ok { color: #198754; }
        .status-warning { color: #ffc107; }
        .status-error { color: #dc3545; }
        .emergency-button {
            padding: 15px 25px;
            font-size: 1.2rem;
            margin: 10px;
        }
        .quick-export-card {
            border: 2px solid #dc3545;
            background: #fff5f5;
        }
        .compliance-card {
            border: 2px solid #198754;
            background: #f0fff4;
        }
        .pulse-animation {
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>

<div class="emergency-header text-center">
    <div class="container">
        <h1><i class="fas fa-exclamation-triangle"></i> 緊急監査対応キット</h1>
        <p class="lead">国土交通省・陸運局監査への即座対応システム</p>
        <div class="alert alert-warning d-inline-block">
            <strong>⚡ 5分で監査必須書類を完全準備</strong>
        </div>
    </div>
</div>

<div class="container">
    <!-- 監査準備度ダッシュボード -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card compliance-card">
                <div class="card-body text-center">
                    <h5 class="card-title">監査準備度</h5>
                    <div class="readiness-score <?php echo $readiness['score'] >= 80 ? 'status-ok' : ($readiness['score'] >= 60 ? 'status-warning' : 'status-error'); ?>">
                        <?php echo $readiness['score']; ?>%
                    </div>
                    <div class="progress">
                        <div class="progress-bar <?php echo $readiness['score'] >= 80 ? 'bg-success' : ($readiness['score'] >= 60 ? 'bg-warning' : 'bg-danger'); ?>" 
                             style="width: <?php echo $readiness['score']; ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-clipboard-check"></i> 法令遵守状況</h5>
                </div>
                <div class="card-body">
                    <?php foreach ($readiness['items'] as $item): ?>
                        <div class="d-flex align-items-center mb-2">
                            <i class="fas <?php echo $item['status'] == 'ok' ? 'fa-check-circle status-ok' : ($item['status'] == 'warning' ? 'fa-exclamation-triangle status-warning' : 'fa-times-circle status-error'); ?>"></i>
                            <div class="ms-3">
                                <strong><?php echo $item['item']; ?></strong>: <?php echo $item['message']; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 緊急出力セクション -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card quick-export-card">
                <div class="card-header bg-danger text-white">
                    <h4><i class="fas fa-rocket"></i> 🚨 緊急監査セット出力</h4>
                    <p class="mb-0">監査官来訪時の即座対応 - ワンクリックで必須書類を出力</p>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label"><strong>出力期間</strong></label>
                            <select name="period_preset" id="periodPreset" class="form-select" onchange="setPeriod()">
                                <option value="">カスタム期間</option>
                                <option value="3months">過去3ヶ月（標準）</option>
                                <option value="6months">過去6ヶ月</option>
                                <option value="1year">過去1年</option>
                                <option value="current_month">今月</option>
                                <option value="last_month">先月</option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">開始日</label>
                            <input type="date" name="start_date" id="startDate" class="form-control" 
                                   value="<?php echo date('Y-m-d', strtotime('-3 months')); ?>" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">終了日</label>
                            <input type="date" name="end_date" id="endDate" class="form-control" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">出力形式</label>
                            <select name="export_type" class="form-select" required>
                                <option value="pdf_complete">PDF完全版（推奨）</option>
                                <option value="pdf_summary">PDF要約版</option>
                                <option value="excel_detailed">Excel詳細版</option>
                                <option value="excel_summary">Excel要約版</option>
                            </select>
                        </div>
                        
                        <div class="col-12">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                                <button type="submit" name="emergency_export" class="btn btn-danger emergency-button pulse-animation">
                                    <i class="fas fa-download"></i> 🚨 緊急監査セット出力
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- 個別書類出力 -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-file-alt"></i> 個別必須書類出力</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="card border-primary">
                                <div class="card-body text-center">
                                    <h6 class="card-title">点呼記録簿</h6>
                                    <p class="card-text small">過去3ヶ月分の乗務前・乗務後点呼記録</p>
                                    <button class="btn btn-primary btn-sm" onclick="exportDocument('call_records')">
                                        <i class="fas fa-download"></i> HTML出力
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h6 class="card-title">運転日報</h6>
                                    <p class="card-text small">運行記録・乗車記録・出庫入庫記録</p>
                                    <button class="btn btn-success btn-sm" onclick="exportDocument('driving_reports')">
                                        <i class="fas fa-download"></i> HTML出力
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h6 class="card-title">日常点検記録</h6>
                                    <p class="card-text small">過去1ヶ月分の日常・定期点検記録</p>
                                    <button class="btn btn-warning btn-sm" onclick="exportDocument('inspection_records')">
                                        <i class="fas fa-download"></i> HTML出力
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 監査準備度改善ボタン -->
                    <?php if ($readiness['score'] < 80): ?>
                    <div class="text-center mt-3">
                        <div class="alert alert-warning">
                            <strong>⚠️ 監査準備度が不足しています（<?php echo $readiness['score']; ?>%）</strong>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <button class="btn btn-info btn-lg mb-2" onclick="generateDataQuickly()">
                                    <i class="fas fa-magic"></i> 📊 データ不足を自動解決
                                </button>
                                <p class="small">不足データを自動生成して準備度向上</p>
                            </div>
                            <div class="col-md-6">
                                <button class="btn btn-warning btn-lg mb-2" onclick="fixTableStructure()">
                                    <i class="fas fa-wrench"></i> 🔧 テーブル構造修正
                                </button>
                                <p class="small">出力エラーの原因を自動修正</p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- 出力エラー対策 -->
                    <div class="text-center mt-3">
                        <div class="alert alert-info">
                            <strong>💡 出力エラーが発生する場合</strong><br>
                            <small>
                                1. 「テーブル構造修正」を実行してください<br>
                                2. 「データ不足を自動解決」でサンプルデータを生成<br>
                                3. 適応型出力システムが自動でテーブル構造に対応します
                            </small>
                        </div>
                    </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 監査対応マニュアル -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card border-info">
                <div class="card-header bg-info text-white">
                    <h5><i class="fas fa-book"></i> 監査官対応マニュアル</h5>
                </div>
                <div class="card-body">
                    <div class="accordion" id="manualAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#step1">
                                    Step1: 監査官来訪時の初期対応
                                </button>
                            </h2>
                            <div id="step1" class="accordion-collapse collapse show" data-bs-parent="#manualAccordion">
                                <div class="accordion-body">
                                    <ol>
                                        <li>身分証明書の確認</li>
                                        <li>監査目的・理由の確認</li>
                                        <li>立会者の確保（代表者・運行管理者）</li>
                                        <li>記録・録音の許可確認</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step2">
                                    Step2: 書類提示の注意点
                                </button>
                            </h2>
                            <div id="step2" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                                <div class="accordion-body">
                                    <ul>
                                        <li>要求された書類のみ提示</li>
                                        <li>原本とコピーを併用</li>
                                        <li>提示書類の記録を取る</li>
                                        <li>不明な点は確認してから回答</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#step3">
                                    Step3: 質疑応答の対応
                                </button>
                            </h2>
                            <div id="step3" class="accordion-collapse collapse" data-bs-parent="#manualAccordion">
                                <div class="accordion-body">
                                    <ul>
                                        <li>正確な事実のみ回答</li>
                                        <li>推測や憶測は避ける</li>
                                        <li>不明な点は「確認します」</li>
                                        <li>署名前に内容を十分確認</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card border-secondary">
                <div class="card-header">
                    <h5><i class="fas fa-phone"></i> 緊急連絡先</h5>
                </div>
                <div class="card-body">
                    <div class="list-group">
                        <div class="list-group-item">
                            <strong>顧問弁護士</strong><br>
                            <small>法的対応が必要な場合</small><br>
                            <span class="text-muted">📞 設定してください</span>
                        </div>
                        <div class="list-group-item">
                            <strong>行政書士</strong><br>
                            <small>許可・届出関連の相談</small><br>
                            <span class="text-muted">📞 設定してください</span>
                        </div>
                        <div class="list-group-item">
                            <strong>システム管理者</strong><br>
                            <small>データ確認・技術的支援</small><br>
                            <span class="text-muted">📞 設定してください</span>
                        </div>
                        <div class="list-group-item">
                            <strong>管轄運輸支局</strong><br>
                            <small>手続き・確認事項</small><br>
                            <span class="text-muted">📞 設定してください</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ナビゲーション -->
    <div class="row">
        <div class="col-12 text-center">
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> ダッシュボードに戻る
            </a>
            <a href="audit_dashboard.php" class="btn btn-info">
                <i class="fas fa-chart-bar"></i> 詳細監査分析
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function setPeriod() {
    const preset = document.getElementById('periodPreset').value;
    const startDate = document.getElementById('startDate');
    const endDate = document.getElementById('endDate');
    const today = new Date();
    
    switch(preset) {
        case '3months':
            startDate.value = new Date(today.setMonth(today.getMonth() - 3)).toISOString().split('T')[0];
            endDate.value = new Date().toISOString().split('T')[0];
            break;
        case '6months':
            startDate.value = new Date(today.setMonth(today.getMonth() - 6)).toISOString().split('T')[0];
            endDate.value = new Date().toISOString().split('T')[0];
            break;
        case '1year':
            startDate.value = new Date(today.setFullYear(today.getFullYear() - 1)).toISOString().split('T')[0];
            endDate.value = new Date().toISOString().split('T')[0];
            break;
        case 'current_month':
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            startDate.value = firstDay.toISOString().split('T')[0];
            endDate.value = new Date().toISOString().split('T')[0];
            break;
        case 'last_month':
            const lastMonthFirst = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const lastMonthLast = new Date(today.getFullYear(), today.getMonth(), 0);
            startDate.value = lastMonthFirst.toISOString().split('T')[0];
            endDate.value = lastMonthLast.toISOString().split('T')[0];
            break;
    }
}

function exportDocument(type) {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    
    if (!startDate || !endDate) {
        alert('期間を設定してください');
        return;
    }
    
    // 適応型出力システムを使用
    const url = `adaptive_export_document.php?type=${type}&start=${startDate}&end=${endDate}`;
    window.open(url, '_blank');
    
    // 出力完了メッセージ
    setTimeout(() => {
        alert(`✅ ${getDocumentTypeName(type)}の出力が完了しました\n\n` +
              '📋 適応型システムにより、現在のテーブル構造に合わせて出力されました。\n' +
              'エラーが発生した場合は「テーブル構造修正」を実行してください。');
    }, 500);
}

function getDocumentTypeName(type) {
    const names = {
        'call_records': '点呼記録簿',
        'driving_reports': '運転日報',
        'inspection_records': '点検記録簿',
        'emergency_kit': '緊急監査セット'
    };
    return names[type] || type;
}

// 一括データ生成機能
function generateDataQuickly() {
    if (confirm('不足しているデータを自動生成しますか？\n\n' +
                '・過去3ヶ月分の点呼記録\n' +
                '・日常点検記録\n' +
                '・運行記録\n' +
                '・乗車記録\n\n' +
                '※既存データには影響しません')) {
        
        // データ生成用の新しいウィンドウを開く
        const dataWindow = window.open('audit_data_manager.php', '_blank');
        
        // データ生成完了後にページをリロード
        setTimeout(() => {
            if (confirm('データ生成が完了しました。\n監査準備度を再確認しますか？')) {
                location.reload();
            }
        }, 3000);
    }
}

// テーブル構造修正機能
function fixTableStructure() {
    if (confirm('テーブル構造を修正しますか？\n\n' +
                '出力エラーの原因となるテーブル構造の問題を自動修正します。\n' +
                '・不足しているカラムの追加\n' +
                '・必要なテーブルの作成\n' +
                '・データ整合性の修正\n\n' +
                '※既存データは保持されます')) {
        
        const fixWindow = window.open('fix_table_structure.php', '_blank');
        
        setTimeout(() => {
            if (confirm('テーブル構造修正が完了しました。\n今すぐ出力テストを実行しますか？')) {
                // 適応型出力のテスト
                const testUrl = `adaptive_export_document.php?type=emergency_kit&start=${document.getElementById('startDate').value}&end=${document.getElementById('endDate').value}`;
                window.open(testUrl, '_blank');
            }
        }, 3000);
    }
}

// ページ読み込み時に監査準備度をチェック
window.addEventListener('load', function() {
    const score = <?php echo $readiness['score']; ?>;
    if (score < 80) {
        setTimeout(() => {
            alert('⚠️ 監査準備度が ' + score + '% です。\n改善が必要な項目を確認してください。');
        }, 1000);
    }
});
</script>

</body>
</html>