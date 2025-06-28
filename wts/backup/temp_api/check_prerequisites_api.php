<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

// 簡易的な応答（実際のチェックは後で実装）
echo json_encode([
    "pre_duty_completed" => true,
    "inspection_completed" => true,
    "already_departed" => false,
    "can_depart" => true
]);
?>