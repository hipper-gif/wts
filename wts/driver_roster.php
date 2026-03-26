<?php
/**
 * 乗務員台帳一覧
 * 乗務員の資格・免許・健診情報を一覧管理するページ
 */

require_once 'config/database.php';
require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

$pdo = getDBConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? $_SESSION['user_role'] ?? 'User';

// 権限チェック（Admin または Manager）
$has_permission = false;
if (isset($_SESSION['permission_level']) && $_SESSION['permission_level'] === 'Admin') {
    $has_permission = true;
} elseif (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    $has_permission = true;
} elseif (isset($_SESSION['is_manager']) && $_SESSION['is_manager'] == 1) {
    $has_permission = true;
}

if (!$has_permission) {
    header('Location: dashboard.php?error=permission_denied');
    exit;
}

// フィルターパラメータ
$search = trim($_GET['q'] ?? '');
$status_filter = $_GET['status'] ?? '';

// 期限判定ヘルパー関数
function getExpiryStatus($date) {
    if (empty($date)) return 'none';
    $days = (strtotime($date) - time()) / 86400;
    if ($days < 0) return 'expired';
    if ($days <= 30) return 'warning';
    return 'ok';
}

function getExpiryBadge($date, $label = '') {
    if (empty($date)) return '<span class="badge bg-secondary">未登録</span>';
    $days = (strtotime($date) - time()) / 86400;
    $formatted = date('Y/m/d', strtotime($date));
    if ($days < 0) {
        return '<span class="text-danger fw-bold">' . $formatted . '</span><br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> 期限切れ</small>';
    } elseif ($days <= 30) {
        return '<span class="text-warning fw-bold">' . $formatted . '</span><br><small class="text-warning"><i class="fas fa-clock"></i> あと' . ceil($days) . '日</small>';
    }
    return '<span>' . $formatted . '</span>';
}

function getExpiryBadgeMobile($date, $label) {
    if (empty($date)) return '<span class="badge bg-secondary">' . htmlspecialchars($label) . ': 未登録</span>';
    $days = (strtotime($date) - time()) / 86400;
    $formatted = date('Y/m/d', strtotime($date));
    if ($days < 0) {
        return '<span class="badge bg-danger">' . htmlspecialchars($label) . ': ' . $formatted . '（期限切れ）</span>';
    } elseif ($days <= 30) {
        return '<span class="badge bg-warning text-dark">' . htmlspecialchars($label) . ': ' . $formatted . '（あと' . ceil($days) . '日）</span>';
    }
    return '<span class="badge bg-success">' . htmlspecialchars($label) . ': ' . $formatted . '</span>';
}

// ドライバーの最も深刻な期限状態を判定
function getDriverOverallStatus($driver) {
    $dates = [
        $driver['driver_license_expiry'] ?? null,
        $driver['health_check_next'] ?? null,
        $driver['aptitude_test_next'] ?? null
    ];
    $worst = 'ok';
    foreach ($dates as $date) {
        if (empty($date)) continue;
        $status = getExpiryStatus($date);
        if ($status === 'expired') return 'expired';
        if ($status === 'warning') $worst = 'warning';
    }
    return $worst;
}

// 統計クエリ
$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_driver = 1 AND is_active = 1");
$stmt->execute();
$total_drivers = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_driver = 1 AND is_active = 1 AND driver_license_expiry IS NOT NULL AND driver_license_expiry < DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stmt->execute();
$license_alert_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_driver = 1 AND is_active = 1 AND health_check_next IS NOT NULL AND health_check_next < DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stmt->execute();
$health_alert_count = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_driver = 1 AND is_active = 1 AND aptitude_test_next IS NOT NULL AND aptitude_test_next < DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
$stmt->execute();
$aptitude_alert_count = $stmt->fetchColumn();

// 乗務員一覧取得
$where = ['u.is_driver = 1', 'u.is_active = 1'];
$params = [];

if ($search) {
    $where[] = 'u.name LIKE ?';
    $params[] = "%$search%";
}

if ($status_filter === 'expired') {
    $where[] = '(
        (u.driver_license_expiry IS NOT NULL AND u.driver_license_expiry < CURDATE())
        OR (u.health_check_next IS NOT NULL AND u.health_check_next < CURDATE())
        OR (u.aptitude_test_next IS NOT NULL AND u.aptitude_test_next < CURDATE())
    )';
} elseif ($status_filter === 'soon') {
    $where[] = '(
        (u.driver_license_expiry IS NOT NULL AND u.driver_license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (u.health_check_next IS NOT NULL AND u.health_check_next BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        OR (u.aptitude_test_next IS NOT NULL AND u.aptitude_test_next BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY))
    )';
}

