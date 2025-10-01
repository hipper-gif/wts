// =================================================================
// タイムツリー移行JavaScript
// 
// ファイル: /Smiley/taxi/wts/calendar/js/timetree_migration.js
// 機能: タイムツリーデータ移行UI・ファイル処理・進捗表示
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

document.addEventListener('DOMContentLoaded', function() {
    // =================================================================
    // グローバル変数
    // =================================================================
    
    let migrationModal;
    let currentStep = 1;
    let uploadedFile = null;
    let previewData = null;
    let migrationInProgress = false;
    
    // =================================================================
    // 初期化
    // =================================================================
    
    function initializeMigration() {
        // モーダル初期化
        const modalElement = document.getElementById('migrationModal');
        if (modalElement) {
            migrationModal = new bootstrap.Modal(modalElement);
        }
        
        setupEventListeners();
        createMigrationButton();
    }
    
    function setupEventListeners() {
        // ファイル選択
        const fileInput = document.getElementById('timetreeFile');
        if (fileInput) {
            fileInput.addEventListener('change', handleFileSelect);
        }
        
        // ドラッグ&ドロップ
        const dropZone = document.getElementById('dropZone');
        if (dropZone) {
            setupDropZone(dropZone);
        }
        
        // ステップボタン
        const nextBtn = document.getElementById('migrationNextBtn');
        const prevBtn = document.getElementById('migrationPrevBtn');
        const executeBtn = document.getElementById('migrationExecuteBtn');
        
        if (nextBtn) nextBtn.addEventListener('click', handleNextStep);
        if (prevBtn) prevBtn.addEventListener('click', handlePrevStep);
        if (executeBtn) executeBtn.addEventListener('click', handleExecuteMigration);
    }
    
    function createMigrationButton() {
        // 管理者のみに移行ボタンを表示
        if (window.calendarConfig && window.calendarConfig.userRole === 'admin') {
            const controlPanel = document.querySelector('.calendar-controls .row .col-lg-5');
            if (controlPanel) {
                const migrationBtn = document.createElement('button');
                migrationBtn.type = 'button';
                migrationBtn.className = 'btn btn-warning ms-2';
                migrationBtn.innerHTML = '<i class="fas fa-database me-1"></i>データ移行';
                migrationBtn.addEventListener('click', openMigrationModal);
                
                controlPanel.appendChild(migrationBtn);
            }
        }
    }
    
    // =================================================================
    // モーダル制御
    // =================================================================
    
    function openMigrationModal() {
        resetMigrationState();
        showStep(1);
        if (migrationModal) {
            migrationModal.show();
        }
    }
    
    function resetMigrationState() {
        currentStep = 1;
        uploadedFile = null;
        previewData = null;
        migrationInProgress = false;
        
        // ファイル入力リセット
        const fileInput = document.getElementById('timetreeFile');
        if (fileInput) {
            fileInput.value = '';
        }
        
        // 表示リセット
        clearFileInfo();
        clearPreview();
        clearProgress();
    }
    
    function showStep(step) {
        currentStep = step;
        
        // 全ステップを非表示
        document.querySelectorAll('.migration-step').forEach(stepEl => {
            stepEl.style.display = 'none';
        });
        
        // 指定ステップを表示
        const targetStep = document.getElementById(`migrationStep${step}`);
        if (targetStep) {
            targetStep.style.display = 'block';
        }
        
        // ボタン状態更新
        updateNavigationButtons();
        
        // ステップインジケーター更新
        updateStepIndicator();
    }
    
    function updateNavigationButtons() {
        const nextBtn = document.getElementById('migrationNextBtn');
        const prevBtn = document.getElementById('migrationPrevBtn');
        const executeBtn = document.getElementById('migrationExecuteBtn');
        
        if (nextBtn) {
            nextBtn.style.display = currentStep < 3 ? 'inline-block' : 'none';
            nextBtn.disabled = !canProceedToNextStep();
        }
        
        if (prevBtn) {
            prevBtn.style.display = currentStep > 1 ? 'inline-block' : 'none';
        }
        
        if (executeBtn) {
            executeBtn.style.display = currentStep === 3 ? 'inline-block' : 'none';
            executeBtn.disabled = !previewData || migrationInProgress;
        }
    }
    
    function updateStepIndicator() {
        const indicators = document.querySelectorAll('.step-indicator .step');
        
        indicators.forEach((indicator, index) => {
            const stepNumber = index + 1;
            
            indicator.classList.remove('active', 'completed');
            
            if (stepNumber === currentStep) {
                indicator.classList.add('active');
            } else if (stepNumber < currentStep) {
                indicator.classList.add('completed');
            }
        });
    }
    
    function canProceedToNextStep() {
        switch (currentStep) {
            case 1: return uploadedFile !== null;
            case 2: return previewData !== null;
            default: return false;
        }
    }
    
    // =================================================================
    // ファイル処理
    // =================================================================
    
    function setupDropZone(dropZone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, unhighlight, false);
        });
        
        dropZone.addEventListener('drop', handleDrop, false);
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        function highlight() {
            dropZone.classList.add('dragover');
        }
        
        function unhighlight() {
            dropZone.classList.remove('dragover');
        }
        
        function handleDrop(e) {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect({ target: { files: files } });
            }
        }
    }
    
    function handleFileSelect(event) {
        const files = event.target.files;
        if (!files || files.length === 0) return;
        
        const file = files[0];
        
        // ファイル検証
        if (!validateFile(file)) {
            return;
        }
        
        uploadedFile = file;
        displayFileInfo(file);
        
        // ファイルアップロード実行
        uploadFile(file);
    }
    
    function validateFile(file) {
        const maxSize = 10 * 1024 * 1024; // 10MB
        const allowedTypes = ['text/csv', 'application/json', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        const allowedExtensions = ['csv', 'json', 'xlsx'];
        
        if (file.size > maxSize) {
            showNotification('ファイルサイズが大きすぎます（10MB以下）', 'error');
            return false;
        }
        
        const extension = file.name.split('.').pop().toLowerCase();
        if (!allowedExtensions.includes(extension)) {
            showNotification('対応していないファイル形式です（CSV, JSON, XLSX）', 'error');
            return false;
        }
        
        return true;
    }
    
    function displayFileInfo(file) {
        const fileInfo = document.getElementById('fileInfo');
        if (!fileInfo) return;
        
        fileInfo.innerHTML = `
            <div class="file-info-card">
                <div class="file-icon">
                    <i class="fas fa-file-${getFileIcon(file.name)}"></i>
                </div>
                <div class="file-details">
                    <div class="file-name">${file.name}</div>
                    <div class="file-size">${formatFileSize(file.size)}</div>
                    <div class="file-type">${file.type || '不明'}</div>
                </div>
                <div class="file-status">
                    <span class="badge bg-info">アップロード中...</span>
                </div>
            </div>
        `;
        
        fileInfo.style.display = 'block';
    }
    
    function getFileIcon(filename) {
        const extension = filename.split('.').pop().toLowerCase();
        const iconMap = {
            'csv': 'csv',
            'json': 'code',
            'xlsx': 'excel'
        };
        return iconMap[extension] || 'alt';
    }
    
    function formatFileSize(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        
        return Math.round(bytes * 100) / 100 + ' ' + units[i];
    }
    
    function clearFileInfo() {
        const fileInfo = document.getElementById('fileInfo');
        if (fileInfo) {
            fileInfo.style.display = 'none';
            fileInfo.innerHTML = '';
        }
    }
    
    // =================================================================
    // ファイルアップロード
    // =================================================================
    
    function uploadFile(file) {
        const formData = new FormData();
        formData.append('timetree_file', file);
        formData.append('action', 'upload');
        
        fetch('api/migrate_timetree.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateFileStatus('アップロード完了', 'success');
                showNotification('ファイルのアップロードが完了しました', 'success');
                updateNavigationButtons();
            } else {
                throw new Error(data.message || 'アップロードに失敗しました');
            }
        })
        .catch(error => {
            console.error('アップロードエラー:', error);
            updateFileStatus('アップロード失敗', 'danger');
            showNotification(error.message, 'error');
            uploadedFile = null;
            updateNavigationButtons();
        });
    }
    
    function updateFileStatus(statusText, statusType) {
        const statusElement = document.querySelector('.file-status .badge');
        if (statusElement) {
            statusElement.textContent = statusText;
            statusElement.className = `badge bg-${statusType}`;
        }
    }
    
    // =================================================================
    // データプレビュー
    // =================================================================
    
    function loadDataPreview() {
        if (!uploadedFile) return;
        
        showPreviewLoading();
        
        fetch('api/migrate_timetree.php?action=preview', {
            method: 'GET'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                previewData = data.data;
                displayPreview(data.data);
                updateNavigationButtons();
            } else {
                throw new Error(data.message || 'プレビュー生成に失敗しました');
            }
        })
        .catch(error => {
            console.error('プレビューエラー:', error);
            showNotification(error.message, 'error');
        })
        .finally(() => {
            hidePreviewLoading();
        });
    }
    
    function showPreviewLoading() {
        const previewArea = document.getElementById('previewArea');
        if (previewArea) {
            previewArea.innerHTML = `
                <div class="text-center p-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">読み込み中...</span>
                    </div>
                    <div class="mt-2">データを解析中...</div>
                </div>
            `;
        }
    }
    
    function hidePreviewLoading() {
        // displayPreview で上書きされるため特別な処理は不要
    }
    
    function displayPreview(data) {
        const previewArea = document.getElementById('previewArea');
        if (!previewArea) return;
        
        let html = `
            <div class="preview-summary">
                <div class="row">
                    <div class="col-md-3">
                        <div class="summary-card bg-primary text-white">
                            <div class="summary-number">${data.total_records}</div>
                            <div class="summary-label">総レコード数</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card bg-success text-white">
                            <div class="summary-number">${data.valid_records}</div>
                            <div class="summary-label">有効データ</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card bg-warning text-white">
                            <div class="summary-number">${data.invalid_records}</div>
                            <div class="summary-label">エラーデータ</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="summary-card bg-info text-white">
                            <div class="summary-number">${Math.round((data.valid_records / data.total_records) * 100)}%</div>
                            <div class="summary-label">成功率</div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // エラーがある場合は表示
        if (data.validation_errors && data.validation_errors.length > 0) {
            html += `
                <div class="validation-errors mt-3">
                    <h6 class="text-warning">⚠️ 検証エラー</h6>
                    <div class="error-list">
                        ${data.validation_errors.slice(0, 10).map(error => `
                            <div class="error-item">${error}</div>
                        `).join('')}
                        ${data.validation_errors.length > 10 ? `
                            <div class="error-item text-muted">...他 ${data.validation_errors.length - 10} 件のエラー</div>
                        ` : ''}
                    </div>
                </div>
            `;
        }
        
        // サンプルデータ表示
        if (data.sample_data && data.sample_data.length > 0) {
            html += `
                <div class="sample-data mt-3">
                    <h6>📊 サンプルデータ（最初の10件）</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>利用者名</th>
                                    <th>日時</th>
                                    <th>出発地→目的地</th>
                                    <th>サービス種別</th>
                                    <th>レンタル</th>
                                    <th>状態</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.sample_data.map(item => `
                                    <tr>
                                        <td>${item.client_name}</td>
                                        <td>${item.reservation_date}<br><small>${item.reservation_time}</small></td>
                                        <td>${item.pickup_location} → ${item.dropoff_location}</td>
                                        <td><span class="badge bg-secondary">${item.service_type}</span></td>
                                        <td>${item.rental_service}</td>
                                        <td><span class="badge bg-info">${item.status}</span></td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
        }
        
        // 統計情報表示
        if (data.statistics) {
            html += generateStatisticsHTML(data.statistics);
        }
        
        previewArea.innerHTML = html;
    }
    
    function generateStatisticsHTML(stats) {
        let html = `
            <div class="statistics mt-3">
                <h6>📈 データ統計</h6>
                <div class="row">
        `;
        
        // サービス種別統計
        if (stats.service_types) {
            html += `
                <div class="col-md-6">
                    <div class="stat-card">
                        <h6>サービス種別</h6>
                        <div class="stat-list">
                            ${Object.entries(stats.service_types).map(([type, count]) => `
                                <div class="stat-item">
                                    <span class="stat-label">${type}</span>
                                    <span class="stat-value">${count}件</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }
        
        // レンタルサービス統計
        if (stats.rental_services) {
            html += `
                <div class="col-md-6">
                    <div class="stat-card">
                        <h6>レンタルサービス</h6>
                        <div class="stat-list">
                            ${Object.entries(stats.rental_services).map(([service, count]) => `
                                <div class="stat-item">
                                    <span class="stat-label">${service}</span>
                                    <span class="stat-value">${count}件</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }
        
        html += `
                </div>
                <div class="date-range mt-2">
                    <small class="text-muted">
                        📅 期間: ${stats.date_range.start} ～ ${stats.date_range.end}
                    </small>
                </div>
            </div>
        `;
        
        return html;
    }
    
    function clearPreview() {
        const previewArea = document.getElementById('previewArea');
        if (previewArea) {
            previewArea.innerHTML = '';
        }
        previewData = null;
    }
    
    // =================================================================
    // 移行実行
    // =================================================================
    
    function handleExecuteMigration() {
        if (!previewData || migrationInProgress) return;
        
        // 確認ダイアログ
        if (!confirm(`${previewData.total_records}件のデータを移行します。この操作は取り消せません。実行しますか？`)) {
            return;
        }
        
        migrationInProgress = true;
        showProgress();
        updateNavigationButtons();
        
        fetch('api/migrate_timetree.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'action=execute'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMigrationResult(data.data);
                showNotification('データ移行が完了しました', 'success');
                
                // カレンダー更新
                if (window.calendarMethods) {
                    window.calendarMethods.refreshEvents();
                }
            } else {
                throw new Error(data.message || '移行に失敗しました');
            }
        })
        .catch(error => {
            console.error('移行エラー:', error);
            showNotification(error.message, 'error');
            showMigrationError(error.message);
        })
        .finally(() => {
            migrationInProgress = false;
            hideProgress();
            updateNavigationButtons();
        });
    }
    
    function showProgress() {
        const progressArea = document.getElementById('progressArea');
        if (progressArea) {
            progressArea.innerHTML = `
                <div class="progress-display">
                    <div class="progress mb-3">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             style="width: 100%" role="progressbar">
                            移行実行中...
                        </div>
                    </div>
                    <div class="progress-status text-center">
                        <i class="fas fa-database fa-spin me-2"></i>
                        データベースに予約データを追加しています...
                    </div>
                </div>
            `;
            progressArea.style.display = 'block';
        }
    }
    
    function hideProgress() {
        const progressArea = document.getElementById('progressArea');
        if (progressArea) {
            progressArea.style.display = 'none';
        }
    }
    
    function showMigrationResult(result) {
        const resultArea = document.getElementById('resultArea');
        if (!resultArea) return;
        
        const successRate = Math.round((result.success_count / result.total_processed) * 100);
        
        let html = `
            <div class="migration-result">
                <div class="result-header text-center mb-3">
                    <i class="fas fa-check-circle fa-3x text-success"></i>
                    <h4 class="mt-2">移行完了</h4>
                </div>
                
                <div class="result-summary">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="result-card bg-primary text-white">
                                <div class="result-number">${result.total_processed}</div>
                                <div class="result-label">処理件数</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="result-card bg-success text-white">
                                <div class="result-number">${result.success_count}</div>
                                <div class="result-label">成功</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="result-card bg-warning text-white">
                                <div class="result-number">${result.error_count}</div>
                                <div class="result-label">エラー</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="result-card bg-info text-white">
                                <div class="result-number">${successRate}%</div>
                                <div class="result-label">成功率</div>
                            </div>
                        </div>
                    </div>
                </div>
        `;
        
        // エラーがある場合は表示
        if (result.errors && result.errors.length > 0) {
            html += `
                <div class="migration-errors mt-3">
                    <h6 class="text-warning">⚠️ 移行エラー</h6>
                    <div class="error-list">
                        ${result.errors.slice(0, 10).map(error => `
                            <div class="error-item">${error}</div>
                        `).join('')}
                        ${result.errors.length > 10 ? `
                            <div class="error-item text-muted">...他 ${result.errors.length - 10} 件のエラー</div>
                        ` : ''}
                    </div>
                </div>
            `;
        }
        
        html += `
                <div class="next-steps mt-3">
                    <h6>📝 次のステップ</h6>
                    <ul>
                        <li>カレンダー画面で移行されたデータを確認してください</li>
                        <li>必要に応じて運転者・車両の割り当てを調整してください</li>
                        <li>紹介者情報を確認・更新してください</li>
                    </ul>
                </div>
            </div>
        `;
        
        resultArea.innerHTML = html;
        resultArea.style.display = 'block';
    }
    
    function showMigrationError(errorMessage) {
        const resultArea = document.getElementById('resultArea');
        if (!resultArea) return;
        
        resultArea.innerHTML = `
            <div class="migration-error">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                    <h4 class="mt-2 text-danger">移行失敗</h4>
                    <p class="mt-3">${errorMessage}</p>
                    <button type="button" class="btn btn-outline-primary" onclick="showStep(1)">
                        最初からやり直す
                    </button>
                </div>
            </div>
        `;
        resultArea.style.display = 'block';
    }
    
    function clearProgress() {
        const progressArea = document.getElementById('progressArea');
        const resultArea = document.getElementById('resultArea');
        
        if (progressArea) {
            progressArea.style.display = 'none';
            progressArea.innerHTML = '';
        }
        
        if (resultArea) {
            resultArea.style.display = 'none';
            resultArea.innerHTML = '';
        }
    }
    
    // =================================================================
    // ナビゲーション
    // =================================================================
    
    function handleNextStep() {
        if (currentStep === 1 && uploadedFile) {
            showStep(2);
            loadDataPreview();
        } else if (currentStep === 2 && previewData) {
            showStep(3);
        }
    }
    
    function handlePrevStep() {
        if (currentStep > 1) {
            showStep(currentStep - 1);
        }
    }
    
    // =================================================================
    // ユーティリティ
    // =================================================================
    
    function showNotification(message, type = 'info') {
        if (window.calendarMethods) {
            window.calendarMethods.showNotification(message, type);
        }
    }
    
    // =================================================================
    // 外部公開関数
    // =================================================================
    
    window.timetreeMigration = {
        openModal: openMigrationModal,
        showStep: showStep,
        getCurrentStep: () => currentStep,
        isInProgress: () => migrationInProgress
    };
    
    // =================================================================
    // 初期化実行
    // =================================================================
    
    initializeMigration();
});

// =================================================================
// CSS（動的追加）
// =================================================================

(function() {
    const style = document.createElement('style');
    style.textContent = `
        .migration-step {
            min-height: 400px;
            padding: 20px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .step-indicator .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: #e9ecef;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            color: #6c757d;
            position: relative;
        }
        
        .step-indicator .step.active {
            background-color: #2196F3;
            color: white;
        }
        
        .step-indicator .step.completed {
            background-color: #4CAF50;
            color: white;
        }
        
        .step-indicator .step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 20px;
            height: 2px;
            background-color: #e9ecef;
            z-index: -1;
        }
        
        .drop-zone {
            border: 2px dashed #e9ecef;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: #2196F3;
            background-color: rgba(33, 150, 243, 0.05);
        }
        
        .file-info-card {
            display: flex;
            align-items: center;
            padding: 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            background-color: #f8f9fa;
            margin-top: 15px;
        }
        
        .file-icon {
            font-size: 24px;
            color: #2196F3;
            margin-right: 15px;
        }
        
        .file-details {
            flex-grow: 1;
        }
        
        .file-name {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .file-size,
        .file-type {
            font-size: 12px;
            color: #6c757d;
        }
        
        .summary-card,
        .result-card {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 15px;
        }
        
        .summary-number,
        .result-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-label,
        .result-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .error-list {
            max-height: 200px;
            overflow-y: auto;
            background-color: #fff3e0;
            border: 1px solid #ff9800;
            border-radius: 4px;
            padding: 10px;
        }
        
        .error-item {
            padding: 5px 0;
            border-bottom: 1px solid rgba(255, 152, 0, 0.2);
            font-size: 14px;
        }
        
        .error-item:last-child {
            border-bottom: none;
        }
        
        .stat-card {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 15px;
        }
        
        .stat-list {
            margin-top: 10px;
        }
        
        .stat-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .stat-item:last-child {
            border-bottom: none;
        }
        
        .stat-label {
            color: #6c757d;
        }
        
        .stat-value {
            font-weight: 500;
        }
        
        .progress-display {
            text-align: center;
            padding: 30px;
        }
        
        .progress-status {
            color: #6c757d;
            font-size: 14px;
        }
        
        .next-steps ul {
            background-color: #e3f2fd;
            border: 1px solid #2196F3;
            border-radius: 6px;
            padding: 15px 30px;
            margin: 0;
        }
        
        .next-steps li {
            margin-bottom: 8px;
        }
        
        .next-steps li:last-child {
            margin-bottom: 0;
        }
    `;
    
    document.head.appendChild(style);
})();
