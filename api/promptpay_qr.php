<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/../config/db.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

$PROMPTPAY_ID = "0972370002"; // <-- ใส่เบอร์/เลขบัตร/เลขภาษี ของร้าน

$raw = file_get_contents("php://input") ?: "";
$data = json_decode($raw, true);

$items = $data["items"] ?? [];
if (!is_array($items) || count($items) === 0) {
  http_response_code(422);
  echo json_encode(["ok"=>false, "message"=>"ตะกร้าว่าง"]);
  exit;
}

$clean = [];
foreach ($items as $it) {
  if (!is_array($it)) continue;
  $id = (int)($it["id"] ?? 0);
  $qty = (int)($it["qty"] ?? 0);
  if ($id <= 0 || $qty <= 0) continue;
  if ($qty > 999) $qty = 999;
  $clean[] = ["id"=>$id, "qty"=>$qty];
}
if (!$clean) {
  http_response_code(422);
  echo json_encode(["ok"=>false, "message"=>"รายการไม่ถูกต้อง"]);
  exit;
}

// รวม id ซ้ำ
$merged = [];
foreach ($clean as $it) $merged[$it["id"]] = ($merged[$it["id"]] ?? 0) + $it["qty"];
$ids = array_keys($merged);

$placeholders = implode(",", array_fill(0, count($ids), "?"));
$stmt = $pdo->prepare("SELECT id, price FROM products WHERE id IN ($placeholders)");
$stmt->execute($ids);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$map = [];
foreach ($rows as $r) $map[(int)$r["id"]] = (float)$r["price"];

$total = 0.0;
foreach ($merged as $pid => $qty) {
  if (!isset($map[(int)$pid])) {
    http_response_code(400);
    echo json_encode(["ok"=>false, "message"=>"ไม่พบสินค้า ID: ".$pid]);
    exit;
  }
  $total += $map[(int)$pid] * (int)$qty;
}

$amount = round($total, 2);
$payload = buildPromptPayPayload($PROMPTPAY_ID, $amount);

echo json_encode(["ok"=>true, "amount"=>$amount, "payload"=>$payload]);

function buildPromptPayPayload(string $id, float $amount): string {
  $id = preg_replace('/\D+/', '', $id) ?? "";
  $isPhone = strlen($id) === 10;

  $aid = "0016A000000677010111"; // PromptPay AID

  if ($isPhone) {
    $pp = "0066" . substr($id, 1); // 0xxxxxxxxx -> 66xxxxxxxxx
    $merchant = $aid . tlv("01", $pp);
  } else {
    $merchant = $aid . tlv("02", $id); // เลขบัตร/เลขภาษี 13 หลัก
  }

  $payload =
    "000201" .
    "010212" .
    tlv("29", $merchant) .
    "5802TH" .
    "5303764" .
    tlv("54", number_format($amount, 2, ".", "")) .
    "6304";

  return $payload . strtoupper(crc16ccitt($payload));
}

function tlv(string $id, string $value): string {
  $len = str_pad((string)strlen($value), 2, "0", STR_PAD_LEFT);
  return $id . $len . $value;
}

function crc16ccitt(string $data): string {
  $crc = 0xFFFF;
  for ($i=0; $i<strlen($data); $i++) {
    $crc ^= (ord($data[$i]) << 8);
    for ($b=0; $b<8; $b++) {
      $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
    }
  }
  return str_pad(dechex($crc), 4, "0", STR_PAD_LEFT);
}
