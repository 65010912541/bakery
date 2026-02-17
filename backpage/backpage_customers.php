<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"]["id"])) {
  header("Location: admin_login.php?next=" . urlencode(basename($_SERVER["PHP_SELF"])));
  exit;
}
require __DIR__ . "/../config/db.php";
// require __DIR__ . "/../config/auth.php"; // ถ้ามี

$currentPage = basename($_SERVER["PHP_SELF"]); // ใช้ทำ active nav

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

if (empty($_SESSION["csrf"])) {
  $_SESSION["csrf"] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION["csrf"];

$perPage = 10;
$page = max(1, (int)($_GET["page"] ?? 1));

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
  header("Location: backpage_customers.php?page=" . $page);
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
    if ($action === "update") {
      $id         = (int)($_POST["id"] ?? 0);
      $username   = trim((string)($_POST["username"] ?? ""));
      $full_name  = trim((string)($_POST["full_name"] ?? ""));
      $phone      = trim((string)($_POST["phone"] ?? ""));
      $email      = trim((string)($_POST["email"] ?? ""));

      if ($id <= 0 || $username === "" || $full_name === "") {
        flash("danger", "ข้อมูลไม่ครบสำหรับแก้ไขลูกค้า");
        redirect_to($page);
      }
      if (!in_array($login_type, ["local","google"], true)) $login_type = "local";

      // update ข้อมูลทั่วไป
        $pdo->prepare("
        UPDATE users
        SET username=?, full_name=?, phone=?, email=?
        WHERE id=?
        ")->execute([
        $username,
        $full_name,
        $phone !== "" ? $phone : null,
        $email !== "" ? $email : null,
        $id
        ]);

      flash("success", "แก้ไขลูกค้าเรียบร้อย");
      redirect_to($page);
    }

    if ($action === "delete") {
      $id = (int)($_POST["id"] ?? 0);
      if ($id <= 0) {
        flash("danger", "ไม่พบ ID ลูกค้า");
        redirect_to($page);
      }

      $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);

      flash("success", "ลบลูกค้าเรียบร้อย");
      redirect_to($page);
    }

    flash("warning", "ไม่พบคำสั่งที่ร้องขอ");
    redirect_to($page);

  } catch (Throwable $e) {
    flash("danger", "เกิดข้อผิดพลาด: " . $e->getMessage());
    redirect_to($page);
  }
}

