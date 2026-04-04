<?php
// =================================================================
// 顧客マスタ管理ページ
//
// ファイル: /Smiley/taxi/wts/calendar/customer_management.php
// 機能: 顧客（利用者）マスタの一覧・検索・登録・編集・削除
// 基盤: 福祉輸送管理システム v3.1 統一ヘッダー対応
// 作成日: 2026年3月11日
// =================================================================

require_once '../includes/session_check.php';

// 基盤システム読み込み
require_once '../functions.php';
require_once '../includes/unified-header.php';

// データベース接続
$pdo = getDBConnection();

// $user_id, $user_name, $user_role は session_check.php で設定済み

// CSRFトークン生成
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ページ設定
$page_config = getPageConfiguration('customer_management');

$page_options = [
    'description' => $page_config['description'],
    'additional_css' => [],
    'additional_js' => [],
    'breadcrumb' => [
        ['text' => 'ダッシュボード', 'url' => '../dashboard.php'],
        ['text' => '予約カレンダー', 'url' => 'index.php'],
        ['text' => '顧客マスタ管理', 'url' => 'customer_management.php']
    ]
];

$page_data = renderCompletePage(
    $page_config['title'],
    $user_name,
    $user_role,
    'customer_management',
    $page_config['icon'],
    $page_config['title'],
    $page_config['subtitle'],
    $page_config['category'],
    $page_options
);

// HTMLヘッダー出力
echo $page_data['html_head'];
echo $page_data['system_header'];
echo $page_data['page_header'];
?>

<!-- メインコンテンツ開始 -->
<main class="main-content" id="main-content" tabindex="-1">
<div class="container-fluid py-4">

<!-- ===== 検索・フィルタバー ===== -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label fw-bold small mb-1">検索</label>
                <input type="text" id="searchText" class="form-control" placeholder="名前・フリガナ・電話番号">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label fw-bold small mb-1">介護度</label>
                <select id="filterCareLevel" class="form-select">
                    <option value="">すべて</option>
                    <option value="要支援1">要支援1</option>
                    <option value="要支援2">要支援2</option>
                    <option value="要介護1">要介護1</option>
                    <option value="要介護2">要介護2</option>
                    <option value="要介護3">要介護3</option>
                    <option value="要介護4">要介護4</option>
                    <option value="要介護5">要介護5</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label fw-bold small mb-1">移動形態</label>
                <select id="filterMobility" class="form-select">
                    <option value="">すべて</option>
                    <option value="independent">自立</option>
                    <option value="wheelchair">車椅子</option>
                    <option value="stretcher">ストレッチャー</option>
                    <option value="walker">歩行器</option>
                </select>
            </div>
            <div class="col-6 col-md-2 col-lg-2">
                <label class="form-label fw-bold small mb-1">状態</label>
                <select id="filterActive" class="form-select">
                    <option value="1">有効のみ</option>
                    <option value="">すべて</option>
                </select>
            </div>
            <div class="col-6 col-md-12 col-lg-3 text-end">
                <div class="d-flex gap-2 justify-content-end">
                    <button class="btn btn-outline-secondary" id="btnImport" title="既存予約から顧客データ取り込み">
                        <i class="fas fa-file-import me-1"></i><span class="d-none d-md-inline">取り込み</span>
                    </button>
                    <button class="btn btn-primary" id="btnAddNew">
                        <i class="fas fa-plus me-1"></i>新規登録
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== 顧客一覧テーブル ===== -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-list me-2"></i>顧客一覧 <span id="totalCount" class="badge bg-secondary ms-1">0</span></h6>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 small">表示件数:</label>
            <select id="pageSize" class="form-select form-select-sm" style="width:80px;">
                <option value="20">20</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="customerTable">
                <thead class="table-light">
                    <tr>
                        <th class="sortable" data-sort="name" role="button" style="cursor:pointer;">名前 <i class="fas fa-sort text-muted ms-1"></i></th>
                        <th class="sortable d-none d-md-table-cell" data-sort="name_kana" role="button" style="cursor:pointer;">フリガナ <i class="fas fa-sort text-muted ms-1"></i></th>
                        <th class="sortable" data-sort="phone" role="button" style="cursor:pointer;">電話番号 <i class="fas fa-sort text-muted ms-1"></i></th>
                        <th class="d-none d-lg-table-cell">住所</th>
                        <th class="sortable" data-sort="care_level" role="button" style="cursor:pointer;">介護度 <i class="fas fa-sort text-muted ms-1"></i></th>
                        <th class="sortable d-none d-md-table-cell" data-sort="mobility_type" role="button" style="cursor:pointer;">移動形態 <i class="fas fa-sort text-muted ms-1"></i></th>
                        <th class="text-center">予約数</th>
                        <th class="text-center" style="width:80px;">操作</th>
                    </tr>
                </thead>
                <tbody id="customerTableBody">
                    <tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>読み込み中...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white">
        <nav aria-label="顧客一覧ページネーション">
            <ul class="pagination pagination-sm justify-content-center mb-0" id="pagination"></ul>
        </nav>
    </div>
</div>

</div><!-- /.container-fluid -->
</main>

