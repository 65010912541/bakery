<?php
declare(strict_types=1);
require __DIR__ . "/auth.php";
require_login("orders_history.php");
$u = current_user();

require __DIR__ . "/config/db.php";

$userId = (int)($u["id"] ?? 0);

// --- filters
$q  = trim((string)($_GET["q"] ?? ""));
$st = trim((string)($_GET["status"] ?? ""));          // order status
$ps = trim((string)($_GET["payment_status"] ?? ""));  // payment status

$allowedSt = ["pending","confirmed","shipped","completed","cancelled"];
$allowedPs = ["pending_verify","paid","rejected"];

if ($st !== "" && !in_array($st, $allowedSt, true)) $st = "";
if ($ps !== "" && !in_array($ps, $allowedPs, true)) $ps = "";

// --- build SQL
$where = ["user_id = :uid"];
$params = [":uid" => $userId];

if ($q !== "") {
  // รองรับค้นหาเลขออเดอร์/ชื่อ/เบอร์
  $where[] = "(order_no LIKE :q OR customer_name LIKE :q OR customer_phone LIKE :q)";
  $params[":q"] = "%" . $q . "%";
}
if ($st !== "") {
  $where[] = "status = :st";
  $params[":st"] = $st;
}
if ($ps !== "") {
  $where[] = "payment_status = :ps";
  $params[":ps"] = $ps;
}

$sql = "
  SELECT id, order_no, customer_name, customer_phone, customer_address, note,
         total_qty, total_amount, status, payment_method, payment_status, payment_slip_url
  FROM orders
  WHERE " . implode(" AND ", $where) . "
  ORDER BY id DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- helpers
