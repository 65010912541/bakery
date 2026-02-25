<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"]["id"])) {
  header("Location: admin_login.php?next=" . urlencode(basename($_SERVER["PHP_SELF"])));
  exit;
}
require __DIR__ . "/../config/db.php";

$currentPage = basename($_SERVER["PHP_SELF"]);
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

if (empty($_SESSION["csrf"])) {
  $_SESSION["csrf"] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION["csrf"];

$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));
$offset = ($page - 1) * $perPage;

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

function flash(string $type, string $msg): void {
  $_SESSION["flash"] = ["type" => $type, "msg" => $msg];
}
function ensure_csrf(): void {
  if (!hash_equals($_SESSION["csrf"] ?? "", (string)($_POST["csrf"] ?? ""))) {
    http_response_code(403);
    exit("CSRF token mismatch");
  }
}
function redirect_to(int $page = 1): void {
  header("Location: backpage_orders.php?page=" . $page);
  exit;
}

function renderPagination(int $page, int $totalPages): string {
  if ($totalPages <= 1) return "";

  $start = $page;
  $end = min($start + 4, $totalPages);
  if (($end - $start) < 4) $start = max(1, $end - 4);

  $qs = fn($p) => "?page={$p}";

  ob_start(); ?>
  <nav class="d-flex justify-content-end">
    <ul class="pagination pagination-soft mb-0">

      <li class="page-item <?= $page <= 1 ? "disabled" : "" ?>">
        <a class="page-link" href="<?= $qs(max(1, $page-1)) ?>" aria-label="Prev">
          <i class="bi bi-chevron-left"></i>
        </a>
      </li>

      <?php for ($i=$start; $i<=$end; $i++): ?>
        <li class="page-item <?= $i === $page ? "active" : "" ?>">
          <a class="page-link" href="<?= $qs($i) ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>

      <?php if ($end < $totalPages): ?>
        <li class="page-item disabled"><span class="page-link">…</span></li>
        <li class="page-item <?= $totalPages === $page ? "active" : "" ?>">
          <a class="page-link" href="<?= $qs($totalPages) ?>"><?= $totalPages ?></a>
        </li>
      <?php endif; ?>

      <li class="page-item <?= $page >= $totalPages ? "disabled" : "" ?>">
        <a class="page-link" href="<?= $qs(min($totalPages, $page+1)) ?>" aria-label="Next">
          <i class="bi bi-chevron-right"></i>
        </a>
      </li>

    </ul>
  </nav>
  <?php
  return (string)ob_get_clean();
}

/* =======================
   HANDLE ACTIONS (POST)
   - update_status
   - delete
======================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  ensure_csrf();

  $action = (string)($_POST["action"] ?? "");

  try {
    if ($action === "update_status") {
    $id = (int)($_POST["id"] ?? 0);
    $step = (string)($_POST["step"] ?? "step2"); // เริ่มจาก step2 เพราะ verified แล้ว

    if ($id <= 0) {
      flash("danger", "ไม่พบ ID ออเดอร์");
      redirect_to($page);
    }

    $step = trim((string)($_POST["step"] ?? "step2"));

    // verified แล้ว → เลือกได้เฉพาะขั้นของออเดอร์
    $map = [
      "step2"     => "confirmed",  // ยืนยันออเดอร์
      "step3"     => "shipped",    // จัดส่ง
      "step4"     => "completed",  // สำเร็จ
      "cancelled" => "cancelled",  // ยกเลิก
    ];
    

    if (!isset($map[$step])) $step = "step2";
    $newStatus = $map[$step];

    $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$newStatus, $id]);

    flash("success", "อัปเดตสถานะออเดอร์เรียบร้อย");
    redirect_to($page);
  }

    if ($action === "delete") {
      $id = (int)($_POST["id"] ?? 0);
      if ($id <= 0) {
        flash("danger", "ไม่พบ ID ออเดอร์");
        redirect_to($page);
      }

      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM order_items WHERE order_id=?")->execute([$id]);
      $pdo->prepare("DELETE FROM orders WHERE id=?")->execute([$id]);
      $pdo->commit();

      flash("success", "ลบออเดอร์เรียบร้อย");
      redirect_to($page);
    }

    flash("warning", "ไม่พบคำสั่งที่ร้องขอ");
    redirect_to($page);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash("danger", "เกิดข้อผิดพลาด: " . $e->getMessage());
    redirect_to($page);
  }
}

/* =======================
   FETCH DATA + PAGINATION
   ✅ แสดงเฉพาะ payment_status = verified
======================= */
$totalOrdersStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE payment_status='verified'");
$totalOrdersStmt->execute();
$totalOrders = (int)$totalOrdersStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalOrders / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
  SELECT id, order_no, customer_name, customer_phone, customer_address, note,
         total_qty, total_amount, status, payment_status
  FROM orders
  WHERE payment_status='verified'
  ORDER BY id DESC
  LIMIT :lim OFFSET :off