<!-- ===== 顧客編集モーダル ===== -->
<div class="modal fade" id="customerModal" tabindex="-1" aria-labelledby="customerModalLabel" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-scrollable">
<div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="customerModalLabel"><i class="fas fa-user-edit me-2"></i>顧客情報</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
    </div>
    <div class="modal-body">
        <!-- 編集/詳細タブ -->
        <ul class="nav nav-tabs mb-3" id="customerTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-edit" data-bs-toggle="tab" data-bs-target="#pane-edit" type="button" role="tab">
                    <i class="fas fa-edit me-1"></i>基本情報
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-history" data-bs-toggle="tab" data-bs-target="#pane-history" type="button" role="tab">
                    <i class="fas fa-history me-1"></i>予約履歴
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-stats" data-bs-toggle="tab" data-bs-target="#pane-stats" type="button" role="tab">
                    <i class="fas fa-chart-bar me-1"></i>統計
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- ===== 基本情報タブ ===== -->
            <div class="tab-pane fade show active" id="pane-edit" role="tabpanel">
                <form id="customerForm">
                    <input type="hidden" id="customerId" name="id" value="">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <!-- 基本情報 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2"><i class="fas fa-id-card me-1"></i> 基本情報</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">名前 <span class="text-danger fw-bold small">（必須）</span></label>
                                    <input type="text" class="form-control" id="customerName" name="name" required>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">フリガナ</label>
                                    <input type="text" class="form-control" id="customerNameKana" name="name_kana">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">電話番号</label>
                                    <input type="tel" class="form-control" id="customerPhone" name="phone" inputmode="tel">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">副電話番号</label>
                                    <input type="tel" class="form-control" id="customerPhoneSecondary" name="phone_secondary" inputmode="tel">
                                </div>
                                <div class="col-12 col-md-4">
                                    <label class="form-label">メール</label>
                                    <input type="email" class="form-control" id="customerEmail" name="email">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 住所 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2"><i class="fas fa-map-marker-alt me-1"></i> 住所</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label class="form-label">郵便番号</label>
                                    <input type="text" class="form-control" id="customerPostalCode" name="postal_code" placeholder="000-0000" inputmode="numeric">
                                </div>
                                <div class="col-12 col-md-8">
                                    <label class="form-label">住所</label>
                                    <input type="text" class="form-control" id="customerAddress" name="address">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">建物名・部屋番号</label>
                                    <input type="text" class="form-control" id="customerAddressDetail" name="address_detail">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 介護情報 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2"><i class="fas fa-heartbeat me-1"></i> 介護情報</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">介護度</label>
                                    <select class="form-select" id="customerCareLevel" name="care_level">
                                        <option value="">未設定</option>
                                        <option value="要支援1">要支援1</option>
                                        <option value="要支援2">要支援2</option>
                                        <option value="要介護1">要介護1</option>
                                        <option value="要介護2">要介護2</option>
                                        <option value="要介護3">要介護3</option>
                                        <option value="要介護4">要介護4</option>
                                        <option value="要介護5">要介護5</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">障害区分</label>
                                    <input type="text" class="form-control" id="customerDisabilityType" name="disability_type">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">移動形態</label>
                                    <select class="form-select" id="customerMobilityType" name="mobility_type">
                                        <option value="independent">自立</option>
                                        <option value="wheelchair">車椅子</option>
                                        <option value="stretcher">ストレッチャー</option>
                                        <option value="walker">歩行器</option>
                                    </select>
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">車椅子タイプ</label>
                                    <input type="text" class="form-control" id="customerWheelchairType" name="wheelchair_type">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 送迎デフォルト -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2"><i class="fas fa-route me-1"></i> 送迎デフォルト</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">デフォルト乗車地</label>
                                    <input type="text" class="form-control" id="customerDefaultPickup" name="default_pickup_location">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">デフォルト降車地</label>
                                    <input type="text" class="form-control" id="customerDefaultDropoff" name="default_dropoff_location">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 緊急連絡先 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2"><i class="fas fa-phone-alt me-1"></i> 緊急連絡先</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-12 col-md-6">
                                    <label class="form-label">連絡先名</label>
                                    <input type="text" class="form-control" id="customerEmergencyName" name="emergency_contact_name">
                                </div>
                                <div class="col-12 col-md-6">
                                    <label class="form-label">連絡先電話番号</label>
                                    <input type="tel" class="form-control" id="customerEmergencyPhone" name="emergency_contact_phone" inputmode="tel">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- メモ -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2"><i class="fas fa-sticky-note me-1"></i> メモ</div>
                        <div class="card-body">
                            <textarea class="form-control" id="customerNotes" name="notes" rows="3" placeholder="アレルギー・注意点等"></textarea>
                        </div>
                    </div>

                    <!-- よく行く場所 -->
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2 d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-map-signs me-1"></i> よく行く場所</span>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddDestination">
                                <i class="fas fa-plus me-1"></i>追加
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm mb-0" id="destinationsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>場所名</th>
                                            <th>住所</th>
                                            <th style="width:120px;">種別</th>
                                            <th>備考</th>
                                            <th style="width:50px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="destinationsBody">
                                        <tr id="noDestRow"><td colspan="5" class="text-center text-muted py-2">登録なし</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ===== 予約履歴タブ ===== -->
            <div class="tab-pane fade" id="pane-history" role="tabpanel">
                <div id="historyLoading" class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i>読み込み中...
                </div>
                <div id="historyContent" style="display:none;">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>日付</th>
                                    <th>時間</th>
                                    <th>乗車地</th>
                                    <th>降車地</th>
                                    <th>種別</th>
                                    <th>状態</th>
                                    <th class="text-end">料金</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody"></tbody>
                        </table>
                    </div>
                    <nav class="mt-2">
                        <ul class="pagination pagination-sm justify-content-center mb-0" id="historyPagination"></ul>
                    </nav>
                </div>
                <div id="historyEmpty" class="text-center py-4 text-muted" style="display:none;">
                    <i class="fas fa-inbox me-2"></i>予約履歴がありません
                </div>
            </div>

            <!-- ===== 統計タブ ===== -->
            <div class="tab-pane fade" id="pane-stats" role="tabpanel">
                <div id="statsLoading" class="text-center py-4 text-muted">
                    <i class="fas fa-spinner fa-spin me-2"></i>読み込み中...
                </div>
                <div id="statsContent" style="display:none;">
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-3">
                            <div class="card text-center border-primary">
                                <div class="card-body py-2">
                                    <div class="text-muted small">総予約数</div>
                                    <div class="fs-4 fw-bold text-primary" id="statTotalRides">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-center border-success">
                                <div class="card-body py-2">
                                    <div class="text-muted small">完了</div>
                                    <div class="fs-4 fw-bold text-success" id="statCompleted">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-center border-danger">
                                <div class="card-body py-2">
                                    <div class="text-muted small">キャンセル</div>
                                    <div class="fs-4 fw-bold text-danger" id="statCancelled">-</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="card text-center border-info">
                                <div class="card-body py-2">
                                    <div class="text-muted small">合計売上</div>
                                    <div class="fs-5 fw-bold text-info" id="statTotalFare">-</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <div class="card">
                                <div class="card-header bg-light py-2"><i class="fas fa-star me-1"></i>よく使う行き先</div>
                                <ul class="list-group list-group-flush" id="statFrequentDest">
                                    <li class="list-group-item text-muted">データなし</li>
                                </ul>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="card">
                                <div class="card-header bg-light py-2"><i class="fas fa-calendar-alt me-1"></i>月間利用状況（直近6ヶ月）</div>
                                <ul class="list-group list-group-flush" id="statMonthlyUsage">
                                    <li class="list-group-item text-muted">データなし</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-12 col-md-6">
                            <div class="text-muted small">初回利用日: <span id="statFirstDate">-</span></div>
                        </div>
                        <div class="col-12 col-md-6">
                            <div class="text-muted small">最終利用日: <span id="statLastDate">-</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /.tab-content -->
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-danger me-auto" id="btnDeleteCustomer" style="display:none;">
            <i class="fas fa-trash me-1"></i>削除
        </button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
        <button type="button" class="btn btn-primary" id="btnSaveCustomer">
            <i class="fas fa-save me-1"></i>保存
        </button>
    </div>
