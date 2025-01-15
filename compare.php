<?php
require_once 'config.php';

function getComparison($category_id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM products WHERE category_id = ?");
    $stmt->execute([$category_id]);
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