");
$stmt->bindValue(":lim", $perPage, PDO::PARAM_INT);
$stmt->bindValue(":off", $offset, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backpage • Bakery Admin</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    body { background: #f6f7fb; font-family: "Kanit", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans Thai", sans-serif; }
    .app-shell { max-width: 1500px; }
    .brand-badge { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 16px; }
    .card { border: 0; border-radius: 18px; box-shadow: 0 10px 25px rgba(15, 23, 42, .06); }
    .nav-pills .nav-link { border-radius: 14px; }
    .table > :not(caption) > * > * { vertical-align: middle; }
    .chip { font-size: .85rem; border-radius: 999px; padding: .25rem .6rem; }
    .truncate { max-width: 340px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .form-control, .form-select { border-radius: 14px; }
    .btn { border-radius: 14px; }
    .soft { background: rgba(255,255,255,.65); border: 1px solid rgba(0,0,0,.06); }
    .toast { border-radius: 16px; }
    .fade-smooth { transition: opacity .18s ease, transform .18s ease; }
    .row-hide { opacity: 0; transform: translateY(4px); }

    .toast {
      border-radius: 18px;
      border: 1px solid rgba(0,0,0,.08);
      box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
    }
    .toast-header { padding: .9rem 1rem; }
    .toast-body { padding: .9rem 1rem 1rem 1rem; font-size: 1.02rem; line-height: 1.4; color: #0f172a; }
    #resultToast { min-width: 380px; max-width: 520px; background: #ffffff; }
    #resultTitle, .toast-header strong { font-size: 1.05rem; }
    #resultIcon { font-size: 1.25rem; }

    @keyframes slideOutRight {
      0% { opacity: 1; transform: translateX(0); }
      100% { opacity: 0; transform: translateX(40px); }
    }
    .toast.slide-out { animation: slideOutRight 0.35s ease forwards; }

    .search-pill { border-radius: 16px; overflow: hidden; }
    .search-pill .input-group-text, .search-pill .form-control { border-radius: 0 !important; }

    .table-headbar{ padding: .25rem 0; }

    .pagination-soft .page-link{
      border: 1px solid transparent;
      background: transparent;
      color: #0f172a;
      border-radius: 12px;
      padding: .55rem .9rem;
      margin-left: 6px;
      box-shadow: none;
    }
    .pagination-soft .page-link:hover{
      background: #eef2f7;
      border-color: #e2e8f0;
      color: #0f172a;
    }
    .pagination-soft .page-item.active .page-link{
      background: #111827;
      border-color: #111827;
      color: #fff;
    }
    .pagination-soft .page-item.disabled .page-link{
      opacity: .35;
      pointer-events: none;
    }
  </style>
</head>
<body>

<div class="container py-4 app-shell">

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-3">
      <div class="brand-badge bg-dark text-white">
        <i class="bi bi-shop fs-5"></i>
      </div>
      <div>
        <div class="fw-semibold">HokKao(69)Bakery Admin</div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <?php
        $adminDisplay = trim((string)($_SESSION["admin"]["full_name"] ?? ""));
        if ($adminDisplay === "") $adminDisplay = trim((string)($_SESSION["admin"]["username"] ?? "Admin"));
      ?>

      <span class="btn btn-outline-dark disabled">
        <i class="bi bi-person-circle me-1"></i> <?= h($adminDisplay) ?>
      </span>
      <a class="btn btn-outline-secondary" href="..admin_login.php">
        <i class="bi bi-box-arrow-right me-1"></i> ออกจากระบบ
      </a>
    </div>
  </div>

  <div class="card p-3 p-md-4">
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
      <div>
        <div class="fw-semibold fs-5">จัดการข้อมูล</div>
      </div>
        <?php require __DIR__ . "/partials/admin_nav.php"; ?>
    </div>

    <hr class="my-2">

    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mt-3">
      <div class="text-muted small">
        แสดง <span class="chip bg-light border" id="ordCount"><?= count($orders) ?></span> รายการ
      </div>

      <div class="input-group soft search-pill ms-lg-auto" style="width: 560px; max-width: 65vw;">
        <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
        <input id="searchOrders" class="form-control border-0 bg-transparent"
              placeholder="ค้นหาออเดอร์ (order_no / ชื่อลูกค้า / เบอร์ / status)">
      </div>
    </div>

    <div class="table-responsive mt-3">
      <div class="table-headbar d-flex align-items-center justify-content-between mb-2">
        <div class="text-muted small">
          ทั้งหมด <span class="fw-semibold"><?= (int)$totalOrders ?></span> รายการ
        </div>
        <?= renderPagination($page, $totalPages) ?>
      </div>

      <table class="table table-hover align-middle" id="tableOrders">
        <thead class="text-muted small">
          <tr>
            <th style="width: 80px;">ID</th>
            <th style="width: 180px;">Order No</th>
            <th>ลูกค้า และที่อยู่</th>
            <th style="width: 140px;">เบอร์</th>
            <th style="width: 90px;" class="text-end">จำนวน</th>
            <th style="width: 140px;" class="text-end">ยอดรวม</th>
            <th style="width: 170px;">ผลตรวจสอบสลิป</th>
            <th style="width: 200px;">สถานะ</th>
            <th style="width: 180px;" class="text-end">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $o): ?>
            <?php
              $blob = strtolower(
                (string)$o["id"] . " " .
                (string)$o["order_no"] . " " .
                (string)$o["customer_name"] . " " .
                (string)$o["customer_phone"] . " " .
                (string)$o["status"]
              );
              $st = (string)$o["status"];
            ?>
            <tr class="fade-smooth" data-search="<?= h($blob) ?>">
              <td class="text-muted"><?= (int)$o["id"] ?></td>
              <td class="fw-semibold"><?= h((string)$o["order_no"]) ?></td>
              <td>
                <div class="fw-semibold"><?= h((string)$o["customer_name"]) ?></div>
                <?php if (!empty($o["customer_address"])): ?>
                  <div class="text-muted small truncate"><?= h((string)$o["customer_address"]) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-muted"><?= h((string)$o["customer_phone"]) ?></td>
                <td class="text-end"><?= (int)$o["total_qty"] ?></td>
                <td class="text-end"><?= number_format((float)$o["total_amount"], 2) ?></td>

                <?php
                  // payment_status ของหน้านี้จะเป็น verified ทั้งหมดอยู่แล้ว
                  $ps = (string)($o["payment_status"] ?? "");
                  if ($ps === "verified") {
                    $psLabel = "ยืนยันแล้ว";
                    $psClass = "bg-success-subtle text-success border border-success-subtle";
                    $psIcon  = "bi-check2-circle";
                  } else {
                    $psLabel = "-";
                    $psClass = "bg-light text-muted border";
                    $psIcon  = "bi-dash-circle";
                  }
                ?>
                <td>
                  <span class="chip <?= h($psClass) ?>">
                    <i class="bi <?= h($psIcon) ?> me-1"></i> <?= h($psLabel) ?>
                  </span>
                </td>
              <td>
                <?php
                // เอาค่าจริงจาก DB
                $st = strtolower(trim((string)($o["status"] ?? "")));

                // ถ้า status ว่าง ให้ถือเป็น step2 (รอยืนยันออเดอร์) หรือ pending ตาม stepper
                  if ($st === "") $st = "pending";

                  // Map สถานะ -> label ตาม Stepper
                  if ($st === "cancelled") {
                    $label = "ยกเลิก";
                    $cls   = "bg-danger-subtle text-danger border";
                    $icon  = "bi-x-circle";
                  } elseif ($st === "completed") {
                    $label = "Step 4: สำเร็จ";
                    $cls   = "bg-success-subtle text-success border border-success-subtle";
                    $icon  = "bi-bag-check";
                  } elseif ($st === "shipped") {
                    $label = "Step 3: จัดส่ง";
                    $cls   = "bg-primary-subtle text-primary border";
                    $icon  = "bi-truck";
                  } elseif ($st === "confirmed") {
                    $label = "Step 2: ยืนยันออเดอร์";
                    $cls   = "bg-info-subtle text-dark border";
                    $icon  = "bi-check2-circle";
                  } else {
                    // pending (หรือค่าอื่นที่ไม่รู้จัก) -> ให้ถือเป็นขั้นรอยืนยันออเดอร์
                    $label = "Step 2: รอยืนยันออเดอร์";
                    $cls   = "bg-warning-subtle text-warning border";
                    $icon  = "bi-clock-history";
                  }
                ?>

                <span class="chip <?= h($cls) ?>">
                  <i class="bi <?= h($icon) ?> me-1"></i> <?= h($label) ?>
                </span>
              </td>

              <td class="text-end">
                <button
                  class="btn btn-outline-primary btn-sm js-view"
                  data-id="<?= (int)$o["id"] ?>"
                  data-order_no="<?= h((string)$o["order_no"]) ?>"
                  data-customer_name="<?= h((string)$o["customer_name"]) ?>"
                  data-customer_phone="<?= h((string)$o["customer_phone"]) ?>"
                  data-customer_address="<?= h((string)($o["customer_address"] ?? "")) ?>"
                  data-note="<?= h((string)($o["note"] ?? "")) ?>"
                  data-total_qty="<?= h((string)$o["total_qty"]) ?>"
                  data-total_amount="<?= h((string)$o["total_amount"]) ?>"
                  data-status="<?= h((string)$o["status"]) ?>"
                  title="ดูรายละเอียด / แก้สถานะ"
                >
                  <i class="bi bi-eye"></i>
                </button>

                <button
                  class="btn btn-outline-danger btn-sm js-confirm"
                  data-id="<?= (int)$o["id"] ?>"
                  data-name="<?= h((string)$o["order_no"]) ?>"
                  title="ลบ"
                >
                  <i class="bi bi-trash"></i>
                </button>
              </td>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!count($orders)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">ยังไม่มีออเดอร์</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <div class="text-center text-muted small mt-4">
    <i class="bi bi-shield-lock me-1"></i> Admin Panel
  </div>
</div>

<form id="hiddenDeleteForm" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="hd_id">
</form>

<!-- ✅ เหลือแค่ Modal รายละเอียด (รวมอัปเดตสถานะไว้ในนี้) -->
<div class="modal fade" id="modalOrderDetail" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-receipt me-2"></i>รายละเอียดออเดอร์</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <!-- ✅ BODY: รายละเอียด + ตารางสินค้า -->
      <div class="modal-body">

        <div class="row g-2 small">
          <div class="col-md-6">
            <div><span class="text-muted">Order No:</span> <span class="fw-semibold" id="d_order_no">-</span></div>
            <div><span class="text-muted">ลูกค้า:</span> <span class="fw-semibold" id="d_customer">-</span></div>
            <div><span class="text-muted">เบอร์:</span> <span id="d_phone">-</span></div>
          </div>

          <div class="col-md-6">
            <div><span class="text-muted">สถานะ:</span> <span class="fw-semibold" id="d_status">-</span></div>
            <div><span class="text-muted">รวม:</span> <span class="fw-semibold" id="d_total">-</span></div>
          </div>

          <div class="col-12 mt-1">
            <div class="text-muted">ที่อยู่:</div>
            <div class="fw-semibold" id="d_address">-</div>
          </div>

          <div class="col-12">
            <div class="text-muted">หมายเหตุ:</div>
            <div id="d_note">-</div>
          </div>
        </div>

        <hr class="my-3">

        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <thead class="text-muted small">
              <tr>
                <th>สินค้า</th>
                <th class="text-end" style="width:120px;">ราคา</th>
                <th class="text-center" style="width:90px;">จำนวน</th>
                <th class="text-end" style="width:140px;">รวม</th>
              </tr>
            </thead>
            <tbody id="detailItems">
              <tr><td colspan="4" class="text-center text-muted py-3">กำลังโหลด...</td></tr>
            </tbody>
          </table>
        </div>

        <hr class="my-3">

        <!-- ✅ อัปเดตสถานะ (อยู่ใน body ก็ได้ แต่จัดให้ชัดเจน) -->
        <div class="fw-semibold mb-2">อัปเดตสถานะ:</div>

        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-center">
          <input type="hidden" id="d_id" value="">

          <select class="form-select" id="d_step" style="max-width: 320px;">
            <option value="step2">Step 2: ยืนยันออเดอร์</option>
            <option value="step3">Step 3: จัดส่ง</option>
            <option value="step4">Step 4: สำเร็จ</option>
            <option value="cancelled">ยกเลิก</option>
          </select>

          <button type="button" class="btn btn-dark" id="btnSaveStatus">
            <i class="bi bi-check2 me-1"></i>บันทึก
          </button>
        </div>

        <!-- ✅ ฟอร์มจริงไว้ submit (ซ่อน) -->
        <form method="post" id="frmUpdateStatus" class="d-none">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="id" id="f_id">
          <input type="hidden" name="step" id="f_step">
        </form>

      </div>

      <!-- ✅ footer -->
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
      </div>

    </div>
  </div>
</div>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080;">
  <div id="resultToast" class="toast mt-3" role="alert" aria-live="polite" aria-atomic="true">
    <div class="toast-header bg-transparent border-0">
      <i id="resultIcon" class="bi bi-info-circle me-2"></i>
      <strong class="me-auto" id="resultTitle">แจ้งเตือน</strong>
    </div>
    <div class="toast-body" id="resultMsg">...</div>
  </div>
</div>

<div class="modal fade" id="confirmDeleteModal"
     data-bs-backdrop="static" data-bs-keyboard="false"
     tabindex="-1" aria-labelledby="confirmDeleteLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header">
        <h1 class="modal-title fs-5" id="confirmDeleteLabel">
          <i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>
          ยืนยันการลบ
        </h1>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="mb-1">ต้องการลบรายการนี้ใช่ไหม?</div>
        <div class="fw-semibold" id="confirmDeleteText">...</div>
        <div class="text-muted small mt-2">การลบจะไม่สามารถกู้คืนได้</div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="button" class="btn btn-danger" id="btnConfirmDeleteYes">
          <i class="bi bi-trash3 me-1"></i> ยืนยันลบ
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // =========================
  // Helpers
  // =========================
  function wireSearch(inputId, tableId, countId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    const countEl = document.getElementById(countId);
    if (!input || !table) return;

    const rows = Array.from(table.querySelectorAll("tbody tr"));

    const run = () => {
      const q = (input.value || "").trim().toLowerCase();
      let visible = 0;

      rows.forEach(tr => {
        const blob = (tr.getAttribute("data-search") || "").toLowerCase();
        const show = q === "" || blob.includes(q);

        if (show) {
          tr.classList.remove("row-hide");
          tr.style.display = "";
          visible++;
        } else {
          tr.classList.add("row-hide");
          setTimeout(() => { tr.style.display = "none"; }, 120);
        }
      });

      if (countEl) countEl.textContent = String(visible);
    };

    let t = null;
    input.addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(run, 80);
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  // status -> step (สำหรับ dropdown)
  function statusToStep(status) {
    const st = (status || "").trim().toLowerCase();
    if (st === "cancelled") return "cancelled";
    if (st === "shipped") return "step3";
    if (st === "completed") return "step4";
    // pending / confirmed / อื่นๆ -> step2
    return "step2";
  }

  // status -> label ไทย (แสดงใน modal)
  function statusToThai(status) {
    const st = (status || "").trim().toLowerCase();
    if (st === "pending") return "รอยืนยันออเดอร์";
    if (st === "confirmed") return "ยืนยันออเดอร์";
    if (st === "shipped") return "จัดส่งแล้ว";
    if (st === "completed") return "สำเร็จ";
    if (st === "cancelled") return "ยกเลิก";
    return st ? st : "-";
  }

  // =========================
  // Search
  // =========================
  wireSearch("searchOrders", "tableOrders", "ordCount");

  // =========================
  // Delete confirm
  // =========================
  const hiddenForm = document.getElementById("hiddenDeleteForm");
  const hdId = document.getElementById("hd_id");

  const confirmDeleteModalEl = document.getElementById("confirmDeleteModal");
  const confirmDeleteModal = confirmDeleteModalEl ? new bootstrap.Modal(confirmDeleteModalEl) : null;
  const confirmDeleteText = document.getElementById("confirmDeleteText");

  let pendingId = null;

  document.querySelectorAll(".js-confirm").forEach(btn => {
    btn.addEventListener("click", () => {
      pendingId = btn.dataset.id;
      const name = btn.dataset.name || "";
      if (confirmDeleteText) {
        confirmDeleteText.innerHTML = `ออเดอร์: <span class="fw-semibold">${escapeHtml(name)}</span>`;
      }
      confirmDeleteModal?.show();
    });
  });

  document.getElementById("btnConfirmDeleteYes")?.addEventListener("click", () => {
    if (!pendingId || !hiddenForm) return;
    hdId.value = pendingId;
    confirmDeleteModal?.hide();
    hiddenForm.submit();
  });

  // =========================
  // Detail modal (ดูรายละเอียด + แก้สถานะในที่เดียว)
  // =========================
  const modalOrderDetailEl = document.getElementById("modalOrderDetail");
  const modalOrderDetail = modalOrderDetailEl ? new bootstrap.Modal(modalOrderDetailEl) : null;

  // ฟิลด์ใน modal detail (ต้องมีจริงใน HTML)
  // - hidden input:  <input type="hidden" name="id" id="d_id">
  // - select step:   <select name="step" id="d_step">...</select>
  const dIdInput = document.getElementById("d_id");
  const dStepSelect = document.getElementById("d_step");

  document.querySelectorAll(".js-view").forEach(btn => {
    btn.addEventListener("click", async () => {
      const id = btn.dataset.id;

      // ✅ เซ็ต id ให้ฟอร์ม update ใน modal เดียวกัน
      if (dIdInput) dIdInput.value = id;

      // ✅ เซ็ตค่า step dropdown ตาม status ปัจจุบัน
      const stRaw = btn.dataset.status || "";
      if (dStepSelect) dStepSelect.value = statusToStep(stRaw);

      // เติมข้อมูลหัวบิล
      document.getElementById("d_order_no").textContent = btn.dataset.order_no || "-";
      document.getElementById("d_customer").textContent = btn.dataset.customer_name || "-";
      document.getElementById("d_phone").textContent = btn.dataset.customer_phone || "-";
      document.getElementById("d_address").textContent = btn.dataset.customer_address || "-";
      document.getElementById("d_note").textContent = btn.dataset.note || "-";

      // แสดงสถานะใน modal (ให้เป็นภาษาไทย)
      document.getElementById("d_status").textContent = statusToThai(stRaw);

      document.getElementById("d_total").textContent =
        `${btn.dataset.total_qty || 0} ชิ้น • ฿${Number(btn.dataset.total_amount || 0).toLocaleString(undefined, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        })}`;

      const tb = document.getElementById("detailItems");
      tb.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">กำลังโหลด...</td></tr>`;

      modalOrderDetail?.show();

      try {
        const res = await fetch("ajax_order_detail.php?id=" + encodeURIComponent(id), {
          headers: { "Accept": "application/json" }
        });
        const data = await res.json();

        if (!data || data.error) {
          tb.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">โหลดรายละเอียดไม่สำเร็จ</td></tr>`;
          return;
        }

        if (!Array.isArray(data.items) || data.items.length === 0) {
          tb.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">ไม่มีรายการสินค้า</td></tr>`;
          return;
        }

        const money = (n) => Number(n || 0).toLocaleString(undefined, {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        });

        let html = "";
        data.items.forEach(it => {
          html += `
            <tr>
              <td>${escapeHtml(it.product_name ?? "")}</td>
              <td class="text-end">${money(it.unit_price)}</td>
              <td class="text-center">${escapeHtml(String(it.qty ?? ""))}</td>
              <td class="text-end">${money(it.line_total)}</td>
            </tr>
          `;
        });
        tb.innerHTML = html;

      } catch (e) {
        tb.innerHTML = `<tr><td colspan="4" class="text-center text-danger py-3">เกิดข้อผิดพลาดในการโหลด</td></tr>`;
      }
    });
  });

  // =========================
  // Toast (Flash)
  // =========================
  const resultToastEl = document.getElementById("resultToast");
  const resultToast = resultToastEl ? new bootstrap.Toast(resultToastEl, { autohide: false }) : null;

  <?php if ($flash): ?>
    (function(){
      const type = <?= json_encode((string)$flash["type"]) ?>;
      const msg  = <?= json_encode((string)$flash["msg"]) ?>;

      const icon = document.getElementById("resultIcon");
      const title = document.getElementById("resultTitle");
      const body = document.getElementById("resultMsg");

      if (body) body.textContent = msg;
      if (title) {
        title.textContent =
          (type === "success") ? "สำเร็จ" :
          (type === "danger") ? "ไม่สำเร็จ" :
          (type === "warning") ? "แจ้งเตือน" : "แจ้งเตือน";
      }
      if (icon) {
        icon.className = "bi me-2 " + (
          (type === "success") ? "bi-check-circle text-success" :
          (type === "danger") ? "bi-x-circle text-danger" :
          (type === "warning") ? "bi-exclamation-triangle text-warning" :
                                 "bi-info-circle text-secondary"
        );
      }
      resultToast?.show();

      setTimeout(() => {
        if (!resultToastEl) return;
        resultToastEl.classList.add("slide-out");
        setTimeout(() => {
          resultToast.hide();
          resultToastEl.classList.remove("slide-out");
        }, 350);
      }, 3000);
    })();
  <?php endif; ?>

    document.getElementById("btnSaveStatus")?.addEventListener("click", () => {
    const id = document.getElementById("d_id")?.value || "";
    const step = document.getElementById("d_step")?.value || "step2";

    document.getElementById("f_id").value = id;
    document.getElementById("f_step").value = step;

    document.getElementById("frmUpdateStatus").submit();
  });
</script>
</body>
</html>