</div>
</div>
</div>

<!-- ===== 取り込みモーダル ===== -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="importModalLabel"><i class="fas fa-file-import me-2"></i>既存予約から顧客データ取り込み</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
    </div>
    <div class="modal-body">
        <p>予約テーブルに登録されている利用者名・電話番号の組み合わせから、
           まだ顧客マスタに登録されていないデータを自動登録します。</p>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-1"></i>
            重複チェックは「名前 + 電話番号」の完全一致で行います。
        </div>
        <div id="importProgress" style="display:none;">
            <div class="progress mb-2">
                <div class="progress-bar progress-bar-striped progress-bar-animated" id="importProgressBar" style="width:0%"></div>
            </div>
            <div class="text-center small text-muted" id="importStatus">処理中...</div>
        </div>
        <div id="importResult" style="display:none;"></div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
        <button type="button" class="btn btn-info text-white" id="btnRunImport">
            <i class="fas fa-play me-1"></i>取り込み開始
        </button>
    </div>
</div>
</div>
</div>

<!-- ===== 削除確認モーダル ===== -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-sm">
<div class="modal-content">
    <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>削除確認</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <p><strong id="deleteCustomerName"></strong> を削除しますか？</p>
        <p class="text-muted small">論理削除のため復元は可能です。</p>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
        <button type="button" class="btn btn-danger" id="btnConfirmDelete"><i class="fas fa-trash me-1"></i>削除</button>
    </div>
</div>
</div>
</div>

