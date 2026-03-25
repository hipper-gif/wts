<?php
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/session_check.php';

// ログインチェック
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '認証が必要です']);
    exit;
}

// Admin権限チェック
if ($_SESSION['permission_level'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '管理者権限が必要です']);
    exit;
}

// POSTメソッドチェック
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POSTメソッドが必要です']);
    exit;
}

// CSRF検証
validateCsrfToken();

try {
    // ファイル存在チェック
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'ファイルのアップロードに失敗しました';
        if (isset($_FILES['file'])) {
            switch ($_FILES['file']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $error_message = 'ファイルサイズが大きすぎます';
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $error_message = 'ファイルが選択されていません';
                    break;
            }
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $error_message]);
        exit;
    }

    $file = $_FILES['file'];

    // ファイルサイズチェック (10MB以下)
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ファイルサイズは10MB以下にしてください']);
        exit;
    }

    // MIMEタイプ検証 (finfo)
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($detectedMime, $allowedMimes, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '許可されていないファイル形式です（PDF, JPG, PNG のみ）']);
        exit;
    }

    // 拡張子チェック
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    $originalFilename = $file['name'];
    $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

    if (!in_array($ext, $allowedExts, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => '許可されていない拡張子です（.pdf, .jpg, .jpeg, .png のみ）']);
        exit;
    }

    // UUID v4 生成
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    // 保存パス生成
    $year = date('Y');
    $month = date('m');
    $storedFilename = $uuid . '.' . $ext;
    $relativeDir = 'uploads/documents/' . $year . '/' . $month;
    $relativePath = $relativeDir . '/' . $storedFilename;
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;

    // ディレクトリ作成
    if (!is_dir($absoluteDir)) {
        if (!mkdir($absoluteDir, 0755, true)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'ディレクトリの作成に失敗しました']);
            exit;
        }
    }

    // ファイル保存
    $absolutePath = $absoluteDir . '/' . $storedFilename;
    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'ファイルの保存に失敗しました']);
        exit;
    }

    // POSTパラメータ取得
    $title = trim($_POST['title'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $expiryDate = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $relatedDriverId = !empty($_POST['related_driver_id']) ? (int)$_POST['related_driver_id'] : null;
    $relatedVehicleId = !empty($_POST['related_vehicle_id']) ? (int)$_POST['related_vehicle_id'] : null;
    $description = trim($_POST['description'] ?? '');

    if ($title === '') {
        // ファイルを削除してエラー返却
        @unlink($absolutePath);
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'タイトルは必須です']);
        exit;
    }

    // DB登録
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        INSERT INTO documents (
            title, category, expiry_date, related_driver_id, related_vehicle_id,
            description, original_filename, stored_filename, file_path,
            file_size, mime_type, uploaded_by, created_at
        ) VALUES (
            :title, :category, :expiry_date, :related_driver_id, :related_vehicle_id,
            :description, :original_filename, :stored_filename, :file_path,
            :file_size, :mime_type, :uploaded_by, NOW()
        )
    ");
    $stmt->execute([
        ':title' => $title,
        ':category' => $category,
        ':expiry_date' => $expiryDate,
        ':related_driver_id' => $relatedDriverId,
        ':related_vehicle_id' => $relatedVehicleId,
        ':description' => $description,
        ':original_filename' => $originalFilename,
        ':stored_filename' => $storedFilename,
        ':file_path' => $relativePath,
        ':file_size' => $file['size'],
        ':mime_type' => $detectedMime,
        ':uploaded_by' => $_SESSION['user_id'],
    ]);

    $documentId = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'document_id' => (int)$documentId
    ]);

} catch (Exception $e) {
    error_log("document_upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'サーバーエラーが発生しました']);
}
