// Ctrl+D で運転手データCSVエクスポート
            if (e.ctrlKey && e.key === 'd') {
                e.preventDefault();
                exportDriverDataCSV();
            }        // 運転手別詳細の表示/非表示切り替え
        function toggleDriverDetails() {
            const details = document.querySelectorAll('.driver-details');
            const icon = document.getElementById('driverToggleIcon');
            const text = document.getElementById('driverToggleText');
            
            const isVisible = details[0] && details[0].style.display !== 'none';
            
            details.forEach(detail => {
                detail.style.display = isVisible ? 'none' : 'table-row';
            });
            
            if (isVisible) {
                icon.className = 'fas fa-chart-pie';
                text.textContent = '詳細表示';
            } else {
                icon.className = 'fas fa-chart-line';
                text.textContent = '詳細非表示';
            }
        }

        // 運転手別データのCSVエクスポート
        function exportDriverDataCSV() {
            const data = [
                ['順位', '運転手', '稼働日数', '総件数', '現金回収', 'カード売上', 'その他', '総売上', '平均単価', '日平均']
            ];
            
            <?php 
            $rank = 1;
            foreach ($monthly_driver_sales as $driverSale): 
                $daily_avg = $driverSale->working_days > 0 ? $driverSale->total_amount / $driverSale->working_days : 0;
            ?>
            data.push([
                '<?= $rank ?>',
                '<?= addslashes($driverSale->driver_name) ?>',
                '<?= $driverSale->working_days ?>',
                '<?= $driverSale->trip_count ?>',
                '<?= $driverSale->cash_amount ?>',
                '<?= $driverSale->card_amount ?>',
                '<?= $driverSale->other_amount ?>',
                '<?= $driverSale->total_amount ?>',
                '<?= $driverSale->avg_fare ?>',
                '<?= round($daily_avg) ?>'
            ]);
            <?php 
            $rank++;
            endforeach; 
            ?>
            
            const csvContent = data.map(row => row.join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = '運転手別売上_<?= $selected_month ?>.csv';
            link.click();
        }

        // 運転手レポート印刷
        function printDriverReport() {
            const printContent = document.getElementById('driverSalesTable').outerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>運転手別売上レポート</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .table { font-size: 12px; }
                        .badge { font-size: 10px; padding: 2px 6px; }
                        @media print {
                            body { margin: 0; }
                            .table th, .table td { border: 1px solid #000 !important; padding: 4px; }
                        }
                    </style>
                </head>
                <body>
                    <h3>運転手別売上レポート（<?= $selected_month ?>）</h3>
                    <p>出力日時: ${new Date().toLocaleString('ja-JP')}</p>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }        // 現金カウント計算（既存テーブル対応）
        function calculateTotal(driverId) {
            const bills10000 = parseInt(document.querySelector(`#driver${driverId} input[name="bills_10000"]`).value) || 0;
            const bills5000 = parseInt(document.querySelector(`#driver${driverId} input[name="bills_5000"]`).value) || 0;
            const bills1000 = parseInt(document.querySelector(`#driver${driverId} input[name="bills_1000"]`).value) || 0;
            const coins500 = parseInt(document.querySelector(`#driver${driverId} input[name="coins_500"]`).value) || 0;
            const coins100 = parseInt(document.querySelector(`#driver${driverId} input[name="coins_100"]`).value) || 0;
            const coins50 = parseInt(document.querySelector(`#driver${driverId} input[name="coins_50"]`).value) || 0;
            const coins10 = parseInt(document.querySelector(`#driver${driverId} input[name="coins_10"]`).value) || 0;
            const coins5 = parseInt(document.querySelector(`#driver${driverId} input[name="coins_5"]`).value) || 0;
            const coins1 = parseInt(document.querySelector(`#driver${driverId} input[name="coins_1"]`).value) || 0;
            const changeAmount = parseInt(document.querySelector(`#driver${driverId} input[name="change_amount"]`).value) || 0;
            
            // 各金種の金額計算
            const amount10000 = bills10000 * 10000;
            const amount5000 = bills5000 * 5000;
            const amount1000 = bills1000 * 1000;
            const amount500 = coins500 * 500;
            const amount100 = coins100 * 100;
            const amount50 = coins50 * 50;
            const amount10 = coins10 * 10;
            const amount5 = coins5 * 5;
            const amount1 = coins1 * 1;
            
            // 各金種の金額表示更新
            document.getElementById(`bills_10000_amount_${driverId}`).textContent = `¥${amount10000.toLocaleString()}`;
            document.getElementById(`bills_5000_amount_${driverId}`).textContent = `¥${amount5000.toLocaleString()}`;
            document.getElementById(`bills_1000_amount_${driverId}`).textContent = `¥${amount1000.toLocaleString()}`;
            document.getElementById(`coins_500_amount_${driverId}`).textContent = `¥${amount500.toLocaleString()}`;
            document.getElementById(`coins_100_amount_${driverId}`).textContent = `¥${amount100.toLocaleString()}`;
            document.getElementById(`coins_50_amount_${driverId}`).textContent = `¥${amount50.toLocaleString()}`;
            document.getElementById(`coins_10_amount_${driverId}`).textContent = `¥${amount10.toLocaleString()}`;
            document.getElementById(`coins_5_amount_${driverId}`).textContent = `¥${amount5.toLocaleString()}`;
            document.getElementById(`coins_1_amount_${driverId}`).textContent = `¥${amount1.toLocaleString()}`;
            
            // 総計算
            const totalCash = amount10000 + amount5000 + amount1000 + amount500 + amount100 + amount50 + amount10 + amount5 + amount1;
            const netAmount = totalCash - changeAmount;
            const calculatedAmount = parseInt(document.querySelector(`#driver${driverId} input[name="calculated_amount"]`).value) || 0;
            const difference = netAmount - calculatedAmount;
            
            // 表示更新
            document.getElementById(`total_cash_${driverId}`).value = `${totalCash.toLocaleString()}`;
            document.getElementById(`net_amount_${driverId}`).value = `${netAmount.toLocaleString()}`;
            
            const diffField = document.getElementById(`difference_${driverId}`);
            diffField.value = difference === 0 ? '一致' : (difference > 0 ? `+¥${difference.toLocaleString()}` : `¥${difference.toLocaleString()}`);
            
            // 差額による色分け
            diffField.className = 'form-control';
            if (difference === 0) {
                diffField.classList.add('bg-success', 'text-white');
            } else if (difference > 0) {
                diffField.classList.add('bg-warning', 'text-dark');
            } else {
                diffField.classList.add('bg-danger', 'text-white');
            }
        }

        // 詳細リストの表示/非表示切り替え
        function toggleDetails() {
            const details = document.getElementById('monthlyDetails');
            const icon = document.getElementById('toggleIcon');
            const text = document.getElementById('toggleText');
            
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.className = 'fas fa-eye-slash';
                text.textContent = '詳細非表示';
            } else {
                details.style.display = 'none';
                icon.className = 'fas fa-eye';
                text.textContent = '詳細表示';
            }
        }        <!-- 運転手別月次売上サマリー -->
        <?php if (!empty($monthly_driver_sales)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-trophy"></i> 運転手別月次売上ランキング（<?= $selected_month ?>）</h5>
                        <div class="no-print">
                            <button class="btn btn-sm btn-outline-success" onclick="exportDriverDataCSV()">
                                <i class="fas fa-file-csv"></i> CSV出力
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="printDriverReport()">
                                <i class="fas fa-print"></i> 印刷
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm" id="driverSalesTable">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-medal"></i> 順位</th>
                                        <th><i class="fas fa-user"></i> 運転手</th>
                                        <th class="text-end">稼働日数</th>
                                        <th class="text-end">総件数</th>
                                        <th class="text-end">現金回収</th>
                                        <th class="text-end">カード売上</th>
                                        <th class="text-end">その他</th>
                                        <th class="text-end">総売上</th>
                                        <th class="text-end">平均単価</th>
                                        <th class="text-end">日平均</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    foreach ($monthly_driver_sales as $driverSale): 
                                        $daily_avg = $driverSale->working_days > 0 ? $driverSale->total_amount / $driverSale->working_days : 0;
                                    ?>
                                    <tr class="<?= $rank <= 3 ? 'table-warning' : '' ?>">
                                        <td>
                                            <?php if ($rank == 1): ?>
                                                <span class="badge bg-warning"><i class="fas fa-crown"></i> <?= $rank ?>位</span>
                                            <?php elseif ($rank == 2): ?>
                                                <span class="badge bg-secondary"><i class="fas fa-medal"></i> <?= $rank ?>位</span>
                                            <?php elseif ($rank == 3): ?>
                                                <span class="badge bg-warning"><i class="fas fa-medal"></i> <?= $rank ?>位</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark"><?= $rank ?>位</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($driverSale->driver_name) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-info"><?= $driverSale->working_days ?>日</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-secondary"><?= $driverSale->trip_count ?>件</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-success">
                                                <i class="fas fa-money-bill-wave"></i> 
                                                ¥<?= number_format($driverSale->cash_amount) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-primary">
                                                <i class="fas fa-credit-card"></i> 
                                                ¥<?= number_format($driverSale->card_amount) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-warning">
                                                ¥<?= number_format($driverSale->other_amount) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <strong class="amount-medium text-primary">¥<?= number_format($driverSale->total_amount) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <small>¥<?= number_format($driverSale->avg_fare) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <small>¥<?= number_format($daily_avg) ?></small>
                                        </td>
                                    </tr>
                                    <?php 
                                    $rank++;
                                    endforeach; 
                                    ?>
                                    <tr class="table-success">
                                        <th colspan="3">全体合計</th>
                                        <th class="text-end"><?= array_sum(array_column($monthly_driver_sales, 'trip_count')) ?>件</th>
                                        <th class="text-end">¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'cash_amount'))) ?></th>
                                        <th class="text-end">¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'card_amount'))) ?></th>
                                        <th class="text-end">¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'other_amount'))) ?></th>
                                        <th class="text-end"><strong>¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'total_amount'))) ?></strong></th>
                                        <th class="text-end">-</th>
                                        <th class="text-end">-</th>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>        // 詳細リストの表示/非表示切り替え
        function toggleDetails() {
            const details = document.getElementById('monthlyDetails');
            const icon = document.getElementById('toggleIcon');
            const text = document.getElementById('toggleText');
            
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.className = 'fas fa-eye-slash';
                text.textContent = '詳細非表示';
            } else {
                details.style.display = 'none';
                icon.className = 'fas fa-eye';
                text.textContent = '詳細表示';
            }
        }

        // 運転手別詳細の表示/非表示切り替え
        function toggleDriverDetails() {
            const details = document.querySelectorAll('.driver-details');
            const icon = document.getElementById('driverToggleIcon');
            const text = document.getElementById('driverToggleText');
            
            const isVisible = details[0] && details[0].style.display !== 'none';
            
            details.forEach(detail => {
                detail.style.display = isVisible ? 'none' : 'table-row';
            });
            
            if (isVisible) {
                icon.className = 'fas fa-chart-pie';
                text.textContent = '詳細表示';
            } else {
                icon.className = 'fas fa-chart-line';
                text.textContent = '詳細非表示';
            }
        }

        // 運転手別データのCSVエクスポート
        function exportDriverDataCSV() {
            const data = [
                ['順位', '運転手', '稼働日数', '総件数', '現金回収', 'カード売上', 'その他', '総売上', '平均単価', '日平均']
            ];
            
            <?php 
            $rank = 1;
            foreach ($monthly_driver_sales as $driverSale): 
                $daily_avg = $driverSale->working_days > 0 ? $driverSale->total_amount / $driverSale->working_days : 0;
            ?>
            data.push([
                '<?= $rank ?>',
                '<?= addslashes($driverSale->driver_name) ?>',
                '<?= $driverSale->working_days ?>',
                '<?= $driverSale->trip_count ?>',
                '<?= $driverSale->cash_amount ?>',
                '<?= $driverSale->card_amount ?>',
                '<?= $driverSale->other_amount ?>',
                '<?= $driverSale->total_amount ?>',
                '<?= $driverSale->avg_fare ?>',
                '<?= round($daily_avg) ?>'
            ]);
            <?php 
            $rank++;
            endforeach; 
            ?>
            
            const csvContent = data.map(row => row.join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = '運転手別売上_<?= $selected_month ?>.csv';
            link.click();
        }

        // 運転手レポート印刷
        function printDriverReport() {
            const printContent = document.getElementById('driverSalesTable').outerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>運転手別売上レポート</title>
                    <link href="https://cdn.<?php
session_start();
require_once 'config/database.php';

// 権限チェック - permission_levelがAdminのみアクセス可能
function checkAdminPermission($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT permission_level FROM users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$user || $user->permission_level !== 'Admin') {
            header('Location: dashboard.php?error=admin_required');
            exit;
        }
    } catch (PDOException $e) {
        header('Location: dashboard.php?error=db_error');
        exit;
    }
}

