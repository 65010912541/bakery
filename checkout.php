<?php
declare(strict_types=1);
require __DIR__ . "/auth.php";
require_login("cart.php");
$u = current_user();

if (empty($u["id"])) {
  http_response_code(401);
  echo json_encode(["ok" => false, "message" => "กรุณาเข้าสู่ระบบใหม่"]);
  exit;
}

require __DIR__ . "/config/db.php";

header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "message" => "Method not allowed"]);
  exit;
}

// รับจาก FormData
$name    = trim((string)($_POST["customer_name"] ?? ""));
$phone   = trim((string)($_POST["customer_phone"] ?? ""));
$address = trim((string)($_POST["customer_address"] ?? ""));
$note    = trim((string)($_POST["note"] ?? ""));
$itemsRaw = (string)($_POST["items"] ?? "");

// วิธีชำระเงิน (เราจะใช้ promptpay เป็นหลัก)
$paymentMethod = trim((string)($_POST["payment_method"] ?? "promptpay"));
if ($paymentMethod === "") $paymentMethod = "promptpay";

$allowedMethods = ["promptpay"];
if (!in_array($paymentMethod, $allowedMethods, true)) {
  $paymentMethod = "promptpay";
}

// items มาเป็น JSON string
$items = json_decode($itemsRaw, true);
if (!is_array($items)) $items = [];


if ($name === "" || $phone === "" || $address === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "กรุณากรอกชื่อ/เบอร์/ที่อยู่ให้ครบ"]);
  exit;
}

if (!is_array($items) || count($items) === 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "ตะกร้าว่าง"]);
  exit;
}

$slipUrl = null;

// ถ้าเป็น promptpay ต้องมีสลิป
if ($paymentMethod === "promptpay") {
  if (empty($_FILES["payment_slip"]) || ($_FILES["payment_slip"]["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(["ok" => false, "message" => "กรุณาแนบสลิปการโอนเงิน"]);
    exit;
  }

  $f = $_FILES["payment_slip"];
  $tmp = (string)$f["tmp_name"];
  $size = (int)$f["size"];

  if ($size <= 0 || $size > 5 * 1024 * 1024) {
    http_response_code(422);
    echo json_encode(["ok" => false, "message" => "ไฟล์สลิปใหญ่เกินไป (สูงสุด 5MB)"]);
    exit;
  }

  $mime = mime_content_type($tmp) ?: "";
  $ext = match ($mime) {
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
    default => ""
  };

  if ($ext === "") {
    http_response_code(422);
    echo json_encode(["ok" => false, "message" => "ไฟล์สลิปต้องเป็นรูป jpg/png/webp"]);
    exit;
  }

  $dir = __DIR__ . "/assets/uploads/slips";
  if (!is_dir($dir)) @mkdir($dir, 0775, true);

  $filename = "slip_" . date("Ymd_His") . "_" . bin2hex(random_bytes(6)) . "." . $ext;
  $path = $dir . "/" . $filename;

  if (!move_uploaded_file($tmp, $path)) {
    http_response_code(500);
    echo json_encode(["ok" => false, "message" => "อัปโหลดสลิปไม่สำเร็จ"]);
    exit;
  }

  $uploadedAbsPath = $path;

  $slipUrl = "/project/assets/uploads/slips/" . $filename; // path เก็บใน DB
}

// sanitize items
$cleanItems = [];
foreach ($items as $it) {
  $pid = (int)($it["id"] ?? 0);
  $qty = (int)($it["qty"] ?? 0);
  $itemNote = trim((string)($it["note"] ?? ""));

  if ($pid <= 0 || $qty <= 0) continue;

  $cleanItems[] = [
    "id" => $pid,
    "qty" => $qty,
    "note" => $itemNote
  ];
}

if (count($cleanItems) === 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "message" => "รายการสินค้าไม่ถูกต้อง"]);
  exit;
}