/* =======================
   FETCH DATA + PAGINATION
======================= */
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalPages = max(1, (int)ceil($totalCustomers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
  SELECT id, username, full_name, phone, email, google_id, login_type, created_at
  FROM users
  ORDER BY id ASC
  LIMIT :lim OFFSET :off
");
$stmt->bindValue(":lim", $perPage, PDO::PARAM_INT);
$stmt->bindValue(":off", $offset, PDO::PARAM_INT);
$stmt->execute();
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    .app-shell { max-width: 1300px; }
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
        <div class="fw-semibold">Bakery Admin</div>
        <div class="text-muted small">Backpage • สมูทขึ้น + CRUD ลูกค้า</div>
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
        <div class="text-muted small">เพิ่ม • แก้ไข • ลบ • ค้นหา • ยืนยันด้วย Toast</div>
      </div>

      <!-- NAV (active ตามหน้า) -->
      <ul class="nav nav-pills gap-2">
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==="backpage_products.php" ? "active" : "" ?>" href="backpage_products.php?page=1">
            <i class="bi bi-bag-heart me-1"></i> สินค้า
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==="backpage_categories.php" ? "active" : "" ?>" href="backpage_categories.php?page=1">
            <i class="bi bi-tags me-1"></i> ประเภทสินค้า
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==="backpage_orders.php" ? "active" : "" ?>" href="backpage_orders.php?page=1">
            <i class="bi bi-receipt me-1"></i> ออเดอร์
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==="backpage_customers.php" ? "active" : "" ?>" href="backpage_customers.php?page=1">
            <i class="bi bi-people me-1"></i> ลูกค้า
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==="backpage_admins.php" ? "active" : "" ?>" href="backpage_admins.php?page=1">
            <i class="bi bi-shield-lock me-1"></i> แอดมิน
          </a>
        </li>
      </ul>
    </div>

    <hr class="my-2">

    <!-- TOOLBAR -->
    <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mt-3">
      <div class="text-muted small">
        แสดง <span class="chip bg-light border" id="custCount"><?= count($customers) ?></span> รายการ
      </div>

      <div class="input-group soft search-pill ms-lg-auto" style="width: 520px; max-width: 60vw;">
        <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
        <input id="searchCustomers" class="form-control border-0 bg-transparent"
               placeholder="ค้นหาลูกค้า (username / ชื่อ / เบอร์ / email / login_type)">
      </div>
    </div>

    <div class="table-responsive mt-3">
      <div class="d-flex justify-content-between align-items-center mt-2">
        <div class="text-muted small">
          ทั้งหมด <span class="fw-semibold"><?= (int)$totalCustomers ?></span> รายการ
        </div>
        <?= renderPagination($page, $totalPages) ?>
      </div>

      <table class="table table-hover align-middle" id="tableCustomers">
        <thead class="text-muted small">
          <tr>
            <th style="width:70px;">ID</th>
            <th style="width:170px;">username</th>
            <th>ชื่อ-นามสกุล</th>
            <th style="width:150px;">เบอร์</th>
            <th style="width:220px;">email</th>
            <th style="width:110px;">login</th>
            <th style="width:190px;">สร้างเมื่อ</th>
            <th style="width:140px;" class="text-end">จัดการ</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($customers as $u): ?>
            <?php
              $blob = strtolower(
                (string)$u["id"] . " " .
                (string)$u["username"] . " " .
                (string)$u["full_name"] . " " .
                (string)($u["phone"] ?? "") . " " .
                (string)($u["email"] ?? "") . " " .
                (string)($u["login_type"] ?? "")
              );
            ?>
            <tr class="fade-smooth" data-search="<?= h($blob) ?>">
              <td class="text-muted"><?= (int)$u["id"] ?></td>
              <td class="fw-semibold"><?= h((string)$u["username"]) ?></td>
              <td><?= h((string)$u["full_name"]) ?></td>
              <td class="text-muted"><?= h((string)($u["phone"] ?? "")) ?></td>
              <td class="text-muted truncate"><?= h((string)($u["email"] ?? "")) ?></td>
              <td>
                <?php $lt = (string)$u["login_type"]; ?>
                <?php if ($lt === "google"): ?>
                  <span class="chip bg-primary-subtle text-primary border">
                    <i class="bi bi-google me-1"></i> google
                  </span>
                <?php else: ?>
                  <span class="chip bg-success-subtle text-success border">
                    <i class="bi bi-person-check me-1"></i> local
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-muted small">
                <?= h((string)$u["created_at"]) ?>
              </td>
              <td class="text-end">
                <button
                  class="btn btn-outline-dark btn-sm"
                  data-bs-toggle="modal"
                  data-bs-target="#modalCustomerEdit"
                  data-id="<?= (int)$u["id"] ?>"
                  data-username="<?= h((string)$u["username"]) ?>"
                  data-full_name="<?= h((string)$u["full_name"]) ?>"
                  data-phone="<?= h((string)($u["phone"] ?? "")) ?>"
                  data-email="<?= h((string)($u["email"] ?? "")) ?>"
                  data-google_id="<?= h((string)($u["google_id"] ?? "")) ?>"
                  data-login_type="<?= h((string)$u["login_type"]) ?>"
                >
                  <i class="bi bi-pencil-square"></i>
                </button>

                <button
                  class="btn btn-outline-danger btn-sm js-confirm"
                  data-id="<?= (int)$u["id"] ?>"
                  data-name="<?= h((string)$u["full_name"]) ?>"
                >
                  <i class="bi bi-trash"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!count($customers)): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีลูกค้า</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <div class="text-center text-muted small mt-4">
    <i class="bi bi-shield-lock me-1"></i> Admin Panel • Customers CRUD • Smooth UI
  </div>
</div>

<!-- Hidden delete form -->
<form id="hiddenDeleteForm" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="hd_id">
</form>

<!-- Edit Customer Modal -->
<div class="modal fade" id="modalCustomerEdit" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" id="cust_edit_id">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขลูกค้า</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">username</label>
            <input class="form-control" name="username" id="cust_edit_username" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">ชื่อ-นามสกุล</label>
            <input class="form-control" name="full_name" id="cust_edit_full_name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">เบอร์</label>
            <input class="form-control" name="phone" id="cust_edit_phone">
          </div>

          <div class="col-md-6">
            <label class="form-label">email</label>
            <input class="form-control" name="email" id="cust_edit_email" type="email">
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
        <button class="btn btn-dark" type="submit"><i class="bi bi-check2 me-1"></i>บันทึก</button>
      </div>
    </form>
  </div>
</div>

<!-- Toast -->
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1080;">
  <div id="resultToast" class="toast mt-3" role="alert" aria-live="polite" aria-atomic="true">
    <div class="toast-header bg-transparent border-0">
      <i id="resultIcon" class="bi bi-info-circle me-2"></i>
      <strong class="me-auto" id="resultTitle">แจ้งเตือน</strong>
    </div>
    <div class="toast-body" id="resultMsg">...</div>
  </div>
</div>

<!-- Confirm delete modal -->
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
  // Fill edit modal
  document.getElementById('modalCustomerEdit')?.addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('cust_edit_id').value = b.getAttribute('data-id');
    document.getElementById('cust_edit_username').value = b.getAttribute('data-username');
    document.getElementById('cust_edit_full_name').value = b.getAttribute('data-full_name');
    document.getElementById('cust_edit_phone').value = b.getAttribute('data-phone');
    document.getElementById('cust_edit_email').value = b.getAttribute('data-email');
  });

  // Smooth search
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
  wireSearch("searchCustomers", "tableCustomers", "custCount");

  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  // Confirm delete wiring
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
        confirmDeleteText.innerHTML = `ลูกค้า: <span class="fw-semibold">${escapeHtml(name)}</span>`;
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

  // Result toast
  const resultToastEl = document.getElementById("resultToast");
  const resultToast = resultToastEl ? new bootstrap.Toast(resultToastEl, {autohide: false}) : null;

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
</script>
</body>
</html>
