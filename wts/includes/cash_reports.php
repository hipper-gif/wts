<!-- レポート期間選択 -->
<div class="row mb-4">
    <div class="col-md-8">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-chart-line me-2"></i>レポート生成</h6>
                <form method="GET" id="reportForm">
                    <input type="hidden" name="tab" value="reports">
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">開始日</label>
                            <input type="date" name="start_date" 
                                   value="<?= $_GET['start_date'] ?? date('Y-m-01') ?>" 
                                   class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">終了日</label>
                            <input type="date" name="end_date" 
                                   value="<?= $_GET['end_date'] ?? date('Y-m-d') ?>" 
                                   class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search me-1"></i>レポート生成
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-info-circle me-2"></i>レポート機能</h6>
                <small class="text-muted">
                    指定期間の詳細な売上分析と<br>
                    各種フォーマットでの出力が可能です。
                </small>
            </div>
        </div>
    </div>
</div>

<?php
// レポート期間の処理
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

if (isset($_GET['start_date']) && isset($_GET['end_date'])) {
    $period_stats = getPeriodStatistics($pdo, $start_date, $end_date);
    $period_daily = getMonthlySummary($pdo, date('Y-m', strtotime($start_date)));
?>

<!-- 期間統計サマリー -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card stats-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= number_format($period_stats['total_rides']) ?></div>
                <div>総乗車回数</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card cash-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($period_stats['total_amount']) ?></div>
                <div>総売上</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card card-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($period_stats['avg_amount']) ?></div>
                <div>平均単価</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($period_stats['cash_amount']) ?></div>
                <div>現金売上</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($period_stats['card_amount']) ?></div>
                <div>カード売上</div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-white">
            <div class="card-body text-center">
                <div class="amount-display">
                    <?= $period_stats['total_amount'] > 0 ? round(($period_stats['cash_amount'] / $period_stats['total_amount']) * 100, 1) : 0 ?>%
                </div>
                <div>現金比率</div>
            </div>
        </div>
    </div>
</div>

<!-- 出力ボタン -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-download me-2"></i>レポート出力</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-danger" onclick="exportDetailedPDF()">
                                <i class="fas fa-file-pdf me-2"></i>詳細PDFレポート
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-success" onclick="exportDetailedExcel()">
                                <i class="fas fa-file-excel me-2"></i>Excelデータ
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-primary" onclick="exportSummaryReport()">
                                <i class="fas fa-chart-pie me-2"></i>サマリーレポート
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="d-grid">
                            <button type="button" class="btn btn-outline-info" onclick="exportCashReport()">
                                <i class="fas fa-money-bill me-2"></i>現金管理レポート
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 分析チャート -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-chart-pie me-2"></i>支払方法別構成比</h6>
            </div>
            <div class="card-body">
                <canvas id="paymentChart" width="400" height="300"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-chart-line me-2"></i>日別売上推移</h6>
            </div>
            <div class="card-body">
                <canvas id="dailyChart" width="400" height="300"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- 詳細統計 -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-calculator me-2"></i>詳細統計情報</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th>期間</th>
                                <td><?= date('Y/m/d', strtotime($start_date)) ?> ～ <?= date('Y/m/d', strtotime($end_date)) ?></td>
                            </tr>
                            <tr>
                                <th>営業日数</th>
                                <td><?= count($period_daily) ?>日</td>
                            </tr>
                            <tr>
                                <th>日平均売上</th>
                                <td><?= formatAmount($period_stats['total_amount'] / max(count($period_daily), 1)) ?></td>
                            </tr>
                            <tr>
                                <th>日平均乗車回数</th>
                                <td><?= round($period_stats['total_rides'] / max(count($period_daily), 1), 1) ?>回</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <th>最高単価</th>
                                <td><?= formatAmount($period_stats['max_amount']) ?></td>
                            </tr>
                            <tr>
                                <th>最低単価</th>
                                <td><?= formatAmount($period_stats['min_amount']) ?></td>
                            </tr>
                            <tr>
                                <th>現金売上比率</th>
                                <td>
                                    <?= $period_stats['total_amount'] > 0 ? round(($period_stats['cash_amount'] / $period_stats['total_amount']) * 100, 1) : 0 ?>%
                                </td>
                            </tr>
                            <tr>
                                <th>カード売上比率</th>
                                <td>
                                    <?= $period_stats['total_amount'] > 0 ? round(($period_stats['card_amount'] / $period_stats['total_amount']) * 100, 1) : 0 ?>%
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Chart.js でのグラフ表示
document.addEventListener('DOMContentLoaded', function() {
    // 支払方法別構成比グラフ
    const paymentCtx = document.getElementById('paymentChart').getContext('2d');
    new Chart(paymentCtx, {
        type: 'pie',
        data: {
            labels: ['現金', 'カード'],
            datasets: [{
                data: [<?= $period_stats['cash_amount'] ?>, <?= $period_stats['card_amount'] ?>],
                backgroundColor: ['#f093fb', '#4facfe']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });

    // 日別売上推移グラフ
    const dailyCtx = document.getElementById('dailyChart').getContext('2d');
    new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: [<?php echo "'" . implode("','", array_column($period_daily, 'date')) . "'"; ?>],
            datasets: [{
                label: '売上',
                data: [<?php echo implode(',', array_column($period_daily, 'total')); ?>],
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
});
</script>

<?php } else { ?>
<!-- 期間未選択時の案内 -->
<div class="row">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            上記のフォームから期間を選択してレポートを生成してください。
        </div>
    </div>
</div>
<?php } ?>