<style>
.sortable:hover { background-color: #e9ecef; }
.sortable .fa-sort-up, .sortable .fa-sort-down { color: #0d6efd; }
#customerTable tbody tr { cursor: pointer; }
.status-badge { font-size: 0.75rem; }
.mobility-badge { font-size: 0.75rem; }
@media (max-width: 767.98px) {
    .modal-dialog { margin: 0.5rem; }
    .card-header { font-size: 0.9rem; }
}
</style>

<script>
(function() {
    'use strict';

    // ===== 定数・状態 =====
    const API_BASE = 'api/';
    const CSRF_TOKEN = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
    const MOBILITY_LABELS = {
        independent: '自立',
        wheelchair: '車椅子',
        stretcher: 'ストレッチャー',
        walker: '歩行器'
    };
    const MOBILITY_COLORS = {
        independent: 'success',
        wheelchair: 'primary',
        stretcher: 'danger',
        walker: 'warning'
    };
    const LOCATION_TYPE_LABELS = {
        hospital: '病院',
        dialysis: '透析',
        facility: '施設',
        home: '自宅',
        other: 'その他'
    };
    const STATUS_COLORS = {
        '予約': 'primary',
        '確定': 'info',
        '完了': 'success',
        'キャンセル': 'secondary'
    };

    let allCustomers = [];
    let filteredCustomers = [];
    let currentPage = 1;
    let pageSize = 20;
    let sortField = 'name_kana';
    let sortDir = 'asc';
    let currentCustomerId = null;
    let historyLoaded = false;
    let statsLoaded = false;

    // ===== 初期化 =====
    document.addEventListener('DOMContentLoaded', () => {
        loadCustomers();
        bindEvents();
    });

    // ===== イベントバインド =====
    function bindEvents() {
        // 検索
        document.getElementById('searchText').addEventListener('input', debounce(applyFilters, 300));
        document.getElementById('filterCareLevel').addEventListener('change', applyFilters);
        document.getElementById('filterMobility').addEventListener('change', applyFilters);
        document.getElementById('filterActive').addEventListener('change', () => { loadCustomers(); });
        document.getElementById('pageSize').addEventListener('change', () => {
            pageSize = parseInt(document.getElementById('pageSize').value);
            currentPage = 1;
            renderTable();
        });

        // ソート
        document.querySelectorAll('.sortable').forEach(th => {
            th.addEventListener('click', () => {
                const field = th.dataset.sort;
                if (sortField === field) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortField = field;
                    sortDir = 'asc';
                }
                sortCustomers();
                renderTable();
                updateSortIcons();
            });
        });

        // 新規登録
        document.getElementById('btnAddNew').addEventListener('click', () => openModal(null));

        // 保存
        document.getElementById('btnSaveCustomer').addEventListener('click', saveCustomer);

        // 削除
        document.getElementById('btnDeleteCustomer').addEventListener('click', () => {
            const name = document.getElementById('customerName').value;
            document.getElementById('deleteCustomerName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
        });
        document.getElementById('btnConfirmDelete').addEventListener('click', deleteCustomer);

        // よく行く場所追加
        document.getElementById('btnAddDestination').addEventListener('click', addDestinationRow);

        // タブ切り替え
        document.getElementById('tab-history').addEventListener('shown.bs.tab', () => {
            if (currentCustomerId && !historyLoaded) loadHistory(currentCustomerId);
        });
        document.getElementById('tab-stats').addEventListener('shown.bs.tab', () => {
            if (currentCustomerId && !statsLoaded) loadStats(currentCustomerId);
        });

        // 取り込み
        document.getElementById('btnImport').addEventListener('click', () => {
            document.getElementById('importProgress').style.display = 'none';
            document.getElementById('importResult').style.display = 'none';
            document.getElementById('btnRunImport').disabled = false;
            new bootstrap.Modal(document.getElementById('importModal')).show();
        });
        document.getElementById('btnRunImport').addEventListener('click', runImport);
    }

    // ===== データ取得 =====
    async function loadCustomers() {
        try {
            const resp = await fetch(API_BASE + 'get_customers.php?limit=1000');
            const json = await resp.json();
            if (json.success) {
                allCustomers = json.data.customers || json.data || [];
                // 予約数を取得するためにreservationsカウントも含める
                await loadReservationCounts();
                applyFilters();
            } else {
                showToast('error', json.message || 'データ取得に失敗しました');
            }
        } catch (e) {
            console.error(e);
            showToast('error', 'データ取得中にエラーが発生しました');
        }
    }

    async function loadReservationCounts() {
        // 顧客IDリストから予約数を一括取得
        try {
            const ids = allCustomers.map(c => c.id);
            if (ids.length === 0) return;
            // 個別APIがないため、各顧客に予約数0をセットし、
            // historyを表示する際に個別取得する
            allCustomers.forEach(c => {
                if (c.reservation_count === undefined) c.reservation_count = '-';
            });
        } catch (e) {
            console.error(e);
        }
    }

    // ===== フィルタリング =====
    function applyFilters() {
        const search = (document.getElementById('searchText').value || '').toLowerCase().trim();
        const careLevel = document.getElementById('filterCareLevel').value;
        const mobility = document.getElementById('filterMobility').value;

        filteredCustomers = allCustomers.filter(c => {
            if (search) {
                const text = ((c.name || '') + (c.name_kana || '') + (c.phone || '')).toLowerCase();
                if (!text.includes(search)) return false;
            }
            if (careLevel && c.care_level !== careLevel) return false;
            if (mobility && c.mobility_type !== mobility) return false;
            return true;
        });

        sortCustomers();
        currentPage = 1;
        renderTable();
    }

    // ===== ソート =====
    function sortCustomers() {
        filteredCustomers.sort((a, b) => {
            let va = (a[sortField] || '').toString().toLowerCase();
            let vb = (b[sortField] || '').toString().toLowerCase();
            if (va < vb) return sortDir === 'asc' ? -1 : 1;
            if (va > vb) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });
    }

    function updateSortIcons() {
        document.querySelectorAll('.sortable i').forEach(icon => {
            icon.className = 'fas fa-sort text-muted ms-1';
        });
        const active = document.querySelector(`.sortable[data-sort="${sortField}"] i`);
        if (active) {
            active.className = sortDir === 'asc'
                ? 'fas fa-sort-up text-primary ms-1'
                : 'fas fa-sort-down text-primary ms-1';
        }
    }

    // ===== テーブル描画 =====
    function renderTable() {
        const tbody = document.getElementById('customerTableBody');
        const total = filteredCustomers.length;
        document.getElementById('totalCount').textContent = total + '件';

        if (total === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4"><i class="fas fa-inbox me-2"></i>顧客が見つかりません</td></tr>';
            renderPagination(0);
            return;
        }

        const start = (currentPage - 1) * pageSize;
        const pageData = filteredCustomers.slice(start, start + pageSize);
        let html = '';

        pageData.forEach(c => {
            const mobilityLabel = MOBILITY_LABELS[c.mobility_type] || c.mobility_type || '-';
            const mobilityColor = MOBILITY_COLORS[c.mobility_type] || 'secondary';
            html += `<tr data-id="${c.id}">
                <td class="fw-bold">${esc(c.name)}</td>
                <td class="d-none d-md-table-cell text-muted">${esc(c.name_kana || '-')}</td>
                <td>${esc(c.phone || '-')}</td>
                <td class="d-none d-lg-table-cell small">${esc(truncate(c.address || '-', 25))}</td>
                <td><span class="badge bg-secondary status-badge">${esc(c.care_level || '-')}</span></td>
                <td class="d-none d-md-table-cell"><span class="badge bg-${mobilityColor} mobility-badge">${mobilityLabel}</span></td>
                <td class="text-center"><span class="badge bg-outline-secondary border">${c.reservation_count ?? '-'}</span></td>
                <td class="text-center">
                    <button class="btn btn-sm btn-outline-primary btn-edit" data-id="${c.id}" title="編集">
                        <i class="fas fa-pen"></i>
                    </button>
                </td>
            </tr>`;
        });

        tbody.innerHTML = html;

        // 行クリック
        tbody.querySelectorAll('tr').forEach(tr => {
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.btn-edit')) return;
                const id = parseInt(tr.dataset.id);
                openModal(id);
            });
        });
        tbody.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                openModal(parseInt(btn.dataset.id));
            });
        });

        renderPagination(total);
    }

    // ===== ページネーション =====
    function renderPagination(total) {
        const totalPages = Math.ceil(total / pageSize);
        const ul = document.getElementById('pagination');
        if (totalPages <= 1) { ul.innerHTML = ''; return; }

        let html = '';
        html += `<li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-page="${currentPage - 1}">&laquo;</a></li>`;

        const start = Math.max(1, currentPage - 2);
        const end = Math.min(totalPages, currentPage + 2);
        for (let i = start; i <= end; i++) {
            html += `<li class="page-item ${i === currentPage ? 'active' : ''}">
                        <a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
        }

        html += `<li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
                    <a class="page-link" href="#" data-page="${currentPage + 1}">&raquo;</a></li>`;

        ul.innerHTML = html;
        ul.querySelectorAll('.page-link').forEach(a => {
            a.addEventListener('click', (e) => {
                e.preventDefault();
                const p = parseInt(a.dataset.page);
                if (p >= 1 && p <= totalPages) {
                    currentPage = p;
                    renderTable();
                }
            });
        });
    }

    // ===== モーダル =====
    function openModal(id) {
        currentCustomerId = id;
        historyLoaded = false;
        statsLoaded = false;

        // タブリセット
        const editTab = new bootstrap.Tab(document.getElementById('tab-edit'));
        editTab.show();

        // フォームリセット
        document.getElementById('customerForm').reset();
        document.getElementById('customerId').value = '';
        document.getElementById('destinationsBody').innerHTML =
            '<tr id="noDestRow"><td colspan="5" class="text-center text-muted py-2">登録なし</td></tr>';

        // 履歴・統計リセット
        document.getElementById('historyLoading').style.display = 'block';
        document.getElementById('historyContent').style.display = 'none';
        document.getElementById('historyEmpty').style.display = 'none';
        document.getElementById('statsLoading').style.display = 'block';
        document.getElementById('statsContent').style.display = 'none';

        if (id) {
            // 編集モード
            document.getElementById('customerModalLabel').innerHTML = '<i class="fas fa-user-edit me-2"></i>顧客編集';
            document.getElementById('btnDeleteCustomer').style.display = '';
            document.getElementById('tab-history').parentElement.style.display = '';
            document.getElementById('tab-stats').parentElement.style.display = '';
            loadCustomerDetail(id);
        } else {
            // 新規モード
            document.getElementById('customerModalLabel').innerHTML = '<i class="fas fa-user-plus me-2"></i>新規顧客登録';
            document.getElementById('btnDeleteCustomer').style.display = 'none';
            document.getElementById('tab-history').parentElement.style.display = 'none';
            document.getElementById('tab-stats').parentElement.style.display = 'none';
        }

        new bootstrap.Modal(document.getElementById('customerModal')).show();
    }

    async function loadCustomerDetail(id) {
        try {
            const resp = await fetch(API_BASE + 'get_customers.php?id=' + id);
            const json = await resp.json();
            if (!json.success) {
                showToast('error', json.message || '顧客情報の取得に失敗しました');
                return;
            }
            const c = json.data;
            populateForm(c);
        } catch (e) {
            console.error(e);
            showToast('error', '顧客情報の取得中にエラーが発生しました');
        }
    }

    function populateForm(c) {
        document.getElementById('customerId').value = c.id || '';
        document.getElementById('customerName').value = c.name || '';
        document.getElementById('customerNameKana').value = c.name_kana || '';
        document.getElementById('customerPhone').value = c.phone || '';
        document.getElementById('customerPhoneSecondary').value = c.phone_secondary || '';
        document.getElementById('customerEmail').value = c.email || '';
        document.getElementById('customerPostalCode').value = c.postal_code || '';
        document.getElementById('customerAddress').value = c.address || '';
        document.getElementById('customerAddressDetail').value = c.address_detail || '';
        document.getElementById('customerCareLevel').value = c.care_level || '';
        document.getElementById('customerDisabilityType').value = c.disability_type || '';
        document.getElementById('customerMobilityType').value = c.mobility_type || 'independent';
        document.getElementById('customerWheelchairType').value = c.wheelchair_type || '';
        document.getElementById('customerDefaultPickup').value = c.default_pickup_location || '';
        document.getElementById('customerDefaultDropoff').value = c.default_dropoff_location || '';
        document.getElementById('customerEmergencyName').value = c.emergency_contact_name || '';
        document.getElementById('customerEmergencyPhone').value = c.emergency_contact_phone || '';
        document.getElementById('customerNotes').value = c.notes || '';

        // よく行く場所
        const tbody = document.getElementById('destinationsBody');
        tbody.innerHTML = '';
        if (c.frequent_destinations && c.frequent_destinations.length > 0) {
            c.frequent_destinations.forEach(d => addDestinationRow(null, d));
        } else {
            tbody.innerHTML = '<tr id="noDestRow"><td colspan="5" class="text-center text-muted py-2">登録なし</td></tr>';
        }
    }

    // ===== よく行く場所 =====
    function addDestinationRow(e, data) {
        if (e) e.preventDefault();
        const noRow = document.getElementById('noDestRow');
        if (noRow) noRow.remove();

        const tbody = document.getElementById('destinationsBody');
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input type="text" class="form-control form-control-sm dest-name" value="${esc(data?.location_name || '')}" placeholder="場所名"></td>
            <td><input type="text" class="form-control form-control-sm dest-address" value="${esc(data?.address || '')}" placeholder="住所"></td>
            <td><select class="form-select form-select-sm dest-type">
                <option value="hospital" ${data?.location_type === 'hospital' ? 'selected' : ''}>病院</option>
                <option value="dialysis" ${data?.location_type === 'dialysis' ? 'selected' : ''}>透析</option>
                <option value="facility" ${data?.location_type === 'facility' ? 'selected' : ''}>施設</option>
                <option value="home" ${data?.location_type === 'home' ? 'selected' : ''}>自宅</option>
                <option value="other" ${(!data || data?.location_type === 'other') ? 'selected' : ''}>その他</option>
            </select></td>
            <td><input type="text" class="form-control form-control-sm dest-notes" value="${esc(data?.notes || '')}" placeholder="備考"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-dest" title="削除"><i class="fas fa-times"></i></button></td>
        `;
        tr.querySelector('.btn-remove-dest').addEventListener('click', () => {
            tr.remove();
            if (tbody.children.length === 0) {
                tbody.innerHTML = '<tr id="noDestRow"><td colspan="5" class="text-center text-muted py-2">登録なし</td></tr>';
            }
        });
        tbody.appendChild(tr);
    }

    function collectDestinations() {
        const rows = document.querySelectorAll('#destinationsBody tr:not(#noDestRow)');
        const dests = [];
        rows.forEach((tr, i) => {
            const name = tr.querySelector('.dest-name')?.value?.trim();
            if (!name) return;
            dests.push({
                location_name: name,
                address: tr.querySelector('.dest-address')?.value?.trim() || '',
                location_type: tr.querySelector('.dest-type')?.value || 'other',
                sort_order: i,
                notes: tr.querySelector('.dest-notes')?.value?.trim() || ''
            });
        });
        return dests;
    }

    // ===== 保存 =====
    async function saveCustomer() {
        const form = document.getElementById('customerForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const data = {
            id: document.getElementById('customerId').value || null,
            name: document.getElementById('customerName').value.trim(),
            name_kana: document.getElementById('customerNameKana').value.trim(),
            phone: document.getElementById('customerPhone').value.trim(),
            phone_secondary: document.getElementById('customerPhoneSecondary').value.trim(),
            email: document.getElementById('customerEmail').value.trim(),
            postal_code: document.getElementById('customerPostalCode').value.trim(),
            address: document.getElementById('customerAddress').value.trim(),
            address_detail: document.getElementById('customerAddressDetail').value.trim(),
            care_level: document.getElementById('customerCareLevel').value,
            disability_type: document.getElementById('customerDisabilityType').value.trim(),
            mobility_type: document.getElementById('customerMobilityType').value,
            wheelchair_type: document.getElementById('customerWheelchairType').value.trim(),
            default_pickup_location: document.getElementById('customerDefaultPickup').value.trim(),
            default_dropoff_location: document.getElementById('customerDefaultDropoff').value.trim(),
            emergency_contact_name: document.getElementById('customerEmergencyName').value.trim(),
            emergency_contact_phone: document.getElementById('customerEmergencyPhone').value.trim(),
            notes: document.getElementById('customerNotes').value.trim(),
            frequent_destinations: collectDestinations()
        };

        const btn = document.getElementById('btnSaveCustomer');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>保存中...';

        try {
            const resp = await fetch(API_BASE + 'save_customer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                body: JSON.stringify(data)
            });
            const json = await resp.json();
            if (json.success) {
                showToast('success', json.message || '保存しました');
                bootstrap.Modal.getInstance(document.getElementById('customerModal'))?.hide();
                loadCustomers();
            } else {
                showToast('error', json.message || '保存に失敗しました');
            }
        } catch (e) {
            console.error(e);
            showToast('error', '保存中にエラーが発生しました');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save me-1"></i>保存';
        }
    }

    // ===== 削除 =====
    async function deleteCustomer() {
        if (!currentCustomerId) return;

        try {
            const resp = await fetch(API_BASE + 'delete_customer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN
                },
                body: JSON.stringify({ id: currentCustomerId })
            });
            const json = await resp.json();
            if (json.success) {
                showToast('success', json.message || '削除しました');
                bootstrap.Modal.getInstance(document.getElementById('deleteConfirmModal'))?.hide();
                bootstrap.Modal.getInstance(document.getElementById('customerModal'))?.hide();
                loadCustomers();
            } else {
                showToast('error', json.message || '削除に失敗しました');
            }
        } catch (e) {
            console.error(e);
            showToast('error', '削除中にエラーが発生しました');
        }
    }

    // ===== 予約履歴 =====
    async function loadHistory(customerId, page) {
        page = page || 1;
        document.getElementById('historyLoading').style.display = 'block';
        document.getElementById('historyContent').style.display = 'none';
        document.getElementById('historyEmpty').style.display = 'none';

        try {
            const resp = await fetch(API_BASE + `get_customer_history.php?customer_id=${customerId}&page=${page}&limit=10`);
            const json = await resp.json();
            if (!json.success) {
                showToast('error', json.message);
                return;
            }

            const reservations = json.data.reservations || [];
            const pagination = json.data.pagination || {};

            document.getElementById('historyLoading').style.display = 'none';

            if (reservations.length === 0 && page === 1) {
                document.getElementById('historyEmpty').style.display = 'block';
                historyLoaded = true;
                return;
            }

            const tbody = document.getElementById('historyTableBody');
            let html = '';
            reservations.forEach(r => {
                const statusColor = STATUS_COLORS[r.status] || 'secondary';
                const fare = r.actual_fare != null ? Number(r.actual_fare).toLocaleString() + '円' :
                             r.estimated_fare != null ? '~' + Number(r.estimated_fare).toLocaleString() + '円' : '-';
                html += `<tr>
                    <td class="small">${esc(r.reservation_date || '-')}</td>
                    <td class="small">${esc((r.reservation_time || '').substring(0, 5))}</td>
                    <td class="small">${esc(truncate(r.pickup_location || '-', 15))}</td>
                    <td class="small">${esc(truncate(r.dropoff_location || '-', 15))}</td>
                    <td><span class="badge bg-outline-secondary border small">${esc(r.service_type || '-')}</span></td>
                    <td><span class="badge bg-${statusColor} status-badge">${esc(r.status || '-')}</span></td>
                    <td class="text-end small">${fare}</td>
                </tr>`;
            });
            tbody.innerHTML = html;

            // ページネーション
            const pUl = document.getElementById('historyPagination');
            const totalPages = pagination.total_pages || 1;
            if (totalPages > 1) {
                let pHtml = '';
                for (let i = 1; i <= totalPages; i++) {
                    pHtml += `<li class="page-item ${i === page ? 'active' : ''}">
                        <a class="page-link hist-page" href="#" data-page="${i}">${i}</a></li>`;
                }
                pUl.innerHTML = pHtml;
                pUl.querySelectorAll('.hist-page').forEach(a => {
                    a.addEventListener('click', (e) => {
                        e.preventDefault();
                        loadHistory(customerId, parseInt(a.dataset.page));
                    });
                });
            } else {
                pUl.innerHTML = '';
            }

            document.getElementById('historyContent').style.display = 'block';
            historyLoaded = true;
        } catch (e) {
            console.error(e);
            document.getElementById('historyLoading').style.display = 'none';
            showToast('error', '履歴の取得に失敗しました');
        }
    }

    // ===== 統計 =====
    async function loadStats(customerId) {
        document.getElementById('statsLoading').style.display = 'block';
        document.getElementById('statsContent').style.display = 'none';

        try {
            // 統計情報は履歴APIから全件取得して算出
            const resp = await fetch(API_BASE + `get_customer_history.php?customer_id=${customerId}&limit=1000`);
            const json = await resp.json();
            if (!json.success) return;

            const reservations = json.data.reservations || [];
            const total = json.data.pagination?.total || reservations.length;

            // 集計
            let completed = 0, cancelled = 0, totalFare = 0;
            let firstDate = null, lastDate = null;
            const destCounts = {};
            const monthlyCounts = {};

            reservations.forEach(r => {
                if (r.status === '完了') completed++;
                if (r.status === 'キャンセル') cancelled++;
                if (r.actual_fare) totalFare += Number(r.actual_fare);

                if (r.reservation_date) {
                    if (!firstDate || r.reservation_date < firstDate) firstDate = r.reservation_date;
                    if (!lastDate || r.reservation_date > lastDate) lastDate = r.reservation_date;
                    const ym = r.reservation_date.substring(0, 7);
                    monthlyCounts[ym] = (monthlyCounts[ym] || 0) + 1;
                }
                if (r.dropoff_location) {
                    destCounts[r.dropoff_location] = (destCounts[r.dropoff_location] || 0) + 1;
                }
            });

            document.getElementById('statTotalRides').textContent = total;
            document.getElementById('statCompleted').textContent = completed;
            document.getElementById('statCancelled').textContent = cancelled;
            document.getElementById('statTotalFare').textContent = totalFare > 0 ? totalFare.toLocaleString() + '円' : '-';
            document.getElementById('statFirstDate').textContent = firstDate || '-';
            document.getElementById('statLastDate').textContent = lastDate || '-';

            // よく行く場所トップ5
            const destSorted = Object.entries(destCounts).sort((a, b) => b[1] - a[1]).slice(0, 5);
            const destUl = document.getElementById('statFrequentDest');
            if (destSorted.length > 0) {
                destUl.innerHTML = destSorted.map(([name, cnt]) =>
                    `<li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="small">${esc(name)}</span>
                        <span class="badge bg-primary rounded-pill">${cnt}回</span>
                    </li>`
                ).join('');
            } else {
                destUl.innerHTML = '<li class="list-group-item text-muted">データなし</li>';
            }

            // 月間利用
            const monthSorted = Object.entries(monthlyCounts).sort((a, b) => b[0].localeCompare(a[0])).slice(0, 6);
            const monthUl = document.getElementById('statMonthlyUsage');
            if (monthSorted.length > 0) {
                monthUl.innerHTML = monthSorted.map(([month, cnt]) =>
                    `<li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="small">${month}</span>
                        <span class="badge bg-info rounded-pill">${cnt}件</span>
                    </li>`
                ).join('');
            } else {
                monthUl.innerHTML = '<li class="list-group-item text-muted">データなし</li>';
            }

            document.getElementById('statsLoading').style.display = 'none';
            document.getElementById('statsContent').style.display = 'block';
            statsLoaded = true;
        } catch (e) {
            console.error(e);
            document.getElementById('statsLoading').style.display = 'none';
            showToast('error', '統計情報の取得に失敗しました');
        }
    }

    // ===== 取り込み機能 =====
    async function runImport() {
        const btn = document.getElementById('btnRunImport');
        btn.disabled = true;
        document.getElementById('importProgress').style.display = 'block';
        document.getElementById('importResult').style.display = 'none';
        document.getElementById('importProgressBar').style.width = '30%';
        document.getElementById('importStatus').textContent = '予約データを検索中...';

        try {
            // Step 1: 予約テーブルからユニークな名前+電話番号の組み合わせを取得
            const resp = await fetch(API_BASE + 'get_reservations.php?all=1&limit=10000');
            const json = await resp.json();
            if (!json.success) {
                showImportResult('error', 'データ取得に失敗しました');
                return;
            }

            document.getElementById('importProgressBar').style.width = '50%';
            document.getElementById('importStatus').textContent = '重複チェック中...';

            const reservations = json.data?.reservations || json.data || [];
            // ユニークな名前+電話のペアを抽出
            const uniqueClients = {};
            reservations.forEach(r => {
                const name = (r.client_name || '').trim();
                if (!name) return;
                const phone = (r.phone || r.client_phone || '').trim();
                const key = name + '|' + phone;
                if (!uniqueClients[key]) {
                    uniqueClients[key] = {
                        name: name,
                        phone: phone,
                        pickup: r.pickup_location || '',
                        dropoff: r.dropoff_location || ''
                    };
                }
            });

            // 既存顧客と比較
            const existingKeys = new Set();
            allCustomers.forEach(c => {
                existingKeys.add((c.name || '').trim() + '|' + (c.phone || '').trim());
            });

            const newClients = Object.entries(uniqueClients)
                .filter(([key]) => !existingKeys.has(key))
                .map(([, val]) => val);

            document.getElementById('importProgressBar').style.width = '70%';
            document.getElementById('importStatus').textContent = `${newClients.length}件の新規顧客を登録中...`;

            if (newClients.length === 0) {
                showImportResult('info', '取り込み対象の新規顧客はありませんでした。すべて登録済みです。');
                return;
            }

            // Step 2: 新規顧客を一件ずつ登録
            let created = 0, failed = 0;
            for (const client of newClients) {
                try {
                    const saveResp = await fetch(API_BASE + 'save_customer.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': CSRF_TOKEN
                        },
                        body: JSON.stringify({
                            name: client.name,
                            phone: client.phone,
                            default_pickup_location: client.pickup,
                            default_dropoff_location: client.dropoff,
                            mobility_type: 'independent'
                        })
                    });
                    const saveJson = await saveResp.json();
                    if (saveJson.success) {
                        created++;
                    } else {
                        failed++;
                    }
                } catch {
                    failed++;
                }
                const progress = 70 + (30 * (created + failed) / newClients.length);
                document.getElementById('importProgressBar').style.width = progress + '%';
            }

            showImportResult('success',
                `取り込み完了: ${created}件登録 ${failed > 0 ? '/ ' + failed + '件失敗' : ''}`);
            loadCustomers();

        } catch (e) {
            console.error(e);
            showImportResult('error', '取り込み中にエラーが発生しました');
        } finally {
            btn.disabled = false;
        }
    }

    function showImportResult(type, message) {
        const colors = { success: 'success', error: 'danger', info: 'info' };
        document.getElementById('importProgressBar').style.width = '100%';
        document.getElementById('importStatus').textContent = '完了';
        document.getElementById('importResult').style.display = 'block';
        document.getElementById('importResult').innerHTML =
            `<div class="alert alert-${colors[type] || 'info'} mb-0">${esc(message)}</div>`;
    }

    // ===== ユーティリティ =====
    function esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function truncate(str, len) {
        if (!str) return '';
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function debounce(fn, ms) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }

    function showToast(type, message) {
        // Bootstrap toast or simple alert fallback
        const container = document.getElementById('toastContainer') || createToastContainer();
        const colors = { success: 'bg-success', error: 'bg-danger', info: 'bg-info', warning: 'bg-warning' };
        const icons = { success: 'check-circle', error: 'exclamation-circle', info: 'info-circle', warning: 'exclamation-triangle' };

        const toast = document.createElement('div');
        toast.className = `toast align-items-center ${colors[type] || 'bg-secondary'} text-white border-0`;
        toast.setAttribute('role', 'alert');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body"><i class="fas fa-${icons[type] || 'info-circle'} me-2"></i>${esc(message)}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>`;
        container.appendChild(toast);
        const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
        bsToast.show();
        toast.addEventListener('hidden.bs.toast', () => toast.remove());
    }

    function createToastContainer() {
        const c = document.createElement('div');
        c.id = 'toastContainer';
        c.className = 'toast-container position-fixed top-0 end-0 p-3';
        c.style.zIndex = '9999';
        document.body.appendChild(c);
        return c;
    }

})();
</script>

<?= $page_data['html_footer'] ?>