function statusText(string $status): string {
  return match ($status) {
    "pending" => "รอดำเนินการ",
    "confirmed" => "ยืนยันแล้ว",
    "shipped" => "จัดส่งแล้ว",
    "completed" => "สำเร็จ",
    "cancelled" => "ยกเลิก",
    default => "ไม่ทราบสถานะ"
  };
}
function statusBadge(string $status): string {
  return match ($status) {
    "pending" => '<span class="badge bg-secondary">รอดำเนินการ</span>',
    "confirmed" => '<span class="badge bg-info text-dark">ยืนยันแล้ว</span>',
    "shipped" => '<span class="badge bg-primary">จัดส่งแล้ว</span>',
    "completed" => '<span class="badge bg-success">สำเร็จ</span>',
    "cancelled" => '<span class="badge bg-danger">ยกเลิก</span>',
    default => '<span class="badge bg-light text-dark">ไม่ทราบ</span>'
  };
}
function paymentBadge(string $status): string {
  return match ($status) {
    "pending_verify" => '<span class="badge bg-warning text-dark">รอตรวจสลิป</span>',
    "paid" => '<span class="badge bg-success">ชำระแล้ว</span>',
    "rejected" => '<span class="badge bg-danger">สลิปไม่ถูกต้อง</span>',
    default => '<span class="badge bg-secondary">ไม่ทราบ</span>'
  };
}
function slipUrl(?string $url): string {
  $url = trim((string)$url);
  if ($url === "") return "";
  if (preg_match('~^(https?://|/)~', $url)) return $url;

  // ของเก่า: uploads/...
  if (str_starts_with($url, "uploads/")) {
    return "/project/assets/" . $url;
  }
  return "/project/" . $url;
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ประวัติการสั่งซื้อ | Bakery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  body{ font-family:"Kanit",sans-serif; }
  .card-soft{
    border:1px solid rgba(0,0,0,.06);
    border-radius:18px;
    background:#fff;
    box-shadow:0 10px 25px rgba(0,0,0,.05);
  }
  .pill{
    display:inline-flex; align-items:center; gap:.45rem;
    padding:.35rem .7rem; border-radius:999px;
    background:rgba(0,0,0,.04); border:1px solid rgba(0,0,0,.06);
    font-size:.9rem; color:rgba(0,0,0,.7);
  }
  .acc-item{
    border:1px solid rgba(0,0,0,.06);
    border-radius:18px;
    overflow:hidden;
    background:#fff;
  }
  .acc-btn{ background:transparent; border:0; padding:0; width:100%; text-align:left; }
  .acc-head{ padding:14px 16px; }
  .table > :not(caption) > * > *{ padding:.65rem .75rem; }
</style>
</head>
<body>

<?php require __DIR__ . "/partials/nav.php"; ?>

<main class="container my-4">

  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
      <div class="text-muted small">ดูรายการย้อนหลังและสถานะ</div>
      <h1 class="h4 fw-semibold mb-0">ประวัติการสั่งซื้อ</h1>
    </div>

    <a href="orders.php" class="btn btn-outline-dark rounded-pill">
      <i class="bi bi-ui-checks-grid me-1"></i> ดูแบบ Stepper
    </a>
  </div>

  <!-- Filters -->
  <div class="card-soft p-3 p-md-4 mb-3">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-6">
        <label class="form-label mb-1">ค้นหา</label>
        <input class="form-control"
               name="q"
               value="<?= htmlspecialchars($q) ?>"
               placeholder="เลขออเดอร์ / ชื่อผู้รับ / เบอร์โทร">
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label mb-1">สถานะออเดอร์</label>
        <select class="form-select" name="status">
          <option value="">ทั้งหมด</option>
          <?php foreach (["pending","confirmed","shipped","completed","cancelled"] as $x): ?>
            <option value="<?= $x ?>" <?= $st===$x?"selected":"" ?>>
              <?= htmlspecialchars(statusText($x)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-3">
        <label class="form-label mb-1">สถานะชำระเงิน</label>
        <select class="form-select" name="payment_status">
          <option value="">ทั้งหมด</option>
          <option value="pending_verify" <?= $ps==="pending_verify"?"selected":"" ?>>รอตรวจสลิป</option>
          <option value="paid" <?= $ps==="paid"?"selected":"" ?>>ชำระแล้ว</option>
          <option value="rejected" <?= $ps==="rejected"?"selected":"" ?>>สลิปไม่ถูกต้อง</option>
        </select>
      </div>

      <div class="col-12 d-flex gap-2">
        <button class="btn btn-dark rounded-pill px-4" type="submit">
          <i class="bi bi-search me-1"></i> ค้นหา
        </button>
        <a class="btn btn-outline-secondary rounded-pill px-4" href="orders_history.php">
          ล้างตัวกรอง
        </a>
      </div>
    </form>
  </div>

  <?php if (!$orders): ?>
    <div class="alert alert-light border rounded-4 text-center py-5">
      <div class="h5 mb-2">ไม่พบรายการสั่งซื้อ</div>
      <div class="text-muted mb-3">ลองเปลี่ยนคำค้นหาหรือยกเลิกตัวกรองดู</div>
      <a href="index.php" class="btn btn-brand rounded-pill px-4">ไปเลือกสินค้า</a>
    </div>
  <?php else: ?>

    <div class="accordion" id="historyAcc">
      <?php foreach ($orders as $order): ?>
        <?php
          $oid = (int)$order["id"];

          $stmtItems = $pdo->prepare("
            SELECT product_name, unit_price, qty, line_total
            FROM order_items
            WHERE order_id = :oid
          ");
          $stmtItems->execute([":oid" => $oid]);
          $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

          $slip = slipUrl($order["payment_slip_url"] ?? "");
        ?>

        <div class="accordion-item mb-3 border-0">
          <div class="acc-item">
            <h2 class="accordion-header" id="h<?= $oid ?>">
              <button class="accordion-button collapsed acc-btn"
                      type="button"
                      data-bs-toggle="collapse"
                      data-bs-target="#c<?= $oid ?>"
                      aria-expanded="false"
                      aria-controls="c<?= $oid ?>">
                <div class="acc-head w-100">
                  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                      <div class="fw-semibold">#<?= htmlspecialchars((string)$order["order_no"]) ?></div>
                      <div class="small text-muted"><?= htmlspecialchars((string)$order["customer_name"]) ?> • <?= htmlspecialchars((string)$order["customer_phone"]) ?></div>
                    </div>
                    <div class="text-end">
                      <?= statusBadge((string)$order["status"]) ?>
                      <?= paymentBadge((string)($order["payment_status"] ?? "")) ?>
                      <div class="fw-bold mt-1"><?= number_format((float)$order["total_amount"], 2) ?> บาท</div>
                    </div>
                  </div>
                </div>
              </button>
            </h2>

            <div id="c<?= $oid ?>" class="accordion-collapse collapse" aria-labelledby="h<?= $oid ?>" data-bs-parent="#historyAcc">
              <div class="accordion-body p-3 p-md-4">

                <div class="mb-3">
                  <div class="pill"><i class="bi bi-geo-alt"></i> ที่อยู่จัดส่ง</div>
                  <div class="text-muted mt-2"><?= nl2br(htmlspecialchars((string)$order["customer_address"])) ?></div>
                </div>

                <?php if (!empty($order["note"])): ?>
                  <div class="mb-3">
                    <div class="pill"><i class="bi bi-chat-left-text"></i> หมายเหตุ</div>
                    <div class="text-muted mt-2"><?= nl2br(htmlspecialchars((string)$order["note"])) ?></div>
                  </div>
                <?php endif; ?>

                <div class="table-responsive">
                  <table class="table align-middle mb-0">
                    <thead>
                      <tr>
                        <th>สินค้า</th>
                        <th class="text-end">ราคา</th>
                        <th class="text-center">จำนวน</th>
                        <th class="text-end">รวม</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($items as $it): ?>
                        <tr>
                          <td><?= htmlspecialchars((string)$it["product_name"]) ?></td>
                          <td class="text-end"><?= number_format((float)$it["unit_price"], 2) ?></td>
                          <td class="text-center"><?= (int)$it["qty"] ?></td>
                          <td class="text-end"><?= number_format((float)$it["line_total"], 2) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <?php if ($slip !== ""): ?>
                  <div class="mt-4">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                      <span class="pill"><i class="bi bi-receipt"></i> สลิปที่แนบ</span>
                      <a href="<?= htmlspecialchars($slip) ?>" target="_blank" class="btn btn-sm btn-outline-dark rounded-pill">
                        ดูสลิป
                      </a>
                    </div>

                    <div class="mt-3">
                      <img src="<?= htmlspecialchars($slip) ?>"
                           class="img-fluid rounded-4 border"
                           style="max-height:420px; object-fit:contain;"
                           alt="Slip">
                    </div>
                  </div>
                <?php endif; ?>

              </div>
            </div>
          </div>
        </div>

      <?php endforeach; ?>
    </div>

  <?php endif; ?>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
