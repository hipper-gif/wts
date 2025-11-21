<?php
/**
 * 入庫処理システム v3.1 - 改良版（統一ヘッダー適用・機能強化版）
 */

session_start();
require_once 'config/database.php';
require_once 'includes/unified-header.php';

// セッション確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'ユーザー';
$user_role = $_SESSION['permission_level'] ?? 'User';

// データベース接続
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // グローバル変数として設定（統一ヘッダーで使用）
    $GLOBALS['pdo'] = $pdo;
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("データベース接続エラー");
}

// 共通関数
function getDrivers($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE is_driver = 1 AND is_active = 1 ORDER BY name");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

function getUnreturnedDepartures($pdo) {
    $stmt = $pdo->query("
        SELECT d.*, u.name as driver_name, v.vehicle_number
        FROM departure_records d
        JOIN users u ON d.driver_id = u.id
        JOIN vehicles v ON d.vehicle_id = v.id
        WHERE d.id NOT IN (SELECT COALESCE(departure_record_id, 0) FROM arrival_records WHERE departure_record_id IS NOT NULL)
        ORDER BY d.departure_date DESC, d.departure_time DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 前提条件チェック
function validateDepartureCompleted($pdo, $driver_id, $date) {
    if (!$driver_id || !$date) return false;
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM departure_records 
        WHERE driver_id = ? AND departure_date = ?
    ");
    $stmt->execute([$driver_id, $date]);
    return $stmt->fetchColumn() > 0;
}

// データ取得
$drivers = getDrivers($pdo);
$vehicles = getVehicles($pdo);
$unreturned_departures = getUnreturnedDepartures($pdo);

// メッセージ変数初期化
$success_message = null;
$error_message = null;
$warning_message = null;

// フォーム処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $departure_record_id = $_POST['departure_record_id'] ?? null;
        $driver_id = filter_var($_POST['driver_id'], FILTER_VALIDATE_INT);
        $vehicle_id = filter_var($_POST['vehicle_id'], FILTER_VALIDATE_INT);
        $arrival_date = $_POST['arrival_date'];
        $arrival_time = $_POST['arrival_time'];
        $arrival_mileage = filter_var($_POST['arrival_mileage'], FILTER_VALIDATE_INT);
        $fuel_cost = filter_var($_POST['fuel_cost'], FILTER_VALIDATE_INT) ?? 0;
        $highway_cost = filter_var($_POST['highway_cost'], FILTER_VALIDATE_INT) ?? 0;
        $other_cost = filter_var($_POST['other_cost'], FILTER_VALIDATE_INT) ?? 0;
        
        // 休憩記録
        $break_location = trim($_POST['break_location'] ?? '');
        $break_start_time = $_POST['break_start_time'] ?? null;
        $break_end_time = $_POST['break_end_time'] ?? null;
        $remarks = trim($_POST['remarks'] ?? '');

        // バリデーション
        if (!$driver_id || !$vehicle_id || !$arrival_date || !$arrival_time || !$arrival_mileage) {
            throw new Exception('必須項目が入力されていません。');
        }

        // 前提条件チェック
        if (!validateDepartureCompleted($pdo, $driver_id, $arrival_date)) {
            $warning_message = "⚠️ この運転者の出庫記録が見つかりません。出庫処理を先に完了してください。";
        }

        // 出庫メーターを取得して走行距離を計算
        $departure_mileage = 0;
        if ($departure_record_id) {
            $stmt = $pdo->prepare("SELECT departure_mileage FROM departure_records WHERE id = ?");
            $stmt->execute([$departure_record_id]);
            $departure_record = $stmt->fetch(PDO::FETCH_OBJ);
            if ($departure_record) {
                $departure_mileage = $departure_record->departure_mileage;
            }
        }

        $total_distance = $arrival_mileage - $departure_mileage;

        // 走行距離の妥当性チェック
        if ($total_distance < 0) {
            throw new Exception('入庫メーターが出庫メーターより小さくなっています。確認してください。');
        }

        // 入庫記録保存（既存コードのカラム名を使用）
        $stmt = $pdo->prepare("
            INSERT INTO arrival_records 
            (departure_record_id, driver_id, vehicle_id, arrival_date, arrival_time, arrival_mileage, 
             total_distance, fuel_cost, highway_cost, other_cost, break_location, break_start_time, 
             break_end_time, remarks, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $departure_record_id,
            $driver_id,
            $vehicle_id,
            $arrival_date,
            $arrival_time,
            $arrival_mileage,
            $total_distance,
            $fuel_cost,
            $highway_cost,
            $other_cost,
            $break_location ?: null,
            $break_start_time ?: null,
            $break_end_time ?: null,
            $remarks ?: null
        ]);

        // 車両の走行距離を更新
        $stmt = $pdo->prepare("UPDATE vehicles SET current_mileage = ? WHERE id = ?");
        $stmt->execute([$arrival_mileage, $vehicle_id]);

        $success_message = "入庫記録を保存しました。";
        $saved_driver_id = $driver_id;
        
        // リダイレクトしてフォーム再送信を防ぐ
        header("Location: arrival.php?success=1&driver_id=" . $driver_id);
        exit;
        
    } catch (Exception $e) {
        $error_message = "エラーが発生しました: " . $e->getMessage();
        error_log("Arrival record error: " . $e->getMessage());
    }
}

// 成功メッセージの表示
if (isset($_GET['success'])) {
    $success_message = "入庫記録を保存しました。";
    $saved_driver_id = $_GET['driver_id'] ?? null;
}

// 統一ヘッダー用のページ設定
$pageTitle = "入庫処理";
$pageIcon = "sign-in-alt";
$pageCategory = "日次業務";
$pageDescription = "入庫時刻・走行距離・費用記録";

// 統一ヘッダーのHTML出力
$page_config = getPageConfiguration('arrival');
$page_options = [
    'description' => $page_config['description'],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => 'dashboard.php'],
        ['text' => '日次業務', 'url' => '#'],
        ['text' => '入庫処理', 'url' => 'arrival.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'arrival',
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

<!-- メインコンテンツ -->
<main class="main-content">
    <div class="container-fluid py-4">
        <!-- 次のステップへの案内バナー -->
        <?php if ($success_message && isset($saved_driver_id)): ?>
        <div class="alert alert-success border-0 mb-4">
            <div class="d-flex align-items-center">
                <i class="fas fa-check-circle fs-3 me-3"></i>
                <div class="flex-grow-1">
                    <h5 class="alert-heading mb-1">入庫処理完了</h5>
                    <p class="mb-0">次は乗務後点呼を行ってください</p>
                </div>
                <a href="post_duty_call.php?driver_id=<?= $saved_driver_id ?>" class="btn btn-success btn-lg">
                    <i class="fas fa-clipboard-check me-2"></i>乗務後点呼へ進む
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- アラート表示 -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($warning_message): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($warning_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 未入庫一覧 -->
        <?php if (!empty($unreturned_departures)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-exclamation-triangle me-2"></i>未入庫車両一覧
                <span class="badge bg-warning ms-2"><?= count($unreturned_departures) ?></span>
            </div>
            <div class="card-body p-0">
                <div style="max-height: 300px; overflow-y: auto;">
                    <?php foreach ($unreturned_departures as $departure): ?>
                    <div class="unreturned-item border-bottom" onclick="selectDeparture(<?= $departure->id ?>, '<?= htmlspecialchars($departure->driver_name) ?>', '<?= htmlspecialchars($departure->vehicle_number) ?>', <?= $departure->departure_mileage ?>, <?= $departure->vehicle_id ?>, <?= $departure->driver_id ?>)">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong class="d-block"><?= htmlspecialchars($departure->driver_name) ?></strong>
                                <span class="text-primary fw-bold"><?= htmlspecialchars($departure->vehicle_number) ?></span>
                            </div>
                            <div class="text-end">
                                <div class="fw-bold"><?= $departure->departure_date ?> <?= $departure->departure_time ?></div>
                                <small class="text-muted">出庫メーター: <?= number_format($departure->departure_mileage) ?>km</small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 入庫記録フォーム -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-edit me-2"></i>入庫記録入力
            </div>
            <div class="card-body">
                <form method="POST" id="arrivalForm">
                    <input type="hidden" id="departure_record_id" name="departure_record_id" value="">
                    
                    <!-- 基本情報 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label for="driver_id" class="form-label">
                                <i class="fas fa-user me-1"></i>運転者 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="driver_id" name="driver_id" required>
                                <option value="">運転者を選択</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?= $driver->id ?>"><?= htmlspecialchars($driver->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="vehicle_id" class="form-label">
                                <i class="fas fa-car me-1"></i>車両 <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="vehicle_id" name="vehicle_id" required>
                                <option value="">車両を選択</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle->id ?>"><?= htmlspecialchars($vehicle->vehicle_number) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- 入庫情報 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label for="arrival_date" class="form-label">
                                <i class="fas fa-calendar me-1"></i>入庫日 <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control" id="arrival_date" name="arrival_date" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="arrival_time" class="form-label">
                                <i class="fas fa-clock me-1"></i>入庫時刻 <span class="text-danger">*</span>
                            </label>
                            <input type="time" class="form-control" id="arrival_time" name="arrival_time" 
                                   value="<?= date('H:i') ?>" required>
                        </div>
                    </div>

                    <!-- メーター情報 -->
                    <div class="row mb-4">
                        <div class="col-md-6 mb-3">
                            <label for="arrival_mileage" class="form-label">
                                <i class="fas fa-tachometer-alt me-1"></i>入庫メーター(km) <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control" id="arrival_mileage" name="arrival_mileage" required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="total_distance" class="form-label">
                                <i class="fas fa-route me-1"></i>走行距離(km)
                            </label>
                            <input type="number" class="form-control" id="total_distance" name="total_distance" readonly>
                            <div class="form-text">自動計算されます</div>
                        </div>
                    </div>

                    <!-- 費用情報 -->
                    <div class="row mb-4">
                        <div class="col-md-4 mb-3">
                            <label for="fuel_cost" class="form-label">
                                <i class="fas fa-gas-pump me-1"></i>燃料代(円)
                            </label>
                            <input type="number" class="form-control" id="fuel_cost" name="fuel_cost" value="0" min="0">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="highway_cost" class="form-label">
                                <i class="fas fa-road me-1"></i>高速代(円)
                            </label>
                            <input type="number" class="form-control" id="highway_cost" name="highway_cost" value="0" min="0">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label for="other_cost" class="form-label">
                                <i class="fas fa-receipt me-1"></i>その他費用(円)
                            </label>
                            <input type="number" class="form-control" id="other_cost" name="other_cost" value="0" min="0">
                        </div>
                    </div>

                    <!-- 休憩記録 -->
                    <div class="card mb-4" style="border: 2px dashed #dee2e6;">
                        <div class="card-header bg-light">
                            <i class="fas fa-coffee me-2"></i>休憩記録（任意）
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="break_location" class="form-label">
                                        <i class="fas fa-map-marker-alt me-1"></i>休憩場所
                                    </label>
                                    <input type="text" class="form-control" id="break_location" name="break_location" 
                                           placeholder="例：○○SA、△△道の駅">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="break_start_time" class="form-label">
                                        <i class="fas fa-play me-1"></i>休憩開始
                                    </label>
                                    <input type="time" class="form-control" id="break_start_time" name="break_start_time">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="break_end_time" class="form-label">
                                        <i class="fas fa-stop me-1"></i>休憩終了
                                    </label>
                                    <input type="time" class="form-control" id="break_end_time" name="break_end_time">
                                </div>
                            </div>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>
                                法定要件ではありませんが、労務管理のため記録を推奨します
                            </div>
                        </div>
                    </div>

                    <!-- 備考 -->
                    <div class="mb-4">
                        <label for="remarks" class="form-label">
                            <i class="fas fa-sticky-note me-1"></i>備考・特記事項
                        </label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3" 
                                  placeholder="特記事項があれば入力してください"></textarea>
                    </div>

                    <!-- 保存ボタン -->
                    <div class="text-center">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save me-2"></i>入庫記録を保存
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 操作ヘルプ -->
        <div class="card">
            <div class="card-header bg-light">
                <i class="fas fa-question-circle me-2"></i>操作ガイド
            </div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6><i class="fas fa-mouse-pointer me-2 text-primary"></i>未入庫車両から選択</h6>
                        <p class="small text-muted mb-0">上の「未入庫車両一覧」から該当車両をクリックすると、運転者・車両・出庫メーターが自動入力されます。</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-calculator me-2 text-success"></i>走行距離自動計算</h6>
                        <p class="small text-muted mb-0">入庫メーターを入力すると、出庫メーターとの差から走行距離が自動計算されます。</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-shield-alt me-2 text-warning"></i>前提条件チェック</h6>
                        <p class="small text-muted mb-0">出庫記録が存在しない場合は警告が表示されます。必ず出庫処理を先に完了してください。</p>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-arrow-right me-2 text-info"></i>次のステップ</h6>
                        <p class="small text-muted mb-0">保存完了後、「乗務後点呼」へ進むボタンが表示されます。</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- カスタムスタイル -->
<style>
.unreturned-item {
    cursor: pointer;
    transition: all 0.2s ease;
    border-radius: 8px;
    margin: 0.5rem;
    padding: 1rem;
}

.unreturned-item:hover {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    transform: translateX(5px);
}

.unreturned-item.selected {
    background: linear-gradient(135deg, #2196F3 0%, #1976D2 100%);
    color: white;
}

.form-control.border-warning {
    border-color: #ffc107 !important;
}

.form-control.border-danger {
    border-color: #dc3545 !important;
}
</style>

<!-- JavaScript -->
<script>
    // 未入庫項目選択時の処理
    function selectDeparture(departureId, driverName, vehicleNumber, departureMileage, vehicleId, driverId) {
        // フォームに値を設定
        document.getElementById('departure_record_id').value = departureId;
        document.getElementById('driver_id').value = driverId;
        document.getElementById('vehicle_id').value = vehicleId;

        // 出庫メーターを保存（数値として明示的に変換）
        window.departureMileage = Number(departureMileage);

        // 走行距離を再計算
        calculateDistance();
        
        // ビジュアルフィードバック
        document.querySelectorAll('.unreturned-item').forEach(item => {
            item.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
        
        // 通知表示
        showNotification(`${driverName} (${vehicleNumber}) を選択しました`, 'success');
    }

    // 走行距離自動計算
    function calculateDistance() {
        const arrivalMileage = parseInt(document.getElementById('arrival_mileage').value) || 0;
        const departureMileage = Number(window.departureMileage) || 0;
        const totalDistance = arrivalMileage - departureMileage;
        
        const distanceField = document.getElementById('total_distance');
        
        if (totalDistance >= 0) {
            distanceField.value = totalDistance;
            distanceField.className = 'form-control';
            
            // 異常値チェック
            if (totalDistance > 500) {
                distanceField.className = 'form-control border-warning';
                showNotification('走行距離が500kmを超えています。確認してください。', 'warning');
            }
        } else if (arrivalMileage > 0) {
            distanceField.value = '';
            distanceField.className = 'form-control border-danger';
            showNotification('入庫メーターが出庫メーターより小さくなっています。', 'error');
        }
    }

    // 通知表示
    function showNotification(message, type = 'info') {
        // 既存のトーストを削除
        const existingToast = document.querySelector('.toast');
        if (existingToast) {
            existingToast.remove();
        }

        const colors = {
            success: 'bg-success',
            warning: 'bg-warning',
            error: 'bg-danger',
            info: 'bg-primary'
        };

        const toastHtml = `
            <div class="toast position-fixed top-0 end-0 m-3" style="z-index: 9999" role="alert">
                <div class="toast-header ${colors[type]} text-white">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong class="me-auto">通知</strong>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    ${message}
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', toastHtml);
        const toast = new bootstrap.Toast(document.querySelector('.toast'));
        toast.show();
    }

    // フォームバリデーション
    function validateForm() {
        const requiredFields = ['driver_id', 'vehicle_id', 'arrival_date', 'arrival_time', 'arrival_mileage'];
        let isValid = true;

        requiredFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (!field.value.trim()) {
                field.classList.add('border-danger');
                isValid = false;
            } else {
                field.classList.remove('border-danger');
            }
        });

        if (!isValid) {
            showNotification('必須項目が入力されていません。', 'error');
            return false;
        }

        return true;
    }

    // 休憩時間の妥当性チェック
    function validateBreakTime() {
        const startTime = document.getElementById('break_start_time').value;
        const endTime = document.getElementById('break_end_time').value;

        if (startTime && endTime) {
            const start = new Date(`2000-01-01 ${startTime}`);
            const end = new Date(`2000-01-01 ${endTime}`);

            if (end <= start) {
                showNotification('休憩終了時刻は開始時刻より後に設定してください。', 'warning');
                return false;
            }

            const breakMinutes = (end - start) / (1000 * 60);
            if (breakMinutes > 480) { // 8時間
                showNotification('休憩時間が8時間を超えています。確認してください。', 'warning');
            }
        }
        return true;
    }

    // イベントリスナー設定
    document.addEventListener('DOMContentLoaded', function() {
        // 現在時刻を自動設定
        const now = new Date();
        const timeString = now.getHours().toString().padStart(2, '0') + ':' + 
                          now.getMinutes().toString().padStart(2, '0');
        document.getElementById('arrival_time').value = timeString;

        // 入庫メーター変更時に走行距離を再計算
        document.getElementById('arrival_mileage').addEventListener('input', calculateDistance);

        // 休憩時間変更時の検証
        document.getElementById('break_start_time').addEventListener('change', validateBreakTime);
        document.getElementById('break_end_time').addEventListener('change', validateBreakTime);

        // フォーム送信時の検証
        document.getElementById('arrivalForm').addEventListener('submit', function(e) {
            if (!validateForm() || !validateBreakTime()) {
                e.preventDefault();
            }
        });

        // リアルタイムバリデーション
        const requiredFields = ['driver_id', 'vehicle_id', 'arrival_date', 'arrival_time', 'arrival_mileage'];
        requiredFields.forEach(fieldId => {
            document.getElementById(fieldId).addEventListener('input', function() {
                this.classList.remove('border-danger');
            });
        });
    });
</script>

<?php
// 統一ヘッダーのフッター出力
echo $page_data['html_footer'];
?>
