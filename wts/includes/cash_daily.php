<?php
// 日次データ取得
$daily_sales = getDailySales($pdo, $selected_date);
$daily_total = getDailyTotal($pdo, $selected_date);
$cash_confirmation = getCashConfirmation($pdo, $selected_date);
?>

<!-- 日付選択 -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="fas fa-calendar-day me-2"></i>対象日選択</h6>
                <form method="GET" class="d-flex">
                    <input type="hidden" name="tab" value="daily">
                    <input type="date" name="date" value="<?= htmlspecialchars($selected_date) ?>" class="form-control me-2">
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
                <h6 class="card-title"><i class="fas fa-info-circle me-2"></i>日次管理について</h6>
                <small class="text-muted">
                    選択した日の売上確認と現金の実地棚卸しを行います。<br>
                    現金売上と実際の現金額に差額がある場合は理由を記録してください。
                </small>
            </div>
        </div>
    </div>
</div>

<!-- 日次売上サマリー -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stats-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($daily_total['total_amount']) ?></div>
                <div>総売上</div>
                <small><?= number_format($daily_total['total_rides'] ?? 0) ?>回</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card cash-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($daily_total['cash_amount']) ?></div>
                <div>現金売上</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card card-card">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($daily_total['card_amount']) ?></div>
                <div>カード売上</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-secondary text-white">
            <div class="card-body text-center">
                <div class="amount-display"><?= formatAmount($daily_total['other_amount']) ?></div>
                <div>その他</div>
            </div>
        </div>
    </div>
</div>

<!-- 現金確認セクション -->
<div class="row mb-4">
    <div class="col-12">
        <?php if ($cash_confirmation): ?>
            <!-- 確認済み -->
            <div class="card confirmed-section">
                <div class="card-body">
                    <h5 class="card-title text-primary">
                        <i class="fas fa-check-circle me-2"></i>現金確認済み
                        <small class="text-muted">(<?= date('Y/m/d', strtotime($selected_date)) ?>)</small>
                    </h5>
                    <div class="row">
                        <div class="col-md-3">
                            <strong>実現金額:</strong><br>
                            <span class="fs-5"><?= formatAmount($cash_confirmation['confirmed_amount']) ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>計算売上:</strong><br>
                            <span class="fs-5"><?= formatAmount($daily_total['cash_amount'] ?? 0) ?></span>
                        </div>
                        <div class="col-md-3">
                            <strong>差額:</strong><br>
                            <span class="fs-5 <?= getDifferenceClass($cash_confirmation['difference']) ?>">
                                <?= $cash_confirmation['difference'] > 0 ? '+' : '' ?><?= formatAmount($cash_confirmation['difference']) ?>
                            </span>
                        </div>
                        <div class="col-md-3">
                            <strong>確認者:</strong><br>
                            <?= htmlspecialchars($cash_confirmation['confirmed_by_name']) ?>
                        </div>
                    </div>
                    <?php if ($cash_confirmation['memo']): ?>
                        <div class="mt-3">
                            <strong>メモ:</strong><br>
                            <?= nl2br(htmlspecialchars($cash_confirmation['memo'])) ?>
                        </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-primary" onclick="editConfirmation()">
                            <i class="fas fa-edit me-1"></i>修正
                        </button>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- 未確認 -->
            <div class="card confirmation-section">
                <div class="card-body">
                    <h5 class="card-title text-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>現金確認が必要です
                        <small class="text-muted">(<?= date('Y/m/d', strtotime($selected_date)) ?>)</small>
                    </h5>
                    <form method="POST" id="cashConfirmForm">
                        <input type="hidden" name="action" value="confirm_cash">
                        <input type="hidden" name="target_date" value="<?= htmlspecialchars($selected_date) ?>">
                        
                        <div class="row">
                            <div class="col-md-4">
                                <label class="form-label">計算上の現金売上</label>
                                <div class="fs-4 text-primary"><?= formatAmount($daily_total['cash_amount'] ?? 0) ?></div>
                            </div>
                            <div class="col-md-4">
                                <label for="confirmed_amount" class="form-label">実際の現金額 *</label>
                                <input type="number" class="form-control" id="confirmed_amount" name="confirmed_amount" 
                                       value="<?= $daily_total['cash_amount'] ?? 0 ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">差額</label>
                                <div class="fs-4" id="difference_display">¥0</div>
                                <input type="hidden" id="difference" name="difference" value="0">
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <label for="memo" class="form-label">メモ（差額がある場合の理由等）</label>
                                <textarea class="form-control" id="memo" name="memo" rows="2" 
                                          placeholder="差額の理由や特記事項があれば記入してください"></textarea>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-warning">
                                <i class="fas fa-check me-1"></i>現金確認を記録
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 詳細売上データ -->
<?php if ($daily_sales): ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-list me-2"></i>支払方法別詳細 (<?= date('Y/m/d', strtotime($selected_date)) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table summary-table">
                        <thead>
                            <tr>
                                <th>支払方法</th>
                                <th class="text-end">乗車回数</th>
                                <th class="text-end">合計金額</th>
                                <th class="text-end">平均単価</th>
                                <th class="text-end">構成比</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($daily_sales as $sale): ?>
                                <tr>
                                    <td>
                                        <i class="fas <?= getPaymentIcon($sale['payment_method']) ?> me-2"></i>
                                        <?= htmlspecialchars($sale['payment_method']) ?>
                                    </td>
                                    <td class="text-end"><?= number_format($sale['count']) ?>回</td>
                                    <td class="text-end"><?= formatAmount($sale['total_amount']) ?></td>
                                    <td class="text-end"><?= formatAmount($sale['avg_amount']) ?></td>
                                    <td class="text-end">
                                        <?= round(($sale['total_amount'] / ($daily_total['total_amount'] ?: 1)) * 100, 1) ?>%
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
<?php else: ?>
<div class="row mb-4">
    <div class="col-12">
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <?= date('Y/m/d', strtotime($selected_date)) ?> の売上データがありません。
        </div>
    </div>
</div>
<?php endif; ?>
