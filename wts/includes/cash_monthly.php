<?php
// 月次データ取得
$monthly_summary = getMonthlySummary($pdo, $selected_month);
$monthly_comparison = getMonthlyComparison($pdo, 6);
?>

<!-- 月選択 -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-calendar-alt me-2"></i>対象月選択</h6>
                <form method="GET" class="d-flex">
                    <input type="hidden" name="tab" value="monthly">
                    <input type="hidden" name="date" value="<?= htmlspecialchars($selected_date) ?>">
                    <input type="month" name="month" value="<?= htmlspecialchars($selected_month) ?>" class="form-control me-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>表示
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-info-circle me-2"></i>月次管理について</h6>
                <small class="text-muted">
                    月間の売上推移と日別詳細を確認できます。<br>
                    PDFレポートとしてダウンロードも可能です。
                </small>
            </div>
        </div>
    </div>
</div>

<!-- 月次サマリー -->
<?php if ($monthly_summary): 
    $monthly_total_rides = array_sum(array_column($monthly_summary, 'rides'));
    $monthly_total_amount = array_sum(array_column($monthly_summary, 'total'));
    $monthly_cash_amount = array_sum(array_column($monthly_summary, 'cash'));
    $monthly_card_amount = array_sum(array_column($monthly_summary, 'card'));
?>
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stats-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($monthly_total_amount) ?></div>
                <div>月間総売上</div>
                <small><?= number_format($monthly_total_rides) ?>回</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card cash-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($monthly_cash_amount) ?></div>
                <div>現金売上</div>
                <small><?= round(($monthly_cash_amount / $monthly_total_amount) * 100, 1) ?>%</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($monthly_card_amount) ?></div>
                <div>カード売上</div>
                <small><?= round(($monthly_card_amount / $monthly_total_amount) * 100, 1) ?>%</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($monthly_total_amount / count($monthly_summary)) ?></div>
                <div>日平均売上</div>
                <small><?= round($monthly_total_rides / count($monthly_summary), 1) ?>回/日</small>
            </div>
        </div>
    </div>
</div>

<!-- 月次詳細テーブル -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-chart-line me-2"></i>月次詳細 (<?= date('Y年m月', strtotime($selected_month . '-01')) ?>)</h5>
                <div>
                    <button type="button" class="btn btn-outline-success btn-sm me-2" onclick="exportMonthlyExcel()">
                        <i class="fas fa-file-excel me-1"></i>Excel出力
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportMonthlyPDF()">
                        <i class="fas fa-file-pdf me-1"></i>PDF出力
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped summary-table">
                        <thead>
                            <tr>
                                <th>日付</th>
                                <th class="text-end">乗車回数</th>
                                <th class="text-end">総売上</th>
                                <th class="text-end">現金</th>
                                <th class="text-end">カード</th>
                                <th class="text-end">現金比率</th>
                                <th class="text-center">確認状況</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthly_summary as $day): 
                                $cash_confirmation = getCashConfirmation($pdo, $day['date']);
                            ?>
                                <tr>
                                    <td>
                                        <a href="?tab=daily&date=<?= $day['date'] ?>" class="text-decoration-none">
                                            <?= date('m/d(D)', strtotime($day['date'])) ?>
                                        </a>
                                    </td>
                                    <td class="text-end"><?= number_format($day['rides']) ?>回</td>
                                    <td class="text-end"><?= formatAmount($day['total']) ?></td>
                                    <td class="text-end"><?= formatAmount($day['cash']) ?></td>
                                    <td class="text-end"><?= formatAmount($day['card']) ?></td>
                                    <td class="text-end">
                                        <?= $day['total'] > 0 ? round(($day['cash'] / $day['total']) * 100, 1) : 0 ?>%
                                    </td>
                                    <td class="text-center">
                                        <?php if ($cash_confirmation): ?>
                                            <span class="badge bg-success">
                                                <i class="fas fa-check"></i> 確認済み
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">
                                                <i class="fas fa-exclamation-triangle"></i> 未確認
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-dark">
                            <tr>
                                <th>月計</th>
                                <th class="text-end"><?= number_format($monthly_total_rides) ?>回</th>
                                <th class="text-end"><?= formatAmount($monthly_total_amount) ?></th>
                                <th class="text-end"><?= formatAmount($monthly_cash_amount) ?></th>
                                <th class="text-end"><?= formatAmount($monthly_card_amount) ?></th>
                                <th class="text-end">
                                    <?= $monthly_total_amount > 0 ? round(($monthly_cash_amount / $monthly_total_amount) * 100, 1) : 0 ?>%
                                </th>
                                <th class="text-center">-</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 月次比較 -->
<?php if ($monthly_comparison): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-chart-bar me-2"></i>月次推移（過去6ヶ月）</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>年月</th>
                                <th class="text-end">乗車回数</th>
                                <th class="text-end">総売上</th>
                                <th class="text-end">現金売上</th>
                                <th class="text-end">カード売上</th>
                                <th class="text-end">前月比</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $prev_total = null;
                            foreach ($monthly_comparison as $month): 
                                $change_rate = null;
                                if ($prev_total !== null && $prev_total > 0) {
                                    $change_rate = round((($month['total'] - $prev_total) / $prev_total) * 100, 1);
                                }
                                $prev_total = $month['total'];
                            ?>
                                <tr>
                                    <td><?= date('Y年m月', strtotime($month['month'] . '-01')) ?></td>
                                    <td class="text-end"><?= number_format($month['rides']) ?>回</td>
                                    <td class="text-end"><?= formatAmount($month['total']) ?></td>
                                    <td class="text-end"><?= formatAmount($month['cash']) ?></td>
                                    <td class="text-end"><?= formatAmount($month['card']) ?></td>
                                    <td class="text-end">
                                        <?php if ($change_rate !== null): ?>
                                            <span class="<?= $change_rate >= 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= $change_rate >= 0 ? '+' : '' ?><?= $change_rate ?>%
                                            </span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
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
<?php endif; ?>

<?php else: ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <?= date('Y年m月', strtotime($selected_month . '-01')) ?> の売上データがありません。
        </div>
    </div>
</div>
<?php endif; ?>
