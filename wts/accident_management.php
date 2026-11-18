<?php

require_once 'functions.php';
require_once 'includes/unified-header.php';
require_once 'includes/session_check.php';

// $pdo, $user_id, $user_name, $user_role は session_check.php で設定済み

// 権限チェック
if ($user_role !== 'Admin') {
    header('Location: dashboard.php');
    exit;
}

// 検索・フィルター条件
$search_year = (int)($_GET['year'] ?? date('Y'));
$search_type = $_GET['type'] ?? '';
$search_status = $_GET['status'] ?? '';

// 選択肢（画面と保存時の検査で同じものを使う）
$ACCIDENT_TYPES = ['交通事故', '重大事故', 'ヒヤリハット', 'その他'];
$ACCIDENT_STATUSES = ['発生', '調査中', '処理完了'];
$WEATHER_OPTIONS = ['晴', '曇', '雨', '雪', '霧'];

/**
 * 事故記録の入力を検査して、保存する値にそろえる。だめな時は理由を InvalidArgumentException で返す。
 */
function normalizeAccidentInput($pdo, array $in, array $types, array $weathers) {
    $date = trim($in['accident_date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('事故発生日を入力してください。');
    }
    if ($date > date('Y-m-d')) {
        throw new InvalidArgumentException('事故発生日が未来の日付になっています。');
    }
    $time = trim($in['accident_time'] ?? '');
    if ($time !== '' && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
        throw new InvalidArgumentException('発生時刻の形式が正しくありません。');
    }
    $vehicle_id = (int)($in['vehicle_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE id = ?");
    $stmt->execute([$vehicle_id]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('車両を選択してください。');
    }
    $driver_id = (int)($in['driver_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ?");
    $stmt->execute([$driver_id]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('運転者を選択してください。');
    }
    $type = $in['accident_type'] ?? '';
    if (!in_array($type, $types, true)) {
        throw new InvalidArgumentException('事故種別を選択してください。');
    }
    $weather = $in['weather'] ?? '';
    if ($weather !== '' && !in_array($weather, $weathers, true)) {
        throw new InvalidArgumentException('天候の選択が正しくありません。');
    }
    $description = trim($in['description'] ?? '');
    if ($description === '') {
        throw new InvalidArgumentException('事故状況を入力してください。');
    }
    foreach (['deaths' => '死者数', 'injuries' => '負傷者数', 'damage_amount' => '損害額'] as $key => $label) {
        $v = trim((string)($in[$key] ?? '0'));
        if ($v !== '' && !preg_match('/^\d{1,9}$/', $v)) {
            throw new InvalidArgumentException($label . 'は0以上の数字で入力してください。');
        }
    }
    return [
        'accident_date' => $date,
        'accident_time' => $time !== '' ? $time : null,
        'vehicle_id' => $vehicle_id,
        'driver_id' => $driver_id,
        'accident_type' => $type,
        'location' => trim($in['location'] ?? ''),
        'weather' => $weather !== '' ? $weather : null,
        'description' => $description,
        'cause_analysis' => trim($in['cause_analysis'] ?? ''),
        'deaths' => (int)($in['deaths'] ?? 0),
        'injuries' => (int)($in['injuries'] ?? 0),
        'property_damage' => isset($in['property_damage']) ? 1 : 0,
        'damage_amount' => (int)($in['damage_amount'] ?? 0),
        'police_report' => isset($in['police_report']) ? 1 : 0,
        'police_report_number' => trim($in['police_report_number'] ?? '') ?: null,
        'insurance_claim' => isset($in['insurance_claim']) ? 1 : 0,
        'insurance_number' => trim($in['insurance_number'] ?? '') ?: null,
        'prevention_measures' => trim($in['prevention_measures'] ?? ''),
    ];
}

// POST処理
$message = '';
$error = '';
$old_add = null;   // 登録が通らなかった時に、入れた値を残して出し直す
$old_edit = null;  // 更新が通らなかった時も同じ

// 保存後は一覧へ移してから知らせる（再読込で同じ記録がもう1件入らないように）
if (!empty($_SESSION['accident_flash'])) {
    $message = $_SESSION['accident_flash'];
    unset($_SESSION['accident_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken();
    $action = $_POST['action'] ?? '';
    try {
        $redirect_year = $search_year;
        switch ($action) {
            case 'add_accident':
                $a = normalizeAccidentInput($pdo, $_POST, $ACCIDENT_TYPES, $WEATHER_OPTIONS);
                $stmt = $pdo->prepare("
                    INSERT INTO accidents (
                        accident_date, accident_time, vehicle_id, driver_id, accident_type,
                        location, weather, description, cause_analysis,
                        deaths, injuries, property_damage, damage_amount,
                        police_report, police_report_number,
                        insurance_claim, insurance_number,
                        prevention_measures, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $a['accident_date'], $a['accident_time'], $a['vehicle_id'], $a['driver_id'], $a['accident_type'],
                    $a['location'], $a['weather'], $a['description'], $a['cause_analysis'],
                    $a['deaths'], $a['injuries'], $a['property_damage'], $a['damage_amount'],
                    $a['police_report'], $a['police_report_number'],
                    $a['insurance_claim'], $a['insurance_number'],
                    $a['prevention_measures'], $user_id
                ]);
                logAudit($pdo, (int)$pdo->lastInsertId(), 'create', $user_id, 'accident');
                $_SESSION['accident_flash'] = '事故記録を登録しました。';
                $redirect_year = (int)substr($a['accident_date'], 0, 4);
                break;

            case 'update_accident':
                $accident_id = (int)($_POST['accident_id'] ?? 0);
                $a = normalizeAccidentInput($pdo, $_POST, $ACCIDENT_TYPES, $WEATHER_OPTIONS);
                $status = $_POST['status'] ?? '';
                if (!in_array($status, $ACCIDENT_STATUSES, true)) {
                    throw new InvalidArgumentException('処理状況を選択してください。');
                }
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM accidents WHERE id = ?");
                $stmt->execute([$accident_id]);
                if (!$stmt->fetchColumn()) {
                    throw new InvalidArgumentException('この事故記録は見つかりません。すでに削除されている可能性があります。');
                }
                $stmt = $pdo->prepare("
                    UPDATE accidents SET
                        accident_date = ?, accident_time = ?, vehicle_id = ?, driver_id = ?,
                        accident_type = ?, location = ?, weather = ?, description = ?,
                        cause_analysis = ?, deaths = ?, injuries = ?, property_damage = ?,
                        damage_amount = ?, police_report = ?, police_report_number = ?,
                        insurance_claim = ?, insurance_number = ?, prevention_measures = ?,
                        status = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([
                    $a['accident_date'], $a['accident_time'], $a['vehicle_id'], $a['driver_id'],
                    $a['accident_type'], $a['location'], $a['weather'], $a['description'],
                    $a['cause_analysis'], $a['deaths'], $a['injuries'], $a['property_damage'],
                    $a['damage_amount'], $a['police_report'], $a['police_report_number'],
                    $a['insurance_claim'], $a['insurance_number'], $a['prevention_measures'],
                    $status, $accident_id
                ]);
                logAudit($pdo, $accident_id, 'edit', $user_id, 'accident');
                $_SESSION['accident_flash'] = '事故記録を更新しました。';
                $redirect_year = (int)substr($a['accident_date'], 0, 4);
                break;

            case 'delete_accident':
                $accident_id = (int)($_POST['accident_id'] ?? 0);
                archiveRowsBeforeDelete($pdo, 'accidents', 'id = ?', [$accident_id], '事故記録の削除', $user_id);
                $stmt = $pdo->prepare("DELETE FROM accidents WHERE id = ?");
                $stmt->execute([$accident_id]);
                if ($stmt->rowCount() > 0) {
                    logAudit($pdo, $accident_id, 'delete', $user_id, 'accident');
                    $_SESSION['accident_flash'] = '事故記録を削除しました。';
                } else {
                    $_SESSION['accident_flash'] = 'この事故記録はすでに削除されています。';
                }
                break;

            default:
                throw new InvalidArgumentException('操作を受け付けられませんでした。もう一度やり直してください。');
        }
        header('Location: accident_management.php?year=' . $redirect_year);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage() . '（入力した内容は残しています）';
    } catch (Exception $e) {
        error_log("Accident management error: " . $e->getMessage());
        $error = '保存できませんでした。入力した内容は残しています。もう一度保存しても同じ場合は、システム担当に連絡してください。';
    }
    // ここに来るのは保存が通らなかった時だけ
    if ($action === 'add_accident') {
        $old_add = $_POST;
    } elseif ($action === 'update_accident') {
        $old_edit = $_POST;
        foreach (['property_damage', 'police_report', 'insurance_claim'] as $k) {
            $old_edit[$k] = isset($_POST[$k]) ? 1 : 0;
        }
        unset($old_edit['csrf_token'], $old_edit['action']);
    }
}

// 車両・運転者情報取得（使っていない車両・無効の運転者は新規登録の選択肢に出さない。過去の記録の編集には出す）
function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number, is_active FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDrivers($pdo) {
    $stmt = $pdo->query("SELECT id, name, is_active FROM users WHERE is_driver = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 事故データ取得
function getAccidents($pdo, $year = null, $type = '', $status = '') {
    $sql = "
        SELECT a.*, v.vehicle_number, u.name as driver_name, 
               creator.name as created_by_name
        FROM accidents a
        LEFT JOIN vehicles v ON a.vehicle_id = v.id
        LEFT JOIN users u ON a.driver_id = u.id
        LEFT JOIN users creator ON a.created_by = creator.id
        WHERE 1=1
    ";
    $params = [];
    
    if ($year) {
        $sql .= " AND YEAR(a.accident_date) = ?";
        $params[] = $year;
    }
    
    if ($type) {
        $sql .= " AND a.accident_type = ?";
        $params[] = $type;
    }
    
    if ($status) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY a.accident_date DESC, a.accident_time DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 事故統計取得
function getAccidentStats($pdo, $year) {
    $stmt = $pdo->prepare("
        SELECT 
            accident_type,
            COUNT(*) as count,
            SUM(deaths) as total_deaths,
            SUM(injuries) as total_injuries,
            SUM(damage_amount) as total_damage
        FROM accidents 
        WHERE YEAR(accident_date) = ?
        GROUP BY accident_type
    ");
    $stmt->execute([$year]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// データ取得
$vehicles = getVehicles($pdo);
$drivers = getDrivers($pdo);
$accidents = getAccidents($pdo, $search_year, $search_type, $search_status);
$accident_stats = getAccidentStats($pdo, $search_year);

// ページ設定・ヘッダー出力
$page_config = getPageConfiguration('accident_management');

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'accident_management',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    [
        'additional_css' => [
            'css/wts-design-tokens.css'
        ],
        'breadcrumb' => [
            ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
            ['text' => 'マスターメニュー', 'url' => 'master_menu.php'],
            ['text' => $page_config['title'], 'url' => 'accident_management.php']
        ]
    ]
);

echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<style>
    .stats-card {
        border-radius: 15px;
        transition: transform 0.2s;
    }
    .stats-card:hover {
        transform: translateY(-2px);
    }
    /* 1色=1意味: アンバー=注意(交通事故) / 赤=重大 / グレー=参照情報(その他) */
    .traffic-accident {
        background: var(--wts-amber-grad);
        color: white;
    }
    .serious-accident {
        background: var(--wts-red-grad);
        color: white;
    }
    .other-accident {
        background: var(--wts-gray-grad);
        color: white;
    }
    .accident-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .accident-row:hover {
        background-color: #f8f9fa;
    }
    .status-badge {
        font-size: 0.8rem;
    }
    .search-section {
        background-color: #f8f9fa;
        border-radius: 10px;
        padding: 1rem;
    }
    .stats-number {
        font-size: 2rem;
        font-weight: bold;
    }
    /* フォーカス可視化（青=現在地・フォーカス） */
    .btn:focus-visible, .form-control:focus, .form-select:focus, textarea.form-control:focus {
        outline: 3px solid var(--wts-blue); outline-offset: 2px;
    }
    /* モバイル主要ボタンのタップターゲット確保 */
    .search-section .btn, .modal-footer .btn { min-height: 44px; }
</style>

<!-- メインコンテンツ開始 -->
<main class="main-content" id="main-content" tabindex="-1">
    <div class="container-fluid py-4">
        <!-- メッセージ表示 -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>document.addEventListener('DOMContentLoaded', function() { showToast(<?= json_encode($message, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, 'success'); });</script>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <script>document.addEventListener('DOMContentLoaded', function() { showToast(<?= json_encode($error, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>, 'danger'); });</script>
        <?php endif; ?>

        <!-- 検索・フィルター -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="search-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-search me-2"></i>検索・フィルター</h5>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addAccidentModal">
                            <i class="fas fa-plus me-1"></i>事故記録追加
                        </button>
                    </div>
                    
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">発生年</label>
                            <select name="year" class="form-select">
                                <?php
                                $year_options = range((int)date('Y'), (int)date('Y') - 5);
                                if (!in_array($search_year, $year_options, true)) { $year_options[] = $search_year; rsort($year_options); }
                                foreach ($year_options as $year): ?>
                                    <option value="<?= $year ?>" <?= $year == $search_year ? 'selected' : '' ?>>
                                        <?= $year ?>年
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">事故種別</label>
                            <select name="type" class="form-select">
                                <option value="">全て</option>
                                <option value="交通事故" <?= $search_type === '交通事故' ? 'selected' : '' ?>>交通事故</option>
                                <option value="重大事故" <?= $search_type === '重大事故' ? 'selected' : '' ?>>重大事故</option>
                                <option value="ヒヤリハット" <?= $search_type === 'ヒヤリハット' ? 'selected' : '' ?>>ヒヤリハット</option>
                                <option value="その他" <?= $search_type === 'その他' ? 'selected' : '' ?>>その他</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">処理状況</label>
                            <select name="status" class="form-select">
                                <option value="">全て</option>
                                <option value="発生" <?= $search_status === '発生' ? 'selected' : '' ?>>発生</option>
                                <option value="調査中" <?= $search_status === '調査中' ? 'selected' : '' ?>>調査中</option>
                                <option value="処理完了" <?= $search_status === '処理完了' ? 'selected' : '' ?>>処理完了</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i>検索
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 統計サマリー -->
        <?php if ($accident_stats): ?>
        <div class="row mb-4">
            <?php
            $traffic_count = 0;
            $serious_count = 0;
            $near_miss_count = 0;
            $other_count = 0;
            $total_deaths = 0;
            $total_injuries = 0;
            $total_damage = 0;

            foreach ($accident_stats as $stat) {
                if ($stat['accident_type'] === '交通事故') $traffic_count = $stat['count'];
                if ($stat['accident_type'] === '重大事故') $serious_count = $stat['count'];
                if ($stat['accident_type'] === 'ヒヤリハット') $near_miss_count = $stat['count'];
                if ($stat['accident_type'] === 'その他') $other_count = $stat['count'];
                $total_deaths += $stat['total_deaths'];
                $total_injuries += $stat['total_injuries'];
                $total_damage += $stat['total_damage'];
            }
            ?>
            
            <div class="col-md-2">
                <div class="card stats-card traffic-accident">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $traffic_count ?></div>
                        <div>交通事故</div>
                        <small><?= $search_year ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stats-card serious-accident">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $serious_count ?></div>
                        <div>重大事故</div>
                        <small><?= $search_year ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stats-card" style="border-left:4px solid var(--wts-amber);">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $near_miss_count ?></div>
                        <div>ヒヤリハット</div>
                        <small><?= $search_year ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stats-card other-accident">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $other_count ?></div>
                        <div>その他</div>
                        <small><?= $search_year ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card bg-dark text-white">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $total_deaths + $total_injuries ?></div>
                        <div>死傷者数</div>
                        <small>死者<?= $total_deaths ?>名・負傷<?= $total_injuries ?>名</small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 事故一覧 -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-list me-2"></i>事故記録一覧 (<?= $search_year ?>年)</h5>
                        <span class="badge bg-secondary"><?= count($accidents) ?>件</span>
                    </div>
                    <div class="card-body">
                        <?php if ($accidents): ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>発生日時</th>
                                            <th>種別</th>
                                            <th>車両</th>
                                            <th>運転者</th>
                                            <th>場所</th>
                                            <th>死傷者</th>
                                            <th>状況</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($accidents as $accident): ?>
                                            <tr class="accident-row" onclick="viewAccident(<?= $accident['id'] ?>)">
                                                <td>
                                                    <?= date('Y/m/d', strtotime($accident['accident_date'])) ?>
                                                    <?php if ($accident['accident_time']): ?>
                                                        <br><small><?= date('H:i', strtotime($accident['accident_time'])) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="wts-badge status-badge
                                                        <?php
                                                        switch($accident['accident_type']) {
                                                            case '交通事故': echo 'amber'; break;
                                                            case '重大事故': echo 'red'; break;
                                                            case 'ヒヤリハット': echo 'amber'; break;
                                                            case 'その他': echo 'gray'; break;
                                                        }
                                                        ?>">
                                                        <?= htmlspecialchars($accident['accident_type']) ?>
                                                    </span>
                                                </td>
                                                <td><?= htmlspecialchars($accident['vehicle_number']) ?></td>
                                                <td><?= htmlspecialchars($accident['driver_name']) ?></td>
                                                <td><?= htmlspecialchars($accident['location'] ?? '-') ?></td>
                                                <td>
                                                    <?php if ($accident['deaths'] > 0 || $accident['injuries'] > 0): ?>
                                                        <span class="text-danger">
                                                            死<?= $accident['deaths'] ?>・傷<?= $accident['injuries'] ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted">なし</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="wts-badge status-badge
                                                        <?php
                                                        switch($accident['status']) {
                                                            case '発生': echo 'red'; break;
                                                            case '調査中': echo 'amber'; break;
                                                            case '処理完了': echo 'green'; break;
                                                        }
                                                        ?>">
                                                        <?= htmlspecialchars($accident['status'] ?? '') ?>
                                                    </span>
                                                </td>
                                                <td onclick="event.stopPropagation()">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-secondary btn-sm"
                                                                onclick="window.open('templates/accident_report.php?id=<?= $accident['id'] ?>', '_blank')"
                                                                title="印刷">
                                                            <i class="fas fa-print"></i>
                                                        </button>
                                                        <button class="btn btn-outline-primary btn-sm"
                                                                onclick="editAccident(<?= $accident['id'] ?>)"
                                                                title="編集" aria-label="編集">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-sm"
                                                                onclick="deleteAccident(<?= $accident['id'] ?>)"
                                                                title="削除" aria-label="削除">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-shield-alt fa-3x text-success mb-3"></i>
                                <p class="text-muted">事故記録はありません</p>
                                <p class="text-success">安全運行が継続されています</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- 戻るボタン -->
        <div class="row">
            <div class="col-12 text-center">
                <a href="annual_report.php" class="btn btn-secondary me-2">
                    <i class="fas fa-arrow-left me-1"></i>陸運局提出に戻る
                </a>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-home me-1"></i>ダッシュボード
                </a>
            </div>
        </div>
    </div>
</main>

    <!-- 事故記録追加モーダル -->
    <div class="modal fade" id="addAccidentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>事故記録追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addAccidentForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_accident">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="accident_date" class="form-label">事故発生日 *</label>
                                    <input type="date" class="form-control" name="accident_date" required 
                                           value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="accident_time" class="form-label">発生時刻</label>
                                    <input type="time" class="form-control" name="accident_time">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="vehicle_id" class="form-label">車両 *</label>
                                    <select name="vehicle_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <?php if (!$vehicle['is_active']) continue; ?>
                                            <option value="<?= $vehicle['id'] ?>">
                                                <?= htmlspecialchars($vehicle['vehicle_number']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="driver_id" class="form-label">運転者 *</label>
                                    <select name="driver_id" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <?php if (!$driver['is_active']) continue; ?>
                                            <option value="<?= $driver['id'] ?>">
                                                <?= htmlspecialchars($driver['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="accident_type" class="form-label">事故種別 *</label>
                                    <select name="accident_type" class="form-select" required>
                                        <option value="">選択してください</option>
                                        <option value="交通事故">交通事故</option>
                                        <option value="重大事故">重大事故</option>
                                        <option value="ヒヤリハット">ヒヤリハット</option>
                                        <option value="その他">その他</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label for="location" class="form-label">発生場所</label>
                                    <input type="text" class="form-control" name="location" 
                                           placeholder="事故発生場所を入力してください">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="weather" class="form-label">天候</label>
                                    <select name="weather" class="form-select">
                                        <option value="">選択してください</option>
                                        <option value="晴">晴</option>
                                        <option value="曇">曇</option>
                                        <option value="雨">雨</option>
                                        <option value="雪">雪</option>
                                        <option value="霧">霧</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">事故状況 *</label>
                            <textarea class="form-control" name="description" rows="3" required
                                      placeholder="事故の詳細な状況を記入してください"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="cause_analysis" class="form-label">原因分析</label>
                            <textarea class="form-control" name="cause_analysis" rows="2"
                                      placeholder="事故の原因分析を記入してください"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="deaths" class="form-label">死者数</label>
                                    <input type="number" class="form-control" name="deaths" value="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="injuries" class="form-label">負傷者数</label>
                                    <input type="number" class="form-control" name="injuries" value="0" min="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="damage_amount" class="form-label">損害額（円）</label>
                                    <input type="number" class="form-control" name="damage_amount" value="0" min="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="property_damage" id="property_damage">
                                        <label class="form-check-label" for="property_damage">
                                            物損事故
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="police_report" id="police_report">
                                        <label class="form-check-label" for="police_report">
                                            警察届出済み
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="insurance_claim" id="insurance_claim">
                                        <label class="form-check-label" for="insurance_claim">
                                            保険請求済み
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="police_report_number" class="form-label">警察受理番号</label>
                                    <input type="text" class="form-control" name="police_report_number" 
                                           placeholder="警察届出番号があれば入力">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="insurance_number" class="form-label">保険事故番号</label>
                                    <input type="text" class="form-control" name="insurance_number" 
                                           placeholder="保険事故番号があれば入力">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="prevention_measures" class="form-label">再発防止策</label>
                            <textarea class="form-control" name="prevention_measures" rows="3"
                                      placeholder="今後の再発防止策を記入してください"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-success" data-loading-text="保存中...">
                            <i class="fas fa-save me-1"></i>記録保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 事故詳細表示モーダル -->
    <div class="modal fade" id="viewAccidentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i>事故記録詳細</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="accidentDetails">
                    <!-- 詳細内容はJavaScriptで動的に挿入 -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 事故記録編集モーダル -->
    <div class="modal fade" id="editAccidentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>事故記録編集</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editAccidentForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <div class="modal-body" id="editAccidentContent">
                        <!-- 編集フォームはJavaScriptで動的に挿入 -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-success" data-loading-text="保存中...">
                            <i class="fas fa-save me-1"></i>更新保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="js/ui-interactions.js"></script>

    <script>
        // 事故データ（PHPから取得）
        const accidents = <?= json_encode($accidents, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        const vehicles = <?= json_encode($vehicles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        const drivers = <?= json_encode($drivers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        const csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        // 保存が通らなかった時に、入れた値を残して出し直すための控え
        const oldAdd = <?= json_encode($old_add, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
        const oldEdit = <?= json_encode($old_edit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;

        // 画面に埋める文字は必ずこれを通す（" や < を含む記録で欄が途中で切れないように）
        function esc(v) {
            if (v === null || v === undefined) return '';
            return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        
        // 事故詳細表示
        function viewAccident(accidentId) {
            const accident = accidents.find(a => a.id == accidentId);
            if (!accident) return;
            
            const details = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle me-2"></i>基本情報</h6>
                        <table class="table table-sm">
                            <tr><td>発生日時</td><td>${esc(accident.accident_date)}${accident.accident_time ? ' ' + esc(String(accident.accident_time).slice(0, 5)) : ''}</td></tr>
                            <tr><td>事故種別</td><td><span class="wts-badge gray">${esc(accident.accident_type)}</span></td></tr>
                            <tr><td>車両</td><td>${esc(accident.vehicle_number)}</td></tr>
                            <tr><td>運転者</td><td>${esc(accident.driver_name)}</td></tr>
                            <tr><td>場所</td><td>${esc(accident.location || '-')}</td></tr>
                            <tr><td>天候</td><td>${esc(accident.weather || '-')}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-bar me-2"></i>被害状況</h6>
                        <table class="table table-sm">
                            <tr><td>死者数</td><td class="text-danger">${esc(accident.deaths)}名</td></tr>
                            <tr><td>負傷者数</td><td class="text-warning">${esc(accident.injuries)}名</td></tr>
                            <tr><td>物損事故</td><td>${Number(accident.property_damage) ? 'あり' : 'なし'}</td></tr>
                            <tr><td>損害額</td><td>¥${(parseInt(accident.damage_amount) || 0).toLocaleString()}</td></tr>
                            <tr><td>警察届出</td><td>${Number(accident.police_report) ? '済み' : '未'}</td></tr>
                            <tr><td>保険請求</td><td>${Number(accident.insurance_claim) ? '済み' : '未'}</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-file-alt me-2"></i>事故状況</h6>
                        <p class="border p-3 bg-light">${esc(accident.description || '-')}</p>
                    </div>
                </div>
                ${accident.cause_analysis ? `
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-search me-2"></i>原因分析</h6>
                        <p class="border p-3 bg-light">${esc(accident.cause_analysis)}</p>
                    </div>
                </div>
                ` : ''}
                ${accident.prevention_measures ? `
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-shield-alt me-2"></i>再発防止策</h6>
                        <p class="border p-3 bg-light">${esc(accident.prevention_measures)}</p>
                    </div>
                </div>
                ` : ''}
                <div class="row">
                    <div class="col-12">
                        <small class="text-muted">
                            登録者: ${esc(accident.created_by_name || '-')} | 
                            登録日: ${esc(accident.created_at)} | 
                            最終更新: ${esc(accident.updated_at)}
                        </small>
                    </div>
                </div>
            `;
            
            document.getElementById('accidentDetails').innerHTML = details;
            new bootstrap.Modal(document.getElementById('viewAccidentModal')).show();
        }
        
        // 事故記録編集
        function editAccident(accidentId) {
            const accident = accidents.find(a => a.id == accidentId);
            if (!accident) return;
            
            // 選択肢は使用中のものと、この記録に入っているもの。記録の運転者が一覧に居なくても消さない
            const vehicleList = vehicles.filter(v => Number(v.is_active) || v.id == accident.vehicle_id);
            const vehicleOptions = vehicleList.map(v =>
                `<option value="${esc(v.id)}" ${v.id == accident.vehicle_id ? 'selected' : ''}>${esc(v.vehicle_number)}</option>`
            ).join('');

            const driverList = drivers.filter(d => Number(d.is_active) || d.id == accident.driver_id);
            if (!driverList.some(d => d.id == accident.driver_id)) {
                driverList.unshift({ id: accident.driver_id, name: accident.driver_name || '（不明）' });
            }
            const driverOptions = driverList.map(d =>
                `<option value="${esc(d.id)}" ${d.id == accident.driver_id ? 'selected' : ''}>${esc(d.name)}</option>`
            ).join('');
            
            const editForm = `
                <input type="hidden" name="action" value="update_accident">
                <input type="hidden" name="accident_id" value="${esc(accident.id)}">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">事故発生日 *</label>
                            <input type="date" class="form-control" name="accident_date" required value="${esc(accident.accident_date)}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">発生時刻</label>
                            <input type="time" class="form-control" name="accident_time" value="${esc(accident.accident_time || '')}">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">車両 *</label>
                            <select name="vehicle_id" class="form-select" required>
                                ${vehicleOptions}
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">運転者 *</label>
                            <select name="driver_id" class="form-select" required>
                                ${driverOptions}
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">事故種別 *</label>
                            <select name="accident_type" class="form-select" required>
                                <option value="交通事故" ${accident.accident_type === '交通事故' ? 'selected' : ''}>交通事故</option>
                                <option value="重大事故" ${accident.accident_type === '重大事故' ? 'selected' : ''}>重大事故</option>
                                <option value="ヒヤリハット" ${accident.accident_type === 'ヒヤリハット' ? 'selected' : ''}>ヒヤリハット</option>
                                <option value="その他" ${accident.accident_type === 'その他' ? 'selected' : ''}>その他</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">発生場所</label>
                            <input type="text" class="form-control" name="location" value="${esc(accident.location || '')}">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label class="form-label">天候</label>
                            <select name="weather" class="form-select">
                                <option value="">選択してください</option>
                                <option value="晴" ${accident.weather === '晴' ? 'selected' : ''}>晴</option>
                                <option value="曇" ${accident.weather === '曇' ? 'selected' : ''}>曇</option>
                                <option value="雨" ${accident.weather === '雨' ? 'selected' : ''}>雨</option>
                                <option value="雪" ${accident.weather === '雪' ? 'selected' : ''}>雪</option>
                                <option value="霧" ${accident.weather === '霧' ? 'selected' : ''}>霧</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">事故状況 *</label>
                    <textarea class="form-control" name="description" rows="3" required>${esc(accident.description || '')}</textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">原因分析</label>
                    <textarea class="form-control" name="cause_analysis" rows="2">${esc(accident.cause_analysis || '')}</textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">死者数</label>
                            <input type="number" class="form-control" name="deaths" value="${esc(accident.deaths)}" min="0">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">負傷者数</label>
                            <input type="number" class="form-control" name="injuries" value="${esc(accident.injuries)}" min="0">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">損害額（円）</label>
                            <input type="number" class="form-control" name="damage_amount" value="${esc(accident.damage_amount)}" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="property_damage" ${Number(accident.property_damage) ? 'checked' : ''}>
                                <label class="form-check-label">物損事故</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="police_report" ${Number(accident.police_report) ? 'checked' : ''}>
                                <label class="form-check-label">警察届出済み</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="insurance_claim" ${Number(accident.insurance_claim) ? 'checked' : ''}>
                                <label class="form-check-label">保険請求済み</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">警察受理番号</label>
                            <input type="text" class="form-control" name="police_report_number" value="${esc(accident.police_report_number || '')}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">保険事故番号</label>
                            <input type="text" class="form-control" name="insurance_number" value="${esc(accident.insurance_number || '')}">
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">処理状況</label>
                    <select name="status" class="form-select">
                        <option value="発生" ${accident.status === '発生' ? 'selected' : ''}>発生</option>
                        <option value="調査中" ${accident.status === '調査中' ? 'selected' : ''}>調査中</option>
                        <option value="処理完了" ${accident.status === '処理完了' ? 'selected' : ''}>処理完了</option>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">再発防止策</label>
                    <textarea class="form-control" name="prevention_measures" rows="3">${esc(accident.prevention_measures || '')}</textarea>
                </div>
            `;
            
            document.getElementById('editAccidentContent').innerHTML = editForm;
            new bootstrap.Modal(document.getElementById('editAccidentModal')).show();
        }
        
        // 事故記録削除
        function deleteAccident(accidentId) {
            showConfirm('この事故記録を削除してもよろしいですか？\n削除後は復元できません。', function() {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="${esc(csrfToken)}">
                    <input type="hidden" name="action" value="delete_accident">
                    <input type="hidden" name="accident_id" value="${esc(accidentId)}">
                `;
                document.body.appendChild(form);
                form.submit();
            }, {
                type: 'danger',
                confirmText: '削除する'
            });
        }
        
        // 中央表示版showToast
        function showToast(message, type) {
            var existing = document.getElementById('centralToast');
            if (existing) existing.remove();
            var colors = { success: '#198754', warning: '#ffc107', error: '#dc3545', danger: '#dc3545', info: '#0d6efd' };
            var toast = document.createElement('div');
            toast.id = 'centralToast';
            toast.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:9999;min-width:300px;text-align:center;padding:1.5rem 2rem;border-radius:12px;font-size:1.1rem;font-weight:600;box-shadow:0 8px 32px rgba(0,0,0,0.2);color:white;background:' + (colors[type] || colors.info);
            toast.textContent = message;
            document.body.appendChild(toast);
            setTimeout(function() { toast.style.transition = 'opacity 0.5s'; toast.style.opacity = '0'; setTimeout(function() { toast.remove(); }, 500); }, 3000);
        }

        // フォーム送信確認
        document.getElementById('addAccidentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            var form = this;
            showConfirm('事故記録を登録します。よろしいですか？', function() {
                form.submit();
            }, {
                type: 'warning',
                confirmText: '登録する'
            });
        });

        // 保存が通らなかった時は、入れた値を残したまま同じ窓を開き直す
        document.addEventListener('DOMContentLoaded', function() {
            if (oldAdd) {
                const form = document.getElementById('addAccidentForm');
                Object.keys(oldAdd).forEach(function(name) {
                    if (name === 'csrf_token' || name === 'action') return;
                    const field = form.elements[name];
                    if (!field) return;
                    if (field.type === 'checkbox') { field.checked = true; } else { field.value = oldAdd[name]; }
                });
                new bootstrap.Modal(document.getElementById('addAccidentModal')).show();
            } else if (oldEdit) {
                const target = accidents.find(a => a.id == oldEdit.accident_id);
                if (target) {
                    Object.assign(target, oldEdit, { id: target.id });
                    editAccident(target.id);
                }
            }
        });
    </script>

<?= $page_data['html_footer'] ?>
