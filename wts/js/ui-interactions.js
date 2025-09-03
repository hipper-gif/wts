/**
 * 福祉輸送管理システム - UIインタラクション制御
 * 
 * ファイル名: ui-interactions.js
 * 配置先: /js/ui-interactions.js
 * 作成日: 2025年9月2日
 * バージョン: v3.0（モダンミニマル完全版）
 */

(function() {
    'use strict';
    
    // DOM読み込み完了後に実行
    document.addEventListener('DOMContentLoaded', function() {
        initializeUIComponents();
    });
    
    /**
     * UIコンポーネントを初期化
     */
    function initializeUIComponents() {
        initializeTabs();
        initializeDropdowns();
        initializeModals();
        initializeToasts();
        initializeCheckItems();
        initializeCashCounters();
        initializeTableSorting();
        initializeFormValidation();
        initializeAlerts();
    }
    
    /**
     * タブナビゲーションを初期化
     */
    function initializeTabs() {
        const tabButtons = document.querySelectorAll('.nav-tab');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', function(e) {
                e.preventDefault();
                
                const targetTab = this.getAttribute('data-tab');
                const tabContainer = this.closest('.nav-tabs');
                
                // アクティブタブを切り替え
                tabContainer.querySelectorAll('.nav-tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                this.classList.add('active');
                
                // タブコンテンツを切り替え
                const contentContainer = tabContainer.nextElementSibling;
                if (contentContainer && contentContainer.classList.contains('tab-content')) {
                    contentContainer.querySelectorAll('.tab-pane').forEach(pane => {
                        pane.classList.remove('active');
                    });
                    
                    const targetPane = contentContainer.querySelector(`#${targetTab}`);
                    if (targetPane) {
                        targetPane.classList.add('active');
                    }
                }
            });
        });
    }
    
    /**
     * ドロップダウンメニューを初期化
     */
    function initializeDropdowns() {
        const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
        
        dropdownToggles.forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                const dropdown = this.parentNode;
                const menu = dropdown.querySelector('.dropdown-menu');
                
                // 他のドロップダウンを閉じる
                document.querySelectorAll('.dropdown-menu.show').forEach(otherMenu => {
                    if (otherMenu !== menu) {
                        otherMenu.classList.remove('show');
                    }
                });
                
                // 現在のドロップダウンを切り替え
                menu.classList.toggle('show');
            });
        });
        
        // 外側クリックでドロップダウンを閉じる
        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                menu.classList.remove('show');
            });
        });
    }
    
    /**
     * モーダルダイアログを初期化
     */
    function initializeModals() {
        // モーダルを開く
        const modalTriggers = document.querySelectorAll('[data-modal]');
        modalTriggers.forEach(trigger => {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                const modalId = this.getAttribute('data-modal');
                const modal = document.getElementById(modalId);
                if (modal) {
                    openModal(modal);
                }
            });
        });
        
        // モーダルを閉じる
        const modalCloses = document.querySelectorAll('.modal-close, [data-dismiss="modal"]');
        modalCloses.forEach(close => {
            close.addEventListener('click', function() {
                const modal = this.closest('.modal');
                if (modal) {
                    closeModal(modal);
                }
            });
        });
        
        // 背景クリックでモーダルを閉じる
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this);
                }
            });
        });
        
        // ESCキーでモーダルを閉じる
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const openModal = document.querySelector('.modal.show');
                if (openModal) {
                    closeModal(openModal);
                }
            }
        });
    }
    
    /**
     * モーダルを開く
     */
    function openModal(modal) {
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        
        // フォーカス管理
        const focusableElements = modal.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        );
        if (focusableElements.length > 0) {
            focusableElements[0].focus();
        }
    }
    
    /**
     * モーダルを閉じる
     */
    function closeModal(modal) {
        modal.classList.remove('show');
        document.body.style.overflow = '';
    }
    
    /**
     * トースト通知を初期化
     */
    function initializeToasts() {
        // トースト表示
        window.showToast = function(message, type = 'info', duration = 5000) {
            const toastContainer = getOrCreateToastContainer();
            const toast = createToast(message, type);
            
            toastContainer.appendChild(toast);
            
            // 表示アニメーション
            setTimeout(() => {
                toast.classList.add('show');
            }, 100);
            
            // 自動削除
            setTimeout(() => {
                hideToast(toast);
            }, duration);
        };
        
        // トーストを閉じる
        document.addEventListener('click', function(e) {
            if (e.target.closest('.toast-close')) {
                const toast = e.target.closest('.toast');
                hideToast(toast);
            }
        });
    }
    
    /**
     * トーストコンテナを取得または作成
     */
    function getOrCreateToastContainer() {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        return container;
    }
    
    /**
     * トースト要素を作成
     */
    function createToast(message, type) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icons = {
            success: 'check-circle',
            warning: 'exclamation-triangle',
            danger: 'times-circle',
            info: 'info-circle'
        };
        
        toast.innerHTML = `
            <div class="toast-header">
                <div class="toast-title">
                    <i class="fas fa-${icons[type] || 'info-circle'}"></i>
                    ${getToastTitle(type)}
                </div>
                <button type="button" class="toast-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="toast-body">${message}</div>
        `;
        
        return toast;
    }
    
    /**
     * トーストタイトルを取得
     */
    function getToastTitle(type) {
        const titles = {
            success: '成功',
            warning: '警告',
            danger: 'エラー',
            info: '情報'
        };
        return titles[type] || '通知';
    }
    
    /**
     * トーストを隠す
     */
    function hideToast(toast) {
        toast.classList.remove('show');
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    }
    
    /**
     * 点検項目チェックボタンを初期化
     */
    function initializeCheckItems() {
        const checkOptions = document.querySelectorAll('.check-option');
        
        checkOptions.forEach(option => {
            option.addEventListener('click', function() {
                const name = this.getAttribute('data-name');
                const value = this.getAttribute('data-value');
                
                // 同じグループの他のオプションを無選択にする
                const group = document.querySelectorAll(`[data-name="${name}"]`);
                group.forEach(opt => {
                    opt.classList.remove('selected', 'success', 'warning', 'danger');
                });
                
                // 選択されたオプションをアクティブにする
                this.classList.add('selected');
                
                // 値に応じてクラスを追加
                if (value === '可' || value === '良好' || value === '○') {
                    this.classList.add('success');
                } else if (value === '要注意' || value === '△') {
                    this.classList.add('warning');
                } else if (value === '否' || value === '不良' || value === '×') {
                    this.classList.add('danger');
                }
                
                // 隠しフィールドに値をセット
                const hiddenField = document.querySelector(`input[name="${name}"]`);
                if (hiddenField) {
                    hiddenField.value = value;
                }
            });
        });
    }
    
    /**
     * 集金カウンターを初期化
     */
    function initializeCashCounters() {
        const cashButtons = document.querySelectorAll('.cash-btn');
        
        cashButtons.forEach(button => {
            button.addEventListener('click', function() {
                const action = this.getAttribute('data-action');
                const inputGroup = this.closest('.cash-input-group');
                const input = inputGroup.querySelector('.cash-input');
                
                let currentValue = parseInt(input.value) || 0;
                
                if (action === 'increase') {
                    currentValue++;
                } else if (action === 'decrease' && currentValue > 0) {
                    currentValue--;
                }
                
                input.value = currentValue;
                
                // 金額を更新
                updateCashAmount(input);
                
                // 合計を再計算
                updateCashTotal();
            });
        });
        
        // 直接入力の場合
        const cashInputs = document.querySelectorAll('.cash-input');
        cashInputs.forEach(input => {
            input.addEventListener('input', function() {
                updateCashAmount(this);
                updateCashTotal();
            });
        });
    }
    
    /**
     * 金額を更新
     */
    function updateCashAmount(input) {
        const cashItem = input.closest('.cash-item');
        const amountDisplay = cashItem.querySelector('.cash-amount');
        const denomination = cashItem.querySelector('.cash-denomination').textContent;
        
        // 単位金額を取得（金種名から推定）
        const unitValue = getUnitValue(denomination);
        const count = parseInt(input.value) || 0;
        const amount = unitValue * count;
        
        amountDisplay.textContent = `¥${amount.toLocaleString()}`;
    }
    
    /**
     * 金種名から単位金額を取得
     */
    function getUnitValue(denomination) {
        if (denomination.includes('500円')) return 500;
        if (denomination.includes('100円')) return 100;
        if (denomination.includes('50円')) return 50;
        if (denomination.includes('10円')) return 10;
        if (denomination.includes('5円')) return 5;
        if (denomination.includes('1円')) return 1;
        return 0;
    }
    
    /**
     * 集金合計を更新
     */
    function updateCashTotal() {
        const cashInputs = document.querySelectorAll('.cash-input');
        let total = 0;
        
        cashInputs.forEach(input => {
            const cashItem = input.closest('.cash-item');
            const denomination = cashItem.querySelector('.cash-denomination').textContent;
            const unitValue = getUnitValue(denomination);
            const count = parseInt(input.value) || 0;
            
            total += unitValue * count;
        });
        
        // 合計表示を更新
        const totalDisplay = document.querySelector('.cash-total');
        if (totalDisplay) {
            totalDisplay.textContent = `¥${total.toLocaleString()}`;
        }
        
        // 入金額を計算（合計 - 基準おつり18,000円）
        const depositAmount = total - 18000;
        const depositDisplay = document.querySelector('.deposit-amount');
        if (depositDisplay) {
            depositDisplay.textContent = `¥${depositAmount.toLocaleString()}`;
            
            // 色分け
            if (depositAmount > 0) {
                depositDisplay.className = 'deposit-amount text-success';
            } else if (depositAmount < 0) {
                depositDisplay.className = 'deposit-amount text-danger';
            } else {
                depositDisplay.className = 'deposit-amount text-muted';
            }
        }
        
        // カスタムイベント発火
        const event = new CustomEvent('cashTotalUpdated', {
            detail: { total: total, deposit: depositAmount }
        });
        document.dispatchEvent(event);
    }
    
    /**
     * テーブルソート機能を初期化
     */
    function initializeTableSorting() {
        const sortLinks = document.querySelectorAll('.sort-link');
        
        sortLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                const sortKey = this.getAttribute('data-sort');
                const table = this.closest('table');
                const currentSortKey = table.getAttribute('data-sort-key');
                const currentSortDir = table.getAttribute('data-sort-dir') || 'asc';
                
                // ソート方向を決定
                let newSortDir = 'asc';
                if (currentSortKey === sortKey && currentSortDir === 'asc') {
                    newSortDir = 'desc';
                }
                
                // テーブル属性を更新
                table.setAttribute('data-sort-key', sortKey);
                table.setAttribute('data-sort-dir', newSortDir);
                
                // ソート実行
                sortTable(table, sortKey, newSortDir);
                
                // ヘッダーアイコンを更新
                updateSortIcons(table, sortKey, newSortDir);
            });
        });
    }
    
    /**
     * テーブルをソート
     */
    function sortTable(table, sortKey, sortDir) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const keyIndex = Array.from(table.querySelectorAll('th')).findIndex(th => 
            th.querySelector(`[data-sort="${sortKey}"]`)
        );
        
        if (keyIndex === -1) return;
        
        rows.sort((a, b) => {
            const aValue = a.cells[keyIndex].textContent.trim();
            const bValue = b.cells[keyIndex].textContent.trim();
            
            // 数値かどうかを判定
            const aNum = parseFloat(aValue.replace(/[^\d.-]/g, ''));
            const bNum = parseFloat(bValue.replace(/[^\d.-]/g, ''));
            
            let comparison = 0;
            if (!isNaN(aNum) && !isNaN(bNum)) {
                comparison = aNum - bNum;
            } else {
                comparison = aValue.localeCompare(bValue, 'ja');
            }
            
            return sortDir === 'asc' ? comparison : -comparison;
        });
        
        // ソートされた行を再配置
        rows.forEach(row => tbody.appendChild(row));
    }
    
    /**
     * ソートアイコンを更新
     */
    function updateSortIcons(table, activeKey, sortDir) {
        const sortLinks = table.querySelectorAll('.sort-link');
        
        sortLinks.forEach(link => {
            const key = link.getAttribute('data-sort');
            const icon = link.querySelector('i');
            
            if (key === activeKey) {
                icon.className = `fas fa-sort-${sortDir === 'asc' ? 'up' : 'down'} ms-1`;
                link.closest('th').classList.add('active');
            } else {
                icon.className = 'fas fa-sort ms-1 text-muted';
                link.closest('th').classList.remove('active');
            }
        });
    }
    
    /**
     * フォームバリデーションを初期化
     */
    function initializeFormValidation() {
        const forms = document.querySelectorAll('form[data-validate]');
        
        forms.forEach(form => {
            form.addEventListener('submit', function(e) {
                if (!validateForm(this)) {
                    e.preventDefault();
                }
            });
            
            // リアルタイムバリデーション
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('blur', function() {
                    validateField(this);
                });
                
                input.addEventListener('input', function() {
                    clearFieldError(this);
                });
            });
        });
    }
    
    /**
     * フォール全体をバリデーション
     */
    function validateForm(form) {
        let isValid = true;
        const inputs = form.querySelectorAll('input, select, textarea');
        
        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    /**
     * 個別フィールドをバリデーション
     */
    function validateField(field) {
        clearFieldError(field);
        
        let isValid = true;
        let errorMessage = '';
        
        // 必須チェック
        if (field.hasAttribute('required') && !field.value.trim()) {
            errorMessage = 'この項目は必須です。';
            isValid = false;
        }
        
        // メール形式チェック
        if (field.type === 'email' && field.value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(field.value)) {
                errorMessage = '正しいメールアドレスを入力してください。';
                isValid = false;
            }
        }
        
        // 数値チェック
        if (field.type === 'number' && field.value) {
            const min = field.getAttribute('min');
            const max = field.getAttribute('max');
            const value = parseFloat(field.value);
            
            if (min && value < parseFloat(min)) {
                errorMessage = `${min}以上の値を入力してください。`;
                isValid = false;
            }
            
            if (max && value > parseFloat(max)) {
                errorMessage = `${max}以下の値を入力してください。`;
                isValid = false;
            }
        }
        
        // エラー表示
        if (!isValid) {
            showFieldError(field, errorMessage);
        }
        
        return isValid;
    }
    
    /**
     * フィールドエラーを表示
     */
    function showFieldError(field, message) {
        field.classList.add('is-invalid');
        
        let errorElement = field.parentNode.querySelector('.field-error');
        if (!errorElement) {
            errorElement = document.createElement('div');
            errorElement.className = 'field-error text-danger text-sm mt-1';
            field.parentNode.appendChild(errorElement);
        }
        
        errorElement.textContent = message;
    }
    
    /**
     * フィールドエラーをクリア
     */
    function clearFieldError(field) {
        field.classList.remove('is-invalid');
        
        const errorElement = field.parentNode.querySelector('.field-error');
        if (errorElement) {
            errorElement.remove();
        }
    }
    
    /**
     * アラートを初期化
     */
    function initializeAlerts() {
        const dismissButtons = document.querySelectorAll('[data-dismiss="alert"]');
        
        dismissButtons.forEach(button => {
            button.addEventListener('click', function() {
                const alert = this.closest('.alert');
                if (alert) {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }
            });
        });
    }
    
    /**
     * ローディングスピナーを表示
     */
    window.showLoading = function() {
        let overlay = document.querySelector('.loading-overlay');
        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'loading-overlay';
            overlay.innerHTML = '<div class="spinner spinner-lg"></div>';
            document.body.appendChild(overlay);
        }
        overlay.style.display = 'flex';
    };
    
    /**
     * ローディングスピナーを隠す
     */
    window.hideLoading = function() {
        const overlay = document.querySelector('.loading-overlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    };
    
    /**
     * 確認ダイアログを表示
     */
    window.showConfirm = function(message, callback) {
        if (confirm(message)) {
            if (typeof callback === 'function') {
                callback();
            }
            return true;
        }
        return false;
    };
    
    /**
     * Ajaxリクエストヘルパー
     */
    window.ajaxRequest = function(url, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        };
        
        const config = Object.assign(defaultOptions, options);
        
        return fetch(url, config)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .catch(error => {
                console.error('Ajax request failed:', error);
                showToast('通信エラーが発生しました。', 'danger');
                throw error;
            });
    };
    
    /**
     * フォームデータをAjaxで送信
     */
    window.submitFormAjax = function(form, successCallback, errorCallback) {
        const formData = new FormData(form);
        const url = form.action || window.location.href;
        
        showLoading();
        
        fetch(url, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            
            if (data.success) {
                if (typeof successCallback === 'function') {
                    successCallback(data);
                } else {
                    showToast(data.message || '保存しました。', 'success');
                }
            } else {
                if (typeof errorCallback === 'function') {
                    errorCallback(data);
                } else {
                    showToast(data.message || 'エラーが発生しました。', 'danger');
                }
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Form submission failed:', error);
            
            if (typeof errorCallback === 'function') {
                errorCallback({ message: '通信エラーが発生しました。' });
            } else {
                showToast('通信エラーが発生しました。', 'danger');
            }
        });
    };
    
    /**
     * データテーブルを動的に更新
     */
    window.updateDataTable = function(tableId, data, columns) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        const tbody = table.querySelector('tbody');
        tbody.innerHTML = '';
        
        data.forEach(row => {
            const tr = document.createElement('tr');
            
            columns.forEach(column => {
                const td = document.createElement('td');
                td.textContent = row[column.key] || '';
                
                if (column.className) {
                    td.className = column.className;
                }
                
                tr.appendChild(td);
            });
            
            tbody.appendChild(tr);
        });
    };
    
    /**
     * ユーティリティ関数：要素が表示されているかチェック
     */
    window.isElementVisible = function(element) {
        return !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
    };
    
    /**
     * ユーティリティ関数：スムーススクロール
     */
    window.scrollToElement = function(element, offset = 0) {
        if (typeof element === 'string') {
            element = document.querySelector(element);
        }
        
        if (element) {
            const headerHeight = document.querySelector('.system-header')?.offsetHeight || 0;
            const subheaderHeight = document.querySelector('.page-header')?.offsetHeight || 0;
            const totalOffset = headerHeight + subheaderHeight + offset;
            
            const elementPosition = element.getBoundingClientRect().top + window.pageYOffset;
            const offsetPosition = elementPosition - totalOffset;
            
            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
        }
    };
    
    /**
     * ユーティリティ関数：デバウンス
     */
    window.debounce = function(func, wait, immediate) {
        let timeout;
        return function executedFunction() {
            const context = this;
            const args = arguments;
            
            const later = function() {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            
            if (callNow) func.apply(context, args);
        };
    };
    
    /**
     * ユーティリティ関数：スロットル
     */
    window.throttle = function(func, limit) {
        let lastFunc;
        let lastRan;
        
        return function() {
            const context = this;
            const args = arguments;
            
            if (!lastRan) {
                func.apply(context, args);
                lastRan = Date.now();
            } else {
                clearTimeout(lastFunc);
                lastFunc = setTimeout(function() {
                    if ((Date.now() - lastRan) >= limit) {
                        func.apply(context, args);
                        lastRan = Date.now();
                    }
                }, limit - (Date.now() - lastRan));
            }
        };
    };
    
    /**
     * レスポンシブヘルパー：画面サイズチェック
     */
    window.isMobile = function() {
        return window.innerWidth <= 767;
    };
    
    window.isTablet = function() {
        return window.innerWidth >= 768 && window.innerWidth <= 1023;
    };
    
    window.isDesktop = function() {
        return window.innerWidth >= 1024;
    };
    
    // グローバルエラーハンドラー
    window.addEventListener('error', function(e) {
        console.error('JavaScript Error:', e.error);
        // 本番環境では適切なエラー報告システムに送信
    });
    
    // 未処理のPromise拒否をキャッチ
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Unhandled Promise Rejection:', e.reason);
        e.preventDefault();
    });
    
})();万円')) return 10000;
        if (denomination.includes('5千円') || denomination.includes('5000円')) return 5000;
        if (denomination.includes('2千円') || denomination.includes('2000円')) return 2000;
        if (denomination.includes('千円') || denomination.includes('1000円')) return 1000;
        if (denomination.includes('