$sql = "SELECT u.id, u.name, u.phone, u.email,
               u.date_of_birth, u.hire_date, u.address, u.emergency_contact,
               u.driver_license_number, u.driver_license_type, u.driver_license_expiry,
               u.care_qualification, u.care_qualification_date,
               u.health_check_date, u.health_check_next,
               u.aptitude_test_date, u.aptitude_test_next,
               u.notes
        FROM users u
        WHERE " . implode(' AND ', $where) . "
        ORDER BY u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ページ設定
$page_config = getPageConfiguration('driver_roster');

$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
        'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css'
    ],
    'additional_js' => [
        'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js',
        'js/ui-interactions.js'
    ],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '管理機能', 'url' => 'master_menu.php'],
        ['text' => '乗務員台帳', 'url' => 'driver_roster.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'driver_roster',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<style>
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 1.25rem;
        box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        border: 1px solid rgba(0,0,0,0.06);
        text-align: center;
    }
    .stat-card .stat-number {
        font-size: 2rem;
        font-weight: 700;
        line-height: 1.2;
    }
    .stat-card .stat-label {
        color: #6c757d;
        font-size: 0.85rem;
        margin-top: 0.25rem;
    }
    .driver-card {
        border-radius: 10px;
        border: 1px solid rgba(0,0,0,0.08);
        box-shadow: 0 1px 4px rgba(0,0,0,0.06);
        overflow: hidden;
        margin-bottom: 0.75rem;
    }
    .driver-card .card-body {
        padding: 1rem;
    }
    .driver-card.status-expired {
        border-left: 4px solid #dc3545;
    }
    .driver-card.status-warning {
        border-left: 4px solid #ffc107;
    }
    .driver-card.status-ok {
        border-left: 4px solid #198754;
    }
    .badge-group {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
        margin-top: 0.5rem;
    }
    .detail-section {
        margin-bottom: 1.25rem;
    }
    .detail-section h6 {
        font-weight: 600;
        color: #2c3e50;
        border-bottom: 2px solid #667eea;
        padding-bottom: 0.4rem;
        margin-bottom: 0.75rem;
        font-size: 0.95rem;
    }
    .detail-row {
        display: flex;
        justify-content: space-between;
        padding: 0.35rem 0;
        border-bottom: 1px solid #f0f0f0;
    }
    .detail-row .detail-label {
        color: #6c757d;
        font-size: 0.85rem;
        min-width: 120px;
    }
    .detail-row .detail-value {
        font-weight: 500;
        text-align: right;
    }
</style>

