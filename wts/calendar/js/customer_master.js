// =================================================================
// 顧客マスター管理 JavaScript
//
// ファイル: /Smiley/taxi/wts/calendar/js/customer_master.js
// 機能: 顧客オートコンプリート・詳細パネル・顧客CRUD管理
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2026年3月11日
// =================================================================

(function() {
    'use strict';

    // =========================================================
    // スタイル注入
    // =========================================================

    const customerStyles = document.createElement('style');
    customerStyles.textContent = `
        /* オートコンプリート ドロップダウン */
        .cm-autocomplete-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 1060;
            max-height: 320px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
        }
        .cm-autocomplete-dropdown.show {
            display: block;
        }
        .cm-autocomplete-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.15s;
        }
        .cm-autocomplete-item:last-child {
            border-bottom: none;
        }
        .cm-autocomplete-item:hover,
        .cm-autocomplete-item.active {
            background-color: #e9ecef;
        }
        .cm-autocomplete-item .cm-item-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #212529;
        }
        .cm-autocomplete-item .cm-item-kana {
            font-size: 0.75rem;
            color: #6c757d;
            margin-left: 6px;
        }
        .cm-autocomplete-item .cm-item-sub {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cm-autocomplete-item .cm-item-phone {
            color: #0d6efd;
            margin-right: 10px;
        }
        .cm-autocomplete-item .cm-item-address {
            color: #6c757d;
        }
        .cm-autocomplete-item.cm-new-customer {
            background-color: #f8f9fa;
            border-top: 2px solid #dee2e6;
            color: #198754;
            font-weight: 500;
            text-align: center;
        }
        .cm-autocomplete-item.cm-new-customer:hover {
            background-color: #d1e7dd;
        }
        .cm-autocomplete-item .cm-match-highlight {
            background-color: #fff3cd;
            border-radius: 2px;
            padding: 0 1px;
        }
        .cm-autocomplete-loading {
            padding: 12px;
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
        }
        .cm-autocomplete-empty {
            padding: 12px;
            text-align: center;
            color: #6c757d;
            font-size: 0.85rem;
        }

        /* 顧客詳細パネル（バッジ） */
        .cm-detail-panel {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 8px 12px;
            margin-top: 6px;
            font-size: 0.82rem;
            position: relative;
            animation: cmSlideDown 0.2s ease-out;
        }
        @keyframes cmSlideDown {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .cm-detail-panel .cm-detail-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .cm-detail-panel .cm-detail-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.78rem;
            font-weight: 500;
        }
        .cm-detail-panel .cm-care-level {
            background-color: #cfe2ff;
            color: #084298;
        }
        .cm-detail-panel .cm-mobility {
            background-color: #d1e7dd;
            color: #0f5132;
        }
        .cm-detail-panel .cm-notes-badge {
            background-color: #fff3cd;
            color: #664d03;
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .cm-detail-panel .cm-detail-links {
            margin-left: auto;
            display: flex;
            gap: 8px;
        }
        .cm-detail-panel .cm-detail-links a {
            font-size: 0.78rem;
            text-decoration: none;
            cursor: pointer;
        }
        .cm-detail-panel .cm-clear-btn {
            position: absolute;
            top: 4px;
            right: 6px;
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0 4px;
            line-height: 1;
        }
        .cm-detail-panel .cm-clear-btn:hover {
            color: #dc3545;
        }

        /* 顧客管理モーダル */
        .cm-modal .modal-dialog {
            max-width: 800px;
        }
        .cm-modal .cm-section-header {
            font-weight: 600;
            font-size: 0.95rem;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 6px;
            margin-bottom: 12px;
            margin-top: 16px;
        }
        .cm-modal .cm-section-header:first-child {
            margin-top: 0;
        }
        .cm-modal .cm-dest-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .cm-modal .cm-dest-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .cm-modal .cm-dest-item:last-child {
            border-bottom: none;
        }
        .cm-modal .cm-dest-item .cm-dest-name {
            flex: 1;
            font-size: 0.9rem;
        }
        .cm-modal .cm-dest-item .cm-dest-type {
            font-size: 0.78rem;
            color: #6c757d;
        }
        .cm-modal .cm-dest-remove {
            color: #dc3545;
            cursor: pointer;
            border: none;
            background: none;
            font-size: 0.85rem;
            padding: 2px 6px;
        }
        .cm-modal .cm-history-table {
            font-size: 0.85rem;
        }
        .cm-modal .cm-history-table td {
            padding: 4px 8px;
        }

        /* 入力フィールドのラッパー（autocomplete用） */
        .cm-input-wrapper {
            position: relative;
        }
    `;
    document.head.appendChild(customerStyles);

    // =========================================================
    // ユーティリティ
    // =========================================================

    function escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function debounce(fn, delay) {
        let timer = null;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), delay);
        };
    }

    function showNotification(message, type) {
        if (window.calendarMethods && window.calendarMethods.showNotification) {
            window.calendarMethods.showNotification(message, type);
        }
    }

    // =========================================================
    // CustomerAutocomplete クラス
    // =========================================================

    class CustomerAutocomplete {
        constructor(options = {}) {
            this.inputId = options.inputId || 'clientName';
            this.apiUrl = options.apiUrl || 'api/get_customers.php';
            this.minChars = options.minChars || 2;
            this.debounceMs = options.debounceMs || 300;
            this.onSelect = options.onSelect || null;

            this.input = null;
            this.dropdown = null;
            this.detailPanel = null;
            this.hiddenInput = null;
            this.selectedIndex = -1;
            this.items = [];
            this.isOpen = false;
            this.selectedCustomer = null;

            this._init();
        }

        _init() {
            this.input = document.getElementById(this.inputId);
            if (!this.input) return;

            // 入力フィールドをラッパーで囲む
            const wrapper = document.createElement('div');
            wrapper.className = 'cm-input-wrapper';
            this.input.parentNode.insertBefore(wrapper, this.input);
            wrapper.appendChild(this.input);

            // hidden field for customer_id (use existing field if present, otherwise create one)
            this.hiddenInput = document.getElementById('customer_id');
            if (!this.hiddenInput) {
                this.hiddenInput = document.createElement('input');
                this.hiddenInput.type = 'hidden';
                this.hiddenInput.name = 'customer_id';
                this.hiddenInput.id = 'customer_id';
                wrapper.appendChild(this.hiddenInput);
            }

            // ドロップダウン作成
            this.dropdown = document.createElement('div');
            this.dropdown.className = 'cm-autocomplete-dropdown';
            wrapper.appendChild(this.dropdown);

            // イベント
            this.input.addEventListener('input', debounce(() => this._onInput(), this.debounceMs));
            this.input.addEventListener('keydown', (e) => this._onKeydown(e));
            this.input.addEventListener('focus', () => {
                if (this.input.value.trim().length >= this.minChars && this.items.length > 0) {
                    this._showDropdown();
                }
            });

            // 外側クリックで閉じる
            document.addEventListener('click', (e) => {
                if (!wrapper.contains(e.target)) {
                    this._hideDropdown();
                }
            });

            // モーダルhiddenイベントで状態リセット
            const modal = this.input.closest('.modal');
            if (modal) {
                modal.addEventListener('hidden.bs.modal', () => this.reset());
            }
        }

        _onInput() {
            const query = this.input.value.trim();

            // 顧客選択済みの場合、手入力で変更されたらクリア
            if (this.selectedCustomer) {
                this._clearSelection();
            }

            if (query.length < this.minChars) {
                this._hideDropdown();
                return;
            }

            this._search(query);
        }

        _search(query) {
            this.dropdown.innerHTML = '<div class="cm-autocomplete-loading"><i class="fas fa-spinner fa-spin me-1"></i>検索中...</div>';
            this._showDropdown();

            const params = new URLSearchParams({ q: query });

            fetch(`${this.apiUrl}?${params}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    this.items = result.data || [];
                    this._renderResults(query);
                } else {
                    this.items = [];
                    this.dropdown.innerHTML = '<div class="cm-autocomplete-empty">検索に失敗しました</div>';
                }
            })
            .catch(() => {
                this.items = [];
                this.dropdown.innerHTML = '<div class="cm-autocomplete-empty">通信エラーが発生しました</div>';
            });
        }

        _renderResults(query) {
            this.selectedIndex = -1;
            let html = '';

            if (this.items.length === 0) {
                html = '<div class="cm-autocomplete-empty">該当する顧客が見つかりません</div>';
            } else {
                this.items.forEach((item, index) => {
                    const name = this._highlight(escapeHtml(item.name || ''), query);
                    const kana = item.name_kana ? this._highlight(escapeHtml(item.name_kana), query) : '';
                    const phone = item.phone ? this._highlight(escapeHtml(item.phone), query) : '';
                    const address = escapeHtml(item.address || '');

                    html += `
                        <div class="cm-autocomplete-item" data-index="${index}">
                            <div>
                                <span class="cm-item-name">${name}</span>
                                ${kana ? '<span class="cm-item-kana">' + kana + '</span>' : ''}
                            </div>
                            <div class="cm-item-sub">
                                ${phone ? '<span class="cm-item-phone"><i class="fas fa-phone-alt me-1"></i>' + phone + '</span>' : ''}
                                ${address ? '<span class="cm-item-address"><i class="fas fa-map-marker-alt me-1"></i>' + address + '</span>' : ''}
                            </div>
                        </div>
                    `;
                });
            }

            // 新規顧客オプション
            html += `
                <div class="cm-autocomplete-item cm-new-customer" data-action="new">
                    <i class="fas fa-plus-circle me-1"></i>新規顧客として登録
                </div>
            `;

            this.dropdown.innerHTML = html;

            // クリックイベント（イベント委任）
            this.dropdown.querySelectorAll('.cm-autocomplete-item').forEach(el => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault(); // blurを防止
                    const idx = el.dataset.index;
                    const action = el.dataset.action;

                    if (action === 'new') {
                        this._hideDropdown();
                        this._openNewCustomerModal();
                    } else if (idx !== undefined) {
                        this._selectItem(parseInt(idx));
                    }
                });
            });

            if (this.items.length > 0 || true) {
                this._showDropdown();
            }
        }

        _highlight(text, query) {
            if (!query) return text;
            // 各文字をエスケープしてから正規表現にする
            const escaped = query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp('(' + escaped + ')', 'gi');
            return text.replace(regex, '<span class="cm-match-highlight">$1</span>');
        }

        _selectItem(index) {
            if (index < 0 || index >= this.items.length) return;

            const customer = this.items[index];
            this.selectedCustomer = customer;

            // フォームへのデータ反映
            this.input.value = customer.name || '';
            this.hiddenInput.value = customer.id || '';

            // 電話番号
            const phoneField = document.getElementById('clientPhone');
            if (phoneField && customer.phone) {
                phoneField.value = customer.phone;
            }

            // 住所 → 乗車場所
            const pickupField = document.getElementById('pickupLocation');
            if (pickupField && customer.address) {
                pickupField.value = customer.address;
            }

            // よく行く目的地があれば降車場所の候補として設定
            const dropoffField = document.getElementById('dropoffLocation');
            if (dropoffField && customer.frequent_destination) {
                dropoffField.value = customer.frequent_destination;
            }

            this._hideDropdown();

            // 詳細パネル表示
            this._showDetailPanel(customer);

            // コールバック
            if (typeof this.onSelect === 'function') {
                this.onSelect(customer);
            }
        }

        _onKeydown(e) {
            if (!this.isOpen) return;

            const totalItems = this.dropdown.querySelectorAll('.cm-autocomplete-item').length;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    this.selectedIndex = Math.min(this.selectedIndex + 1, totalItems - 1);
                    this._updateActiveItem();
                    break;

                case 'ArrowUp':
                    e.preventDefault();
                    this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                    this._updateActiveItem();
                    break;

                case 'Enter':
                    e.preventDefault();
                    if (this.selectedIndex >= 0) {
                        const activeItem = this.dropdown.querySelectorAll('.cm-autocomplete-item')[this.selectedIndex];
                        if (activeItem) {
                            const action = activeItem.dataset.action;
                            const idx = activeItem.dataset.index;
                            if (action === 'new') {
                                this._hideDropdown();
                                this._openNewCustomerModal();
                            } else if (idx !== undefined) {
                                this._selectItem(parseInt(idx));
                            }
                        }
                    }
                    break;

                case 'Escape':
                    e.preventDefault();
                    this._hideDropdown();
                    break;
            }
        }

        _updateActiveItem() {
            const items = this.dropdown.querySelectorAll('.cm-autocomplete-item');
            items.forEach((item, i) => {
                item.classList.toggle('active', i === this.selectedIndex);
            });

            // スクロール追従
            if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                items[this.selectedIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        _showDropdown() {
            this.dropdown.classList.add('show');
            this.isOpen = true;
        }

        _hideDropdown() {
            this.dropdown.classList.remove('show');
            this.isOpen = false;
            this.selectedIndex = -1;
        }

        _clearSelection() {
            this.selectedCustomer = null;
            this.hiddenInput.value = '';
            this._removeDetailPanel();
        }

        _showDetailPanel(customer) {
            this._removeDetailPanel();

            const wrapper = this.input.closest('.cm-input-wrapper');
            if (!wrapper) return;

            const panel = document.createElement('div');
            panel.className = 'cm-detail-panel';

            const careLevel = customer.care_level || '';
            const mobility = customer.mobility_type || '';
            const notes = customer.notes || '';
            const historyCount = customer.reservation_count || 0;

            let badgesHtml = '';
            if (careLevel) {
                badgesHtml += `<span class="cm-detail-badge cm-care-level"><i class="fas fa-heart me-1"></i>${escapeHtml(careLevel)}</span>`;
            }
            if (mobility) {
                badgesHtml += `<span class="cm-detail-badge cm-mobility"><i class="fas fa-wheelchair me-1"></i>${escapeHtml(mobility)}</span>`;
            }
            if (notes) {
                badgesHtml += `<span class="cm-detail-badge cm-notes-badge" title="${escapeHtml(notes)}"><i class="fas fa-sticky-note me-1"></i>${escapeHtml(notes)}</span>`;
            }

            panel.innerHTML = `
                <button type="button" class="cm-clear-btn" title="選択解除">&times;</button>
                <div class="cm-detail-row">
                    ${badgesHtml}
                    <span class="cm-detail-links">
                        <a href="javascript:void(0)" class="cm-link-detail text-primary"><i class="fas fa-user-edit me-1"></i>顧客詳細</a>
                        <a href="javascript:void(0)" class="cm-link-history text-info"><i class="fas fa-history me-1"></i>履歴(${historyCount})</a>
                    </span>
                </div>
            `;

            wrapper.appendChild(panel);
            this.detailPanel = panel;

            // イベント
            panel.querySelector('.cm-clear-btn').addEventListener('click', () => {
                this._clearSelection();
                this.input.value = '';
                this.input.focus();
            });

            panel.querySelector('.cm-link-detail').addEventListener('click', () => {
                if (this.selectedCustomer) {
                    window.CustomerManagementModal.open(this.selectedCustomer.id);
                }
            });

            panel.querySelector('.cm-link-history').addEventListener('click', () => {
                if (this.selectedCustomer) {
                    window.CustomerManagementModal.open(this.selectedCustomer.id, 'history');
                }
            });
        }

        _removeDetailPanel() {
            if (this.detailPanel) {
                this.detailPanel.remove();
                this.detailPanel = null;
            }
        }

        _openNewCustomerModal() {
            const nameValue = this.input.value.trim();
            window.CustomerManagementModal.open(null, 'edit', { name: nameValue });
        }

        /**
         * 外部から呼び出し可能：顧客選択状態をセット
         */
        setCustomer(customer) {
            if (!customer) return;
            this.selectedCustomer = customer;
            this.input.value = customer.name || '';
            this.hiddenInput.value = customer.id || '';
            this._showDetailPanel(customer);
        }

        /**
         * 外部から呼び出し可能：顧客IDで読み込み＆選択状態をセット
         */
        loadCustomer(customerId) {
            if (!customerId) return;
            fetch(`${this.apiUrl}?id=${encodeURIComponent(customerId)}`, {
                method: 'GET',
                headers: { 'X-CSRF-TOKEN': getCsrfToken() }
            })
            .then(res => res.json())
            .then(result => {
                if (result.success && result.data) {
                    this.setCustomer(result.data);
                }
            })
            .catch(() => {});
        }

        /**
         * 現在選択中の顧客を取得
         */
        getSelectedCustomer() {
            return this.selectedCustomer;
        }

        /**
         * 状態リセット
         */
        reset() {
            this.selectedCustomer = null;
            this.items = [];
            this.hiddenInput.value = '';
            this._hideDropdown();
            this._removeDetailPanel();
        }
    }

    // =========================================================
    // CustomerDetailPanel (スタンドアロン利用向け)
    // =========================================================

    class CustomerDetailPanel {
        constructor(containerEl, options = {}) {
            this.container = typeof containerEl === 'string'
                ? document.querySelector(containerEl)
                : containerEl;
            this.customer = null;
            this.onClear = options.onClear || null;
        }

        show(customer) {
            if (!this.container || !customer) return;
            this.customer = customer;

            this.container.innerHTML = '';

            const panel = document.createElement('div');
            panel.className = 'cm-detail-panel';

            const careLevel = customer.care_level || '';
            const mobility = customer.mobility_type || '';
            const notes = customer.notes || '';
            const historyCount = customer.reservation_count || 0;

            let badgesHtml = '';
            if (careLevel) {
                badgesHtml += `<span class="cm-detail-badge cm-care-level"><i class="fas fa-heart me-1"></i>${escapeHtml(careLevel)}</span>`;
            }
            if (mobility) {
                badgesHtml += `<span class="cm-detail-badge cm-mobility"><i class="fas fa-wheelchair me-1"></i>${escapeHtml(mobility)}</span>`;
            }
            if (notes) {
                badgesHtml += `<span class="cm-detail-badge cm-notes-badge" title="${escapeHtml(notes)}"><i class="fas fa-sticky-note me-1"></i>${escapeHtml(notes)}</span>`;
            }

            panel.innerHTML = `
                <button type="button" class="cm-clear-btn" title="選択解除">&times;</button>
                <div class="cm-detail-row">
                    ${badgesHtml}
                    <span class="cm-detail-links">
                        <a href="javascript:void(0)" class="cm-link-detail text-primary"><i class="fas fa-user-edit me-1"></i>顧客詳細</a>
                        <a href="javascript:void(0)" class="cm-link-history text-info"><i class="fas fa-history me-1"></i>履歴(${historyCount})</a>
                    </span>
                </div>
            `;

            this.container.appendChild(panel);

            panel.querySelector('.cm-clear-btn').addEventListener('click', () => {
                this.clear();
                if (typeof this.onClear === 'function') this.onClear();
            });

            panel.querySelector('.cm-link-detail').addEventListener('click', () => {
                if (this.customer) {
                    window.CustomerManagementModal.open(this.customer.id);
                }
            });

            panel.querySelector('.cm-link-history').addEventListener('click', () => {
                if (this.customer) {
                    window.CustomerManagementModal.open(this.customer.id, 'history');
                }
            });
        }

        clear() {
            this.customer = null;
            if (this.container) this.container.innerHTML = '';
        }
    }

    // =========================================================
    // CustomerManagementModal
    // =========================================================

    const CustomerManagementModal = {
        _modal: null,
        _modalEl: null,
        _customerId: null,
        _destinations: [],
        _activeTab: 'edit',
        _onSaveCallback: null,

        /**
         * モーダルを開く
         * @param {number|null} customerId - null=新規作成
         * @param {string} tab - 'edit' or 'history'
         * @param {object} prefill - 新規作成時の初期値
         */
        open(customerId, tab, prefill) {
            this._customerId = customerId || null;
            this._activeTab = tab || 'edit';
            this._destinations = [];

            this._ensureModalExists();

            // フォームリセット
            const form = document.getElementById('cmCustomerForm');
            if (form) form.reset();
            document.getElementById('cmCustomerId').value = '';

            if (customerId) {
                this._loadCustomer(customerId);
            } else if (prefill) {
                this._applyPrefill(prefill);
            }

            this._updateTabView();
            this._modal.show();
        },

        close() {
            if (this._modal) this._modal.hide();
        },

        onSave(callback) {
            this._onSaveCallback = callback;
        },

        _ensureModalExists() {
            if (this._modalEl) return;

            const html = `
            <div class="modal fade cm-modal" id="customerManagementModal" tabindex="-1" aria-hidden="true" style="z-index:1065">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="cmModalTitle">
                                <i class="fas fa-user-plus me-2"></i>顧客情報
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
                        </div>
                        <div class="modal-body">
                            <!-- タブ -->
                            <ul class="nav nav-tabs mb-3" id="cmTabs" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link active" id="cmTabEdit" data-cm-tab="edit" type="button" role="tab">
                                        <i class="fas fa-edit me-1"></i>顧客情報
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button class="nav-link" id="cmTabHistory" data-cm-tab="history" type="button" role="tab">
                                        <i class="fas fa-history me-1"></i>予約履歴
                                    </button>
                                </li>
                            </ul>

                            <!-- 編集タブ -->
                            <div id="cmPanelEdit">
                                <form id="cmCustomerForm" novalidate>
                                    <input type="hidden" id="cmCustomerId" name="id">

                                    <div class="cm-section-header"><i class="fas fa-id-card me-1"></i>基本情報</div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="cmName" class="form-label">氏名 <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="cmName" name="name" required placeholder="例: 山田太郎">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="cmNameKana" class="form-label">フリガナ</label>
                                            <input type="text" class="form-control" id="cmNameKana" name="name_kana" placeholder="例: ヤマダタロウ">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="cmPhone" class="form-label">電話番号</label>
                                            <input type="tel" class="form-control" id="cmPhone" name="phone" placeholder="例: 090-1234-5678">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="cmPhoneSub" class="form-label">電話番号（予備）</label>
                                            <input type="tel" class="form-control" id="cmPhoneSub" name="phone_sub" placeholder="例: 06-1234-5678">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="cmAddress" class="form-label">住所</label>
                                            <input type="text" class="form-control" id="cmAddress" name="address" placeholder="例: 大阪市北区梅田1-2-3">
                                        </div>
                                    </div>

                                    <div class="cm-section-header"><i class="fas fa-heartbeat me-1"></i>介護情報</div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="cmCareLevel" class="form-label">介護度</label>
                                            <select class="form-select" id="cmCareLevel" name="care_level">
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
                                        <div class="col-md-4 mb-3">
                                            <label for="cmMobilityType" class="form-label">移動区分</label>
                                            <select class="form-select" id="cmMobilityType" name="mobility_type">
                                                <option value="">未設定</option>
                                                <option value="独歩">独歩</option>
                                                <option value="杖">杖</option>
                                                <option value="歩行器">歩行器</option>
                                                <option value="車椅子">車椅子</option>
                                                <option value="リクライニング車椅子">リクライニング車椅子</option>
                                                <option value="ストレッチャー">ストレッチャー</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="cmDisabilityGrade" class="form-label">障害等級</label>
                                            <input type="text" class="form-control" id="cmDisabilityGrade" name="disability_grade" placeholder="例: 1級">
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12 mb-3">
                                            <label for="cmNotes" class="form-label">備考・注意事項</label>
                                            <textarea class="form-control" id="cmNotes" name="notes" rows="3" placeholder="配車時の注意事項など"></textarea>
                                        </div>
                                    </div>

                                    <div class="cm-section-header"><i class="fas fa-map-marked-alt me-1"></i>よく行く目的地</div>
                                    <div id="cmDestinations">
                                        <ul class="cm-dest-list" id="cmDestList"></ul>
                                        <div class="d-flex gap-2 mt-2">
                                            <input type="text" class="form-control form-control-sm" id="cmNewDestName" placeholder="目的地名（例: ○○病院）">
                                            <input type="text" class="form-control form-control-sm" id="cmNewDestAddress" placeholder="住所">
                                            <button type="button" class="btn btn-sm btn-outline-success" id="cmAddDestBtn" style="white-space:nowrap">
                                                <i class="fas fa-plus"></i> 追加
                                            </button>
                                        </div>
                                    </div>

                                    <div class="cm-section-header"><i class="fas fa-user-tie me-1"></i>紹介者・ケアマネ情報</div>
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label for="cmCareManagerName" class="form-label">ケアマネ氏名</label>
                                            <input type="text" class="form-control" id="cmCareManagerName" name="care_manager_name" placeholder="例: 鈴木花子">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="cmCareManagerPhone" class="form-label">ケアマネ電話</label>
                                            <input type="tel" class="form-control" id="cmCareManagerPhone" name="care_manager_phone">
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label for="cmFacilityName" class="form-label">所属事業所</label>
                                            <input type="text" class="form-control" id="cmFacilityName" name="facility_name">
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- 履歴タブ -->
                            <div id="cmPanelHistory" style="display:none">
                                <div id="cmHistoryContent">
                                    <div class="text-center text-muted py-4">
                                        <i class="fas fa-spinner fa-spin me-1"></i>読み込み中...
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">閉じる</button>
                            <button type="button" class="btn btn-danger d-none" id="cmDeleteBtn">
                                <i class="fas fa-trash me-1"></i>削除
                            </button>
                            <button type="button" class="btn btn-primary" id="cmSaveBtn">
                                <i class="fas fa-save me-1"></i>保存
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            `;

            // backdrop用にz-index調整
            const container = document.createElement('div');
            container.innerHTML = html;
            document.body.appendChild(container.firstElementChild);

            this._modalEl = document.getElementById('customerManagementModal');
            this._modal = new bootstrap.Modal(this._modalEl, {
                backdrop: 'static'
            });

            // タブ切り替え
            this._modalEl.querySelectorAll('[data-cm-tab]').forEach(btn => {
                btn.addEventListener('click', () => {
                    this._activeTab = btn.dataset.cmTab;
                    this._updateTabView();
                });
            });

            // 保存
            document.getElementById('cmSaveBtn').addEventListener('click', () => this._save());

            // 削除
            document.getElementById('cmDeleteBtn').addEventListener('click', () => this._delete());

            // 目的地追加
            document.getElementById('cmAddDestBtn').addEventListener('click', () => this._addDestination());

            // backdrop z-index 調整（予約モーダルの上に表示）
            this._modalEl.addEventListener('shown.bs.modal', () => {
                // Bootstrap のbackdropのz-indexを調整
                const backdrops = document.querySelectorAll('.modal-backdrop');
                if (backdrops.length > 1) {
                    backdrops[backdrops.length - 1].style.zIndex = '1064';
                }
            });
        },

        _updateTabView() {
            const editTab = document.getElementById('cmTabEdit');
            const historyTab = document.getElementById('cmTabHistory');
            const editPanel = document.getElementById('cmPanelEdit');
            const historyPanel = document.getElementById('cmPanelHistory');
            const saveBtn = document.getElementById('cmSaveBtn');
            const deleteBtn = document.getElementById('cmDeleteBtn');

            if (this._activeTab === 'edit') {
                editTab.classList.add('active');
                historyTab.classList.remove('active');
                editPanel.style.display = '';
                historyPanel.style.display = 'none';
                saveBtn.style.display = '';
                if (this._customerId) {
                    deleteBtn.classList.remove('d-none');
                } else {
                    deleteBtn.classList.add('d-none');
                }
            } else {
                editTab.classList.remove('active');
                historyTab.classList.add('active');
                editPanel.style.display = 'none';
                historyPanel.style.display = '';
                saveBtn.style.display = 'none';
                deleteBtn.classList.add('d-none');

                if (this._customerId) {
                    this._loadHistory(this._customerId);
                }
            }

            // タイトル更新
            const title = document.getElementById('cmModalTitle');
            if (title) {
                const icon = this._customerId ? 'fa-user-edit' : 'fa-user-plus';
                const text = this._customerId ? '顧客情報編集' : '新規顧客登録';
                title.innerHTML = `<i class="fas ${icon} me-2"></i>${text}`;
            }
        },

        _applyPrefill(prefill) {
            if (prefill.name) document.getElementById('cmName').value = prefill.name;
            if (prefill.name_kana) document.getElementById('cmNameKana').value = prefill.name_kana;
            if (prefill.phone) document.getElementById('cmPhone').value = prefill.phone;
            if (prefill.address) document.getElementById('cmAddress').value = prefill.address;
        },

        _loadCustomer(id) {
            fetch(`api/get_customers.php?id=${encodeURIComponent(id)}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            })
            .then(res => res.json())
            .then(result => {
                if (result.success && result.data) {
                    const c = result.data;
                    document.getElementById('cmCustomerId').value = c.id || '';
                    document.getElementById('cmName').value = c.name || '';
                    document.getElementById('cmNameKana').value = c.name_kana || '';
                    document.getElementById('cmPhone').value = c.phone || '';
                    document.getElementById('cmPhoneSub').value = c.phone_sub || '';
                    document.getElementById('cmAddress').value = c.address || '';
                    document.getElementById('cmCareLevel').value = c.care_level || '';
                    document.getElementById('cmMobilityType').value = c.mobility_type || '';
                    document.getElementById('cmDisabilityGrade').value = c.disability_grade || '';
                    document.getElementById('cmNotes').value = c.notes || '';
                    document.getElementById('cmCareManagerName').value = c.care_manager_name || '';
                    document.getElementById('cmCareManagerPhone').value = c.care_manager_phone || '';
                    document.getElementById('cmFacilityName').value = c.facility_name || '';

                    // よく行く目的地
                    this._destinations = c.frequent_destinations || [];
                    this._renderDestinations();

                    // 削除ボタン表示
                    document.getElementById('cmDeleteBtn').classList.remove('d-none');
                }
            })
            .catch(() => {
                showNotification('顧客情報の取得に失敗しました', 'error');
            });
        },

        _renderDestinations() {
            const list = document.getElementById('cmDestList');
            if (!list) return;

            if (this._destinations.length === 0) {
                list.innerHTML = '<li class="text-muted py-2" style="font-size:0.85rem">登録された目的地はありません</li>';
                return;
            }

            list.innerHTML = this._destinations.map((dest, index) => `
                <li class="cm-dest-item">
                    <span class="cm-dest-name">${escapeHtml(dest.name)}</span>
                    <span class="cm-dest-type">${escapeHtml(dest.address || '')}</span>
                    <button type="button" class="cm-dest-remove" data-index="${index}" title="削除">
                        <i class="fas fa-times"></i>
                    </button>
                </li>
            `).join('');

            // 削除ボタン
            list.querySelectorAll('.cm-dest-remove').forEach(btn => {
                btn.addEventListener('click', () => {
                    const idx = parseInt(btn.dataset.index);
                    this._destinations.splice(idx, 1);
                    this._renderDestinations();
                });
            });
        },

        _addDestination() {
            const nameInput = document.getElementById('cmNewDestName');
            const addressInput = document.getElementById('cmNewDestAddress');

            const name = nameInput.value.trim();
            const address = addressInput.value.trim();

            if (!name) {
                nameInput.classList.add('is-invalid');
                setTimeout(() => nameInput.classList.remove('is-invalid'), 2000);
                return;
            }

            this._destinations.push({ name, address });
            this._renderDestinations();

            nameInput.value = '';
            addressInput.value = '';
            nameInput.focus();
        },

        _save() {
            const form = document.getElementById('cmCustomerForm');
            const nameField = document.getElementById('cmName');

            // バリデーション
            if (!nameField.value.trim()) {
                nameField.classList.add('is-invalid');
                nameField.focus();
                setTimeout(() => nameField.classList.remove('is-invalid'), 3000);
                showNotification('氏名は必須です', 'error');
                return;
            }

            const formData = new FormData(form);
            const data = {};
            for (let [key, value] of formData.entries()) {
                data[key] = value;
            }

            // 目的地データ
            data.frequent_destinations = this._destinations;

            const saveBtn = document.getElementById('cmSaveBtn');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>保存中...';

            fetch('api/save_customer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify(data)
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showNotification(result.message || '顧客情報を保存しました', 'success');

                    // コールバック
                    if (typeof this._onSaveCallback === 'function') {
                        this._onSaveCallback(result.data || data);
                    }

                    // オートコンプリートのhiddenフィールドを更新
                    if (result.data && result.data.id) {
                        const hiddenField = document.getElementById('customerId');
                        if (hiddenField) {
                            hiddenField.value = result.data.id;
                        }

                        // 名前フィールドも更新
                        const clientNameField = document.getElementById('clientName');
                        if (clientNameField && result.data.name) {
                            clientNameField.value = result.data.name;
                        }
                    }

                    this.close();
                } else {
                    throw new Error(result.message || '保存に失敗しました');
                }
            })
            .catch(error => {
                showNotification(error.message, 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = '<i class="fas fa-save me-1"></i>保存';
            });
        },

        _delete() {
            if (!this._customerId) return;

            if (!confirm('この顧客情報を削除しますか？\n関連する予約データは削除されません。')) {
                return;
            }

            fetch('api/delete_customer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken()
                },
                body: JSON.stringify({
                    id: this._customerId
                })
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showNotification('顧客情報を削除しました', 'success');

                    // オートコンプリートのクリア
                    const hiddenField = document.getElementById('customerId');
                    if (hiddenField) hiddenField.value = '';

                    this.close();
                } else {
                    throw new Error(result.message || '削除に失敗しました');
                }
            })
            .catch(error => {
                showNotification(error.message, 'error');
            });
        },

        _loadHistory(customerId) {
            const container = document.getElementById('cmHistoryContent');
            container.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>読み込み中...</div>';

            fetch(`api/get_customer_history.php?customer_id=${encodeURIComponent(customerId)}`, {
                method: 'GET',
                headers: {
                    'X-CSRF-TOKEN': getCsrfToken()
                }
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    const history = result.data.reservations || [];
                    this._renderHistory(history, container);
                } else {
                    container.innerHTML = '<div class="text-center text-muted py-4">履歴の取得に失敗しました</div>';
                }
            })
            .catch(() => {
                container.innerHTML = '<div class="text-center text-muted py-4">通信エラーが発生しました</div>';
            });
        },

        _renderHistory(reservations, container) {
            if (reservations.length === 0) {
                container.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-inbox me-1"></i>予約履歴はありません</div>';
                return;
            }

            let html = `
                <div class="cm-section-header"><i class="fas fa-calendar-check me-1"></i>この顧客の予約履歴（${reservations.length}件）</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover cm-history-table">
                        <thead class="table-light">
                            <tr>
                                <th>日付</th>
                                <th>時刻</th>
                                <th>区間</th>
                                <th>サービス</th>
                                <th>状態</th>
                            </tr>
                        </thead>
                        <tbody>
            `;

            reservations.forEach(r => {
                const statusClass = r.status === '完了' ? 'text-success' :
                                    r.status === 'キャンセル' ? 'text-danger' :
                                    'text-primary';
                html += `
                    <tr>
                        <td>${escapeHtml(r.reservation_date || '')}</td>
                        <td>${escapeHtml(r.reservation_time || '')}</td>
                        <td>${escapeHtml(r.pickup_location || '')} → ${escapeHtml(r.dropoff_location || '')}</td>
                        <td>${escapeHtml(r.service_type || '')}</td>
                        <td class="${statusClass}">${escapeHtml(r.status || '')}</td>
                    </tr>
                `;
            });

            html += '</tbody></table></div>';
            container.innerHTML = html;
        }
    };

    // =========================================================
    // グローバル公開
    // =========================================================

    window.CustomerAutocomplete = CustomerAutocomplete;
    window.CustomerDetailPanel = CustomerDetailPanel;
    window.CustomerManagementModal = CustomerManagementModal;

    // =========================================================
    // DOMContentLoaded時の自動初期化
    // =========================================================

    document.addEventListener('DOMContentLoaded', function() {
        // clientName フィールドが存在すれば自動的にオートコンプリートを初期化
        const clientNameField = document.getElementById('clientName');
        if (clientNameField) {
            window.customerAutocomplete = new CustomerAutocomplete({
                inputId: 'clientName',
                apiUrl: 'api/get_customers.php',
                onSelect: function(customer) {
                    // 電話番号フィールドがあれば設定
                    const phoneField = document.getElementById('clientPhone');
                    if (phoneField && customer.phone) {
                        phoneField.value = customer.phone;
                    }
                }
            });
        }

        // 顧客管理モーダルの保存後コールバック：オートコンプリートに反映
        CustomerManagementModal.onSave(function(customer) {
            if (window.customerAutocomplete && customer) {
                window.customerAutocomplete.setCustomer(customer);
            }
        });

        // 顧客管理ボタンのクリックイベント
        var cmBtn = document.getElementById('customerManagementBtn');
        if (cmBtn) {
            cmBtn.addEventListener('click', function() {
                window.location.href = 'customer_management.php';
            });
        }
    });

})();
