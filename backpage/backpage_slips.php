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
  header("Location: backpage_slips.php?page=" . $page);
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
======================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  ensure_csrf();
  $action = (string)($_POST["action"] ?? "");

  try {
    if ($action === "verify_slip") {
      $id = (int)($_POST["id"] ?? 0);
      $newPayStatus = (string)($_POST["payment_status"] ?? "pending_verify");
      $vnote = trim((string)($_POST["verified_note"] ?? ""));

      if ($id <= 0) {
        flash("danger", "ไม่พบ ID ออเดอร์");
        redirect_to($page);
      }

      $allowPay = ["pending_verify","verified","rejected"];
      if (!in_array($newPayStatus, $allowPay, true)) $newPayStatus = "pending_verify";

      // ใช้ชื่อแอดมินคนที่ล็อกอินอยู่ "ตอนนี้" จาก DB เสมอ
      $adminId = (int)($_SESSION["admin"]["id"] ?? 0);

      $adminName = "";
      if ($adminId > 0) {
        $stmtAdmin = $pdo->prepare("SELECT full_name, username FROM admins WHERE id=? LIMIT 1");
        $stmtAdmin->execute([$adminId]);
        $adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

        $adminName = trim((string)($adminRow["full_name"] ?? ""));
        if ($adminName === "") {
          $adminName = trim((string)($adminRow["username"] ?? ""));
        }
      }
      if ($adminName === "") $adminName = "admin";

      $pdo->prepare("
        UPDATE orders
        SET payment_status=?,
            verified_by_admin=?,
            verified_at=NOW(),
            verified_note=?
        WHERE id=?
      ")->execute([$newPayStatus, $adminName, ($vnote !== "" ? $vnote : null), $id]);

      flash("success", "บันทึกการตรวจสอบสลิปเรียบร้อย");
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

    if ($action === "update_status") {
      $id = (int)($_POST["id"] ?? 0);
      $status = (string)($_POST["status"] ?? "pending");

      if ($id <= 0) {
        flash("danger", "ไม่พบ ID ออเดอร์");
        redirect_to($page);
      }

      $allow = ["pending","paid","cancelled","completed"];
      if (!in_array($status, $allow, true)) $status = "pending";

      $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $id]);

      flash("success", "อัปเดตสถานะเรียบร้อย");
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
   หน้านี้แสดง 3 สถานะ:
   pending_verify -> rejected -> verified
======================= */
$totalOrdersStmt = $pdo->prepare("
  SELECT COUNT(*)
  FROM orders
  WHERE payment_status IN ('pending_verify','rejected','verified')
");
$totalOrdersStmt->execute();
$totalOrders = (int)$totalOrdersStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalOrders / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
  SELECT id, order_no, customer_name, customer_phone, customer_address, note,
         total_qty, total_amount, status, created_at,
         payment_method, payment_status, payment_slip_url,
         verified_by_admin, verified_at, verified_note
  FROM orders
  WHERE payment_status IN ('pending_verify','rejected','verified')
  ORDER BY
    CASE payment_status
      WHEN 'pending_verify' THEN 1
      WHEN 'rejected' THEN 2
      WHEN 'verified' THEN 3
      ELSE 4
    END,
    id DESC
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
      <a class="btn btn-outline-secondary" href="admin_logout.php">
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
            <th style="width: 70px;">ID</th>
            <th style="width: 190px;">Order No</th>
            <th>ลูกค้า และที่อยู่</th>
            <th style="width: 150px;">เบอร์</th>
            <th style="width: 160px;">ผลตรวจสลิป</th>
            <th style="width: 210px;">ตรวจโดย</th>
            <th style="width: 180px;">หมายเหตุ</th>
            <th style="width: 200px;" class="text-end">จัดการ</th>
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
                (string)($o["payment_status"] ?? "")
              );

              $ps = (string)($o["payment_status"] ?? "");
              if ($ps === "verified") {
                $psLabel = "ยืนยันแล้ว";
                $psClass = "bg-success-subtle text-success border border-success-subtle";
                $psIcon  = "bi-check2-circle";
              } elseif ($ps === "rejected") {
                $psLabel = "ปฏิเสธ";
                $psClass = "bg-danger-subtle text-danger border";
                $psIcon  = "bi-x-octagon";
              } elseif ($ps === "pending_verify") {
                $psLabel = "รอดำเนินการ";
                $psClass = "bg-warning-subtle text-warning border";
                $psIcon  = "bi-hourglass-split";
              } else {
                $psLabel = "-";
                $psClass = "bg-light text-muted border";
                $psIcon  = "bi-dash-circle";
              }
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

              <td>
                <span class="chip <?= h($psClass) ?>">
                  <i class="bi <?= h($psIcon) ?> me-1"></i> <?= h($psLabel) ?>
                </span>
              </td>

              <td class="text-muted small">
                <?= $o["verified_by_admin"] ? h((string)$o["verified_by_admin"]) : "-" ?>
                <?php if (!empty($o["verified_at"])): ?>
                  <div class="small text-muted"><?= h((string)$o["verified_at"]) ?></div>
                <?php endif; ?>
              </td>

              <td class="small">
                <?= !empty($o["verified_note"]) ? h((string)$o["verified_note"]) : "-" ?>
              </td>

              <td class="text-end">

                <?php
                  $slip = trim((string)($o["payment_slip_url"] ?? ""));
                  $slipUrl = "";
                  if ($slip !== "") {
                    if (preg_match('~^https?://~i', $slip) || str_starts_with($slip, "/")) {
                      $slipUrl = $slip;
                    } else {
                      $slipFile = basename($slip);
                      $slipUrl = "/project/assets/uploads/slips/" . $slipFile;
                    }
                  }
                ?>
                <button
                  class="btn btn-outline-secondary btn-sm js-slip"
                  <?= $slipUrl === "" ? "disabled" : "" ?>
                  data-id="<?= (int)$o["id"] ?>"
                  data-order_no="<?= h((string)$o["order_no"]) ?>"

                  data-customer_name="<?= h((string)($o["customer_name"] ?? "")) ?>"
                  data-customer_phone="<?= h((string)($o["customer_phone"] ?? "")) ?>"
                  data-customer_address="<?= h((string)($o["customer_address"] ?? "")) ?>"
                  data-note="<?= h((string)($o["note"] ?? "")) ?>"
                  data-status="<?= h((string)($o["status"] ?? "")) ?>"
                  data-created_at="<?= h((string)($o["created_at"] ?? "")) ?>"
                  data-total_qty="<?= h((string)($o["total_qty"] ?? "")) ?>"
                  data-total_amount="<?= h((string)($o["total_amount"] ?? "")) ?>"

                  data-slip_url="<?= h($slipUrl) ?>"
                  data-payment_status="<?= h((string)($o["payment_status"] ?? "")) ?>"
                  data-verified_by="<?= h((string)($o["verified_by_admin"] ?? "")) ?>"
                  data-verified_at="<?= h((string)($o["verified_at"] ?? "")) ?>"
                  data-verified_note="<?= h((string)($o["verified_note"] ?? "")) ?>"
                >
                  <i class="bi bi-receipt"></i>
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
            </tr>
          <?php endforeach; ?>

          <?php if (!count($orders)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีออเดอร์</td></tr>
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
  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="hd_id">
</form>

<div class="modal fade" id="modalSlip" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content" style="border-radius:18px;">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-receipt me-2"></i> ตรวจสอบสลิป</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
      <div class="row g-3">

        <!-- ====== สรุปออเดอร์ (ย้ายมาจาก modalOrderDetail) ====== -->
        <div class="col-12">
          <div class="row g-2 small">
            <div class="col-md-6">
              <div><span class="text-muted">Order No:</span> <span class="fw-semibold" id="d_order_no">-</span></div>
              <div><span class="text-muted">ลูกค้า:</span> <span class="fw-semibold" id="d_customer">-</span></div>
              <div><span class="text-muted">เบอร์:</span> <span id="d_phone">-</span></div>
            </div>
            <div class="col-md-6">
              <div><span class="text-muted">สถานะออเดอร์:</span> <span class="fw-semibold" id="d_status">-</span></div>
              <div><span class="text-muted">สร้างเมื่อ:</span> <span id="d_created">-</span></div>
              <div><span class="text-muted">รวม:</span> <span class="fw-semibold" id="d_total">-</span></div>
            </div>

            <div class="col-12">
              <div class="text-muted">ที่อยู่:</div>
              <div class="fw-semibold" id="d_address">-</div>
            </div>

            <div class="col-12">
              <div class="text-muted">หมายเหตุ:</div>
              <div id="d_note">-</div>
            </div>
          </div>
        </div>

        <div class="col-12"><hr class="my-2"></div>

        <!-- ====== รูปสลิป ====== -->
        <div class="col-12">
          <div class="small text-muted mb-2">สลิป</div>
          <div class="soft p-2 rounded-4 text-center">
            <img id="slip_img" src="" alt="slip"
                style="max-width:100%; max-height:520px; border-radius:14px;">
          </div>
        </div>

        <!-- ====== รายการสินค้า (ย้ายมาจาก modalOrderDetail) ====== -->
        <div class="col-12">
          <div class="small text-muted mb-2">รายการสินค้า</div>
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
        </div>

        <div class="col-12"><hr class="my-2"></div>

        <!-- ====== ฟอร์มตรวจสลิป ====== -->
        <div class="col-md-6">
          <label class="form-label">ผลตรวจสอบ</label>
          <select class="form-select" id="slip_payment_status">
            <option value="pending_verify">รอดำเนินการ</option>
            <option value="verified">ยืนยันแล้ว</option>
            <option value="rejected">ปฏิเสธ</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">หมายเหตุ (ถ้ามี)</label>
          <input class="form-control" id="slip_note" placeholder="เช่น สลิปไม่ชัด / ยอดไม่ตรง">
        </div>

        <div class="col-12">
          <div class="text-muted small">
            ตรวจสอบล่าสุดโดย:
            <span class="fw-semibold" id="slip_verified_by">-</span>
            <span class="ms-2" id="slip_verified_at"></span>
          </div>
        </div>

      </div>
    </div>

      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ปิด</button>
        <button class="btn btn-dark" type="button" id="btnSaveSlip">
          <i class="bi bi-check2 me-1"></i> บันทึกการตรวจ
        </button>
      </div>
    </div>
  </div>
</div>

<form id="slipForm" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="action" value="verify_slip">
  <input type="hidden" name="id" id="sf_id">
  <input type="hidden" name="payment_status" id="sf_payment_status">
  <input type="hidden" name="verified_note" id="sf_verified_note">
</form>

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
  // ===== Search (table filter) =====
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
  wireSearch("searchOrders", "tableOrders", "ordCount");

  // ===== Helpers =====
  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  function money(n){
    const x = Number(n || 0);
    return x.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
  }

  // ===== Slip Modal =====
  const modalSlipEl = document.getElementById("modalSlip");
  const modalSlip = modalSlipEl ? new bootstrap.Modal(modalSlipEl) : null;

  async function loadOrderItems(orderId) {
    const tb = document.getElementById("detailItems");
    if (!tb) return;

    tb.innerHTML = `<tr><td colspan="4" class="text-center text-muted py-3">กำลังโหลด...</td></tr>`;

    try {
      const res = await fetch("ajax_order_detail.php?id=" + encodeURIComponent(orderId), {
        headers: {"Accept":"application/json"}
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
  }

  document.querySelectorAll(".js-slip").forEach(btn => {
    btn.addEventListener("click", async () => {
      const id = btn.dataset.id || "";
      document.getElementById("sf_id").value = id;

      // ===== เติมข้อมูลออเดอร์ (แสดงใน modalSlip) =====
      const setText = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = val ?? "-";
      };

      setText("d_order_no", btn.dataset.order_no || "-");
      setText("d_customer", btn.dataset.customer_name || "-");
      setText("d_phone", btn.dataset.customer_phone || "-");
      setText("d_address", btn.dataset.customer_address || "-");
      setText("d_note", btn.dataset.note || "-");
      setText("d_status", btn.dataset.status || "-");
      setText("d_created", btn.dataset.created_at || "-");

      const totalEl = document.getElementById("d_total");
      if (totalEl) {
        totalEl.textContent = `${btn.dataset.total_qty || 0} ชิ้น • ฿${money(btn.dataset.total_amount)}`;
      }

      // ===== เติมข้อมูลการตรวจสลิป =====
      const paySel = document.getElementById("slip_payment_status");
      const noteEl = document.getElementById("slip_note");
      const byEl   = document.getElementById("slip_verified_by");
      const atEl   = document.getElementById("slip_verified_at");

      if (paySel) paySel.value = btn.dataset.payment_status || "pending_verify";
      if (noteEl) noteEl.value = btn.dataset.verified_note || "";
      if (byEl) byEl.textContent = btn.dataset.verified_by || "-";
      if (atEl) atEl.textContent = btn.dataset.verified_at ? ("• " + btn.dataset.verified_at) : "";

      // ===== รูปสลิป =====
      const url = (btn.dataset.slip_url || "").trim();
      const img = document.getElementById("slip_img");
      if (img) {
        if (!url) {
          img.removeAttribute("src");
          img.alt = "ไม่มีสลิป";
        } else {
          img.src = url + (url.includes("?") ? "&" : "?") + "v=" + Date.now();
          img.alt = "slip";
          img.onerror = () => {
            img.removeAttribute("src");
            img.alt = "โหลดสลิปไม่สำเร็จ (พาธผิดหรือไม่พบไฟล์)";
          };
        }
      }

      // เปิด modal ก่อน แล้วค่อยโหลดรายการสินค้า
      modalSlip?.show();
      await loadOrderItems(id);
    });
  });

  // ===== Save slip verify =====
  document.getElementById("btnSaveSlip")?.addEventListener("click", () => {
    const paySel = document.getElementById("slip_payment_status");
    const noteEl = document.getElementById("slip_note");

    document.getElementById("sf_payment_status").value = paySel ? paySel.value : "pending_verify";
    document.getElementById("sf_verified_note").value = noteEl ? (noteEl.value || "") : "";

    document.getElementById("slipForm").submit();
  });

  // ===== Result toast (flash) =====
  const resultToastEl = document.getElementById("resultToast");
  const resultToast = resultToastEl ? new bootstrap.Toast(resultToastEl, {autohide: false}) : null;

  <?php if ($flash): ?>
    (function(){
      const type = <?= json_encode((string)$flash["type"]) ?>;
      const msg  = <?= json_encode((string)$flash["msg"]) ?>;

      const icon  = document.getElementById("resultIcon");
      const title = document.getElementById("resultTitle");
      const body  = document.getElementById("resultMsg");

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
          (type === "danger")  ? "bi-x-circle text-danger" :
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
</script>
</body>
</html>