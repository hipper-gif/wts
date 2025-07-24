<?php
session_start();

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// データベース接続
require_once 'config/database.php';

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('データベース接続エラー: ' . $e->getMessage());
}

// ユーザー情報取得
$stmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if (!$user) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// 権限チェック
if (!in_array($user['role'], ['管理者', 'システム管理者'])) {
    die('アクセス権限がありません。管理者のみ利用可能です。');
}

// 事故管理テーブルの確認・作成
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS accidents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            accident_date DATE NOT NULL,
            accident_time TIME,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            accident_type ENUM('交通事故', '重大事故', 'その他') NOT NULL,
            location VARCHAR(255),
            weather VARCHAR(50),
            description TEXT,
            cause_analysis TEXT,
            deaths INT DEFAULT 0,
            injuries INT DEFAULT 0,
            property_damage BOOLEAN DEFAULT FALSE,
            damage_amount INT DEFAULT 0,
            police_report BOOLEAN DEFAULT FALSE,
            police_report_number VARCHAR(100),
            insurance_claim BOOLEAN DEFAULT FALSE,
            insurance_number VARCHAR(100),
            prevention_measures TEXT,
            status ENUM('発生', '調査中', '処理完了') DEFAULT '発生',
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_accident_date (accident_date),
            INDEX idx_vehicle_id (vehicle_id),
            INDEX idx_driver_id (driver_id),
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id),
            FOREIGN KEY (driver_id) REFERENCES users(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        )
    ");
} catch (PDOException $e) {
    $error = "事故管理テーブルの作成に失敗しました: " . $e->getMessage();
}

// 検索・フィルター条件
$search_year = $_GET['year'] ?? date('Y');
$search_type = $_GET['type'] ?? '';
$search_status = $_GET['status'] ?? '';

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_accident':
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
                        $_POST['accident_date'],
                        $_POST['accident_time'] ?: null,
                        $_POST['vehicle_id'],
                        $_POST['driver_id'],
                        $_POST['accident_type'],
                        $_POST['location'],
                        $_POST['weather'],
                        $_POST['description'],
                        $_POST['cause_analysis'],
                        (int)$_POST['deaths'],
                        (int)$_POST['injuries'],
                        isset($_POST['property_damage']) ? 1 : 0,
                        (int)$_POST['damage_amount'],
                        isset($_POST['police_report']) ? 1 : 0,
                        $_POST['police_report_number'] ?: null,
                        isset($_POST['insurance_claim']) ? 1 : 0,
                        $_POST['insurance_number'] ?: null,
                        $_POST['prevention_measures'],
                        $_SESSION['user_id']
                    ]);
                    
                    $message = "事故記録を登録しました。";
                    break;
                    
                case 'update_accident':
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
                        $_POST['accident_date'],
                        $_POST['accident_time'] ?: null,
                        $_POST['vehicle_id'],
                        $_POST['driver_id'],
                        $_POST['accident_type'],
                        $_POST['location'],
                        $_POST['weather'],
                        $_POST['description'],
                        $_POST['cause_analysis'],
                        (int)$_POST['deaths'],
                        (int)$_POST['injuries'],
                        isset($_POST['property_damage']) ? 1 : 0,
                        (int)$_POST['damage_amount'],
                        isset($_POST['police_report']) ? 1 : 0,
                        $_POST['police_report_number'] ?: null,
                        isset($_POST['insurance_claim']) ? 1 : 0,
                        $_POST['insurance_number'] ?: null,
                        $_POST['prevention_measures'],
                        $_POST['status'],
                        $_POST['accident_id']
                    ]);
                    
                    $message = "事故記録を更新しました。";
                    break;
                    
                case 'delete_accident':
                    $stmt = $pdo->prepare("DELETE FROM accidents WHERE id = ?");
                    $stmt->execute([$_POST['accident_id']]);
                    
                    $message = "事故記録を削除しました。";
                    break;
            }
        }
    } catch (Exception $e) {
        $error = "エラーが発生しました: " . $e->getMessage();
    }
}