// ログイン確認
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// 権限チェック実行
checkAdminPermission($pdo, $_SESSION['user_id']);

// 現在のユーザー情報取得
$stmt = $pdo->prepare("SELECT name, permission_level FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_OBJ);

// 日付設定
$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_month = $_GET['month'] ?? date('Y-m');

// POST処理
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['cash_count'])) {
            // 現金カウント記録（既存テーブル使用）
            $bills_10000 = (int)$_POST['bills_10000'];
            $bills_5000 = (int)$_POST['bills_5000'];
            $bills_1000 = (int)$_POST['bills_1000'];
            $coins_500 = (int)$_POST['coins_500'];
            $coins_100 = (int)$_POST['coins_100'];
            $coins_50 = (int)$_POST['coins_50'];
            $coins_10 = (int)$_POST['coins_10'];
            $coins_5 = (int)$_POST['coins_5'];
            $coins_1 = (int)$_POST['coins_1'];
            $change_amount = (int)$_POST['change_amount'];
            
            $total_cash = ($bills_10000 * 10000) + ($bills_5000 * 5000) + ($bills_1000 * 1000) + 
                         ($coins_500 * 500) + ($coins_100 * 100) + ($coins_50 * 50) + 
                         ($coins_10 * 10) + ($coins_5 * 5) + ($coins_1 * 1);
            
            $net_amount = $total_cash - $change_amount;
            $calculated_amount = (int)$_POST['calculated_amount'];
            $difference = $net_amount - $calculated_amount;
            
            $stmt = $pdo->prepare("
                INSERT INTO detailed_cash_confirmations 
                (confirmation_date, driver_id, bills_10000, bills_5000, bills_1000, 
                 coins_500, coins_100, coins_50, coins_10, coins_5, coins_1, 
                 total_cash, change_amount, net_amount, calculated_amount, difference, memo, confirmed_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                bills_10000 = VALUES(bills_10000), bills_5000 = VALUES(bills_5000), bills_1000 = VALUES(bills_1000),
                coins_500 = VALUES(coins_500), coins_100 = VALUES(coins_100), coins_50 = VALUES(coins_50),
                coins_10 = VALUES(coins_10), coins_5 = VALUES(coins_5), coins_1 = VALUES(coins_1),
                total_cash = VALUES(total_cash), change_amount = VALUES(change_amount), net_amount = VALUES(net_amount),
                calculated_amount = VALUES(calculated_amount), difference = VALUES(difference), 
                memo = VALUES(memo), confirmed_by = VALUES(confirmed_by), updated_at = NOW()
            ");
            $stmt->execute([
                $_POST['confirmation_date'],
                $_POST['driver_id'],
                $bills_10000, $bills_5000, $bills_1000,
                $coins_500, $coins_100, $coins_50, $coins_10, $coins_5, $coins_1,
                $total_cash, $change_amount, $net_amount, $calculated_amount, $difference,
                $_POST['memo'],
                $_SESSION['user_id']
            ]);
            $message = '現金カウントを記録しました。';
        }
        
        if (isset($_POST['update_change_stock'])) {
            // おつり在庫調整
            $stmt = $pdo->prepare("
                INSERT INTO driver_change_stocks (driver_id, stock_amount, notes, updated_by, updated_at)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                stock_amount = VALUES(stock_amount),
                notes = VALUES(notes),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
            ");
            $stmt->execute([
                $_POST['driver_id'],
                $_POST['stock_amount'],
                $_POST['notes'],
                $_SESSION['user_id']
            ]);
            $message = 'おつり在庫を更新しました。';
        }
    } catch (PDOException $e) {
        $error = 'データベースエラーが発生しました: ' . $e->getMessage();
    }
}

// 各種データ取得関数
function getDailySales($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                payment_method,
                COUNT(*) as trip_count,
                SUM(fare) as total_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ? 
            GROUP BY payment_method
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getDailyTotal($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_trips,
                SUM(fare) as total_amount,
                SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash_amount,
                SUM(CASE WHEN payment_method = 'カード' THEN fare ELSE 0 END) as card_amount,
                SUM(CASE WHEN payment_method NOT IN ('現金', 'カード') THEN fare ELSE 0 END) as other_amount
            FROM ride_records 
            WHERE DATE(ride_date) = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return (object)['total_trips' => 0, 'total_amount' => 0, 'cash_amount' => 0, 'card_amount' => 0, 'other_amount' => 0];
    }
}

function getMonthlySummary($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(ride_date) as date,
                COUNT(*) as trips,
                SUM(fare) as total,
                SUM(CASE WHEN payment_method = '現金' THEN fare ELSE 0 END) as cash,
                SUM(CASE WHEN payment_method = 'カード' THEN fare ELSE 0 END) as card,
                SUM(CASE WHEN payment_method NOT IN ('現金', 'カード') THEN fare ELSE 0 END) as other
            FROM ride_records 
            WHERE DATE_FORMAT(ride_date, '%Y-%m') = ?
            GROUP BY DATE(ride_date)
            ORDER BY DATE(ride_date) DESC
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getMonthlySalesDetails($pdo, $month) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.ride_date,
                r.ride_time,
                u.name as driver_name,
                r.pickup_location,
                r.dropoff_location,
                r.passenger_count,
                r.fare,
                r.payment_method,
                r.transportation_type
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE_FORMAT(r.ride_date, '%Y-%m') = ?
            ORDER BY r.ride_date DESC, r.ride_time DESC
        ");
        $stmt->execute([$month]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getCashConfirmation($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT c.*, u.name as confirmed_by_name
            FROM cash_confirmations c
            LEFT JOIN users u ON c.confirmed_by = u.id
            WHERE c.date = ?
        ");
        $stmt->execute([$date]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return null;
    }
}

function getDriverChangeStocks($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                dcs.*,
                u.name as driver_name,
                uu.name as updated_by_name
            FROM driver_change_stocks dcs
            LEFT JOIN users u ON dcs.driver_id = u.id
            LEFT JOIN users uu ON dcs.updated_by = uu.id
            WHERE u.is_active = TRUE AND u.is_driver = TRUE
            ORDER BY u.name
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getDailyDrivers($pdo, $date) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id, 
                u.name,
                SUM(CASE WHEN r.payment_method = '現金' THEN r.fare ELSE 0 END) as cash_collected
            FROM ride_records r
            LEFT JOIN users u ON r.driver_id = u.id
            WHERE DATE(r.ride_date) = ? AND u.is_active = TRUE AND r.payment_method = '現金'
            GROUP BY u.id, u.name
            ORDER BY u.name
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return [];
    }
}

function getCashCount($pdo, $date, $driver_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM detailed_cash_confirmations 
            WHERE confirmation_date = ? AND driver_id = ?
        ");
        $stmt->execute([$date, $driver_id]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    } catch (PDOException $e) {
        return null;
    }
}

// データ取得
$daily_sales = getDailySales($pdo, $selected_date);
$daily_total = getDailyTotal($pdo, $selected_date);
$monthly_summary = getMonthlySummary($pdo, $selected_month);
$monthly_details = getMonthlySalesDetails($pdo, $selected_month);
$cash_confirmation = getCashConfirmation($pdo, $selected_date);
$driver_stocks = getDriverChangeStocks($pdo);
$daily_drivers = getDailyDrivers($pdo, $selected_date);

// 現金計算（おつり在庫考慮）
$total_change_stock = array_sum(array_column($driver_stocks, 'stock_amount'));
$calculated_cash_in_office = $daily_total->cash_amount - $total_change_stock;
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>集金管理 - 福祉輸送管理システム</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .cash-card { background: linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%); }
        .card-card { background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%); }
        .other-card { background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%); }
        .total-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .amount-large { font-size: 1.8rem; font-weight: bold; }
        .amount-medium { font-size: 1.3rem; font-weight: 600; }
        .stock-positive { color: #28a745; }
        .stock-warning { color: #ffc107; }
        .stock-danger { color: #dc3545; }
        .accounting-memo { background: #f8f9fa; border-left: 4px solid #007bff; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
        }
    </style>
</head>
<body class="bg-light">
    <!-- ナビゲーション -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="fas fa-cash-register"></i> 集金管理
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3">
                    <i class="fas fa-user"></i> <?= htmlspecialchars($current_user->name) ?>
                </span>
                <a class="nav-link" href="dashboard.php">
                    <i class="fas fa-tachometer-alt"></i> ダッシュボード
                </a>
                <a class="nav-link" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> ログアウト
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- 日付選択 -->
        <div class="row mb-4 no-print">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-calendar-day"></i> 日次管理</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="d-flex align-items-end gap-2">
                            <div class="flex-grow-1">
                                <label class="form-label">対象日</label>
                                <input type="date" name="date" value="<?= $selected_date ?>" 
                                       class="form-control" onchange="this.form.submit()">
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> 表示
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-calendar-alt"></i> 月次管理</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="d-flex align-items-end gap-2">
                            <div class="flex-grow-1">
                                <label class="form-label">対象月</label>
                                <input type="month" name="month" value="<?= $selected_month ?>" 
                                       class="form-control" onchange="this.form.submit()">
                            </div>
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-chart-bar"></i> 月次表示
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- 日次売上サマリー -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card total-card">
                    <div class="card-body text-center">
                        <h6 class="card-title"><i class="fas fa-chart-line"></i> 総売上</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->total_amount) ?></div>
                        <small><?= $daily_total->total_trips ?>件</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card cash-card">
                    <div class="card-body text-center text-dark">
                        <h6 class="card-title"><i class="fas fa-money-bill-wave"></i> 現金売上</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->cash_amount) ?></div>
                        <small><?= count(array_filter($daily_sales, fn($s) => $s->payment_method === '現金')) ? array_filter($daily_sales, fn($s) => $s->payment_method === '現金')[0]->trip_count ?? 0 : 0 ?>件</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card card-card">
                    <div class="card-body text-center text-dark">
                        <h6 class="card-title"><i class="fas fa-credit-card"></i> カード売上</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->card_amount) ?></div>
                        <small><?= count(array_filter($daily_sales, fn($s) => $s->payment_method === 'カード')) ? array_filter($daily_sales, fn($s) => $s->payment_method === 'カード')[0]->trip_count ?? 0 : 0 ?>件</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card other-card">
                    <div class="card-body text-center text-dark">
                        <h6 class="card-title"><i class="fas fa-receipt"></i> その他</h6>
                        <div class="amount-large">¥<?= number_format($daily_total->other_amount) ?></div>
                        <small><?= count(array_filter($daily_sales, fn($s) => !in_array($s->payment_method, ['現金', 'カード']))) ?>種類</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- 左側: 現金管理 -->
            <div class="col-lg-6">
                <!-- 運転者別おつり在庫 -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-coins"></i> 運転者別おつり在庫</h5>
                        <button class="btn btn-sm btn-outline-primary no-print" data-bs-toggle="modal" data-bs-target="#updateStockModal">
                            <i class="fas fa-edit"></i> 在庫調整
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>運転者</th>
                                        <th class="text-end">在庫金額</th>
                                        <th>最終更新</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($driver_stocks as $stock): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($stock->driver_name ?? '不明') ?></td>
                                        <td class="text-end">
                                            <span class="<?= $stock->stock_amount >= 10000 ? 'stock-positive' : ($stock->stock_amount >= 5000 ? 'stock-warning' : 'stock-danger') ?>">
                                                ¥<?= number_format($stock->stock_amount) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small><?= $stock->updated_at ? date('m/d H:i', strtotime($stock->updated_at)) : '未設定' ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-info">
                                        <th>合計在庫</th>
                                        <th class="text-end">¥<?= number_format($total_change_stock) ?></th>
                                        <th></th>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 現金確認 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-calculator"></i> 現金確認</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="cashConfirmForm">
                            <input type="hidden" name="date" value="<?= $selected_date ?>">
                            <input type="hidden" name="calculated_amount" value="<?= $calculated_cash_in_office ?>">
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">計算上の事務所現金</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="text" class="form-control" value="<?= number_format($calculated_cash_in_office) ?>" readonly>
                                    </div>
                                    <small class="text-muted">現金売上 - おつり在庫</small>
                                </div>
                                <div class="col-6">
                                    <label class="form-label">実際の事務所現金</label>
                                    <div class="input-group">
                                        <span class="input-group-text">¥</span>
                                        <input type="number" name="cash_amount" class="form-control" 
                                               value="<?= $cash_confirmation->cash_amount ?? '' ?>"
                                               placeholder="実際の金額を入力" 
                                               onchange="calculateDifference()">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mb-3">
                                <div class="col-6">
                                    <label class="form-label">差額</label>
                                    <input type="number" name="difference" class="form-control" 
                                           id="difference" readonly
                                           value="<?= $cash_confirmation->difference ?? '' ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label">備考</label>
                                    <input type="text" name="notes" class="form-control" 
                                           value="<?= htmlspecialchars($cash_confirmation->notes ?? '') ?>"
                                           placeholder="差額がある場合の理由など">
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" name="confirm_cash" class="btn btn-success">
                                    <i class="fas fa-check"></i> 現金確認を記録
                                </button>
                            </div>
                            
                            <?php if ($cash_confirmation): ?>
                            <div class="mt-2 text-center">
                                <small class="text-muted">
                                    最終確認: <?= date('Y/m/d H:i', strtotime($cash_confirmation->confirmed_at)) ?> 
                                    (<?= htmlspecialchars($cash_confirmation->confirmed_by_name) ?>)
                                </small>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- 経理入力用仕訳メモ -->
                <div class="card accounting-memo">
                    <div class="card-header">
                        <h6><i class="fas fa-file-invoice"></i> 経理ソフト入力用仕訳メモ</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-12">
                                <strong>売上計上仕訳（<?= $selected_date ?>）</strong>
                                <pre class="mt-2 mb-0" style="font-size: 0.9rem;">現金     <?= str_pad(number_format($daily_total->cash_amount), 10, ' ', STR_PAD_LEFT) ?> / 売上     <?= str_pad(number_format($daily_total->total_amount), 10, ' ', STR_PAD_LEFT) ?>