try {
  $pdo->beginTransaction();

  // สร้าง order_no
  // ตัวอย่าง: BK20260216-AB12CD
  $orderNo = "BK" . date("Ymd") . "-" . strtoupper(substr(bin2hex(random_bytes(6)), 0, 6));

  // เตรียม map สินค้า: id => qtyรวม (ในกรณีมี id ซ้ำหลายบรรทัด)
  $qtyByProduct = [];
  foreach ($cleanItems as $ci) {
    $qtyByProduct[$ci["id"]] = ($qtyByProduct[$ci["id"]] ?? 0) + $ci["qty"];
  }
  $productIds = array_keys($qtyByProduct);

  // ล็อกแถวสินค้าเพื่อเช็ค stock (FOR UPDATE)
  $in = implode(",", array_fill(0, count($productIds), "?"));
  $stmt = $pdo->prepare("
    SELECT id, name, price, stock, status
    FROM products
    WHERE id IN ($in)
    FOR UPDATE
  ");
  $stmt->execute($productIds);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  // ตรวจว่าครบทุกตัว
  $productMap = [];
  foreach ($rows as $r) $productMap[(int)$r["id"]] = $r;

  foreach ($productIds as $pid) {
    if (!isset($productMap[$pid])) {
      throw new RuntimeException("ไม่พบสินค้า ID: $pid");
    }
    if (($productMap[$pid]["status"] ?? "") !== "active") {
      throw new RuntimeException("สินค้าบางรายการไม่พร้อมขาย: " . $productMap[$pid]["name"]);
    }
    $need = $qtyByProduct[$pid];
    $stock = (int)$productMap[$pid]["stock"];
    if ($stock < $need) {
      throw new RuntimeException("สต็อกไม่พอ: {$productMap[$pid]["name"]} (เหลือ $stock)");
    }
  }

  // คำนวณยอดรวมจากราคาใน DB (ไม่เชื่อราคาจากฝั่ง client)
  $totalQty = 0;
  $totalAmount = 0.0;

  // บันทึก order
  $stmtOrder = $pdo->prepare("
    INSERT INTO orders
      (order_no, user_id, customer_name, customer_phone, customer_address, note,
      total_qty, total_amount, status,
      payment_method, payment_status, payment_slip_url)
    VALUES
      (:order_no, :user_id, :name, :phone, :address, :note,
      0, 0, 'pending',
      :pm, :ps, :slip)
  ");

  $stmtOrder->execute([
    ":order_no" => $orderNo,
    ":user_id"  => (int)($u["id"] ?? 0),
    ":name"     => $name,
    ":phone"    => $phone,
    ":address"  => $address,
    ":note"     => ($note === "" ? null : $note),
    ":pm"       => $paymentMethod,                 // promptpay
    ":ps"       => "pending_verify",               // รอตรวจสลิป
    ":slip"     => $slipUrl
  ]);

  $orderId = (int)$pdo->lastInsertId();

  // บันทึก order_items (ตามบรรทัดในตะกร้า เพื่อแยก note ได้)
  $stmtItem = $pdo->prepare("
    INSERT INTO order_items (order_id, product_id, product_name, unit_price, qty, note, line_total)
    VALUES (:order_id, :product_id, :product_name, :unit_price, :qty, :note, :line_total)
  ");

  foreach ($cleanItems as $ci) {
    $p = $productMap[$ci["id"]];
    $unitPrice = (float)$p["price"];
    $lineTotal = $unitPrice * $ci["qty"];

    $totalQty += $ci["qty"];
    $totalAmount += $lineTotal;

    $stmtItem->execute([
      ":order_id" => $orderId,
      ":product_id" => (int)$ci["id"],
      ":product_name" => (string)$p["name"],
      ":unit_price" => $unitPrice,
      ":qty" => (int)$ci["qty"],
      ":note" => ($ci["note"] === "" ? null : $ci["note"]),
      ":line_total" => $lineTotal,
    ]);
  }

  // อัปเดตยอดรวมใน orders
  $stmtUpd = $pdo->prepare("
    UPDATE orders
    SET total_qty = :total_qty, total_amount = :total_amount
    WHERE id = :id
  ");
  $stmtUpd->execute([
    ":total_qty" => $totalQty,
    ":total_amount" => $totalAmount,
    ":id" => $orderId,
  ]);

  // ตัด stock
  $stmtStock = $pdo->prepare("UPDATE products SET stock = stock - :qty WHERE id = :id");
  foreach ($qtyByProduct as $pid => $needQty) {
    $stmtStock->execute([":qty" => $needQty, ":id" => $pid]);
  }

  $pdo->commit();

  echo json_encode([
    "ok" => true,
    "order_id" => $orderId,
    "order_no" => $orderNo,
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  // ลบไฟล์สลิป ถ้า DB บันทึกไม่สำเร็จ
  if (!empty($uploadedAbsPath) && file_exists($uploadedAbsPath)) {
    @unlink($uploadedAbsPath);
  }

  http_response_code(400);
  echo json_encode(["ok" => false, "message" => $e->getMessage()]);
  exit;
}