// 車両・運転者情報取得
function getVehicles($pdo) {
    $stmt = $pdo->query("SELECT id, vehicle_number FROM vehicles ORDER BY vehicle_number");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getDrivers($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM users WHERE role = '運転者' ORDER BY name");
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
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>事故管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .navbar-brand {
            font-weight: bold;
        }
        .stats-card {
            border-radius: 15px;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-2px);
        }
        .traffic-accident {
            background: linear-gradient(135deg, #ffeaa7 0%, #fab1a0 100%);
            color: #2d3436;
        }
        .serious-accident {
            background: linear-gradient(135deg, #fd79a8 0%, #e84393 100%);
            color: white;
        }
        .other-accident {
            background: linear-gradient(135deg, #a8e6cf 0%, #7fcdcd 100%);
            color: #2d3436;
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
    </style>
</head>

<body class="bg-light">
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-danger">
        <div class="container">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-exclamation-triangle me-2"></i>事故管理
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user me-1"></i><?= htmlspecialchars($user['name']) ?>さん
                </span>
                <a class="nav-link" href="annual_report.php">
                    <i class="fas fa-file-alt me-1"></i>陸運局提出
                </a>
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-home me-1"></i>ダッシュボード
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt me-1"></i>ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- メッセージ表示 -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 検索・フィルター -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="search-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0"><i class="fas fa-search me-2"></i>検索・フィルター</h5>
                        <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#addAccidentModal">
                            <i class="fas fa-plus me-1"></i>事故記録追加
                        </button>
                    </div>
                    
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">年度</label>
                            <select name="year" class="form-select">
                                <?php for ($year = date('Y'); $year >= date('Y') - 5; $year--): ?>
                                    <option value="<?= $year ?>" <?= $year == $search_year ? 'selected' : '' ?>>
                                        <?= $year ?>年
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">事故種別</label>
                            <select name="type" class="form-select">
                                <option value="">全て</option>
                                <option value="交通事故" <?= $search_type === '交通事故' ? 'selected' : '' ?>>交通事故</option>
                                <option value="重大事故" <?= $search_type === '重大事故' ? 'selected' : '' ?>>重大事故</option>
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
            $other_count = 0;
            $total_deaths = 0;
            $total_injuries = 0;
            $total_damage = 0;
            
            foreach ($accident_stats as $stat) {
                if ($stat['accident_type'] === '交通事故') $traffic_count = $stat['count'];
                if ($stat['accident_type'] === '重大事故') $serious_count = $stat['count'];
                if ($stat['accident_type'] === 'その他') $other_count = $stat['count'];
                $total_deaths += $stat['total_deaths'];
                $total_injuries += $stat['total_injuries'];
                $total_damage += $stat['total_damage'];
            }
            ?>
            
            <div class="col-md-3">
                <div class="card stats-card traffic-accident">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $traffic_count ?></div>
                        <div>交通事故</div>
                        <small><?= $search_year ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card serious-accident">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $serious_count ?></div>
                        <div>重大事故</div>
                        <small><?= $search_year ?>年</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card other-accident">
                    <div class="card-body text-center">
                        <div class="stats-number"><?= $other_count ?></div>
                        <div>その他事故</div>
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
                                                    <span class="badge 
                                                        <?php
                                                        switch($accident['accident_type']) {
                                                            case '交通事故': echo 'bg-warning text-dark'; break;
                                                            case '重大事故': echo 'bg-danger'; break;
                                                            case 'その他': echo 'bg-info'; break;
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
                                                    <span class="badge status-badge
                                                        <?php
                                                        switch($accident['status']) {
                                                            case '発生': echo 'bg-danger'; break;
                                                            case '調査中': echo 'bg-warning text-dark'; break;
                                                            case '処理完了': echo 'bg-success'; break;
                                                        }
                                                        ?>">
                                                        <?= $accident['status'] ?>
                                                    </span>
                                                </td>
                                                <td onclick="event.stopPropagation()">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn btn-outline-primary btn-sm" 
                                                                onclick="editAccident(<?= $accident['id'] ?>)">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn btn-outline-danger btn-sm" 
                                                                onclick="deleteAccident(<?= $accident['id'] ?>)">
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

    <!-- 事故記録追加モーダル -->
    <div class="modal fade" id="addAccidentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus me-2"></i>事故記録追加</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="addAccidentForm">
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
                        <button type="submit" class="btn btn-danger">
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
                    <div class="modal-body" id="editAccidentContent">
                        <!-- 編集フォームはJavaScriptで動的に挿入 -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save me-1"></i>更新保存
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // 事故データ（PHPから取得）
        const accidents = <?= json_encode($accidents) ?>;
        const vehicles = <?= json_encode($vehicles) ?>;
        const drivers = <?= json_encode($drivers) ?>;
        
        // 事故詳細表示
        function viewAccident(accidentId) {
            const accident = accidents.find(a => a.id == accidentId);
            if (!accident) return;
            
            const details = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle me-2"></i>基本情報</h6>
                        <table class="table table-sm">
                            <tr><td>発生日時</td><td>${accident.accident_date}${accident.accident_time ? ' ' + accident.accident_time : ''}</td></tr>
                            <tr><td>事故種別</td><td><span class="badge bg-warning">${accident.accident_type}</span></td></tr>
                            <tr><td>車両</td><td>${accident.vehicle_number}</td></tr>
                            <tr><td>運転者</td><td>${accident.driver_name}</td></tr>
                            <tr><td>場所</td><td>${accident.location || '-'}</td></tr>
                            <tr><td>天候</td><td>${accident.weather || '-'}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-bar me-2"></i>被害状況</h6>
                        <table class="table table-sm">
                            <tr><td>死者数</td><td class="text-danger">${accident.deaths}名</td></tr>
                            <tr><td>負傷者数</td><td class="text-warning">${accident.injuries}名</td></tr>
                            <tr><td>物損事故</td><td>${accident.property_damage ? 'あり' : 'なし'}</td></tr>
                            <tr><td>損害額</td><td>¥${parseInt(accident.damage_amount).toLocaleString()}</td></tr>
                            <tr><td>警察届出</td><td>${accident.police_report ? '済み' : '未'}</td></tr>
                            <tr><td>保険請求</td><td>${accident.insurance_claim ? '済み' : '未'}</td></tr>
                        </table>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-file-alt me-2"></i>事故状況</h6>
                        <p class="border p-3 bg-light">${accident.description || '-'}</p>
                    </div>
                </div>
                ${accident.cause_analysis ? `
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-search me-2"></i>原因分析</h6>
                        <p class="border p-3 bg-light">${accident.cause_analysis}</p>
                    </div>
                </div>
                ` : ''}
                ${accident.prevention_measures ? `
                <div class="row">
                    <div class="col-12">
                        <h6><i class="fas fa-shield-alt me-2"></i>再発防止策</h6>
                        <p class="border p-3 bg-light">${accident.prevention_measures}</p>
                    </div>
                </div>
                ` : ''}
                <div class="row">
                    <div class="col-12">
                        <small class="text-muted">
                            登録者: ${accident.created_by_name} | 
                            登録日: ${accident.created_at} | 
                            最終更新: ${accident.updated_at}
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
            
            const vehicleOptions = vehicles.map(v => 
                `<option value="${v.id}" ${v.id == accident.vehicle_id ? 'selected' : ''}>${v.vehicle_number}</option>`
            ).join('');
            
            const driverOptions = drivers.map(d => 
                `<option value="${d.id}" ${d.id == accident.driver_id ? 'selected' : ''}>${d.name}</option>`
            ).join('');
            
            const editForm = `
                <input type="hidden" name="action" value="update_accident">
                <input type="hidden" name="accident_id" value="${accident.id}">
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">事故発生日 *</label>
                            <input type="date" class="form-control" name="accident_date" required value="${accident.accident_date}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">発生時刻</label>
                            <input type="time" class="form-control" name="accident_time" value="${accident.accident_time || ''}">
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
                                <option value="その他" ${accident.accident_type === 'その他' ? 'selected' : ''}>その他</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="mb-3">
                            <label class="form-label">発生場所</label>
                            <input type="text" class="form-control" name="location" value="${accident.location || ''}">
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
                    <textarea class="form-control" name="description" rows="3" required>${accident.description || ''}</textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">原因分析</label>
                    <textarea class="form-control" name="cause_analysis" rows="2">${accident.cause_analysis || ''}</textarea>
                </div>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">死者数</label>
                            <input type="number" class="form-control" name="deaths" value="${accident.deaths}" min="0">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="mb-3">
                            <label class="form-label">負傷者数</label>
                            <input type="number" class="form-control" name="injuries" value="${accident.injuries}" min="0">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">損害額（円）</label>
                            <input type="number" class="form-control" name="damage_amount" value="${accident.damage_amount}" min="0">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="property_damage" ${accident.property_damage ? 'checked' : ''}>
                                <label class="form-check-label">物損事故</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="police_report" ${accident.police_report ? 'checked' : ''}>
                                <label class="form-check-label">警察届出済み</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="insurance_claim" ${accident.insurance_claim ? 'checked' : ''}>
                                <label class="form-check-label">保険請求済み</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">警察受理番号</label>
                            <input type="text" class="form-control" name="police_report_number" value="${accident.police_report_number || ''}">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="form-label">保険事故番号</label>
                            <input type="text" class="form-control" name="insurance_number" value="${accident.insurance_number || ''}">
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
                    <textarea class="form-control" name="prevention_measures" rows="3">${accident.prevention_measures || ''}</textarea>
                </div>
            `;
            
            document.getElementById('editAccidentContent').innerHTML = editForm;
            new bootstrap.Modal(document.getElementById('editAccidentModal')).show();
        }
        
        // 事故記録削除
        function deleteAccident(accidentId) {
            if (confirm('この事故記録を削除してもよろしいですか？\n削除後は復元できません。')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_accident">
                    <input type="hidden" name="accident_id" value="${accidentId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // フォーム送信確認
        document.getElementById('addAccidentForm').addEventListener('submit', function(e) {
            if (!confirm('事故記録を登録します。よろしいですか？')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
