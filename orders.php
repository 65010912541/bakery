<?php
declare(strict_types=1);
require __DIR__ . "/auth.php";
require_login("orders.php");
$u = current_user();

require __DIR__ . "/config/db.php";

$userId = (int)($u["id"] ?? 0);

// ดึงรายการ order ของ user คนนี้
$stmt = $pdo->prepare("
  SELECT *
  FROM orders
  WHERE user_id = :uid
  ORDER BY id DESC
");
$stmt->execute([":uid" => $userId]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

function statusBadge(string $status): string {
  return match ($status) {
    "pending" => '<span class="badge bg-secondary">รอดำเนินการ</span>',
    "confirmed" => '<span class="badge bg-info text-dark">ยืนยันแล้ว</span>',
    "shipped" => '<span class="badge bg-primary">จัดส่งแล้ว</span>',
    "completed" => '<span class="badge bg-success">สำเร็จ</span>',
    "cancelled" => '<span class="badge bg-danger">ยกเลิก</span>',
    default => '<span class="badge bg-light text-dark">ไม่ทราบสถานะ</span>'
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

/**
 * สร้าง Stepper state (ไม่ต้องเพิ่มคอลัมน์ใน DB)
 * - step1: payment verify
 * - step2: order confirmed
 * - step3: shipped
 * - step4: completed
 */
function buildSteps(string $orderStatus, string $payStatus): array {
  // index 1..4
  $steps = [
    1 => ["title" => "รอตรวจสลิป", "desc" => "แนบสลิปและรอตรวจสอบ", "state" => "todo"],
    2 => ["title" => "ยืนยันออเดอร์", "desc" => "ร้านค้ารับออเดอร์แล้ว", "state" => "todo"],
    3 => ["title" => "จัดส่ง", "desc" => "กำลังจัดส่งสินค้า", "state" => "todo"],
    4 => ["title" => "สำเร็จ", "desc" => "รับสินค้าเรียบร้อย", "state" => "todo"],
  ];

  if ($orderStatus === "cancelled") {
    // ทุกขั้น fail
    foreach ($steps as $k => $v) $steps[$k]["state"] = "fail";
    return $steps;
  }

  // Step1
  if ($payStatus === "rejected") {
    $steps[1]["state"] = "fail";
    return $steps;
  }
  if ($payStatus === "paid") {
    $steps[1]["state"] = "done";
  } else {
    $steps[1]["state"] = "doing"; // pending_verify
    return $steps;
  }

  // Step2+
  if ($orderStatus === "confirmed") {
    $steps[2]["state"] = "done";
    $steps[3]["state"] = "doing";
    return $steps;
  }

  if ($orderStatus === "shipped") {
    $steps[2]["state"] = "done";
    $steps[3]["state"] = "done";
    $steps[4]["state"] = "doing";
    return $steps;
  }

  if ($orderStatus === "completed") {
    $steps[2]["state"] = "done";
    $steps[3]["state"] = "done";
    $steps[4]["state"] = "done";
    return $steps;
  }

  // pending (แต่จ่ายแล้ว)
  $steps[2]["state"] = "doing";
  return $steps;
}

?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>คำสั่งซื้อของฉัน | Bakery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

<style>
  /* Card */
  .order-card{
    border: 1px solid rgba(0,0,0,.06);
    border-radius: 18px;
    overflow: hidden;
    background: #fff;
    box-shadow: 0 10px 25px rgba(0,0,0,.05);
  }
  .order-head{
    padding: 14px 16px;
    background: rgba(255,255,255,.7);
  }
  .order-meta{
    display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;
  }
  .order-no{ font-weight: 700; }
  .order-sub{ color: rgba(0,0,0,.55); font-size: .9rem; }

  /* Stepper */
  .stepper{
    display:grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-top: 12px;
  }
  .step{
    position: relative;
    padding: 12px 12px 12px 14px;
    border-radius: 16px;
    border: 1px solid rgba(0,0,0,.06);
    background: rgba(0,0,0,.02);
    min-height: 74px;
  }
  .step::before{
    content:"";
    position:absolute;
    left: 12px; top: 14px;
    width: 10px; height: 10px;
    border-radius: 50%;
    background: rgba(0,0,0,.25);
  }
  .step-title{ font-weight: 700; font-size:.95rem; padding-left: 14px; }
  .step-desc{ font-size:.82rem; color: rgba(0,0,0,.55); padding-left: 14px; margin-top: 3px; }

  /* state colors */
  .step.done{ background: rgba(25,135,84,.08); border-color: rgba(25,135,84,.20); }
  .step.done::before{ background: #198754; }

  .step.doing{ background: rgba(13,110,253,.08); border-color: rgba(13,110,253,.20); }
  .step.doing::before{ background: #0d6efd; }

  .step.fail{ background: rgba(220,53,69,.08); border-color: rgba(220,53,69,.20); }
  .step.fail::before{ background: #dc3545; }

  /* accordion button custom */
  .acc-btn{
    width:100%;
    text-align:left;
    background: transparent;
    border:0;
    padding:0;
  }
  .order-body{
    padding: 16px;
    border-top: 1px solid rgba(0,0,0,.06);
  }

  .table > :not(caption) > * > *{ padding: .65rem .75rem; }
</style>
</head>
<body>

<?php require __DIR__ . "/partials/nav.php"; ?>

<div class="container my-4">

  <div class="d-flex justify-content-between align-items-center mb-4">
    <div>
      <div class="text-muted small">ติดตามสถานะคำสั่งซื้อ</div>
      <h1 class="h4 fw-semibold mb-0">คำสั่งซื้อของฉัน</h1>
    </div>
  </div>

  <?php if (!$orders): ?>
    <div class="alert alert-light border rounded-4 text-center py-5">
      <div class="h5 mb-2">ยังไม่มีคำสั่งซื้อ</div>
      <a href="index.php" class="btn btn-brand rounded-pill px-4">ไปเลือกสินค้า</a>
    </div>
  <?php else: ?>

    <div class="accordion" id="orderAccordion">
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

          $steps = buildSteps((string)$order["status"], (string)($order["payment_status"] ?? ""));
          $slip = slipUrl($order["payment_slip_url"] ?? "");
        ?>

        <div class="accordion-item mb-3 border-0">
          <div class="order-card">

            <h2 class="accordion-header" id="h<?= $oid ?>">
              <button class="accordion-button collapsed acc-btn"
                      type="button"
                      data-bs-toggle="collapse"
                      data-bs-target="#c<?= $oid ?>"
                      aria-expanded="false"
                      aria-controls="c<?= $oid ?>">
                <div class="order-head w-100">
                  <div class="order-meta">
                    <div>
                      <div class="order-no">#<?= htmlspecialchars((string)$order["order_no"]) ?></div>
                      <div class="order-sub"><?= htmlspecialchars((string)$order["customer_name"]) ?></div>
                    </div>
                    <div class="text-end">
                      <?= statusBadge((string)$order["status"]) ?>
                      <?= paymentBadge((string)($order["payment_status"] ?? "")) ?>
                      <div class="fw-bold mt-1"><?= number_format((float)$order["total_amount"], 2) ?> บาท</div>
                    </div>
                  </div>

                  <!-- Stepper -->
                  <div class="stepper">
                    <?php foreach ($steps as $s): ?>
                      <div class="step <?= htmlspecialchars($s["state"]) ?>">
                        <div class="step-title"><?= htmlspecialchars($s["title"]) ?></div>
                        <div class="step-desc"><?= htmlspecialchars($s["desc"]) ?></div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </button>
            </h2>

            <div id="c<?= $oid ?>" class="accordion-collapse collapse" aria-labelledby="h<?= $oid ?>" data-bs-parent="#orderAccordion">
              <div class="order-body">

                <div class="mb-3">
                  <div class="fw-semibold">ที่อยู่จัดส่ง</div>
                  <div class="text-muted"><?= nl2br(htmlspecialchars((string)$order["customer_address"])) ?></div>
                </div>

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
                    <div class="small text-muted mb-2">สลิปที่แนบ:</div>

                    <div class="d-flex flex-wrap gap-2 align-items-center">
                      <a href="<?= htmlspecialchars($slip) ?>" target="_blank"
                         class="btn btn-sm btn-outline-dark rounded-pill">
                        ดูสลิป
                      </a>
                      <span class="small text-muted">คลิกเพื่อเปิดรูปเต็มจอ</span>
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

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
