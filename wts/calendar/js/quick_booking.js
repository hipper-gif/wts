// =================================================================
// クイック予約（Quick Booking）JavaScript
//
// ファイル: /Smiley/taxi/wts/calendar/js/quick_booking.js
// 機能: 電話対応向け簡易予約フロー（3ステップウィザード）
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2026年3月11日
// =================================================================

(function() {
    'use strict';

    // =================================================================
    // 定数・設定
    // =================================================================

    const STEP_CUSTOMER = 1;
    const STEP_DATETIME = 2;
    const STEP_CONFIRM  = 3;
    const TOTAL_STEPS   = 3;

    const DEFAULT_SERVICE_TYPE = 'お迎え';
    const DEFAULT_PASSENGER_COUNT = 1;
    const DEFAULT_RENTAL_SERVICE = 'なし';
    const DEFAULT_REFERRER_TYPE = 'CM';
    const DEFAULT_PAYMENT_METHOD = '現金';
    const DEFAULT_DURATION_MINUTES = 60;

    const CUSTOMER_SEARCH_API = 'api/get_customers.php';

    // =================================================================
    // 状態管理
    // =================================================================

    let currentStep = STEP_CUSTOMER;
    let isSubmitting = false;
    let customerSearchTimer = null;
    let quickBookingModal = null;

    // 予約データ（ウィザード全体で共有）
    let bookingData = {};

    // =================================================================
    // HTML生成・DOM挿入
    // =================================================================

    function injectQuickBookingUI() {
        // FABボタン挿入
        injectFAB();
        // モーダル挿入
        injectModal();
        // スタイル挿入
        injectStyles();
    }

    function injectFAB() {
        const fab = document.createElement('button');
        fab.type = 'button';
        fab.id = 'quickBookingFAB';
        fab.className = 'quick-booking-fab';
        fab.setAttribute('aria-label', 'クイック予約');
        fab.innerHTML = '<i class="fas fa-plus me-1"></i><span class="fab-label">クイック予約</span>';
        fab.addEventListener('click', openQuickBooking);
        document.body.appendChild(fab);

        // スクロールで非表示/表示
        let lastScrollY = window.scrollY;
        let fabHidden = false;
        window.addEventListener('scroll', function() {
            const currentScrollY = window.scrollY;
            if (currentScrollY > lastScrollY && currentScrollY > 100 && !fabHidden) {
                fab.classList.add('fab-hidden');
                fabHidden = true;
            } else if (currentScrollY < lastScrollY && fabHidden) {
                fab.classList.remove('fab-hidden');
                fabHidden = false;
            }
            lastScrollY = currentScrollY;
        }, { passive: true });
    }

    function injectModal() {
        const modalHTML = `
        <div class="modal fade" id="quickBookingModal" tabindex="-1" aria-labelledby="quickBookingModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-success text-white py-2">
                        <h5 class="modal-title" id="quickBookingModalTitle">
                            <i class="fas fa-bolt me-2"></i>クイック予約
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
                    </div>

                    <!-- ステッププログレス -->
                    <div class="qb-progress-bar px-4 pt-3 pb-0">
                        <div class="d-flex justify-content-between align-items-center position-relative">
                            <div class="qb-progress-line"></div>
                            <div class="qb-progress-line-fill" id="qbProgressFill"></div>
                            <div class="qb-step-indicator active" data-step="1">
                                <div class="qb-step-circle">1</div>
                                <div class="qb-step-label">顧客選択</div>
                            </div>
                            <div class="qb-step-indicator" data-step="2">
                                <div class="qb-step-circle">2</div>
                                <div class="qb-step-label">日時</div>
                            </div>
                            <div class="qb-step-indicator" data-step="3">
                                <div class="qb-step-circle">3</div>
                                <div class="qb-step-label">確認</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-body p-3">
                        <div class="qb-steps-container" id="qbStepsContainer">

                            <!-- Step 1: 顧客選択 -->
                            <div class="qb-step active" id="qbStep1">
                                <div class="card border-0">
                                    <div class="card-body p-0">
                                        <label class="form-label fw-bold fs-6 mb-2">
                                            <i class="fas fa-user me-1 text-success"></i>利用者を検索
                                        </label>
                                        <div class="input-group input-group-lg mb-3">
                                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            <input type="text" class="form-control" id="qbCustomerSearch"
                                                   placeholder="名前・電話番号で検索" autocomplete="off">
                                            <button class="btn btn-outline-secondary" type="button" id="qbClearSearch">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>

                                        <!-- 検索結果 -->
                                        <div id="qbCustomerResults" class="qb-customer-results">
                                            <div class="text-center text-muted py-4">
                                                <i class="fas fa-user-plus fa-2x mb-2 d-block"></i>
                                                利用者名または電話番号を入力してください
                                            </div>
                                        </div>

                                        <!-- 選択済み顧客表示 -->
                                        <div id="qbSelectedCustomer" class="qb-selected-customer d-none">
                                            <div class="card bg-light border-success">
                                                <div class="card-body py-2 px-3">
                                                    <div class="d-flex justify-content-between align-items-center">
                                                        <div>
                                                            <span class="fw-bold fs-5" id="qbSelectedName"></span>
                                                            <span class="text-muted ms-2" id="qbSelectedPhone"></span>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" id="qbDeselectCustomer">
                                                            <i class="fas fa-times me-1"></i>変更
                                                        </button>
                                                    </div>
                                                    <div class="small text-muted mt-1" id="qbSelectedAddress"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 新規顧客入力 -->
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-outline-primary btn-sm" id="qbNewCustomerToggle">
                                                <i class="fas fa-user-plus me-1"></i>新規利用者として入力
                                            </button>
                                            <div id="qbNewCustomerForm" class="d-none mt-3">
                                                <div class="row g-2">
                                                    <div class="col-12 col-sm-6">
                                                        <label class="form-label small">利用者名 <span class="text-danger">*</span></label>
                                                        <input type="text" class="form-control" id="qbNewClientName" placeholder="山田 太郎">
                                                    </div>
                                                    <div class="col-12 col-sm-6">
                                                        <label class="form-label small">電話番号</label>
                                                        <input type="tel" class="form-control" id="qbNewClientPhone" placeholder="090-1234-5678">
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label small">住所（乗車場所）</label>
                                                        <input type="text" class="form-control" id="qbNewClientAddress" placeholder="大阪市...">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 2: 日時選択 -->
                            <div class="qb-step" id="qbStep2">
                                <div class="card border-0">
                                    <div class="card-body p-0">
                                        <!-- 日付 -->
                                        <label class="form-label fw-bold fs-6 mb-2">
                                            <i class="fas fa-calendar-alt me-1 text-success"></i>予約日
                                        </label>
                                        <input type="date" class="form-control form-control-lg mb-3" id="qbDate">

                                        <!-- クイック日付ボタン -->
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" class="btn btn-outline-primary qb-quick-date" data-date="today">
                                                <i class="fas fa-calendar-day me-1"></i>今日
                                            </button>
                                            <button type="button" class="btn btn-outline-primary qb-quick-date" data-date="tomorrow">
                                                <i class="fas fa-calendar-plus me-1"></i>明日
                                            </button>
                                            <button type="button" class="btn btn-outline-primary qb-quick-date" data-date="day-after">
                                                明後日
                                            </button>
                                        </div>

                                        <!-- 時刻 -->
                                        <label class="form-label fw-bold fs-6 mb-2">
                                            <i class="fas fa-clock me-1 text-success"></i>予約時刻
                                        </label>
                                        <input type="time" class="form-control form-control-lg mb-3" id="qbTime" step="300">

                                        <!-- クイック時刻ボタン -->
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" class="btn btn-outline-info qb-quick-time" data-action="next-slot">
                                                <i class="fas fa-forward me-1"></i>次の空き
                                            </button>
                                            <button type="button" class="btn btn-outline-info qb-quick-time" data-action="today-pm">
                                                今日午後
                                            </button>
                                            <button type="button" class="btn btn-outline-info qb-quick-time" data-action="tomorrow-am">
                                                明日午前
                                            </button>
                                        </div>

                                        <!-- サービス種別（大きめボタン） -->
                                        <label class="form-label fw-bold fs-6 mb-2">
                                            <i class="fas fa-concierge-bell me-1 text-success"></i>サービス種別
                                        </label>
                                        <div class="d-flex flex-wrap gap-2 mb-3">
                                            <button type="button" class="btn btn-outline-primary qb-service-btn active" data-value="お迎え">お迎え</button>
                                            <button type="button" class="btn btn-outline-primary qb-service-btn" data-value="お送り">お送り</button>
                                            <button type="button" class="btn btn-outline-primary qb-service-btn" data-value="入院">入院</button>
                                            <button type="button" class="btn btn-outline-primary qb-service-btn" data-value="退院">退院</button>
                                            <button type="button" class="btn btn-outline-primary qb-service-btn" data-value="転院">転院</button>
                                            <button type="button" class="btn btn-outline-primary qb-service-btn" data-value="その他">その他</button>
                                        </div>

                                        <!-- 乗降車場所 -->
                                        <div class="row g-2">
                                            <div class="col-12 col-sm-6">
                                                <label class="form-label fw-bold small">
                                                    <i class="fas fa-map-marker-alt me-1 text-primary"></i>乗車場所
                                                </label>
                                                <input type="text" class="form-control" id="qbPickup" placeholder="乗車場所">
                                            </div>
                                            <div class="col-12 col-sm-6">
                                                <label class="form-label fw-bold small">
                                                    <i class="fas fa-flag-checkered me-1 text-danger"></i>降車場所
                                                </label>
                                                <input type="text" class="form-control" id="qbDropoff" placeholder="降車場所">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Step 3: 確認 -->
                            <div class="qb-step" id="qbStep3">
                                <div class="card border-0">
                                    <div class="card-body p-0">
                                        <div class="qb-summary">
                                            <h6 class="text-success mb-3">
                                                <i class="fas fa-check-circle me-1"></i>予約内容を確認してください
                                            </h6>

                                            <table class="table table-borderless mb-0">
                                                <tbody>
                                                    <tr>
                                                        <td class="text-muted fw-bold" style="width:120px">利用者名</td>
                                                        <td class="fs-5 fw-bold" id="qbSummaryName">-</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted fw-bold">電話番号</td>
                                                        <td id="qbSummaryPhone">-</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted fw-bold">予約日時</td>
                                                        <td class="fs-5" id="qbSummaryDateTime">-</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted fw-bold">サービス</td>
                                                        <td id="qbSummaryService">-</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted fw-bold">区間</td>
                                                        <td id="qbSummaryRoute">-</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted fw-bold">車両</td>
                                                        <td id="qbSummaryVehicle">自動割当</td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-muted fw-bold">見積料金</td>
                                                        <td id="qbSummaryFare">-</td>
                                                    </tr>
                                                </tbody>
                                            </table>

                                            <!-- 備考 -->
                                            <div class="mt-3">
                                                <label class="form-label fw-bold small text-muted">備考（任意）</label>
                                                <textarea class="form-control" id="qbNotes" rows="2" placeholder="特記事項があれば入力"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer d-flex justify-content-between py-2">
                        <div>
                            <button type="button" class="btn btn-outline-secondary" id="qbPrevBtn" disabled>
                                <i class="fas fa-chevron-left me-1"></i>戻る
                            </button>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-info d-none" id="qbDetailBtn">
                                <i class="fas fa-expand-alt me-1"></i>詳細入力
                            </button>
                            <button type="button" class="btn btn-success btn-lg" id="qbNextBtn">
                                次へ<i class="fas fa-chevron-right ms-1"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;

        const container = document.createElement('div');
        container.innerHTML = modalHTML;
        document.body.appendChild(container.firstElementChild);
    }

    function injectStyles() {
        const css = `
        /* =================================================================
           FAB (Floating Action Button)
           ================================================================= */
        .quick-booking-fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 1040;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 14px 24px;
            border: none;
            border-radius: 50px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: #fff;
            font-size: 1rem;
            font-weight: 700;
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
            cursor: pointer;
            transition: all 0.3s ease;
            animation: qb-fab-pulse 2s ease-in-out infinite;
        }
        .quick-booking-fab:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 8px 28px rgba(40, 167, 69, 0.55);
        }
        .quick-booking-fab:active {
            transform: translateY(0) scale(0.98);
        }
        .quick-booking-fab.fab-hidden {
            transform: translateY(100px);
            opacity: 0;
            pointer-events: none;
        }
        @keyframes qb-fab-pulse {
            0%, 100% { box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4); }
            50% { box-shadow: 0 6px 30px rgba(40, 167, 69, 0.65); }
        }
        @media (max-width: 576px) {
            .quick-booking-fab {
                bottom: 16px;
                right: 16px;
                padding: 12px 18px;
                font-size: 0.9rem;
            }
        }

        /* =================================================================
           プログレスバー
           ================================================================= */
        .qb-progress-bar {
            position: relative;
        }
        .qb-progress-line {
            position: absolute;
            top: 18px;
            left: 16.66%;
            right: 16.66%;
            height: 3px;
            background: #dee2e6;
            z-index: 0;
        }
        .qb-progress-line-fill {
            position: absolute;
            top: 18px;
            left: 16.66%;
            height: 3px;
            background: #28a745;
            z-index: 1;
            transition: width 0.4s ease;
            width: 0%;
        }
        .qb-step-indicator {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 2;
            flex: 1;
        }
        .qb-step-circle {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #dee2e6;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .qb-step-indicator.active .qb-step-circle,
        .qb-step-indicator.completed .qb-step-circle {
            background: #28a745;
            color: #fff;
        }
        .qb-step-indicator.completed .qb-step-circle::after {
            content: '\\f00c';
            font-family: 'Font Awesome 5 Free', 'Font Awesome 6 Free';
            font-weight: 900;
        }
        .qb-step-indicator.completed .qb-step-circle {
            font-size: 0;
        }
        .qb-step-indicator.completed .qb-step-circle::after {
            font-size: 0.9rem;
        }
        .qb-step-label {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 4px;
            font-weight: 600;
        }
        .qb-step-indicator.active .qb-step-label {
            color: #28a745;
        }

        /* =================================================================
           ステップカード・アニメーション
           ================================================================= */
        .qb-steps-container {
            position: relative;
            overflow: hidden;
            min-height: 320px;
        }
        .qb-step {
            display: none;
            animation: qb-slide-in-right 0.3s ease forwards;
        }
        .qb-step.active {
            display: block;
        }
        .qb-step.slide-out-left {
            animation: qb-slide-out-left 0.3s ease forwards;
        }
        .qb-step.slide-in-left {
            animation: qb-slide-in-left 0.3s ease forwards;
        }
        @keyframes qb-slide-in-right {
            from { opacity: 0; transform: translateX(40px); }
            to   { opacity: 1; transform: translateX(0); }
        }
        @keyframes qb-slide-out-left {
            from { opacity: 1; transform: translateX(0); }
            to   { opacity: 0; transform: translateX(-40px); }
        }
        @keyframes qb-slide-in-left {
            from { opacity: 0; transform: translateX(-40px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        /* =================================================================
           顧客検索結果
           ================================================================= */
        .qb-customer-results {
            max-height: 240px;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .qb-customer-item {
            display: flex;
            align-items: center;
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .qb-customer-item:last-child {
            border-bottom: none;
        }
        .qb-customer-item:hover,
        .qb-customer-item:focus {
            background: #e8f5e9;
        }
        .qb-customer-item .customer-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e3f2fd;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            flex-shrink: 0;
            font-size: 1.1rem;
            color: #1976D2;
        }
        .qb-customer-item .customer-info {
            flex: 1;
            min-width: 0;
        }
        .qb-customer-item .customer-name {
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .qb-customer-item .customer-meta {
            font-size: 0.8rem;
            color: #6c757d;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .qb-customer-item .customer-arrow {
            color: #adb5bd;
            margin-left: 8px;
        }

        /* 選択済み顧客 */
        .qb-selected-customer .card {
            border-width: 2px;
        }

        /* =================================================================
           サービスボタン
           ================================================================= */
        .qb-service-btn {
            min-width: 70px;
            font-weight: 600;
        }
        .qb-service-btn.active {
            background-color: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }

        /* =================================================================
           クイック時刻ボタン
           ================================================================= */
        .qb-quick-date.active,
        .qb-quick-time.active {
            background-color: #0d6efd;
            color: #fff;
        }

        /* =================================================================
           確認画面サマリー
           ================================================================= */
        .qb-summary table td {
            padding: 6px 8px;
            vertical-align: middle;
        }

        /* =================================================================
           ローディング
           ================================================================= */
        .qb-loading {
            position: relative;
            pointer-events: none;
            opacity: 0.6;
        }
        .qb-loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 32px;
            height: 32px;
            margin: -16px 0 0 -16px;
            border: 3px solid #dee2e6;
            border-top-color: #28a745;
            border-radius: 50%;
            animation: qb-spin 0.6s linear infinite;
        }
        @keyframes qb-spin {
            to { transform: rotate(360deg); }
        }

        /* =================================================================
           モバイル対応
           ================================================================= */
        @media (max-width: 576px) {
            #quickBookingModal .modal-dialog {
                margin: 8px;
            }
            #quickBookingModal .modal-body {
                padding: 12px !important;
            }
            .qb-steps-container {
                min-height: 280px;
            }
            .qb-step-label {
                font-size: 0.65rem;
            }
            .qb-step-circle {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
            .qb-service-btn {
                min-width: 60px;
                font-size: 0.85rem;
                padding: 6px 10px;
            }
            .qb-customer-item {
                padding: 8px 10px;
            }
        }

        /* 成功トースト */
        .qb-toast-success {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1060;
            min-width: 300px;
        }
        `;

        const style = document.createElement('style');
        style.id = 'quickBookingStyles';
        style.textContent = css;
        document.head.appendChild(style);
    }

    // =================================================================
    // モーダル制御
    // =================================================================

    function openQuickBooking(presetData) {
        if (!quickBookingModal) {
            const el = document.getElementById('quickBookingModal');
            if (!el) return;
            quickBookingModal = new bootstrap.Modal(el);

            // モーダルイベント
            el.addEventListener('hidden.bs.modal', handleQuickBookingClosed);
        }

        resetWizard();

        // プリセットデータ（カレンダーのスロットクリック等から）
        if (presetData && typeof presetData === 'object' && !(presetData instanceof Event)) {
            if (presetData.reservation_date) {
                bookingData.reservation_date = presetData.reservation_date;
            }
            if (presetData.reservation_time) {
                bookingData.reservation_time = presetData.reservation_time;
            }
        }

        quickBookingModal.show();

        // 検索フィールドにフォーカス
        setTimeout(function() {
            var searchInput = document.getElementById('qbCustomerSearch');
            if (searchInput) searchInput.focus();
        }, 300);
    }

    function handleQuickBookingClosed() {
        resetWizard();
    }

    function resetWizard() {
        currentStep = STEP_CUSTOMER;
        isSubmitting = false;
        bookingData = {
            service_type: DEFAULT_SERVICE_TYPE,
            passenger_count: DEFAULT_PASSENGER_COUNT,
            rental_service: DEFAULT_RENTAL_SERVICE,
            referrer_type: DEFAULT_REFERRER_TYPE,
            payment_method: DEFAULT_PAYMENT_METHOD,
            is_time_critical: true
        };

        // フォームリセット
        var searchInput = document.getElementById('qbCustomerSearch');
        if (searchInput) searchInput.value = '';
        var resultsEl = document.getElementById('qbCustomerResults');
        if (resultsEl) {
            resultsEl.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-user-plus fa-2x mb-2 d-block"></i>利用者名または電話番号を入力してください</div>';
        }
        var selectedEl = document.getElementById('qbSelectedCustomer');
        if (selectedEl) selectedEl.classList.add('d-none');
        var newForm = document.getElementById('qbNewCustomerForm');
        if (newForm) newForm.classList.add('d-none');

        // 日時リセット
        var dateInput = document.getElementById('qbDate');
        if (dateInput) dateInput.value = '';
        var timeInput = document.getElementById('qbTime');
        if (timeInput) timeInput.value = '';
        var pickupInput = document.getElementById('qbPickup');
        if (pickupInput) pickupInput.value = '';
        var dropoffInput = document.getElementById('qbDropoff');
        if (dropoffInput) dropoffInput.value = '';
        var notesInput = document.getElementById('qbNotes');
        if (notesInput) notesInput.value = '';

        // サービスボタンリセット
        document.querySelectorAll('.qb-service-btn').forEach(function(btn) {
            btn.classList.remove('active');
            if (btn.dataset.value === DEFAULT_SERVICE_TYPE) {
                btn.classList.add('active');
            }
        });

        updateStepDisplay();
    }

    // =================================================================
    // ステップナビゲーション
    // =================================================================

    function goToStep(step) {
        if (step < STEP_CUSTOMER || step > TOTAL_STEPS) return;

        var direction = step > currentStep ? 'forward' : 'backward';
        var currentStepEl = document.getElementById('qbStep' + currentStep);
        var nextStepEl = document.getElementById('qbStep' + step);

        if (!currentStepEl || !nextStepEl) return;

        // アニメーション
        currentStepEl.classList.remove('active');
        if (direction === 'forward') {
            currentStepEl.classList.add('slide-out-left');
        }

        setTimeout(function() {
            currentStepEl.classList.remove('slide-out-left', 'slide-in-left');
            currentStepEl.style.display = 'none';

            currentStep = step;
            nextStepEl.style.display = 'block';
            nextStepEl.classList.add('active');
            if (direction === 'backward') {
                nextStepEl.classList.add('slide-in-left');
            }

            updateStepDisplay();

            // Step3: 確認画面の更新
            if (step === STEP_CONFIRM) {
                updateSummary();
            }
        }, direction === 'forward' ? 250 : 0);
    }

    function updateStepDisplay() {
        // プログレスインジケータ更新
        document.querySelectorAll('.qb-step-indicator').forEach(function(indicator) {
            var step = parseInt(indicator.dataset.step);
            indicator.classList.remove('active', 'completed');
            if (step === currentStep) {
                indicator.classList.add('active');
            } else if (step < currentStep) {
                indicator.classList.add('completed');
            }
        });

        // プログレスライン更新
        var fill = document.getElementById('qbProgressFill');
        if (fill) {
            var percent = ((currentStep - 1) / (TOTAL_STEPS - 1)) * 100;
            fill.style.width = percent + '%';
        }

        // ボタン更新
        var prevBtn = document.getElementById('qbPrevBtn');
        var nextBtn = document.getElementById('qbNextBtn');
        var detailBtn = document.getElementById('qbDetailBtn');

        if (prevBtn) prevBtn.disabled = (currentStep === STEP_CUSTOMER);

        if (nextBtn) {
            if (currentStep === STEP_CONFIRM) {
                nextBtn.innerHTML = '<i class="fas fa-save me-1"></i>保存';
                nextBtn.className = 'btn btn-success btn-lg';
            } else {
                nextBtn.innerHTML = '次へ<i class="fas fa-chevron-right ms-1"></i>';
                nextBtn.className = 'btn btn-success btn-lg';
            }
        }

        if (detailBtn) {
            if (currentStep === STEP_CONFIRM) {
                detailBtn.classList.remove('d-none');
            } else {
                detailBtn.classList.add('d-none');
            }
        }
    }

    // =================================================================
    // バリデーション
    // =================================================================

    function validateCurrentStep() {
        switch (currentStep) {
            case STEP_CUSTOMER:
                return validateCustomerStep();
            case STEP_DATETIME:
                return validateDateTimeStep();
            case STEP_CONFIRM:
                return true;
            default:
                return false;
        }
    }

    function validateCustomerStep() {
        // 既存顧客が選択されているか
        if (bookingData.client_name) {
            return true;
        }

        // 新規顧客フォームが開いているか
        var newForm = document.getElementById('qbNewCustomerForm');
        if (newForm && !newForm.classList.contains('d-none')) {
            var name = (document.getElementById('qbNewClientName').value || '').trim();
            if (name.length < 2) {
                showQuickNotification('利用者名を2文字以上で入力してください', 'warning');
                document.getElementById('qbNewClientName').focus();
                return false;
            }
            // 新規顧客データをセット
            bookingData.client_name = name;
            bookingData.client_phone = (document.getElementById('qbNewClientPhone').value || '').trim();
            var addr = (document.getElementById('qbNewClientAddress').value || '').trim();
            if (addr && !bookingData.pickup_location) {
                bookingData.pickup_location = addr;
            }
            return true;
        }

        showQuickNotification('利用者を選択するか、新規入力してください', 'warning');
        return false;
    }

    function validateDateTimeStep() {
        // 日付を取得
        var dateVal = document.getElementById('qbDate').value;
        var timeVal = document.getElementById('qbTime').value;
        var pickup = (document.getElementById('qbPickup').value || '').trim();
        var dropoff = (document.getElementById('qbDropoff').value || '').trim();

        if (!dateVal) {
            showQuickNotification('予約日を選択してください', 'warning');
            document.getElementById('qbDate').focus();
            return false;
        }

        if (!timeVal) {
            showQuickNotification('予約時刻を入力してください', 'warning');
            document.getElementById('qbTime').focus();
            return false;
        }

        // 過去日付チェック
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        if (new Date(dateVal) < today) {
            showQuickNotification('過去の日付は選択できません', 'warning');
            return false;
        }

        // 営業時間チェック
        var hour = parseInt(timeVal.split(':')[0], 10);
        if (hour < 7 || hour > 19) {
            showQuickNotification('営業時間外です（7:00-19:00）', 'warning');
            return false;
        }

        if (!pickup) {
            showQuickNotification('乗車場所を入力してください', 'warning');
            document.getElementById('qbPickup').focus();
            return false;
        }

        if (!dropoff) {
            showQuickNotification('降車場所を入力してください', 'warning');
            document.getElementById('qbDropoff').focus();
            return false;
        }

        // データ保存
        bookingData.reservation_date = dateVal;
        bookingData.reservation_time = timeVal;
        bookingData.pickup_location = pickup;
        bookingData.dropoff_location = dropoff;

        // 選択中のサービス種別
        var activeServiceBtn = document.querySelector('.qb-service-btn.active');
        if (activeServiceBtn) {
            bookingData.service_type = activeServiceBtn.dataset.value;
        }

        return true;
    }

    // =================================================================
    // 確認画面サマリー更新
    // =================================================================

    function updateSummary() {
        setTextContent('qbSummaryName', (bookingData.client_name || '-') + ' 様');
        setTextContent('qbSummaryPhone', bookingData.client_phone || '-');

        // 日時フォーマット
        var dateStr = '-';
        if (bookingData.reservation_date && bookingData.reservation_time) {
            var d = new Date(bookingData.reservation_date);
            var days = ['日', '月', '火', '水', '木', '金', '土'];
            dateStr = d.getFullYear() + '年'
                + (d.getMonth() + 1) + '月'
                + d.getDate() + '日'
                + '（' + days[d.getDay()] + '）'
                + ' ' + bookingData.reservation_time;
        }
        setTextContent('qbSummaryDateTime', dateStr);

        setTextContent('qbSummaryService', bookingData.service_type || '-');

        // 区間
        var route = '-';
        if (bookingData.pickup_location && bookingData.dropoff_location) {
            route = bookingData.pickup_location + '  →  ' + bookingData.dropoff_location;
        }
        setTextContent('qbSummaryRoute', route);

        // 車両（自動割当）
        var vehicleText = '自動割当';
        if (bookingData.vehicle_id) {
            var vehicles = (window.calendarConfig || {}).vehicles || [];
            var matched = vehicles.find(function(v) { return v.id == bookingData.vehicle_id; });
            if (matched) {
                vehicleText = matched.vehicle_number + ' (' + matched.model + ')';
            }
        }
        setTextContent('qbSummaryVehicle', vehicleText);

        // 見積料金
        setTextContent('qbSummaryFare', bookingData.estimated_fare ? (Number(bookingData.estimated_fare).toLocaleString() + ' 円') : '未設定');
    }

    // =================================================================
    // 顧客検索
    // =================================================================

    function handleCustomerSearch(query) {
        query = (query || '').trim();
        var resultsEl = document.getElementById('qbCustomerResults');
        if (!resultsEl) return;

        if (query.length < 1) {
            resultsEl.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-user-plus fa-2x mb-2 d-block"></i>利用者名または電話番号を入力してください</div>';
            return;
        }

        resultsEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-success" role="status"></div><span class="ms-2 text-muted">検索中...</span></div>';

        // API呼び出し
        var csrfToken = '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) csrfToken = csrfMeta.content;

        fetch(CUSTOMER_SEARCH_API + '?q=' + encodeURIComponent(query), {
            method: 'GET',
            headers: {
                'X-CSRF-TOKEN': csrfToken
            }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success && data.data && data.data.length > 0) {
                renderCustomerResults(data.data);
            } else {
                // APIがない場合やデータなしの場合、フォールバック
                renderFallbackSearch(query);
            }
        })
        .catch(function() {
            // API未実装時のフォールバック：入力値をそのまま使用可能にする
            renderFallbackSearch(query);
        });
    }

    function renderFallbackSearch(query) {
        var resultsEl = document.getElementById('qbCustomerResults');
        if (!resultsEl) return;

        resultsEl.innerHTML =
            '<div class="qb-customer-item" data-action="use-query">' +
                '<div class="customer-icon"><i class="fas fa-user-plus"></i></div>' +
                '<div class="customer-info">' +
                    '<div class="customer-name">「' + escapeHtml(query) + '」で登録</div>' +
                    '<div class="customer-meta">入力した名前をそのまま使用します</div>' +
                '</div>' +
                '<div class="customer-arrow"><i class="fas fa-chevron-right"></i></div>' +
            '</div>';

        // クリックイベント
        var item = resultsEl.querySelector('[data-action="use-query"]');
        if (item) {
            item.addEventListener('click', function() {
                selectCustomerDirect(query, '', '');
            });
        }
    }

    function renderCustomerResults(customers) {
        var resultsEl = document.getElementById('qbCustomerResults');
        if (!resultsEl) return;

        var html = '';
        customers.forEach(function(c) {
            html +=
                '<div class="qb-customer-item" data-customer-id="' + (c.id || '') + '">' +
                    '<div class="customer-icon"><i class="fas fa-user"></i></div>' +
                    '<div class="customer-info">' +
                        '<div class="customer-name">' + escapeHtml(c.name || c.client_name || '') + '</div>' +
                        '<div class="customer-meta">' +
                            escapeHtml(c.phone || '') +
                            (c.address ? ' / ' + escapeHtml(c.address) : '') +
                        '</div>' +
                    '</div>' +
                    '<div class="customer-arrow"><i class="fas fa-chevron-right"></i></div>' +
                '</div>';
        });

        resultsEl.innerHTML = html;

        // クリックイベント
        resultsEl.querySelectorAll('.qb-customer-item').forEach(function(item) {
            item.addEventListener('click', function() {
                var id = this.dataset.customerId;
                var customer = customers.find(function(c) { return String(c.id) === String(id); });
                if (customer) {
                    selectCustomer(customer);
                }
            });
        });
    }

    function selectCustomer(customer) {
        bookingData.customer_id = customer.id;
        bookingData.client_name = customer.name || customer.client_name || '';
        bookingData.client_phone = customer.phone || '';

        // デフォルト値プリフィル
        if (customer.address) {
            bookingData.pickup_location = customer.address;
        }
        if (customer.frequent_destination) {
            bookingData.dropoff_location = customer.frequent_destination;
        }
        if (customer.service_type) {
            bookingData.service_type = customer.service_type;
        }
        if (customer.mobility_type) {
            bookingData.mobility_type = customer.mobility_type;
            autoAssignVehicle(customer.mobility_type);
        }
        if (customer.estimated_fare) {
            bookingData.estimated_fare = customer.estimated_fare;
        }

        showSelectedCustomer();
    }

    function selectCustomerDirect(name, phone, address) {
        bookingData.client_name = name;
        bookingData.client_phone = phone || '';
        if (address) {
            bookingData.pickup_location = address;
        }
        showSelectedCustomer();
    }

    function showSelectedCustomer() {
        var selectedEl = document.getElementById('qbSelectedCustomer');
        var resultsEl = document.getElementById('qbCustomerResults');
        var searchInput = document.getElementById('qbCustomerSearch');

        setTextContent('qbSelectedName', bookingData.client_name || '');
        setTextContent('qbSelectedPhone', bookingData.client_phone || '');
        setTextContent('qbSelectedAddress', bookingData.pickup_location || '');

        if (selectedEl) selectedEl.classList.remove('d-none');
        if (resultsEl) resultsEl.style.display = 'none';
        if (searchInput) searchInput.closest('.input-group').style.display = 'none';

        // 新規顧客フォームを非表示
        var newForm = document.getElementById('qbNewCustomerForm');
        if (newForm) newForm.classList.add('d-none');
        var toggleBtn = document.getElementById('qbNewCustomerToggle');
        if (toggleBtn) toggleBtn.style.display = 'none';
    }

    function deselectCustomer() {
        bookingData.client_name = '';
        bookingData.client_phone = '';
        bookingData.customer_id = null;

        var selectedEl = document.getElementById('qbSelectedCustomer');
        var resultsEl = document.getElementById('qbCustomerResults');
        var searchInput = document.getElementById('qbCustomerSearch');

        if (selectedEl) selectedEl.classList.add('d-none');
        if (resultsEl) resultsEl.style.display = '';
        if (searchInput) {
            searchInput.closest('.input-group').style.display = '';
            searchInput.value = '';
            searchInput.focus();
        }

        var toggleBtn = document.getElementById('qbNewCustomerToggle');
        if (toggleBtn) toggleBtn.style.display = '';
    }

    // =================================================================
    // 車両自動割当
    // =================================================================

    function autoAssignVehicle(mobilityType) {
        var vehicles = (window.calendarConfig || {}).vehicles || [];
        if (!vehicles.length) return;

        // mobilityType に基づいて車両を選択
        var matched = null;
        if (mobilityType === 'ストレッチャー') {
            matched = vehicles.find(function(v) { return v.model === 'ハイエース'; });
        } else if (mobilityType === '車いす' || mobilityType === 'リクライニング') {
            // 車いす対応車両を優先
            matched = vehicles.find(function(v) {
                return v.model === 'ハイエース' || v.model === 'セレナ';
            });
        }

        if (matched) {
            bookingData.vehicle_id = matched.id;
            bookingData.rental_service = mobilityType;
        }
    }

    // =================================================================
    // クイック日時ボタン
    // =================================================================

    function handleQuickDate(dateType) {
        var dateInput = document.getElementById('qbDate');
        if (!dateInput) return;

        var d = new Date();
        switch (dateType) {
            case 'today':
                break;
            case 'tomorrow':
                d.setDate(d.getDate() + 1);
                break;
            case 'day-after':
                d.setDate(d.getDate() + 2);
                break;
        }

        dateInput.value = formatDate(d);

        // ボタンハイライト
        document.querySelectorAll('.qb-quick-date').forEach(function(btn) {
            btn.classList.remove('active');
        });
        var activeBtn = document.querySelector('.qb-quick-date[data-date="' + dateType + '"]');
        if (activeBtn) activeBtn.classList.add('active');
    }

    function handleQuickTime(action) {
        var dateInput = document.getElementById('qbDate');
        var timeInput = document.getElementById('qbTime');
        if (!dateInput || !timeInput) return;

        var now = new Date();

        switch (action) {
            case 'next-slot':
                // 次の空き = 現在時刻の次の30分刻み（最低1時間後）
                if (!dateInput.value) {
                    dateInput.value = formatDate(now);
                }
                var nextSlot = new Date(now.getTime() + 60 * 60 * 1000);
                var mins = nextSlot.getMinutes();
                nextSlot.setMinutes(mins < 30 ? 30 : 0);
                if (mins >= 30) nextSlot.setHours(nextSlot.getHours() + 1);
                timeInput.value = formatTime(nextSlot);
                break;

            case 'today-pm':
                dateInput.value = formatDate(now);
                timeInput.value = '13:00';
                break;

            case 'tomorrow-am':
                var tomorrow = new Date(now);
                tomorrow.setDate(tomorrow.getDate() + 1);
                dateInput.value = formatDate(tomorrow);
                timeInput.value = '09:00';
                break;
        }

        // ボタンハイライト
        document.querySelectorAll('.qb-quick-time').forEach(function(btn) {
            btn.classList.remove('active');
        });
        var activeBtn = document.querySelector('.qb-quick-time[data-action="' + action + '"]');
        if (activeBtn) activeBtn.classList.add('active');
    }

    // =================================================================
    // 保存処理
    // =================================================================

    function handleSave() {
        if (isSubmitting) return;

        // 備考取得
        var notesVal = (document.getElementById('qbNotes').value || '').trim();
        if (notesVal) {
            bookingData.special_notes = notesVal;
        }

        // 保存データ組み立て
        var saveData = {
            reservation_date: bookingData.reservation_date,
            reservation_time: bookingData.reservation_time,
            client_name: bookingData.client_name,
            pickup_location: bookingData.pickup_location,
            dropoff_location: bookingData.dropoff_location,
            service_type: bookingData.service_type || DEFAULT_SERVICE_TYPE,
            passenger_count: bookingData.passenger_count || DEFAULT_PASSENGER_COUNT,
            rental_service: bookingData.rental_service || DEFAULT_RENTAL_SERVICE,
            referrer_type: bookingData.referrer_type || DEFAULT_REFERRER_TYPE,
            payment_method: bookingData.payment_method || DEFAULT_PAYMENT_METHOD,
            is_time_critical: bookingData.is_time_critical ? 1 : 0,
            special_notes: bookingData.special_notes || '',
            status: '予約'
        };

        // オプション項目
        if (bookingData.vehicle_id) {
            saveData.vehicle_id = bookingData.vehicle_id;
        }
        if (bookingData.estimated_fare) {
            saveData.estimated_fare = bookingData.estimated_fare;
        }

        isSubmitting = true;
        setModalLoading(true);

        var csrfToken = '';
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) csrfToken = csrfMeta.content;

        var saveUrl = (window.calendarConfig && window.calendarConfig.apiUrls)
            ? window.calendarConfig.apiUrls.saveReservation
            : 'api/save_reservation.php';

        fetch(saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify(saveData)
        })
        .then(function(response) {
            return response.text().then(function(text) {
                if (text.trim().startsWith('<') || text.includes('<br />') || text.includes('Warning:') || text.includes('Fatal error:')) {
                    throw new Error('サーバーエラーが発生しました。');
                }
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('無効なレスポンス形式です');
                }
            });
        })
        .then(function(result) {
            if (result.success) {
                // モーダル閉じる
                if (quickBookingModal) quickBookingModal.hide();

                // 成功トースト
                showSuccessToast(result.message || '予約を作成しました');

                // カレンダー更新
                if (window.calendarMethods) {
                    window.calendarMethods.refreshEvents();
                }
            } else {
                throw new Error(result.message || '保存に失敗しました');
            }
        })
        .catch(function(error) {
            showQuickNotification(error.message, 'error');
        })
        .finally(function() {
            isSubmitting = false;
            setModalLoading(false);
        });
    }

    // =================================================================
    // 詳細入力への切り替え
    // =================================================================

    function switchToFullModal() {
        // 現在のクイック予約データを通常モーダルに引き継ぐ
        var transferData = {
            reservation_date: bookingData.reservation_date || document.getElementById('qbDate').value,
            reservation_time: bookingData.reservation_time || document.getElementById('qbTime').value,
            client_name: bookingData.client_name || '',
            pickup_location: bookingData.pickup_location || document.getElementById('qbPickup').value,
            dropoff_location: bookingData.dropoff_location || document.getElementById('qbDropoff').value,
            service_type: bookingData.service_type || DEFAULT_SERVICE_TYPE,
            rental_service: bookingData.rental_service || DEFAULT_RENTAL_SERVICE,
            vehicle_id: bookingData.vehicle_id || '',
            estimated_fare: bookingData.estimated_fare || ''
        };

        // クイック予約モーダルを閉じる
        if (quickBookingModal) quickBookingModal.hide();

        // 少し待ってから通常モーダルを開く（Bootstrap modal stacking対策）
        setTimeout(function() {
            if (typeof window.openReservationModal === 'function') {
                window.openReservationModal('create', transferData);
            }
        }, 400);
    }

    // =================================================================
    // UI ヘルパー
    // =================================================================

    function setModalLoading(loading) {
        var body = document.querySelector('#quickBookingModal .modal-body');
        var btns = document.querySelectorAll('#quickBookingModal .modal-footer .btn');

        if (loading) {
            if (body) body.classList.add('qb-loading');
            btns.forEach(function(b) { b.disabled = true; });
        } else {
            if (body) body.classList.remove('qb-loading');
            btns.forEach(function(b) { b.disabled = false; });
        }
    }

    function showQuickNotification(message, type) {
        if (type === 'error' || type === 'warning') {
            // Bootstrap alert inside modal
            var body = document.querySelector('#quickBookingModal .modal-body');
            if (!body) return;

            // 既存のアラートを削除
            var existing = body.querySelector('.qb-alert');
            if (existing) existing.remove();

            var alertClass = type === 'error' ? 'alert-danger' : 'alert-warning';
            var alert = document.createElement('div');
            alert.className = 'alert ' + alertClass + ' alert-dismissible fade show qb-alert mb-2';
            alert.setAttribute('role', 'alert');
            alert.innerHTML = escapeHtml(message) +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="閉じる"></button>';
            body.insertBefore(alert, body.firstChild);

            // 3秒後に自動削除
            setTimeout(function() {
                if (alert.parentNode) alert.remove();
            }, 3000);
        } else {
            // 成功: calendarMethodsのnotificationを使用
            if (window.calendarMethods) {
                window.calendarMethods.showNotification(message, type || 'info');
            }
        }
    }

    function showSuccessToast(message) {
        // 既存のトーストを削除
        var existing = document.querySelector('.qb-toast-success');
        if (existing) existing.remove();

        var toast = document.createElement('div');
        toast.className = 'qb-toast-success';
        toast.innerHTML =
            '<div class="toast show align-items-center text-bg-success border-0" role="alert">' +
                '<div class="d-flex">' +
                    '<div class="toast-body fs-6">' +
                        '<i class="fas fa-check-circle me-2"></i>' + escapeHtml(message) +
                    '</div>' +
                    '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="閉じる"></button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(toast);

        setTimeout(function() {
            if (toast.parentNode) {
                toast.style.transition = 'opacity 0.3s';
                toast.style.opacity = '0';
                setTimeout(function() { toast.remove(); }, 300);
            }
        }, 4000);
    }

    function setTextContent(id, text) {
        var el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(d) {
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function formatTime(d) {
        var h = String(d.getHours()).padStart(2, '0');
        var m = String(d.getMinutes()).padStart(2, '0');
        return h + ':' + m;
    }

    // =================================================================
    // イベントリスナー設定
    // =================================================================

    function setupEventListeners() {
        // 次へ/保存ボタン
        var nextBtn = document.getElementById('qbNextBtn');
        if (nextBtn) {
            nextBtn.addEventListener('click', function() {
                if (currentStep === STEP_CONFIRM) {
                    handleSave();
                } else {
                    if (validateCurrentStep()) {
                        goToStep(currentStep + 1);
                    }
                }
            });
        }

        // 戻るボタン
        var prevBtn = document.getElementById('qbPrevBtn');
        if (prevBtn) {
            prevBtn.addEventListener('click', function() {
                if (currentStep > STEP_CUSTOMER) {
                    goToStep(currentStep - 1);
                }
            });
        }

        // 詳細入力ボタン
        var detailBtn = document.getElementById('qbDetailBtn');
        if (detailBtn) {
            detailBtn.addEventListener('click', switchToFullModal);
        }

        // 顧客検索
        var searchInput = document.getElementById('qbCustomerSearch');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                var q = this.value;
                clearTimeout(customerSearchTimer);
                customerSearchTimer = setTimeout(function() {
                    handleCustomerSearch(q);
                }, 300);
            });
        }

        // 検索クリア
        var clearBtn = document.getElementById('qbClearSearch');
        if (clearBtn) {
            clearBtn.addEventListener('click', function() {
                var input = document.getElementById('qbCustomerSearch');
                if (input) {
                    input.value = '';
                    input.focus();
                }
                var resultsEl = document.getElementById('qbCustomerResults');
                if (resultsEl) {
                    resultsEl.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-user-plus fa-2x mb-2 d-block"></i>利用者名または電話番号を入力してください</div>';
                }
            });
        }

        // 顧客選択解除
        var deselectBtn = document.getElementById('qbDeselectCustomer');
        if (deselectBtn) {
            deselectBtn.addEventListener('click', deselectCustomer);
        }

        // 新規顧客トグル
        var newCustomerToggle = document.getElementById('qbNewCustomerToggle');
        if (newCustomerToggle) {
            newCustomerToggle.addEventListener('click', function() {
                var form = document.getElementById('qbNewCustomerForm');
                if (form) {
                    form.classList.toggle('d-none');
                    if (!form.classList.contains('d-none')) {
                        var nameInput = document.getElementById('qbNewClientName');
                        if (nameInput) nameInput.focus();
                    }
                }
            });
        }

        // クイック日付ボタン
        document.querySelectorAll('.qb-quick-date').forEach(function(btn) {
            btn.addEventListener('click', function() {
                handleQuickDate(this.dataset.date);
            });
        });

        // クイック時刻ボタン
        document.querySelectorAll('.qb-quick-time').forEach(function(btn) {
            btn.addEventListener('click', function() {
                handleQuickTime(this.dataset.action);
            });
        });

        // サービス種別ボタン
        document.querySelectorAll('.qb-service-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.qb-service-btn').forEach(function(b) {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                bookingData.service_type = this.dataset.value;
            });
        });

        // 既存の「新規予約作成」ボタンに簡易入力トグルを追加
        var createBtn = document.getElementById('createReservationBtn');
        if (createBtn) {
            // 簡易入力ボタンを隣に追加
            var quickToggle = document.createElement('button');
            quickToggle.type = 'button';
            quickToggle.className = 'btn btn-outline-success flex-grow-1 flex-md-grow-0';
            quickToggle.id = 'quickBookingToggleBtn';
            quickToggle.innerHTML = '<i class="fas fa-bolt me-1"></i>簡易入力';
            quickToggle.addEventListener('click', function() {
                openQuickBooking();
            });

            // ボタングループの親に追加
            var parentEl = createBtn.parentElement;
            if (parentEl) {
                parentEl.insertBefore(quickToggle, createBtn.nextSibling);
            }
        }
    }

    // =================================================================
    // Step2 プリフィル（既存データ反映）
    // =================================================================

    function prefillStep2() {
        var dateInput = document.getElementById('qbDate');
        var timeInput = document.getElementById('qbTime');
        var pickupInput = document.getElementById('qbPickup');
        var dropoffInput = document.getElementById('qbDropoff');

        if (dateInput && bookingData.reservation_date) {
            dateInput.value = bookingData.reservation_date;
        }
        if (timeInput && bookingData.reservation_time) {
            timeInput.value = bookingData.reservation_time;
        }
        if (pickupInput && bookingData.pickup_location) {
            pickupInput.value = bookingData.pickup_location;
        }
        if (dropoffInput && bookingData.dropoff_location) {
            dropoffInput.value = bookingData.dropoff_location;
        }

        // サービス種別ボタンの反映
        if (bookingData.service_type) {
            document.querySelectorAll('.qb-service-btn').forEach(function(btn) {
                btn.classList.remove('active');
                if (btn.dataset.value === bookingData.service_type) {
                    btn.classList.add('active');
                }
            });
        }
    }

    // goToStep のオーバーライド: Step2に行くときにプリフィル
    var originalGoToStep = goToStep;
    goToStep = function(step) {
        originalGoToStep(step);
        if (step === STEP_DATETIME) {
            prefillStep2();
        }
    };

    // =================================================================
    // 初期化
    // =================================================================

    function initialize() {
        injectQuickBookingUI();
        setupEventListeners();
    }

    // DOM準備完了後に初期化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }

    // =================================================================
    // 外部公開API
    // =================================================================

    window.openQuickBooking = openQuickBooking;

})();
