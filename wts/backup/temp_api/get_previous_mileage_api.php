<?php
session_start();
header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit();
}

// 簡易的な応答
echo json_encode([
    "previous_mileage" => 50000,
    "date" => "2024-12-01"
]);
?>