<main class="container mt-4">

    <!-- 統計カード -->
    <div class="row mb-4">
        <div class="col-6 col-md-3 mb-3 mb-md-0">
            <div class="stat-card">
                <div class="stat-number text-primary"><?= $total_drivers ?></div>
                <div class="stat-label"><i class="fas fa-users me-1"></i>総乗務員数</div>
            </div>
        </div>
        <div class="col-6 col-md-3 mb-3 mb-md-0">
            <div class="stat-card">
                <div class="stat-number text-danger"><?= $license_alert_count ?></div>
                <div class="stat-label"><i class="fas fa-id-card me-1"></i>免許期限注意</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number text-warning"><?= $health_alert_count ?></div>
                <div class="stat-label"><i class="fas fa-heartbeat me-1"></i>健診期限注意</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card">
                <div class="stat-number text-info"><?= $aptitude_alert_count ?></div>
                <div class="stat-label"><i class="fas fa-brain me-1"></i>適性診断注意</div>
            </div>
        </div>
    </div>

    <!-- フィルターツールバー -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div class="input-group" style="max-width:260px">
            <span class="input-group-text"><i class="fas fa-search"></i></span>
            <input type="text" class="form-control" id="searchInput" placeholder="名前で検索..." value="<?= htmlspecialchars($search) ?>"
                   onkeydown="if(event.key==='Enter') applyFilter('q', this.value)">
        </div>

        <select class="form-select" style="width:auto;max-width:170px" onchange="applyFilter('status', this.value)">
            <option value="">ステータス：全て</option>
            <option value="expired" <?= $status_filter === 'expired' ? 'selected' : '' ?>>期限切れあり</option>
            <option value="soon" <?= $status_filter === 'soon' ? 'selected' : '' ?>>30日以内</option>
        </select>

        <?php if ($search || $status_filter): ?>
            <a href="driver_roster.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-times me-1"></i>フィルター解除
            </a>
        <?php endif; ?>

        <span class="text-muted ms-auto">全<?= count($drivers) ?>名</span>
    </div>

    <!-- テーブル表示（PC） -->
    <div class="card d-none d-md-block">
        <div class="card-body p-0">
            <?php if (empty($drivers)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">該当する乗務員がいません</h5>
                    <p class="text-muted mb-0">フィルター条件を変更してください。</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>名前</th>
                                <th>免許種別</th>
                                <th>免許期限</th>
                                <th>健康診断次回</th>
                                <th>適性診断次回</th>
                                <th>介護資格</th>
                                <th class="text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($drivers as $driver):
                                // 行の色分け判定
                                $row_class = '';
                                $overall = getDriverOverallStatus($driver);
                                if ($overall === 'expired') {
                                    $row_class = 'table-danger';
                                } elseif ($overall === 'warning') {
                                    $row_class = 'table-warning';
                                }
                            ?>
                            <tr class="<?= $row_class ?>">
                                <td>
                                    <strong><?= htmlspecialchars($driver['name']) ?></strong>
                                    <?php if ($driver['phone']): ?>
                                        <br><small class="text-muted"><i class="fas fa-phone me-1"></i><?= htmlspecialchars($driver['phone']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($driver['driver_license_type']): ?>
                                        <?= htmlspecialchars($driver['driver_license_type']) ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= getExpiryBadge($driver['driver_license_expiry']) ?></td>
                                <td><?= getExpiryBadge($driver['health_check_next']) ?></td>
                                <td><?= getExpiryBadge($driver['aptitude_test_next']) ?></td>
                                <td>
                                    <?php if ($driver['care_qualification']): ?>
                                        <span class="badge bg-info"><?= htmlspecialchars($driver['care_qualification']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-info" title="詳細"
                                                onclick='showDetail(<?= json_encode($driver, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <a href="user_management.php?edit=<?= $driver['id'] ?>" class="btn btn-outline-primary" title="編集">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- カードビュー（モバイル） -->
    <div class="d-md-none">
        <?php if (empty($drivers)): ?>
            <div class="text-center py-5">
                <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">該当する乗務員がいません</h5>
            </div>
        <?php else: ?>
            <?php foreach ($drivers as $driver):
                $overall = getDriverOverallStatus($driver);
            ?>
            <div class="card driver-card status-<?= $overall ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <strong class="fs-6"><?= htmlspecialchars($driver['name']) ?></strong>
                            <?php if ($driver['driver_license_type']): ?>
                                <span class="badge bg-primary ms-1"><?= htmlspecialchars($driver['driver_license_type']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($driver['care_qualification']): ?>
                            <span class="badge bg-info"><?= htmlspecialchars($driver['care_qualification']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($driver['phone']): ?>
                        <div class="small text-muted mb-2">
                            <i class="fas fa-phone me-1"></i><?= htmlspecialchars($driver['phone']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="badge-group">
                        <?= getExpiryBadgeMobile($driver['driver_license_expiry'], '免許') ?>
                        <?= getExpiryBadgeMobile($driver['health_check_next'], '健診') ?>
                        <?= getExpiryBadgeMobile($driver['aptitude_test_next'], '適性') ?>
                    </div>

                    <div class="d-flex gap-2 mt-3">
                        <button class="btn btn-sm btn-outline-info flex-fill"
                                onclick='showDetail(<?= json_encode($driver, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
                            <i class="fas fa-eye me-1"></i>詳細
                        </button>
                        <a href="user_management.php?edit=<?= $driver['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                            <i class="fas fa-edit me-1"></i>編集
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</main>

<!-- 詳細モーダル -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content unified-modal">
            <div class="modal-header unified-modal-header">
                <h5 class="modal-title"><i class="fas fa-id-card me-2"></i><span id="detailName"></span> - 台帳情報</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="detailBody">
                <!-- 動的に生成 -->
            </div>
            <div class="modal-footer">
                <a id="detailEditLink" href="#" class="btn btn-primary">
                    <i class="fas fa-edit me-1"></i>編集画面へ
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>閉じる
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// フィルター適用
function applyFilter(key, value) {
    var params = new URLSearchParams(window.location.search);
    if (value) {
        params.set(key, value);
    } else {
        params.delete(key);
    }
    window.location.href = '?' + params.toString();
}

// 詳細モーダル表示
function showDetail(driver) {
    document.getElementById('detailName').textContent = driver.name;
    document.getElementById('detailEditLink').href = 'user_management.php?edit=' + driver.id;

    function esc(str) {
        if (!str) return '<span class="text-muted">未登録</span>';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function fmtDate(dateStr) {
        if (!dateStr) return '<span class="text-muted">未登録</span>';
        return dateStr.replace(/-/g, '/');
    }

    function expiryHtml(dateStr) {
        if (!dateStr) return '<span class="text-muted">未登録</span>';
        var d = new Date(dateStr);
        var now = new Date();
        var days = (d - now) / 86400000;
        var formatted = dateStr.replace(/-/g, '/');
        if (days < 0) {
            return '<span class="text-danger fw-bold">' + formatted + ' <i class="fas fa-exclamation-triangle"></i> 期限切れ</span>';
        } else if (days <= 30) {
            return '<span class="text-warning fw-bold">' + formatted + ' <i class="fas fa-clock"></i> あと' + Math.ceil(days) + '日</span>';
        }
        return '<span class="text-success">' + formatted + '</span>';
    }

    var html = '';

    // 基本情報
    html += '<div class="detail-section">';
    html += '<h6><i class="fas fa-user me-2"></i>基本情報</h6>';
    html += '<div class="detail-row"><span class="detail-label">氏名</span><span class="detail-value">' + esc(driver.name) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">電話番号</span><span class="detail-value">' + esc(driver.phone) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">メール</span><span class="detail-value">' + esc(driver.email) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">生年月日</span><span class="detail-value">' + fmtDate(driver.date_of_birth) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">入社日</span><span class="detail-value">' + fmtDate(driver.hire_date) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">住所</span><span class="detail-value">' + esc(driver.address) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">緊急連絡先</span><span class="detail-value">' + esc(driver.emergency_contact) + '</span></div>';
    html += '</div>';

    // 免許情報
    html += '<div class="detail-section">';
    html += '<h6><i class="fas fa-id-card me-2"></i>免許情報</h6>';
    html += '<div class="detail-row"><span class="detail-label">免許証番号</span><span class="detail-value">' + esc(driver.driver_license_number) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">免許種別</span><span class="detail-value">' + esc(driver.driver_license_type) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">有効期限</span><span class="detail-value">' + expiryHtml(driver.driver_license_expiry) + '</span></div>';
    html += '</div>';

    // 介護資格
    html += '<div class="detail-section">';
    html += '<h6><i class="fas fa-hand-holding-heart me-2"></i>介護資格</h6>';
    html += '<div class="detail-row"><span class="detail-label">資格名</span><span class="detail-value">' + esc(driver.care_qualification) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">取得日</span><span class="detail-value">' + fmtDate(driver.care_qualification_date) + '</span></div>';
    html += '</div>';

    // 健診・適性診断
    html += '<div class="detail-section">';
    html += '<h6><i class="fas fa-heartbeat me-2"></i>健康診断・適性診断</h6>';
    html += '<div class="detail-row"><span class="detail-label">健診実施日</span><span class="detail-value">' + fmtDate(driver.health_check_date) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">健診次回予定</span><span class="detail-value">' + expiryHtml(driver.health_check_next) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">適性診断実施日</span><span class="detail-value">' + fmtDate(driver.aptitude_test_date) + '</span></div>';
    html += '<div class="detail-row"><span class="detail-label">適性診断次回予定</span><span class="detail-value">' + expiryHtml(driver.aptitude_test_next) + '</span></div>';
    html += '</div>';

    // 備考
    html += '<div class="detail-section">';
    html += '<h6><i class="fas fa-sticky-note me-2"></i>備考</h6>';
    if (driver.notes) {
        html += '<p class="mb-0" style="white-space:pre-wrap">' + esc(driver.notes) + '</p>';
    } else {
        html += '<p class="text-muted mb-0">備考なし</p>';
    }
    html += '</div>';

    document.getElementById('detailBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('detailModal')).show();
}
</script>

<?php echo $page_data['html_footer']; ?>