普通預金 <?= str_pad(number_format($daily_total->card_amount), 10, ' ', STR_PAD_LEFT) ?> /
<?php if ($daily_total->other_amount > 0): ?>
売掛金   <?= str_pad(number_format($daily_total->other_amount), 10, ' ', STR_PAD_LEFT) ?> /
<?php endif; ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右側: 現金カウント -->
            <div class="col-lg-6">
                <!-- 運転手別現金回収カウント -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-calculator"></i> 運転手別現金カウント（<?= date('m/d', strtotime($selected_date)) ?>）</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($daily_drivers)): ?>
                            <div class="text-center text-muted py-3">
                                <i class="fas fa-info-circle"></i> 
                                <?= date('m/d', strtotime($selected_date)) ?>の現金売上がありません
                            </div>
                        <?php else: ?>
                            <div class="accordion" id="driverCashAccordion">
                                <?php foreach ($daily_drivers as $index => $driver): 
                                    $cash_count = getCashCount($pdo, $selected_date, $driver->id);
                                ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button" 
                                                data-bs-toggle="collapse" data-bs-target="#driver<?= $driver->id ?>"
                                                <?= $index === 0 ? 'aria-expanded="true"' : 'aria-expanded="false"' ?>>
                                            <div class="d-flex justify-content-between w-100 pe-3">
                                                <span>
                                                    <i class="fas fa-user"></i> <?= htmlspecialchars($driver->name) ?>
                                                </span>
                                                <span class="text-end">
                                                    <small class="text-muted me-2">回収予定: ¥<?= number_format($driver->cash_collected) ?></small>
                                                    <?php if ($cash_count): ?>
                                                        <?php if ($cash_count->difference == 0): ?>
                                                            <span class="badge bg-success">一致</span>
                                                        <?php elseif ($cash_count->difference > 0): ?>
                                                            <span class="badge bg-warning">+¥<?= number_format($cash_count->difference) ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">¥<?= number_format($cash_count->difference) ?></span>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">未カウント</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="driver<?= $driver->id ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" 
                                         data-bs-parent="#driverCashAccordion">
                                        <div class="accordion-body">
                                            <form method="POST" class="cash-count-form">
                                                <input type="hidden" name="confirmation_date" value="<?= $selected_date ?>">
                                                <input type="hidden" name="driver_id" value="<?= $driver->id ?>">
                                                <input type="hidden" name="calculated_amount" value="<?= $driver->cash_collected ?>">
                                                
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <div class="alert alert-info d-flex justify-content-between">
                                                            <span><i class="fas fa-calculator"></i> 回収予定金額</span>
                                                            <strong>¥<?= number_format($driver->cash_collected) ?></strong>
                                                        </div>
                                                    </div>
                                                </div>
                                                
                                                <!-- 紙幣カウント -->
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <h6 class="border-bottom pb-2"><i class="fas fa-money-bill"></i> 紙幣</h6>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">一万円札</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="bills_10000" min="0" max="50" 
                                                                   class="form-control bill-input" 
                                                                   value="<?= $cash_count->bills_10000 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="bills_10000_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">五千円札</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="bills_5000" min="0" max="100" 
                                                                   class="form-control bill-input" 
                                                                   value="<?= $cash_count->bills_5000 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="bills_5000_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">千円札</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="bills_1000" min="0" max="500" 
                                                                   class="form-control bill-input" 
                                                                   value="<?= $cash_count->bills_1000 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="bills_1000_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                </div>
                                                
                                                <!-- 硬貨カウント -->
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <h6 class="border-bottom pb-2"><i class="fas fa-coins"></i> 硬貨</h6>
                                                    </div>
                                                    <div class="col-3">
                                                        <label class="form-label">500円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coins_500" min="0" max="100" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coins_500 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coins_500_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-3">
                                                        <label class="form-label">100円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coins_100" min="0" max="200" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coins_100 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coins_100_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-3">
                                                        <label class="form-label">50円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coins_50" min="0" max="100" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coins_50 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coins_50_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-3">
                                                        <label class="form-label">10円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coins_10" min="0" max="100" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coins_10 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coins_10_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                </div>
                                                
                                                <!-- 小額硬貨 -->
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <label class="form-label">5円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coins_5" min="0" max="100" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coins_5 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coins_5_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">1円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coins_1" min="0" max="100" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coins_1 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coins_1_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                </div>
                                                
                                                <!-- おつり在庫金額 -->
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <label class="form-label">
                                                            <i class="fas fa-hand-holding-usd"></i> おつり在庫金額
                                                        </label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">¥</span>
                                                            <input type="number" name="change_amount" class="form-control" 
                                                                   value="<?= $cash_count->change_amount ?? 18000 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)"
                                                                   placeholder="運転手が持つおつり金額">
                                                        </div>
                                                        <small class="text-muted">運転手が翌日の業務用に持ち帰るおつり金額</small>
                                                    </div>
                                                </div>
                                                
                                                <!-- 計算結果 -->
                                                <div class="row mb-3">
                                                    <div class="col-4">
                                                        <label class="form-label">カウント合計</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">¥</span>
                                                            <input type="text" class="form-control bg-primary text-white" 
                                                                   id="total_cash_<?= $driver->id ?>" readonly>
                                                        </div>
                                                        <small class="text-muted">回収した現金の総額</small>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">事務所入金額</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">¥</span>
                                                            <input type="text" class="form-control bg-success text-white" 
                                                                   id="net_amount_<?= $driver->id ?>" readonly>
                                                        </div>
                                                        <small class="text-muted">総額 - おつり在庫</small>
                                                    </div>
                                                    <div class="col-4">
                                                        <label class="form-label">差額</label>
                                                        <input type="text" class="form-control" 
                                                               id="difference_<?= $driver->id ?>" readonly>
                                                        <small class="text-muted">入金額 - 回収予定</small>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <label class="form-label">備考</label>
                                                        <input type="text" name="memo" class="form-control" 
                                                               value="<?= htmlspecialchars($cash_count->memo ?? '') ?>"
                                                               placeholder="差額がある場合の理由など">
                                                    </div>
                                                </div>
                                                
                                                <div class="d-grid">
                                                    <button type="submit" name="cash_count" class="btn btn-success">
                                                        <i class="fas fa-save"></i> カウント結果を記録
                                                    </button>
                                                </div>
                                                
                                                <?php if ($cash_count): ?>
                                                <div class="mt-2 text-center">
                                                    <small class="text-muted">
                                                        最終カウント: <?= date('Y/m/d H:i', strtotime($cash_count->updated_at)) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>                        <small class="text-muted" id="coin_100_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-3">
                                                        <label class="form-label">50円</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coin_50" min="0" max="100" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= $cash_count->coin_50 ?? 0 ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">枚</span>
                                                        </div>
                                                        <small class="text-muted" id="coin_50_amount_<?= $driver->id ?>">¥0</small>
                                                    </div>
                                                    <div class="col-3">
                                                        <label class="form-label">10円以下</label>
                                                        <div class="input-group input-group-sm">
                                                            <input type="number" name="coin_small" min="0" max="500" 
                                                                   class="form-control coin-input" 
                                                                   value="<?= ($cash_count->coin_10 ?? 0) + ($cash_count->coin_5 ?? 0) + ($cash_count->coin_1 ?? 0) ?>"
                                                                   onchange="calculateTotal(<?= $driver->id ?>)">
                                                            <span class="input-group-text">円</span>
                                                        </div>
                                                        <small class="text-muted">10円・5円・1円の合計</small>
                                                    </div>
                                                </div>
                                                
                                                <!-- 計算結果 -->
                                                <div class="row mb-3">
                                                    <div class="col-6">
                                                        <label class="form-label">カウント合計</label>
                                                        <div class="input-group">
                                                            <span class="input-group-text">¥</span>
                                                            <input type="text" class="form-control bg-light" 
                                                                   id="counted_total_<?= $driver->id ?>" readonly>
                                                        </div>
                                                    </div>
                                                    <div class="col-6">
                                                        <label class="form-label">差額</label>
                                                        <input type="text" class="form-control" 
                                                               id="difference_<?= $driver->id ?>" readonly>
                                                    </div>
                                                </div>
                                                
                                                <div class="row mb-3">
                                                    <div class="col-12">
                                                        <label class="form-label">備考</label>
                                                        <input type="text" name="notes" class="form-control" 
                                                               value="<?= htmlspecialchars($cash_count->notes ?? '') ?>"
                                                               placeholder="差額がある場合の理由など">
                                                        <input type="hidden" name="coin_10" value="0">
                                                        <input type="hidden" name="coin_5" value="0">
                                                        <input type="hidden" name="coin_1" value="0">
                                                    </div>
                                                </div>
                                                
                                                <div class="d-grid">
                                                    <button type="submit" name="cash_count" class="btn btn-success">
                                                        <i class="fas fa-save"></i> カウント結果を記録
                                                    </button>
                                                </div>
                                                
                                                <?php if ($cash_count): ?>
                                                <div class="mt-2 text-center">
                                                    <small class="text-muted">
                                                        最終カウント: <?= date('Y/m/d H:i', strtotime($cash_count->counted_at)) ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 支払方法別詳細 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-list"></i> 支払方法別詳細</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>支払方法</th>
                                        <th class="text-end">件数</th>
                                        <th class="text-end">金額</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_sales as $sale): ?>
                                    <tr>
                                        <td>
                                            <?php if ($sale->payment_method === '現金'): ?>
                                                <i class="fas fa-money-bill-wave text-success"></i>
                                            <?php elseif ($sale->payment_method === 'カード'): ?>
                                                <i class="fas fa-credit-card text-primary"></i>
                                            <?php else: ?>
                                                <i class="fas fa-receipt text-warning"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($sale->payment_method) ?>
                                        </td>
                                        <td class="text-end"><?= $sale->trip_count ?>件</td>
                                        <td class="text-end amount-medium">¥<?= number_format($sale->total_amount) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 当月売上詳細リスト -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-table"></i> 当月売上詳細</h5>
                        <button class="btn btn-sm btn-outline-secondary no-print" onclick="toggleDetails()">
                            <i class="fas fa-eye" id="toggleIcon"></i> 
                            <span id="toggleText">詳細表示</span>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div id="monthlyDetails" style="display: none; max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>日時</th>
                                        <th>運転者</th>
                                        <th>乗降地</th>
                                        <th class="text-end">料金</th>
                                        <th>支払</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_details as $detail): ?>
                                    <tr>
                                        <td>
                                            <small>
                                                <?= date('m/d', strtotime($detail->ride_date)) ?><br>
                                                <?= date('H:i', strtotime($detail->ride_time)) ?>
                                            </small>
                                        </td>
                                        <td><?= htmlspecialchars($detail->driver_name ?? '不明') ?></td>
                                        <td>
                                            <small>
                                                <?= htmlspecialchars(mb_strimwidth($detail->pickup_location ?? '', 0, 15, '...')) ?><br>
                                                <i class="fas fa-arrow-down text-muted"></i><br>
                                                <?= htmlspecialchars(mb_strimwidth($detail->dropoff_location ?? '', 0, 15, '...')) ?>
                                            </small>
                                        </td>
                                        <td class="text-end">¥<?= number_format($detail->fare) ?></td>
                                        <td>
                                            <small>
                                                <?php if ($detail->payment_method === '現金'): ?>
                                                    <span class="badge bg-success">現金</span>
                                                <?php elseif ($detail->payment_method === 'カード'): ?>
                                                    <span class="badge bg-primary">カード</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning"><?= htmlspecialchars($detail->payment_method) ?></span>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>specialchars($sale->driver_name) ?></strong>
                                            </td>
                                            <td class="text-end">
                                                <span class="badge bg-secondary"><?= $sale->trip_count ?>件</span>
                                            </td>
                                            <td class="text-end">
                                                <strong class="amount-medium">¥<?= number_format($sale->total_amount) ?></strong>
                                            </td>
                                            <td class="text-end no-print">
                                                <span class="badge bg-success">¥<?= number_format($sale->cash_amount) ?></span>
                                            </td>
                                        </tr>
                                        <tr id="driverDetail_<?= $sale->id ?>" class="driver-details" style="display: none;">
                                            <td colspan="4">
                                                <div class="row text-center py-2 bg-light">
                                                    <div class="col-4">
                                                        <small class="text-muted">現金</small><br>
                                                        <span class="text-success">
                                                            <i class="fas fa-money-bill-wave"></i> 
                                                            ¥<?= number_format($sale->cash_amount) ?>
                                                            <small>(<?= $sale->cash_trips ?>件)</small>
                                                        </span>
                                                    </div>
                                                    <div class="col-4">
                                                        <small class="text-muted">カード</small><br>
                                                        <span class="text-primary">
                                                            <i class="fas fa-credit-card"></i> 
                                                            ¥<?= number_format($sale->card_amount) ?>
                                                            <small>(<?= $sale->card_trips ?>件)</small>
                                                        </span>
                                                    </div>
                                                    <div class="col-4">
                                                        <small class="text-muted">その他</small><br>
                                                        <span class="text-warning">
                                                            <i class="fas fa-receipt"></i> 
                                                            ¥<?= number_format($sale->other_amount) ?>
                                                            <small>(<?= $sale->other_trips ?>件)</small>
                                                        </span>
                                                    </div>
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

                <!-- 支払方法別詳細 -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><i class="fas fa-list"></i> 支払方法別詳細</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>支払方法</th>
                                        <th class="text-end">件数</th>
                                        <th class="text-end">金額</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($daily_sales as $sale): ?>
                                    <tr>
                                        <td>
                                            <?php if ($sale->payment_method === '現金'): ?>
                                                <i class="fas fa-money-bill-wave text-success"></i>
                                            <?php elseif ($sale->payment_method === 'カード'): ?>
                                                <i class="fas fa-credit-card text-primary"></i>
                                            <?php else: ?>
                                                <i class="fas fa-receipt text-warning"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($sale->payment_method) ?>
                                        </td>
                                        <td class="text-end"><?= $sale->trip_count ?>件</td>
                                        <td class="text-end amount-medium">¥<?= number_format($sale->total_amount) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 当月売上詳細リスト -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-table"></i> 当月売上詳細</h5>
                        <button class="btn btn-sm btn-outline-secondary no-print" onclick="toggleDetails()">
                            <i class="fas fa-eye" id="toggleIcon"></i> 
                            <span id="toggleText">詳細表示</span>
                        </button>
                    </div>
                    <div class="card-body p-0">
                        <div id="monthlyDetails" style="display: none; max-height: 400px; overflow-y: auto;">
                            <table class="table table-sm table-striped mb-0">
                                <thead class="table-dark">
                                    <tr>
                                        <th>日時</th>
                                        <th>運転者</th>
                                        <th>乗降地</th>
                                        <th class="text-end">料金</th>
                                        <th>支払</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_details as $detail): ?>
                                    <tr>
                                        <td>
                                            <small>
                                                <?= date('m/d', strtotime($detail->ride_date)) ?><br>
                                                <?= date('H:i', strtotime($detail->ride_time)) ?>
                                            </small>
                                        </td>
                                        <td><?= htmlspecialchars($detail->driver_name ?? '不明') ?></td>
                                        <td>
                                            <small>
                                                <?= htmlspecialchars(mb_strimwidth($detail->pickup_location ?? '', 0, 15, '...')) ?><br>
                                                <i class="fas fa-arrow-down text-muted"></i><br>
                                                <?= htmlspecialchars(mb_strimwidth($detail->dropoff_location ?? '', 0, 15, '...')) ?>
                                            </small>
                                        </td>
                                        <td class="text-end">¥<?= number_format($detail->fare) ?></td>
                                        <td>
                                            <small>
                                                <?php if ($detail->payment_method === '現金'): ?>
                                                    <span class="badge bg-success">現金</span>
                                                <?php elseif ($detail->payment_method === 'カード'): ?>
                                                    <span class="badge bg-primary">カード</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning"><?= htmlspecialchars($detail->payment_method) ?></span>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 運転手別月次売上サマリー -->
        <?php if (!empty($monthly_driver_sales)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5><i class="fas fa-trophy"></i> 運転手別月次売上ランキング（<?= $selected_month ?>）</h5>
                        <div class="no-print">
                            <button class="btn btn-sm btn-outline-success" onclick="exportDriverDataCSV()">
                                <i class="fas fa-file-csv"></i> CSV出力
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="printDriverReport()">
                                <i class="fas fa-print"></i> 印刷
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm" id="driverSalesTable">
                                <thead>
                                    <tr>
                                        <th><i class="fas fa-medal"></i> 順位</th>
                                        <th><i class="fas fa-user"></i> 運転手</th>
                                        <th class="text-end">稼働日数</th>
                                        <th class="text-end">総件数</th>
                                        <th class="text-end">現金回収</th>
                                        <th class="text-end">カード売上</th>
                                        <th class="text-end">その他</th>
                                        <th class="text-end">総売上</th>
                                        <th class="text-end">平均単価</th>
                                        <th class="text-end">日平均</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rank = 1;
                                    foreach ($monthly_driver_sales as $driverSale): 
                                        $daily_avg = $driverSale->working_days > 0 ? $driverSale->total_amount / $driverSale->working_days : 0;
                                    ?>
                                    <tr class="<?= $rank <= 3 ? 'table-warning' : '' ?>">
                                        <td>
                                            <?php if ($rank == 1): ?>
                                                <span class="badge bg-warning"><i class="fas fa-crown"></i> <?= $rank ?>位</span>
                                            <?php elseif ($rank == 2): ?>
                                                <span class="badge bg-secondary"><i class="fas fa-medal"></i> <?= $rank ?>位</span>
                                            <?php elseif ($rank == 3): ?>
                                                <span class="badge bg-warning"><i class="fas fa-medal"></i> <?= $rank ?>位</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark"><?= $rank ?>位</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($driverSale->driver_name) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-info"><?= $driverSale->working_days ?>日</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="badge bg-secondary"><?= $driverSale->trip_count ?>件</span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-success">
                                                <i class="fas fa-money-bill-wave"></i> 
                                                ¥<?= number_format($driverSale->cash_amount) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-primary">
                                                <i class="fas fa-credit-card"></i> 
                                                ¥<?= number_format($driverSale->card_amount) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <span class="text-warning">
                                                ¥<?= number_format($driverSale->other_amount) ?>
                                            </span>
                                        </td>
                                        <td class="text-end">
                                            <strong class="amount-medium text-primary">¥<?= number_format($driverSale->total_amount) ?></strong>
                                        </td>
                                        <td class="text-end">
                                            <small>¥<?= number_format($driverSale->avg_fare) ?></small>
                                        </td>
                                        <td class="text-end">
                                            <small>¥<?= number_format($daily_avg) ?></small>
                                        </td>
                                    </tr>
                                    <?php 
                                    $rank++;
                                    endforeach; 
                                    ?>
                                    <tr class="table-success">
                                        <th colspan="3">全体合計</th>
                                        <th class="text-end"><?= array_sum(array_column($monthly_driver_sales, 'trip_count')) ?>件</th>
                                        <th class="text-end">¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'cash_amount'))) ?></th>
                                        <th class="text-end">¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'card_amount'))) ?></th>
                                        <th class="text-end">¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'other_amount'))) ?></th>
                                        <th class="text-end"><strong>¥<?= number_format(array_sum(array_column($monthly_driver_sales, 'total_amount'))) ?></strong></th>
                                        <th class="text-end">-</th>
                                        <th class="text-end">-</th>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        </div>

        <!-- 月次サマリー -->
        <?php if (!empty($monthly_summary)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-bar"></i> 月次サマリー（<?= $selected_month ?>）</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>日付</th>
                                        <th class="text-end">件数</th>
                                        <th class="text-end">現金</th>
                                        <th class="text-end">カード</th>
                                        <th class="text-end">その他</th>
                                        <th class="text-end">合計</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $month_total_trips = 0;
                                    $month_total_cash = 0;
                                    $month_total_card = 0;
                                    $month_total_other = 0;
                                    $month_total_amount = 0;
                                    
                                    foreach ($monthly_summary as $day): 
                                        $month_total_trips += $day->trips;
                                        $month_total_cash += $day->cash;
                                        $month_total_card += $day->card;
                                        $month_total_other += $day->other;
                                        $month_total_amount += $day->total;
                                    ?>
                                    <tr>
                                        <td><?= date('m/d(D)', strtotime($day->date)) ?></td>
                                        <td class="text-end"><?= $day->trips ?></td>
                                        <td class="text-end">¥<?= number_format($day->cash) ?></td>
                                        <td class="text-end">¥<?= number_format($day->card) ?></td>
                                        <td class="text-end">¥<?= number_format($day->other) ?></td>
                                        <td class="text-end"><strong>¥<?= number_format($day->total) ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr class="table-warning">
                                        <th>月合計</th>
                                        <th class="text-end"><?= $month_total_trips ?>件</th>
                                        <th class="text-end">¥<?= number_format($month_total_cash) ?></th>
                                        <th class="text-end">¥<?= number_format($month_total_card) ?></th>
                                        <th class="text-end">¥<?= number_format($month_total_other) ?></th>
                                        <th class="text-end"><strong>¥<?= number_format($month_total_amount) ?></strong></th>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- おつり在庫調整モーダル -->
    <div class="modal fade" id="updateStockModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">おつり在庫調整</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                                <div class="form-group">
                                    <label>運転者</label>
                                    <select name="driver_id" class="form-select" required>
                                        <option value="">運転者を選択</option>
                                        <?php 
                                        // 全ての運転者を表示（現金売上がない場合も含む）
                                        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE is_active = TRUE AND is_driver = TRUE ORDER BY name");
                                        $stmt->execute();
                                        $all_drivers = $stmt->fetchAll(PDO::FETCH_OBJ);
                                        foreach ($all_drivers as $driver): 
                                        ?>
                                        <option value="<?= $driver->id ?>"><?= htmlspecialchars($driver->name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                        <div class="mb-3">
                            <label class="form-label">在庫金額</label>
                            <div class="input-group">
                                <span class="input-group-text">¥</span>
                                <input type="number" name="stock_amount" class="form-control" 
                                       min="0" max="50000" required>
                            </div>
                            <div class="form-text">推奨在庫: 10,000円〜20,000円</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">備考</label>
                            <textarea name="notes" class="form-control" rows="2" 
                                      placeholder="在庫調整の理由など"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
                        <button type="submit" name="update_change_stock" class="btn btn-primary">
                            <i class="fas fa-save"></i> 更新
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 現金差額の自動計算
        function calculateDifference() {
            const actualAmount = parseInt(document.querySelector('input[name="cash_amount"]').value) || 0;
            const calculatedAmount = <?= $calculated_cash_in_office ?>;
            const difference = actualAmount - calculatedAmount;
            document.getElementById('difference').value = difference;
            
            // 差額の色分け
            const diffField = document.getElementById('difference');
            diffField.className = 'form-control';
            if (difference > 0) {
                diffField.classList.add('bg-success', 'text-white');
            } else if (difference < 0) {
                diffField.classList.add('bg-danger', 'text-white');
            }
        }

        // 詳細リストの表示/非表示切り替え
        function toggleDetails() {
            const details = document.getElementById('monthlyDetails');
            const icon = document.getElementById('toggleIcon');
            const text = document.getElementById('toggleText');
            
            if (details.style.display === 'none') {
                details.style.display = 'block';
                icon.className = 'fas fa-eye-slash';
                text.textContent = '詳細非表示';
            } else {
                details.style.display = 'none';
                icon.className = 'fas fa-eye';
                text.textContent = '詳細表示';
            }
        }

        // ページ読み込み時の初期計算
        document.addEventListener('DOMContentLoaded', function() {
            calculateDifference();
            
            // 今日の日付にフォーカス
            const today = '<?= date('Y-m-d') ?>';
            const selectedDate = '<?= $selected_date ?>';
            if (today === selectedDate) {
                document.querySelector('input[name="cash_amount"]')?.focus();
            }
        });

        // 印刷機能
        function printPage() {
            window.print();
        }

        // 金額入力時のフォーマット
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value) {
                    this.value = parseInt(this.value);
                }
            });
        });

        // おつり在庫の色分け更新
        function updateStockColors() {
            document.querySelectorAll('[data-stock-amount]').forEach(element => {
                const amount = parseInt(element.getAttribute('data-stock-amount'));
                element.className = '';
                if (amount >= 10000) {
                    element.classList.add('stock-positive');
                } else if (amount >= 5000) {
                    element.classList.add('stock-warning');
                } else {
                    element.classList.add('stock-danger');
                }
            });
        }

        // 月次データの自動更新
        function autoRefresh() {
            // 5分毎にページを自動更新（営業時間内のみ）
            const now = new Date();
            const hour = now.getHours();
            if (hour >= 8 && hour <= 19) {
                setTimeout(() => {
                    location.reload();
                }, 300000); // 5分
            }
        }

        // 自動更新開始
        autoRefresh();

        // Enterキーでの送信防止（誤操作防止）
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && e.target.type !== 'submit') {
                e.preventDefault();
            }
        });

        // 数値入力の妥当性チェック
        document.querySelectorAll('input[type="number"]').forEach(input => {
            input.addEventListener('input', function() {
                if (this.value < 0) {
                    this.value = 0;
                }
                if (this.name === 'stock_amount' && this.value > 50000) {
                    this.value = 50000;
                    alert('おつり在庫は50,000円以下で設定してください。');
                }
            });
        });
    </script>

    <!-- 追加のCSS（印刷用） -->
    <style>
        @media print {
            .card {
                border: 1px solid #000 !important;
                page-break-inside: avoid;
            }
            .card-header {
                background: #f8f9fa !important;
                border-bottom: 1px solid #000 !important;
            }
            .amount-large, .amount-medium {
                color: #000 !important;
            }
            .table th, .table td {
                border: 1px solid #000 !important;
            }
        }
        
        /* 追加のレスポンシブ調整 */
        @media (max-width: 768px) {
            .amount-large {
                font-size: 1.5rem;
            }
            .card-body {
                padding: 1rem 0.5rem;
            }
            .table-responsive {
                font-size: 0.85rem;
            }
        }
        
        /* ダークモード対応 */
        @media (prefers-color-scheme: dark) {
            .accounting-memo {
                background: #2d3748;
                border-left: 4px solid #4299e1;
                color: #e2e8f0;
            }
        }
        
        /* アニメーション効果 */
        .card {
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        /* フォーカス時のハイライト */
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }
        
        /* 在庫ステータスアイコン */
        .stock-positive::before {
            content: '✓ ';
            color: #28a745;
        }
        
        .stock-warning::before {
            content: '⚠ ';
            color: #ffc107;
        }
        
        .stock-danger::before {
            content: '⚠ ';
            color: #dc3545;
        }
    </style>

    <!-- 追加のJavaScript機能 -->
    <script>
        // 経理仕訳のコピー機能
        function copyAccounting() {
            const accountingText = document.querySelector('.accounting-memo pre').textContent;
            navigator.clipboard.writeText(accountingText).then(() => {
                alert('仕訳内容をクリップボードにコピーしました');
            });
        }
        
        // 売上データのCSVエクスポート機能
        function exportToCSV() {
            const data = [
                ['日付', '件数', '現金', 'カード', 'その他', '合計']
            ];
            
            <?php foreach ($monthly_summary as $day): ?>
            data.push([
                '<?= $day->date ?>',
                '<?= $day->trips ?>',
                '<?= $day->cash ?>',
                '<?= $day->card ?>',
                '<?= $day->other ?>',
                '<?= $day->total ?>'
            ]);
            <?php endforeach; ?>
            
            const csvContent = data.map(row => row.join(',')).join('\n');
            const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = '売上データ_<?= $selected_month ?>.csv';
            link.click();
        }
        
        // キーボードショートカット
        document.addEventListener('keydown', function(e) {
            // Ctrl+P で印刷
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printPage();
            }
            
            // Ctrl+E でCSVエクスポート
            if (e.ctrlKey && e.key === 'e') {
                e.preventDefault();
                exportToCSV();
            }
        });
        
        // リアルタイム時刻表示
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('ja-JP');
            const timeElement = document.getElementById('currentTime');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        setInterval(updateTime, 1000);
        
        // 通知機能（在庫不足の警告）
        function checkStockAlerts() {
            <?php foreach ($driver_stocks as $stock): ?>
            <?php if ($stock->stock_amount < 5000): ?>
            console.warn('在庫不足警告: <?= $stock->driver_name ?>さんのおつり在庫が不足しています（¥<?= number_format($stock->stock_amount) ?>）');
            <?php endif; ?>
            <?php endforeach; ?>
        }
        
        // ページ読み込み時に在庫チェック
        document.addEventListener('DOMContentLoaded', checkStockAlerts);
    </script>
</body>
</html>
