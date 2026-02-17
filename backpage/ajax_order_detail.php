<?php
declare(strict_types=1);

require __DIR__ . "/../config/db.php";
header("Content-Type: application/json; charset=utf-8");

$id = (int)($_GET["id"] ?? 0);
if ($id <= 0) {
  echo json_encode(["error" => "invalid id"]);
  exit;
}

// เช็คว่า order มีจริงไหม (กันยิงมั่ว)
$chk = $pdo->prepare("SELECT id FROM orders WHERE id=?");
$chk->execute([$id]);
if (!$chk->fetchColumn()) {
  echo json_encode(["error" => "order not found"]);
  exit;
}

$stmt = $pdo->prepare("
  SELECT product_name, unit_price, qty, line_total
  FROM order_items
  WHERE order_id=?
  ORDER BY id ASC
");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  "items" => $items
]);
