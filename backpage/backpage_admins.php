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
  header("Location: backpage_admins.php?page=" . $page);
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
   - create_admin
   - update_admin
   - delete_admin
======================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  ensure_csrf();
  $action = (string)($_POST["action"] ?? "");

  try {
    if ($action === "create_admin") {
      $username  = trim((string)($_POST["username"] ?? ""));
      $fullName  = trim((string)($_POST["full_name"] ?? ""));
      $status    = (string)($_POST["status"] ?? "active");
      $password  = (string)($_POST["password"] ?? "");

      if ($username === "" || $password === "") {
        flash("danger", "กรุณากรอก Username และ Password");
        redirect_to($page);
      }
      if ($fullName === "") $fullName = $username;


      $allowStatus = ["active","inactive"];
      if (!in_array($status, $allowStatus, true)) $status = "active";

      // username ต้องไม่ซ้ำ
      $chk = $pdo->prepare("SELECT id FROM admins WHERE username=? LIMIT 1");
      $chk->execute([$username]);
      if ($chk->fetch()) {
        flash("danger", "Username นี้มีอยู่แล้ว");
        redirect_to($page);
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $pdo->prepare("
        INSERT INTO admins (username, password_hash, full_name, status)
        VALUES (?, ?, ?, ?)
      ")->execute([$username, $hash, $fullName, $status]);

      flash("success", "เพิ่มแอดมินเรียบร้อย");
      redirect_to($page);
    }

    if ($action === "update_admin") {
      $id        = (int)($_POST["id"] ?? 0);
      $username  = trim((string)($_POST["username"] ?? ""));
      $fullName  = trim((string)($_POST["full_name"] ?? ""));
      $status    = (string)($_POST["status"] ?? "active");
      $password  = (string)($_POST["password"] ?? ""); // เว้นว่าง = ไม่เปลี่ยนรหัส

      if ($id <= 0) {
        flash("danger", "ไม่พบ ID แอดมิน");
        redirect_to($page);
      }
      if ($username === "") {
        flash("danger", "กรุณากรอก Username");
        redirect_to($page);
      }
      if ($fullName === "") $fullName = $username;

      $allowStatus = ["active","inactive"];
      if (!in_array($status, $allowStatus, true)) $status = "active";

      // username ต้องไม่ชนคนอื่น
      $chk = $pdo->prepare("SELECT id FROM admins WHERE username=? AND id<>? LIMIT 1");
      $chk->execute([$username, $id]);
      if ($chk->fetch()) {
        flash("danger", "Username นี้ถูกใช้งานแล้ว");
        redirect_to($page);
      }

      // อัปเดตพื้นฐาน
      if ($password !== "") {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare("
          UPDATE admins
          SET username=?, full_name=?, status=?, password_hash=?
          WHERE id=?
        ")->execute([$username, $fullName, $status, $hash, $id]);
      } else {
        $pdo->prepare("
          UPDATE admins
          SET username=?, full_name=?, status=?
          WHERE id=?
        ")->execute([$username, $fullName, $status, $id]);
      }

      flash("success", "แก้ไขแอดมินเรียบร้อย");
      redirect_to($page);
    }

    if ($action === "delete_admin") {
      $id = (int)($_POST["id"] ?? 0);
      if ($id <= 0) {
        flash("danger", "ไม่พบ ID แอดมิน");
        redirect_to($page);
      }

      $selfId = (int)($_SESSION["admin"]["id"] ?? 0);
      if ($selfId > 0 && $id === $selfId) {
        flash("danger", "ไม่สามารถลบบัญชีที่กำลังล็อกอินอยู่ได้");
        redirect_to($page);
      }

      $pdo->prepare("DELETE FROM admins WHERE id=?")->execute([$id]);
      flash("success", "ลบแอดมินเรียบร้อย");
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
$totalAdmins = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$totalPages = max(1, (int)ceil($totalAdmins / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
  SELECT id, username, full_name, status, created_at, updated_at
  FROM admins
  ORDER BY id DESC
  LIMIT :lim OFFSET :off
");
$stmt->bindValue(":lim", $perPage, PDO::PARAM_INT);
$stmt->bindValue(":off", $offset, PDO::PARAM_INT);
$stmt->execute();
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    .truncate { max-width: 360px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
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
        แสดง <span class="chip bg-light border" id="admCount"><?= count($admins) ?></span> รายการ
      </div>

        <div class="input-group soft search-pill ms-lg-auto" style="width: 520px; max-width: 65vw;">
            <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
            <input id="searchAdmins" class="form-control border-0 bg-transparent"
                placeholder="ค้นหาแอดมิน (username / ชื่อ / status)">
        </div>

        <button class="btn btn-dark" type="button" id="btnAddAdmin">
            <i class="bi bi-plus-lg me-1"></i> เพิ่มแอดมิน
        </button>
    </div>

    <div class="table-responsive mt-3">
      <div class="table-headbar d-flex align-items-center justify-content-between mb-2">
        <div class="text-muted small">
          ทั้งหมด <span class="fw-semibold"><?= (int)$totalAdmins ?></span> รายการ
        </div>
        <?= renderPagination($page, $totalPages) ?>
      </div>

      <table class="table table-hover align-middle" id="tableAdmins">
        <thead class="text-muted small">
            <tr>
                <th style="width: 70px;">ID</th>
                <th style="width: 160px;">Username</th>
                <th style="width: 260px;">ชื่อแสดง</th>
                <th style="width: 130px;">สถานะ</th>
                <th style="width: 170px;">สร้างเมื่อ</th>
                <th style="width: 150px;" class="text-end">จัดการ</th>
            </tr>
        </thead>
        <tbody>
          <?php foreach ($admins as $a): ?>
            <?php
              $blob = strtolower(
                (string)$a["id"] . " " .
                (string)$a["username"] . " " .
                (string)$a["full_name"] . " " .
                (string)$a["status"]
              );

              $status = (string)$a["status"];

              $stLabel = $status === "active" ? "active" : "inactive";
              $stClass = $status === "active"
                ? "bg-success-subtle text-success border border-success-subtle"
                : "bg-warning-subtle text-warning border";
            ?>
            <tr class="fade-smooth" data-search="<?= h($blob) ?>">
              <td class="text-muted"><?= (int)$a["id"] ?></td>
              <td class="fw-semibold"><?= h((string)$a["username"]) ?></td>
              <td>
                <div class="fw-semibold"><?= h((string)$a["full_name"]) ?></div>
                <?php if (!empty($a["updated_at"])): ?>
                  <div class="text-muted small">อัปเดต: <?= h((string)$a["updated_at"]) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="chip <?= h($stClass) ?>">
                  <i class="bi <?= $status === "active" ? "bi-check-circle" : "bi-pause-circle" ?> me-1"></i>
                  <?= h($stLabel) ?>
                </span>
              </td>
              <td class="text-muted small"><?= h((string)$a["created_at"]) ?></td>

              <td class="text-end">
                <button
                  class="btn btn-outline-dark btn-sm js-edit"
                  data-id="<?= (int)$a["id"] ?>"
                  data-username="<?= h((string)$a["username"]) ?>"
                  data-full_name="<?= h((string)$a["full_name"]) ?>"
                  data-status="<?= h((string)$a["status"]) ?>"
                  title="แก้ไข"
                >
                  <i class="bi bi-pencil-square"></i>
                </button>

                <button
                  class="btn btn-outline-danger btn-sm js-confirm"
                  data-id="<?= (int)$a["id"] ?>"
                  data-name="<?= h((string)$a["username"]) ?>"
                  title="ลบ"
                >
                  <i class="bi bi-trash"></i>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!count($admins)): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">ยังไม่มีแอดมิน</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>

  <div class="text-center text-muted small mt-4">
    <i class="bi bi-people me-1"></i> Admin Panel
  </div>
</div>

<!-- Hidden delete form -->
<form id="hiddenDeleteForm" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
  <input type="hidden" name="action" value="delete_admin">
  <input type="hidden" name="id" id="hd_id">
</form>

<!-- Modal: Add/Edit Admin -->
<div class="modal fade" id="modalAdmin" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" id="adminForm">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="action" id="af_action" value="create_admin">
      <input type="hidden" name="id" id="af_id" value="">

      <div class="modal-header">
        <h5 class="modal-title" id="adminModalTitle">
          <i class="bi bi-person-gear me-2"></i>แอดมิน
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Username</label>
            <input class="form-control" name="username" id="af_username" required>
          </div>

          <div class="col-12">
            <label class="form-label">ชื่อแสดง (full_name)</label>
            <input class="form-control" name="full_name" id="af_full_name">
          </div>

          <div class="col-md-6">
            <label class="form-label">สถานะ (status)</label>
            <select class="form-select" name="status" id="af_status">
              <option value="active">active</option>
              <option value="inactive">inactive</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label" id="pwLabel">Password</label>
            <input class="form-control" name="password" id="af_password" type="password" placeholder="ใส่รหัสผ่าน">
            <div class="text-muted small mt-1" id="pwHint" style="display:none;">
              ถ้าไม่ต้องการเปลี่ยนรหัส ให้เว้นว่างไว้
            </div>
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
  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }

  // Search (client-side, เหมือนหน้าอื่น)
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
  wireSearch("searchAdmins", "tableAdmins", "admCount");

  // Modal Add/Edit
  const modalAdminEl = document.getElementById("modalAdmin");
  const modalAdmin = modalAdminEl ? new bootstrap.Modal(modalAdminEl) : null;

  const afAction = document.getElementById("af_action");
  const afId = document.getElementById("af_id");
  const afUsername = document.getElementById("af_username");
  const afFullName = document.getElementById("af_full_name");
  const afStatus = document.getElementById("af_status");
  const afPassword = document.getElementById("af_password");
  const adminModalTitle = document.getElementById("adminModalTitle");
  const pwHint = document.getElementById("pwHint");
  const pwLabel = document.getElementById("pwLabel");

  document.getElementById("btnAddAdmin")?.addEventListener("click", () => {
    afAction.value = "create_admin";
    afId.value = "";
    afUsername.value = "";
    afFullName.value = "";
    afStatus.value = "active";
    afPassword.value = "";
    afPassword.required = true;

    if (adminModalTitle) adminModalTitle.innerHTML = `<i class="bi bi-person-plus me-2"></i>เพิ่มแอดมิน`;
    if (pwHint) pwHint.style.display = "none";
    if (pwLabel) pwLabel.textContent = "Password";

    modalAdmin?.show();
  });

  document.querySelectorAll(".js-edit").forEach(btn => {
    btn.addEventListener("click", () => {
      afAction.value = "update_admin";
      afId.value = btn.dataset.id || "";
      afUsername.value = btn.dataset.username || "";
      afFullName.value = btn.dataset.full_name || "";
      afStatus.value = btn.dataset.status || "active";
      afPassword.value = "";
      afPassword.required = false;

      if (adminModalTitle) adminModalTitle.innerHTML = `<i class="bi bi-person-gear me-2"></i>แก้ไขแอดมิน`;
      if (pwHint) pwHint.style.display = "block";
      if (pwLabel) pwLabel.textContent = "Password (ถ้าต้องการเปลี่ยน)";

      modalAdmin?.show();
    });
  });

  // Confirm delete
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
        confirmDeleteText.innerHTML = `แอดมิน: <span class="fw-semibold">${escapeHtml(name)}</span>`;
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

  // Toast from server flash
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