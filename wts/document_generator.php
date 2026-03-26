<?php
/**
 * 監査対応ページ
 * コンプライアンス状況の確認・監査パッケージ一括生成・監査チェックリスト
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

// 権限チェック（Admin または Manager）
$has_permission = false;
if ($user_role === 'Admin') {
    $has_permission = true;
} elseif (isset($_SESSION['is_manager']) && $_SESSION['is_manager'] == 1) {
    $has_permission = true;
}

if (!$has_permission) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// 運転者・車両一覧
$drivers = getActiveDrivers($pdo);
$vehicles = [];
try {
    $vstmt = $pdo->query("SELECT id, vehicle_number, vehicle_name FROM vehicles WHERE is_active = 1 ORDER BY vehicle_number");
    $vehicles = $vstmt->fetchAll();
} catch (Exception $e) {}

// --- コンプライアンス統計の集計 ---
$today = date('Y-m-d');
$current_month_start = date('Y-m-01');
$current_month_end = date('Y-m-t');
$last_month_start = date('Y-m-01', strtotime('-1 month'));
$last_month_end = date('Y-m-t', strtotime('-1 month'));

// 対象乗務員数
$driver_count = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_driver = 1");
    $driver_count = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// 対象車両数
$vehicle_count = count($vehicles);

// --- 1. 乗務員台帳 ---
$license_expired = $license_expiring = 0;
$health_expired = $health_expiring = 0;
$aptitude_expired = $aptitude_expiring = 0;
try {
    $checks = [
        'driver_license_expiry' => ['expired' => &$license_expired, 'expiring' => &$license_expiring],
        'health_check_next' => ['expired' => &$health_expired, 'expiring' => &$health_expiring],
        'aptitude_test_next' => ['expired' => &$aptitude_expired, 'expiring' => &$aptitude_expiring],
    ];
    foreach ($checks as $col => $refs) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_driver = 1 AND {$col} IS NOT NULL AND {$col} < CURDATE()");
        $stmt->execute();
        $refs['expired'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active = 1 AND is_driver = 1 AND {$col} IS NOT NULL AND {$col} BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        $stmt->execute();
        $refs['expiring'] = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {}
$roster_issues = $license_expired + $health_expired + $aptitude_expired;
$roster_warnings = $license_expiring + $health_expiring + $aptitude_expiring;

// --- 2. 点呼実施率（今月・先月） ---
function getCallRate($pdo, $start, $end) {
    // 乗務した日（ride_recordsがある日×乗務員）の中で、点呼が完了した割合
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(driver_id, '-', ride_date)) FROM ride_records WHERE ride_date BETWEEN ? AND ?");
        $stmt->execute([$start, $end]);
        $total_duty_days = (int)$stmt->fetchColumn();
        if ($total_duty_days === 0) return ['rate' => null, 'completed' => 0, 'total' => 0];

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(driver_id, '-', call_date)) FROM pre_duty_calls WHERE call_date BETWEEN ? AND ? AND is_completed = 1");
        $stmt->execute([$start, $end]);
        $pre_completed = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(driver_id, '-', call_date)) FROM post_duty_calls WHERE call_date BETWEEN ? AND ? AND is_completed = 1");
        $stmt->execute([$start, $end]);
        $post_completed = (int)$stmt->fetchColumn();

        $completed = min($pre_completed, $post_completed);
        $rate = ($total_duty_days > 0) ? round(($completed / $total_duty_days) * 100, 1) : 0;
        return ['rate' => $rate, 'completed' => $completed, 'total' => $total_duty_days];
    } catch (Exception $e) {
        return ['rate' => null, 'completed' => 0, 'total' => 0];
    }
}
$call_rate_this = getCallRate($pdo, $current_month_start, $current_month_end);
$call_rate_last = getCallRate($pdo, $last_month_start, $last_month_end);

// --- 3. 日常点検実施率（今月・先月） ---
function getInspectionRate($pdo, $start, $end) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(driver_id, '-', ride_date)) FROM ride_records WHERE ride_date BETWEEN ? AND ?");
        $stmt->execute([$start, $end]);
        $total_duty_days = (int)$stmt->fetchColumn();
        if ($total_duty_days === 0) return ['rate' => null, 'completed' => 0, 'total' => 0];

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM daily_inspections WHERE inspection_date BETWEEN ? AND ? AND deleted_at IS NULL");
        $stmt->execute([$start, $end]);
        $completed = (int)$stmt->fetchColumn();

        $rate = ($total_duty_days > 0) ? round(($completed / $total_duty_days) * 100, 1) : 0;
        return ['rate' => $rate, 'completed' => $completed, 'total' => $total_duty_days];
    } catch (Exception $e) {
        return ['rate' => null, 'completed' => 0, 'total' => 0];
    }
}
$insp_rate_this = getInspectionRate($pdo, $current_month_start, $current_month_end);
$insp_rate_last = getInspectionRate($pdo, $last_month_start, $last_month_end);

// --- 4. 書類管理 ---
$doc_expired = $doc_expiring = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date < CURDATE()");
    $doc_expired = (int)$stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM documents WHERE is_active = 1 AND expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
    $doc_expiring = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

// 会社情報
$company_name = '';
try {
    $stmt = $pdo->query("SELECT company_name FROM company_info ORDER BY id LIMIT 1");
    $row = $stmt->fetch();
    if ($row) $company_name = $row['company_name'];
} catch (Exception $e) {}

// ページ設定
$page_config = getPageConfiguration('document_generator');

$page_data = renderCompletePage(
    '監査対応',
    $user_name,
    $user_role,
    'document_generator',
    'shield-alt',
    '監査対応',
    'コンプライアンス状況確認・監査資料作成',
    $page_config['category'],
    [
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => 'マスターメニュー', 'url' => 'master_menu.php'],
            ['text' => '監査対応', 'url' => 'document_generator.php']
        ]
    ]
);

echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];

// ステータス判定ヘルパー
function statusIcon($issues, $warnings) {
    if ($issues > 0) return '<span class="compliance-badge badge-danger"><i class="fas fa-times-circle me-1"></i>要対応</span>';
    if ($warnings > 0) return '<span class="compliance-badge badge-warning"><i class="fas fa-exclamation-triangle me-1"></i>注意</span>';
    return '<span class="compliance-badge badge-success"><i class="fas fa-check-circle me-1"></i>良好</span>';
}
function rateIcon($rate) {
    if ($rate === null) return '<span class="compliance-badge badge-muted"><i class="fas fa-minus-circle me-1"></i>データなし</span>';
    if ($rate >= 95) return '<span class="compliance-badge badge-success"><i class="fas fa-check-circle me-1"></i>' . $rate . '%</span>';
    if ($rate >= 80) return '<span class="compliance-badge badge-warning"><i class="fas fa-exclamation-triangle me-1"></i>' . $rate . '%</span>';
    return '<span class="compliance-badge badge-danger"><i class="fas fa-times-circle me-1"></i>' . $rate . '%</span>';
}
?>

<main class="main-content" id="main-content" tabindex="-1">
    <div class="container-fluid py-4">

        <!-- ===== Section 1: コンプライアンス状況 ===== -->
        <h5 class="section-title"><i class="fas fa-heartbeat me-2"></i>コンプライアンス状況</h5>
        <p class="text-muted mb-3">運輸局監査で確認される主要項目の準備状況です。<?= $company_name ? htmlspecialchars($company_name) . ' — ' : '' ?><?= date('Y年n月j日') ?>時点</p>

        <div class="row mb-4">
            <!-- 乗務員台帳 -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="compliance-card">
                    <div class="compliance-card-header">
                        <i class="fas fa-id-card"></i>
                        <span>乗務員台帳</span>
                    </div>
                    <div class="compliance-status">
                        <?= statusIcon($roster_issues, $roster_warnings) ?>
                    </div>
                    <ul class="compliance-details">
                        <li>登録乗務員: <strong><?= $driver_count ?>名</strong></li>
                        <?php if ($license_expired): ?><li class="text-danger">免許期限切れ: <?= $license_expired ?>名</li><?php endif; ?>
                        <?php if ($license_expiring): ?><li class="text-warning">免許30日以内: <?= $license_expiring ?>名</li><?php endif; ?>
                        <?php if ($health_expired): ?><li class="text-danger">健診期限切れ: <?= $health_expired ?>名</li><?php endif; ?>
                        <?php if ($health_expiring): ?><li class="text-warning">健診30日以内: <?= $health_expiring ?>名</li><?php endif; ?>
                        <?php if ($aptitude_expired): ?><li class="text-danger">適性診断切れ: <?= $aptitude_expired ?>名</li><?php endif; ?>
                        <?php if ($aptitude_expiring): ?><li class="text-warning">適性診断30日以内: <?= $aptitude_expiring ?>名</li><?php endif; ?>
                        <?php if ($roster_issues === 0 && $roster_warnings === 0): ?><li class="text-success">全項目有効期限内</li><?php endif; ?>
                    </ul>
                    <a href="driver_roster.php" class="btn btn-sm btn-outline-primary w-100">台帳を確認</a>
                </div>
            </div>

            <!-- 点呼実施率 -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="compliance-card">
                    <div class="compliance-card-header">
                        <i class="fas fa-clipboard-check"></i>
                        <span>点呼実施</span>
                    </div>
                    <div class="compliance-status">
                        <?= rateIcon($call_rate_this['rate']) ?>
                    </div>
                    <ul class="compliance-details">
                        <li>今月: <strong><?= $call_rate_this['completed'] ?>/<?= $call_rate_this['total'] ?>日</strong></li>
                        <li>先月: <?= $call_rate_last['rate'] !== null ? $call_rate_last['rate'] . '%' : '-' ?>
                            (<?= $call_rate_last['completed'] ?>/<?= $call_rate_last['total'] ?>日)</li>
                        <li class="text-muted small">乗務前・乗務後の両方完了で1カウント</li>
                    </ul>
                    <a href="pre_duty_call.php" class="btn btn-sm btn-outline-primary w-100">点呼画面へ</a>
                </div>
            </div>

            <!-- 日常点検実施率 -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="compliance-card">
                    <div class="compliance-card-header">
                        <i class="fas fa-car"></i>
                        <span>日常点検</span>
                    </div>
                    <div class="compliance-status">
                        <?= rateIcon($insp_rate_this['rate']) ?>
                    </div>
                    <ul class="compliance-details">
                        <li>今月: <strong><?= $insp_rate_this['completed'] ?>/<?= $insp_rate_this['total'] ?>日</strong></li>
                        <li>先月: <?= $insp_rate_last['rate'] !== null ? $insp_rate_last['rate'] . '%' : '-' ?>
                            (<?= $insp_rate_last['completed'] ?>/<?= $insp_rate_last['total'] ?>日)</li>
                    </ul>
                    <a href="daily_inspection.php" class="btn btn-sm btn-outline-primary w-100">点検画面へ</a>
                </div>
            </div>

            <!-- 書類管理 -->
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="compliance-card">
                    <div class="compliance-card-header">
                        <i class="fas fa-folder-open"></i>
                        <span>書類管理</span>
                    </div>
                    <div class="compliance-status">
                        <?= statusIcon($doc_expired, $doc_expiring) ?>
                    </div>
                    <ul class="compliance-details">
                        <?php if ($doc_expired): ?><li class="text-danger">期限切れ: <?= $doc_expired ?>件</li><?php endif; ?>
                        <?php if ($doc_expiring): ?><li class="text-warning">30日以内: <?= $doc_expiring ?>件</li><?php endif; ?>
                        <?php if ($doc_expired === 0 && $doc_expiring === 0): ?><li class="text-success">全書類有効期限内</li><?php endif; ?>
                    </ul>
                    <a href="document_management.php" class="btn btn-sm btn-outline-primary w-100">書類管理へ</a>
                </div>
            </div>
        </div>

        <!-- ===== Section 2: 監査パッケージ一括生成 ===== -->
        <h5 class="section-title"><i class="fas fa-file-archive me-2"></i>監査資料の作成</h5>
        <p class="text-muted mb-3">監査時に提示が求められる帳票を一括で作成できます。印刷またはPDF保存してください。</p>

        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-end">
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i>対象月</label>
                        <input type="month" class="form-control" id="auditMonth" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-user me-1"></i>乗務員</label>
                        <select class="form-select" id="auditDriver">
                            <option value="all">全員</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold"><i class="fas fa-car me-1"></i>車両（点検用）</label>
                        <select class="form-select" id="auditVehicle">
                            <option value="">全車両</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= htmlspecialchars($v['vehicle_number'] . ' ' . ($v['vehicle_name'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 mb-3">
                        <button class="btn btn-primary btn-lg w-100" onclick="generateAll()">
                            <i class="fas fa-print me-2"></i>一括作成
                        </button>
                    </div>
                </div>
                <div class="form-text"><i class="fas fa-info-circle me-1"></i>「一括作成」で3種類の帳票が別タブで同時に開きます。各タブで Ctrl+P（PDF保存/印刷）してください。</div>
            </div>
        </div>

        <!-- 個別作成 -->
        <div class="row mb-4">
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="doc-card" onclick="generateSingle('daily_report')">
                    <div class="doc-card-icon"><i class="fas fa-route"></i></div>
                    <h6 class="fw-bold mb-1">運転日報</h6>
                    <p class="doc-card-desc mb-0">乗務記録（日次）</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="doc-card" onclick="generateSingle('roll_call')">
                    <div class="doc-card-icon"><i class="fas fa-clipboard-check"></i></div>
                    <h6 class="fw-bold mb-1">点呼記録簿</h6>
                    <p class="doc-card-desc mb-0">乗務前・乗務後（月次）</p>
                </div>
            </div>
            <div class="col-lg-4 col-md-6 mb-3">
                <div class="doc-card" onclick="generateSingle('inspection')">
                    <div class="doc-card-icon"><i class="fas fa-car"></i></div>
                    <h6 class="fw-bold mb-1">日常点検記録</h6>
                    <p class="doc-card-desc mb-0">車両点検（月次）</p>
                </div>
            </div>
        </div>

        <!-- ===== Section 3: 監査チェックリスト ===== -->
        <h5 class="section-title"><i class="fas fa-tasks me-2"></i>監査チェックリスト</h5>
        <p class="text-muted mb-3">運輸局の監査で確認される主な項目です。事前にチェックしておきましょう。</p>

        <div class="card mb-4">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>確認項目</th>
                            <th>根拠法令</th>
                            <th>保存期間</th>
                            <th>システム状況</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $checklist = [
                            [
                                'item' => '乗務員台帳の整備',
                                'detail' => '全乗務員の免許証・資格・健診情報が最新か',
                                'law' => '運輸規則 第37条',
                                'retention' => '退職後3年',
                                'status' => $roster_issues === 0 ? ($roster_warnings === 0 ? 'ok' : 'warn') : 'ng',
                                'note' => $roster_issues > 0 ? "期限切れ{$roster_issues}件" : ($roster_warnings > 0 ? "30日以内{$roster_warnings}件" : ''),
                            ],
                            [
                                'item' => '乗務前点呼の実施・記録',
                                'detail' => '酒気帯び確認・健康状態・日常点検結果の確認',
                                'law' => '運輸規則 第24条',
                                'retention' => '1年間',
                                'status' => $call_rate_this['rate'] === null ? 'none' : ($call_rate_this['rate'] >= 95 ? 'ok' : ($call_rate_this['rate'] >= 80 ? 'warn' : 'ng')),
                                'note' => $call_rate_this['rate'] !== null ? "今月実施率{$call_rate_this['rate']}%" : '',
                            ],
                            [
                                'item' => '乗務後点呼の実施・記録',
                                'detail' => '車両状態・事故報告・酒気帯び確認',
                                'law' => '運輸規則 第24条',
                                'retention' => '1年間',
                                'status' => $call_rate_this['rate'] === null ? 'none' : ($call_rate_this['rate'] >= 95 ? 'ok' : ($call_rate_this['rate'] >= 80 ? 'warn' : 'ng')),
                                'note' => '乗務前と同一集計',
                            ],
                            [
                                'item' => '運転日報（乗務記録）の作成',
                                'detail' => '乗務開始/終了・経路・運賃・走行距離',
                                'law' => '運輸規則 第25条',
                                'retention' => '1年間',
                                'status' => $call_rate_this['total'] > 0 ? 'ok' : 'none',
                                'note' => '乗車記録から自動生成可能',
                            ],
                            [
                                'item' => '日常点検の実施・記録',
                                'detail' => '運行前の車両点検15項目以上',
                                'law' => '車両法 第47条の2',
                                'retention' => '1年間',
                                'status' => $insp_rate_this['rate'] === null ? 'none' : ($insp_rate_this['rate'] >= 95 ? 'ok' : ($insp_rate_this['rate'] >= 80 ? 'warn' : 'ng')),
                                'note' => $insp_rate_this['rate'] !== null ? "今月実施率{$insp_rate_this['rate']}%" : '',
                            ],
                            [
                                'item' => '定期点検整備記録',
                                'detail' => '3ヶ月点検・12ヶ月点検の実施',
                                'law' => '車両法 第48条',
                                'retention' => '2年間',
                                'status' => 'manual',
                                'note' => 'システムで管理可能',
                            ],
                            [
                                'item' => '事業用自動車の保険加入',
                                'detail' => '対人無制限・対物の任意保険',
                                'law' => '道路運送法',
                                'retention' => '常時',
                                'status' => $doc_expired === 0 ? 'ok' : 'ng',
                                'note' => '書類管理で確認',
                            ],
                            [
                                'item' => '事故記録・報告',
                                'detail' => '事故報告書の作成・30日以内の届出',
                                'law' => '事故報告規則 第3条',
                                'retention' => '3年間',
                                'status' => 'manual',
                                'note' => '事故発生時に対応',
                            ],
                            [
                                'item' => '指導監督記録',
                                'detail' => '初任教育・適齢教育・安全指導の記録',
                                'law' => '運輸規則 第38条',
                                'retention' => '3年間',
                                'status' => 'manual',
                                'note' => '紙ベースで管理',
                            ],
                            [
                                'item' => '苦情処理記録',
                                'detail' => '苦情の受付・対応内容の記録',
                                'law' => '運輸規則 第3条',
                                'retention' => '2-3年',
                                'status' => 'manual',
                                'note' => '発生時に記録',
                            ],
                        ];
                        foreach ($checklist as $i => $c):
                            $icons = [
                                'ok' => '<i class="fas fa-check-circle text-success"></i>',
                                'warn' => '<i class="fas fa-exclamation-triangle text-warning"></i>',
                                'ng' => '<i class="fas fa-times-circle text-danger"></i>',
                                'none' => '<i class="fas fa-minus-circle text-muted"></i>',
                                'manual' => '<i class="fas fa-hand-paper text-secondary"></i>',
                            ];
                        ?>
                        <tr>
                            <td class="text-center"><?= $icons[$c['status']] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($c['item']) ?></strong>
                                <div class="text-muted small"><?= htmlspecialchars($c['detail']) ?></div>
                            </td>
                            <td class="small"><?= htmlspecialchars($c['law']) ?></td>
                            <td class="small"><?= htmlspecialchars($c['retention']) ?></td>
                            <td>
                                <?php if ($c['note']): ?>
                                    <span class="small"><?= htmlspecialchars($c['note']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="alert alert-light border mb-4">
            <i class="fas fa-info-circle text-primary me-2"></i>
            <strong>凡例:</strong>
            <i class="fas fa-check-circle text-success ms-3 me-1"></i>良好
            <i class="fas fa-exclamation-triangle text-warning ms-3 me-1"></i>注意
            <i class="fas fa-times-circle text-danger ms-3 me-1"></i>要対応
            <i class="fas fa-hand-paper text-secondary ms-3 me-1"></i>手動管理
            <i class="fas fa-minus-circle text-muted ms-3 me-1"></i>データなし
        </div>

    </div>
</main>

<style>
.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 0.5rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid #667eea;
}

/* コンプライアンスカード */
.compliance-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    height: 100%;
    display: flex;
    flex-direction: column;
}
.compliance-card-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 0.75rem;
    color: #2c3e50;
}
.compliance-card-header i {
    font-size: 1.2rem;
    color: #667eea;
}
.compliance-status {
    margin-bottom: 0.75rem;
}
.compliance-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}
.badge-success { background: #d1e7dd; color: #0f5132; }
.badge-warning { background: #fff3cd; color: #664d03; }
.badge-danger { background: #f8d7da; color: #842029; }
.badge-muted { background: #e9ecef; color: #6c757d; }
.compliance-details {
    list-style: none;
    padding: 0;
    margin: 0 0 0.75rem 0;
    font-size: 0.85rem;
    flex-grow: 1;
}
.compliance-details li {
    padding: 0.15rem 0;
}

/* 帳票カード */
.doc-card {
    background: white;
    border-radius: 12px;
    padding: 1.25rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border: 2px solid transparent;
    transition: all 0.3s ease;
    cursor: pointer;
    text-align: center;
    height: 100%;
}
.doc-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    border-color: #667eea;
}
.doc-card-icon {
    font-size: 2rem;
    color: #667eea;
    margin-bottom: 0.5rem;
}
.doc-card-desc {
    color: #6c757d;
    font-size: 0.8rem;
}

/* チェックリストテーブル */
.table th { font-size: 0.85rem; white-space: nowrap; }
.table td { vertical-align: middle; }
</style>

<script>
var templateUrls = {
    'daily_report': 'templates/daily_report.php',
    'roll_call': 'templates/roll_call_record.php',
    'inspection': 'templates/inspection_record.php'
};

function generateAll() {
    var month = document.getElementById('auditMonth').value;
    var driver = document.getElementById('auditDriver').value;
    var vehicle = document.getElementById('auditVehicle').value;

    // 月の最終日を計算
    var parts = month.split('-');
    var lastDay = new Date(parseInt(parts[0]), parseInt(parts[1]), 0).getDate();
    var dateParam = month + '-' + String(lastDay).padStart(2, '0');

    // 運転日報（日次 × 月末日。月全体を見るには日ごとに出す必要があるが、まず月末日分を出力）
    window.open('templates/daily_report.php?date=' + dateParam + '&driver_id=' + driver, '_blank');

    // 点呼記録簿（月次）
    window.open('templates/roll_call_record.php?month=' + month + '&driver_id=' + driver, '_blank');

    // 日常点検記録（月次・車両単位）
    if (vehicle) {
        window.open('templates/inspection_record.php?month=' + month + '&vehicle_id=' + vehicle, '_blank');
    } else {
        window.open('templates/inspection_record.php?month=' + month + '&driver_id=' + driver, '_blank');
    }
}

function generateSingle(type) {
    var month = document.getElementById('auditMonth').value;
    var driver = document.getElementById('auditDriver').value;
    var vehicle = document.getElementById('auditVehicle').value;

    if (type === 'daily_report') {
        // 運転日報は日次なので対象月の各日を出力するか確認
        var parts = month.split('-');
        var lastDay = new Date(parseInt(parts[0]), parseInt(parts[1]), 0).getDate();
        var dateParam = month + '-' + String(lastDay).padStart(2, '0');
        window.open('templates/daily_report.php?date=' + dateParam + '&driver_id=' + driver, '_blank');
    } else if (type === 'roll_call') {
        window.open('templates/roll_call_record.php?month=' + month + '&driver_id=' + driver, '_blank');
    } else if (type === 'inspection') {
        if (vehicle) {
            window.open('templates/inspection_record.php?month=' + month + '&vehicle_id=' + vehicle, '_blank');
        } else {
            window.open('templates/inspection_record.php?month=' + month + '&driver_id=' + driver, '_blank');
        }
    }
}
</script>

<?= $page_data['html_footer'] ?>
