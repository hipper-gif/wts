// =================================================================
// 車両制約制御JavaScript
// 
// ファイル: /Smiley/taxi/wts/calendar/js/vehicle_constraints.js
// 機能: 車両とレンタルサービスの制約チェック・UI制御
// 基盤: 福祉輸送管理システム v3.1
// 作成日: 2025年9月27日
// =================================================================

document.addEventListener('DOMContentLoaded', function() {
    // =================================================================
    // グローバル変数・設定
    // =================================================================
    
    let vehicleConstraints = {
        // ストレッチャー対応車両
        stretcher_compatible: ['ハイエース'],
        
        // 車いす対応車両（全車両対応）
        wheelchair_compatible: ['ハイエース', '普通車', 'セレナ'],
        
        // リクライニング対応車両（全車両対応）
        reclining_compatible: ['ハイエース', '普通車', 'セレナ'],
        
        // 車両定員情報
        capacity: {
            'ハイエース': 10,
            '普通車': 4,
            'セレナ': 8
        }
    };
    
    let currentConstraintWarnings = [];
    
    // =================================================================
    // 初期化
    // =================================================================
    
    function initializeVehicleConstraints() {
        setupConstraintListeners();
        loadVehicleData();
        
        // 初期チェック
        setTimeout(checkAllConstraints, 100);
    }
    
    function setupConstraintListeners() {
        // レンタルサービス変更
        const rentalService = document.getElementById('rentalService');
        if (rentalService) {
            rentalService.addEventListener('change', handleRentalServiceChange);
        }
        
        // 車両選択変更
        const vehicleId = document.getElementById('vehicleId');
        if (vehicleId) {
            vehicleId.addEventListener('change', handleVehicleChange);
        }
        
        // 乗車人数変更
        const passengerCount = document.getElementById('passengerCount');
        if (passengerCount) {
            passengerCount.addEventListener('change', handlePassengerCountChange);
        }
        
        // 運転者変更（車両自動選択用）
        const driverId = document.getElementById('driverId');
        if (driverId) {
            driverId.addEventListener('change', handleDriverChange);
        }
    }
    
    function loadVehicleData() {
        // 設定から車両データを取得
        if (window.calendarConfig && window.calendarConfig.vehicles) {
            updateVehicleConstraintsData(window.calendarConfig.vehicles);
        }
    }
    
    function updateVehicleConstraintsData(vehicles) {
        // 車両データを制約システムに反映
        vehicles.forEach(vehicle => {
            if (!vehicleConstraints.capacity[vehicle.model]) {
                vehicleConstraints.capacity[vehicle.model] = 4; // デフォルト定員
            }
        });
    }
    
    // =================================================================
    // イベントハンドラー
    // =================================================================
    
    function handleRentalServiceChange(event) {
        const selectedService = event.target.value;
        
        clearConstraintWarnings();
        updateVehicleOptionsForService(selectedService);
        checkServiceCompatibility(selectedService);
        
        // 選択中の車両が非対応の場合は警告
        const selectedVehicle = getSelectedVehicle();
        if (selectedVehicle && !isServiceCompatible(selectedVehicle.model, selectedService)) {
            showVehicleIncompatibilityWarning(selectedVehicle, selectedService);
        }
    }
    
    function handleVehicleChange(event) {
        const vehicleId = event.target.value;
        
        clearConstraintWarnings();
        
        if (!vehicleId) return;
        
        const vehicle = getVehicleById(vehicleId);
        if (!vehicle) return;
        
        // 選択中のレンタルサービスとの互換性チェック
        const selectedService = document.getElementById('rentalService').value;
        if (selectedService && selectedService !== 'なし') {
            if (!isServiceCompatible(vehicle.model, selectedService)) {
                showVehicleIncompatibilityWarning(vehicle, selectedService);
            }
        }
        
        // 乗車人数チェック
        checkPassengerCapacity(vehicle);
        
        // 車両情報表示更新
        updateVehicleInfoDisplay(vehicle);
    }
    
    function handlePassengerCountChange(event) {
        const passengerCount = parseInt(event.target.value);
        const selectedVehicle = getSelectedVehicle();
        
        if (selectedVehicle) {
            checkPassengerCapacity(selectedVehicle, passengerCount);
        }
    }
    
    function handleDriverChange(event) {
        const driverId = event.target.value;
        
        if (!driverId) return;
        
        // 運転者の担当車両を自動選択（簡易版）
        suggestVehicleForDriver(driverId);
    }
    
    // =================================================================
    // 制約チェック機能
    // =================================================================
    
    function checkAllConstraints() {
        clearConstraintWarnings();
        
        const selectedVehicle = getSelectedVehicle();
        const selectedService = document.getElementById('rentalService').value;
        const passengerCount = parseInt(document.getElementById('passengerCount').value);
        
        if (selectedVehicle && selectedService && selectedService !== 'なし') {
            checkServiceCompatibility(selectedService, selectedVehicle);
        }
        
        if (selectedVehicle && passengerCount) {
            checkPassengerCapacity(selectedVehicle, passengerCount);
        }
    }
    
    function checkServiceCompatibility(service, vehicle = null) {
        if (!vehicle) {
            vehicle = getSelectedVehicle();
        }
        
        if (!vehicle) return true;
        
        const compatible = isServiceCompatible(vehicle.model, service);
        
        if (!compatible) {
            showServiceIncompatibilityError(vehicle, service);
            return false;
        }
        
        return true;
    }
    
    function isServiceCompatible(vehicleModel, service) {
        switch (service) {
            case 'ストレッチャー':
                return vehicleConstraints.stretcher_compatible.includes(vehicleModel);
            case '車いす':
                return vehicleConstraints.wheelchair_compatible.includes(vehicleModel);
            case 'リクライニング':
                return vehicleConstraints.reclining_compatible.includes(vehicleModel);
            case 'なし':
            default:
                return true;
        }
    }
    
    function checkPassengerCapacity(vehicle, passengerCount = null) {
        if (!passengerCount) {
            passengerCount = parseInt(document.getElementById('passengerCount').value);
        }
        
        const capacity = vehicleConstraints.capacity[vehicle.model] || 4;
        
        if (passengerCount > capacity) {
            showCapacityExceededWarning(vehicle, passengerCount, capacity);
            return false;
        }
        
        return true;
    }
    
    // =================================================================
    // UI更新機能
    // =================================================================
    
    function updateVehicleOptionsForService(service) {
        const vehicleSelect = document.getElementById('vehicleId');
        if (!vehicleSelect) return;
        
        const vehicles = window.calendarConfig.vehicles || [];
        
        Array.from(vehicleSelect.options).forEach((option, index) => {
            if (index === 0) return; // "選択してください"をスキップ
            
            const vehicleId = parseInt(option.value);
            const vehicle = vehicles.find(v => v.id === vehicleId);
            
            if (vehicle) {
                const compatible = isServiceCompatible(vehicle.model, service);
                
                option.disabled = !compatible;
                option.className = compatible ? '' : 'constraint-incompatible';
                
                // オプションテキスト更新
                let text = `${vehicle.vehicle_number} (${vehicle.model})`;
                if (!compatible) {
                    text += ' - 非対応';
                }
                option.textContent = text;
            }
        });
    }
    
    function updateVehicleInfoDisplay(vehicle) {
        // 車両情報表示エリアがあれば更新
        const infoArea = document.getElementById('vehicleInfoDisplay');
        if (!infoArea) return;
        
        const capacity = vehicleConstraints.capacity[vehicle.model] || 4;
        const compatibleServices = getCompatibleServices(vehicle.model);
        
        infoArea.innerHTML = `
            <div class="vehicle-info-card">
                <h6>🚐 ${vehicle.vehicle_number}</h6>
                <div class="info-item">
                    <span class="label">車種:</span>
                    <span class="value">${vehicle.model}</span>
                </div>
                <div class="info-item">
                    <span class="label">定員:</span>
                    <span class="value">${capacity}名</span>
                </div>
                <div class="info-item">
                    <span class="label">対応サービス:</span>
                    <span class="value">${compatibleServices.join(', ')}</span>
                </div>
            </div>
        `;
    }
    
    function getCompatibleServices(vehicleModel) {
        const services = [];
        
        if (vehicleConstraints.wheelchair_compatible.includes(vehicleModel)) {
            services.push('車いす');
        }
        if (vehicleConstraints.reclining_compatible.includes(vehicleModel)) {
            services.push('リクライニング');
        }
        if (vehicleConstraints.stretcher_compatible.includes(vehicleModel)) {
            services.push('ストレッチャー');
        }
        
        return services.length > 0 ? services : ['基本送迎のみ'];
    }
    
    // =================================================================
    // 警告・エラー表示
    // =================================================================
    
    function showServiceIncompatibilityError(vehicle, service) {
        const message = `${service}は${vehicle.model}（${vehicle.vehicle_number}）では対応できません。` +
                       getAlternativeVehicleSuggestion(service);
        
        showConstraintError(message, 'service-incompatibility');
        
        // フォーム送信防止
        disableFormSubmission('車両とレンタルサービスの組み合わせを確認してください');
    }
    
    function showVehicleIncompatibilityWarning(vehicle, service) {
        const message = `選択中の車両（${vehicle.model}）は${service}に対応していません。`;
        
        showConstraintWarning(message, 'vehicle-incompatibility');
    }
    
    function showCapacityExceededWarning(vehicle, passengerCount, capacity) {
        const message = `${vehicle.model}（${vehicle.vehicle_number}）の定員は${capacity}名です。` +
                       `${passengerCount}名の乗車はできません。`;
        
        showConstraintError(message, 'capacity-exceeded');
        
        // 乗車人数を定員に調整
        document.getElementById('passengerCount').value = capacity;
    }
    
    function getAlternativeVehicleSuggestion(service) {
        const compatibleModels = getCompatibleVehicleModels(service);
        
        if (compatibleModels.length === 0) {
            return 'このサービスに対応できる車両がありません。';
        }
        
        return `\n対応可能車両: ${compatibleModels.join(', ')}`;
    }
    
    function getCompatibleVehicleModels(service) {
        switch (service) {
            case 'ストレッチャー':
                return vehicleConstraints.stretcher_compatible;
            case '車いす':
                return vehicleConstraints.wheelchair_compatible;
            case 'リクライニング':
                return vehicleConstraints.reclining_compatible;
            default:
                return [];
        }
    }
    
    function showConstraintError(message, type) {
        const warning = createConstraintWarning(message, 'error', type);
        addConstraintWarning(warning);
        displayConstraintWarning(warning);
    }
    
    function showConstraintWarning(message, type) {
        const warning = createConstraintWarning(message, 'warning', type);
        addConstraintWarning(warning);
        displayConstraintWarning(warning);
    }
    
    function createConstraintWarning(message, level, type) {
        return {
            id: `constraint-${type}-${Date.now()}`,
            message: message,
            level: level, // 'error', 'warning', 'info'
            type: type,
            timestamp: new Date()
        };
    }
    
    function addConstraintWarning(warning) {
        currentConstraintWarnings.push(warning);
    }
    
    function displayConstraintWarning(warning) {
        const container = getConstraintWarningContainer();
        
        const element = document.createElement('div');
        element.id = warning.id;
        element.className = `constraint-alert constraint-${warning.level}`;
        
        const icon = warning.level === 'error' ? 'fa-times-circle' : 'fa-exclamation-triangle';
        
        element.innerHTML = `
            <div class="constraint-alert-content">
                <i class="fas ${icon} constraint-alert-icon"></i>
                <span class="constraint-alert-message">${warning.message}</span>
                <button type="button" class="constraint-alert-close" onclick="dismissConstraintWarning('${warning.id}')">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        container.appendChild(element);
        
        // アニメーション
        setTimeout(() => {
            element.classList.add('show');
        }, 10);
    }
    
    function getConstraintWarningContainer() {
        let container = document.getElementById('constraintWarningsContainer');
        
        if (!container) {
            container = document.createElement('div');
            container.id = 'constraintWarningsContainer';
            container.className = 'constraint-warnings-container';
            
            // 車両選択フィールドの後に挿入
            const vehicleField = document.getElementById('vehicleId');
            if (vehicleField && vehicleField.parentElement) {
                vehicleField.parentElement.insertAdjacentElement('afterend', container);
            } else {
                // フォール Back：フォームの先頭に挿入
                const form = document.getElementById('reservationForm');
                if (form) {
                    form.insertAdjacentElement('afterbegin', container);
                }
            }
        }
        
        return container;
    }
    
    function clearConstraintWarnings() {
        currentConstraintWarnings = [];
        
        const container = document.getElementById('constraintWarningsContainer');
        if (container) {
            container.innerHTML = '';
        }
        
        // フォーム送信有効化
        enableFormSubmission();
    }
    
    window.dismissConstraintWarning = function(warningId) {
        // 警告を配列から削除
        currentConstraintWarnings = currentConstraintWarnings.filter(w => w.id !== warningId);
        
        // DOM要素を削除
        const element = document.getElementById(warningId);
        if (element) {
            element.classList.remove('show');
            setTimeout(() => element.remove(), 300);
        }
        
        // エラーが全て解決された場合はフォーム送信有効化
        const hasErrors = currentConstraintWarnings.some(w => w.level === 'error');
        if (!hasErrors) {
            enableFormSubmission();
        }
    };
    
    // =================================================================
    // フォーム送信制御
    // =================================================================
    
    function disableFormSubmission(reason) {
        const submitButtons = document.querySelectorAll('#reservationModal .modal-footer .btn-primary, #reservationModal .modal-footer .btn-success');
        
        submitButtons.forEach(button => {
            button.disabled = true;
            button.setAttribute('title', reason);
            button.classList.add('constraint-disabled');
        });
    }
    
    function enableFormSubmission() {
        const submitButtons = document.querySelectorAll('#reservationModal .modal-footer .btn-primary, #reservationModal .modal-footer .btn-success');
        
        submitButtons.forEach(button => {
            button.disabled = false;
            button.removeAttribute('title');
            button.classList.remove('constraint-disabled');
        });
    }
    
    // =================================================================
    // 車両推奨機能
    // =================================================================
    
    function suggestVehicleForDriver(driverId) {
        // 簡易版：運転者IDに基づく車両推奨
        // 実際の実装では運転者の担当車両情報をAPIから取得
        
        const drivers = window.calendarConfig.drivers || [];
        const driver = drivers.find(d => d.id == driverId);
        
        if (!driver) return;
        
        // 運転者名に基づく簡易的な車両推奨
        const vehicleSelect = document.getElementById('vehicleId');
        const vehicles = window.calendarConfig.vehicles || [];
        
        // ここでは簡易的にアルファベット順で推奨
        const suggestedVehicle = vehicles[0]; // 最初の車両を推奨
        
        if (suggestedVehicle && vehicleSelect) {
            vehicleSelect.value = suggestedVehicle.id;
            vehicleSelect.dispatchEvent(new Event('change'));
        }
    }
    
    function suggestOptimalVehicle(service, passengerCount) {
        const vehicles = window.calendarConfig.vehicles || [];
        
        // 条件に合う車両を抽出
        const compatibleVehicles = vehicles.filter(vehicle => {
            const serviceOk = isServiceCompatible(vehicle.model, service);
            const capacityOk = (vehicleConstraints.capacity[vehicle.model] || 4) >= passengerCount;
            return serviceOk && capacityOk;
        });
        
        if (compatibleVehicles.length === 0) return null;
        
        // 最適な車両を選択（定員が条件に近い車両を優先）
        compatibleVehicles.sort((a, b) => {
            const capacityA = vehicleConstraints.capacity[a.model] || 4;
            const capacityB = vehicleConstraints.capacity[b.model] || 4;
            return capacityA - capacityB;
        });
        
        return compatibleVehicles[0];
    }
    
    // =================================================================
    // ユーティリティ関数
    // =================================================================
    
    function getSelectedVehicle() {
        const vehicleId = document.getElementById('vehicleId').value;
        if (!vehicleId) return null;
        
        return getVehicleById(vehicleId);
    }
    
    function getVehicleById(vehicleId) {
        const vehicles = window.calendarConfig.vehicles || [];
        return vehicles.find(v => v.id == vehicleId);
    }
    
    function getSelectedService() {
        return document.getElementById('rentalService').value;
    }
    
    function getSelectedPassengerCount() {
        return parseInt(document.getElementById('passengerCount').value) || 1;
    }
    
    // =================================================================
    // 外部公開関数
    // =================================================================
    
    window.vehicleConstraintsAPI = {
        checkCompatibility: checkServiceCompatibility,
        suggestVehicle: suggestOptimalVehicle,
        clearWarnings: clearConstraintWarnings,
        isServiceCompatible: isServiceCompatible,
        checkCapacity: checkPassengerCapacity,
        
        // 制約データ取得
        getConstraints: () => vehicleConstraints,
        getCurrentWarnings: () => currentConstraintWarnings
    };
    
    // =================================================================
    // 初期化実行
    // =================================================================
    
    initializeVehicleConstraints();
});

// =================================================================
// CSS（JavaScript内でスタイル定義）
// =================================================================

// 制約警告用のスタイルを動的に追加
(function() {
    const style = document.createElement('style');
    style.textContent = `
        .constraint-warnings-container {
            margin: 15px 0;
        }
        
        .constraint-alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 8px;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }
        
        .constraint-alert.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .constraint-alert.constraint-error {
            background-color: #ffebee;
            border: 1px solid #f44336;
            color: #c62828;
        }
        
        .constraint-alert.constraint-warning {
            background-color: #fff3e0;
            border: 1px solid #ff9800;
            color: #f57c00;
        }
        
        .constraint-alert-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .constraint-alert-icon {
            flex-shrink: 0;
            font-size: 16px;
        }
        
        .constraint-alert-message {
            flex-grow: 1;
            font-weight: 500;
            line-height: 1.4;
        }
        
        .constraint-alert-close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            opacity: 0.7;
            transition: opacity 0.2s ease;
        }
        
        .constraint-alert-close:hover {
            opacity: 1;
        }
        
        .constraint-incompatible {
            color: #999 !important;
            font-style: italic;
        }
        
        .constraint-disabled {
            opacity: 0.6;
            cursor: not-allowed !important;
        }
        
        .vehicle-info-card {
            background-color: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
            margin-top: 10px;
        }
        
        .vehicle-info-card h6 {
            margin: 0 0 8px 0;
            color: #2c3e50;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
            font-size: 13px;
        }
        
        .info-item .label {
            color: #6c757d;
        }
        
        .info-item .value {
            font-weight: 500;
            color: #2c3e50;
        }
    `;
    
    document.head.appendChild(style);
})();
