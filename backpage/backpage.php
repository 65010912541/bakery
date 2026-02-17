<?php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";
// require __DIR__ . "/../config/auth.php"; // ถ้ามี

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

if (empty($_SESSION["csrf"])) {
  $_SESSION["csrf"] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION["csrf"];

$tab = (string)($_GET["tab"] ?? "products"); // products|categories

$perPage = 10; // แสดงหน้าละ 10 รายการ
$page = max(1, (int)($_GET["page"] ?? 1));
$offset = ($page - 1) * $perPage;

$flash = $_SESSION["flash"] ?? null;
unset($_SESSION["flash"]);

function redirect_to(string $tab, int $page = 1): void {
  header("Location: backpage.php?tab=" . urlencode($tab) . "&page=" . $page);
  exit;
}

function flash(string $type, string $msg): void {
  $_SESSION["flash"] = ["type" => $type, "msg" => $msg];
}
function ensure_csrf(): void {
  if (!hash_equals($_SESSION["csrf"] ?? "", (string)($_POST["csrf"] ?? ""))) {
    http_response_code(403);
    exit("CSRF token mismatch");
  }
}
function make_slug(string $text): string {
  $text = trim($text);
  if ($text === "") return "";
  $slug = strtolower($text);
  $slug = preg_replace('/\s+/', '-', $slug);
  $slug = preg_replace('/[^a-z0-9\-ก-๙]+/u', '', $slug);
  return trim((string)$slug, '-');
}
function renderPagination(string $tab, int $page, int $totalPages): string {
  if ($totalPages <= 1) return "";

  // กดหน้า 5 -> แสดง 5-9
  $start = $page;
  $end = min($start + 4, $totalPages);

  // ถ้าใกล้หน้าสุดท้าย ให้ถอยเพื่อให้ครบ 5 ปุ่ม
  if (($end - $start) < 4) {
    $start = max(1, $end - 4);
  }

  $qs = fn($p) => "?tab={$tab}&page={$p}";

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

/**
 * อัปโหลดไฟล์รูป -> คืนค่า path แบบ relative สำหรับเก็บ DB (เช่น assets/uploads/products/xxx.jpg)
 * - ถ้าไม่มีไฟล์ (error UPLOAD_ERR_NO_FILE) => คืน "" (แปลว่าไม่เปลี่ยน/ไม่ใส่)
 * - รองรับ jpg/png/webp
 */
function upload_product_image(array $file, string $uploadDirAbs, string $uploadDirRel): string {
  if (!isset($file["error"]) || $file["error"] === UPLOAD_ERR_NO_FILE) return "";

  if ($file["error"] !== UPLOAD_ERR_OK) {
    throw new RuntimeException("อัปโหลดรูปไม่สำเร็จ (code: {$file["error"]})");
  }

  $tmp = (string)$file["tmp_name"];
  $size = (int)$file["size"];
  if ($size <= 0) throw new RuntimeException("ไฟล์รูปไม่ถูกต้อง");
  if ($size > 5 * 1024 * 1024) throw new RuntimeException("ไฟล์ใหญ่เกินไป (สูงสุด 5MB)");

  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $mime = (string)$finfo->file($tmp);

  $ext = match ($mime) {
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
    default => ""
  };
  if ($ext === "") {
    throw new RuntimeException("รองรับเฉพาะไฟล์ JPG/PNG/WEBP เท่านั้น");
  }

  if (!is_dir($uploadDirAbs)) {
    if (!mkdir($uploadDirAbs, 0775, true) && !is_dir($uploadDirAbs)) {
      throw new RuntimeException("สร้างโฟลเดอร์อัปโหลดไม่สำเร็จ");
    }
  }

  $safeName = bin2hex(random_bytes(10)) . "." . $ext;
  $destAbs = rtrim($uploadDirAbs, "/\\") . DIRECTORY_SEPARATOR . $safeName;

  if (!move_uploaded_file($tmp, $destAbs)) {
    throw new RuntimeException("ย้ายไฟล์อัปโหลดไม่สำเร็จ");
  }

  return rtrim($uploadDirRel, "/") . "/" . $safeName;
}

/* =======================
   CONFIG UPLOAD PATH
======================= */
$UPLOAD_DIR_ABS = realpath(__DIR__ . "/../assets") ?: (__DIR__ . "/../assets");
$UPLOAD_DIR_ABS .= "/uploads/products";
$UPLOAD_DIR_REL = "assets/uploads/products";

/* =======================
   HANDLE ACTIONS (POST)
======================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  ensure_csrf();

  $action = (string)($_POST["action"] ?? "");
  $entity = (string)($_POST["entity"] ?? "");
  $backTab = (string)($_POST["back_tab"] ?? "products");

  try {
    /* --------- CATEGORIES CRUD --------- */
    if ($entity === "category") {
      if ($action === "create") {
        $name = trim((string)($_POST["name"] ?? ""));
        $slug = trim((string)($_POST["slug"] ?? ""));
        if ($slug === "" && $name !== "") $slug = make_slug($name);

        if ($name === "" || $slug === "") {
          flash("danger", "กรุณากรอกชื่อและ slug ของประเภทสินค้า");
          redirect_to("categories");
        }

        $stmt = $pdo->prepare("INSERT INTO categories (name, slug, created_at) VALUES (?, ?, NOW())");
        $stmt->execute([$name, $slug]);

        flash("success", "เพิ่มประเภทสินค้าเรียบร้อย");
        redirect_to("categories");
      }

      if ($action === "update") {
        $id = (int)($_POST["id"] ?? 0);
        $name = trim((string)($_POST["name"] ?? ""));
        $slug = trim((string)($_POST["slug"] ?? ""));
        if ($slug === "" && $name !== "") $slug = make_slug($name);

        if ($id <= 0 || $name === "" || $slug === "") {
          flash("danger", "ข้อมูลไม่ครบสำหรับแก้ไขประเภทสินค้า");
          redirect_to("categories");
        }

        $stmt = $pdo->prepare("UPDATE categories SET name=?, slug=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$name, $slug, $id]);

        flash("success", "แก้ไขประเภทสินค้าเรียบร้อย");
        redirect_to("categories");
      }

      if ($action === "delete") {
        $id = (int)($_POST["id"] ?? 0);
        if ($id <= 0) {
          flash("danger", "ไม่พบ ID ประเภทสินค้า");
          redirect_to("categories");
        }

        $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id=?");
        $check->execute([$id]);
        if ((int)$check->fetchColumn() > 0) {
          flash("warning", "ลบไม่ได้: ยังมีสินค้าอยู่ในประเภทนี้");
          redirect_to("categories");
        }

        $stmt = $pdo->prepare("DELETE FROM categories WHERE id=?");
        $stmt->execute([$id]);

        flash("success", "ลบประเภทสินค้าเรียบร้อย");
        redirect_to("categories");
      }
    }

    /* --------- PRODUCTS CRUD (+ image upload in product_images) --------- */
    if ($entity === "product") {
      if ($action === "create") {
        $category_id = (int)($_POST["category_id"] ?? 0);
        $name = trim((string)($_POST["name"] ?? ""));
        $slug = trim((string)($_POST["slug"] ?? ""));
        $price = (float)($_POST["price"] ?? 0);
        $stock = (int)($_POST["stock"] ?? 0);
        $description = trim((string)($_POST["description"] ?? ""));
        $status = (string)($_POST["status"] ?? "active");

        if ($slug === "" && $name !== "") $slug = make_slug($name);
        if ($category_id <= 0 || $name === "" || $slug === "") {
          flash("danger", "กรุณากรอกข้อมูลสินค้าให้ครบ (ประเภท, ชื่อ, slug)");
          redirect_to("products");
        }

        // อัปโหลดรูป (ถ้ามี)
        $imageRelPath = "";
        if (isset($_FILES["image_file"])) {
          $imageRelPath = upload_product_image($_FILES["image_file"], $UPLOAD_DIR_ABS, $UPLOAD_DIR_REL);
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
          INSERT INTO products (category_id, name, slug, price, stock, description, status, created_at)
          VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$category_id, $name, $slug, $price, $stock, $description, $status]);
        $product_id = (int)$pdo->lastInsertId();

        if ($imageRelPath !== "") {
          $pdo->prepare("
            INSERT INTO product_images (product_id, image_url, sort_order, created_at)
            VALUES (?, ?, 1, NOW())
          ")->execute([$product_id, $imageRelPath]);
        }

        $pdo->commit();

        flash("success", "เพิ่มสินค้าเรียบร้อย");
        redirect_to("products");
      }

      if ($action === "update") {
        $id = (int)($_POST["id"] ?? 0);
        $category_id = (int)($_POST["category_id"] ?? 0);
        $name = trim((string)($_POST["name"] ?? ""));
        $slug = trim((string)($_POST["slug"] ?? ""));
        $price = (float)($_POST["price"] ?? 0);
        $stock = (int)($_POST["stock"] ?? 0);
        $description = trim((string)($_POST["description"] ?? ""));
        $status = (string)($_POST["status"] ?? "active");

        if ($slug === "" && $name !== "") $slug = make_slug($name);
        if ($id <= 0 || $category_id <= 0 || $name === "" || $slug === "") {
          flash("danger", "ข้อมูลไม่ครบสำหรับแก้ไขสินค้า");
          redirect_to("products");
        }

        // อัปโหลดรูปใหม่ (ถ้ามีเลือกไฟล์) -> replace รูปหลัก
        $newImageRelPath = "";
        if (isset($_FILES["image_file"])) {
          $newImageRelPath = upload_product_image($_FILES["image_file"], $UPLOAD_DIR_ABS, $UPLOAD_DIR_REL);
        }

        $pdo->beginTransaction();

        $pdo->prepare("
          UPDATE products
          SET category_id=?, name=?, slug=?, price=?, stock=?, description=?, status=?, updated_at=NOW()
          WHERE id=?
        ")->execute([$category_id, $name, $slug, $price, $stock, $description, $status, $id]);

        // หา “รูปหลัก” (รูปแรก)
        $findImg = $pdo->prepare("
          SELECT id, image_url FROM product_images
          WHERE product_id=?
          ORDER BY sort_order ASC, id ASC
          LIMIT 1
        ");
        $findImg->execute([$id]);
        $imgRow = $findImg->fetch(PDO::FETCH_ASSOC);
        $imgId = (int)($imgRow["id"] ?? 0);

        if ($newImageRelPath !== "") {
          // มีการอัปโหลดใหม่ -> update ถ้ามี row เดิม ไม่มีก็ insert
          if ($imgId > 0) {
            $pdo->prepare("UPDATE product_images SET image_url=?, sort_order=1 WHERE id=?")
              ->execute([$newImageRelPath, $imgId]);
          } else {
            $pdo->prepare("
              INSERT INTO product_images (product_id, image_url, sort_order, created_at)
              VALUES (?, ?, 1, NOW())
            ")->execute([$id, $newImageRelPath]);
          }
        }
        // ถ้าไม่เลือกไฟล์ใหม่ = ไม่แตะรูปเดิม (สมูท ไม่บังคับ)

        $pdo->commit();

        flash("success", "แก้ไขสินค้าเรียบร้อย");
        redirect_to("products");
      }

      if ($action === "delete") {
        $id = (int)($_POST["id"] ?? 0);
        if ($id <= 0) {
          flash("danger", "ไม่พบ ID สินค้า");
          redirect_to("products");
        }

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM product_images WHERE product_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
        $pdo->commit();

        flash("success", "ลบสินค้าเรียบร้อย");
        redirect_to("products");
      }
    }

    flash("warning", "ไม่พบคำสั่งที่ร้องขอ");
    redirect_to($backTab);

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash("danger", "เกิดข้อผิดพลาด: " . $e->getMessage());
    redirect_to($backTab);
  }
}

// ===== PRODUCTS pagination =====
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$totalPagesProducts = max(1, (int)ceil($totalProducts / $perPage));

$pageProducts = min($page, $totalPagesProducts);
$offsetProducts = ($pageProducts - 1) * $perPage;

$stmt = $pdo->prepare("
  SELECT
    p.id, p.name, p.slug, p.price, p.stock, p.description, p.status, p.category_id,
    c.name AS category_name,
    (
      SELECT pi.image_url
      FROM product_images pi
      WHERE pi.product_id = p.id
      ORDER BY pi.sort_order ASC, pi.id ASC
      LIMIT 1
    ) AS image_url
  FROM products p
  JOIN categories c ON c.id = p.category_id
  ORDER BY p.id ASC
  LIMIT :lim OFFSET :off
");
$stmt->bindValue(":lim", $perPage, PDO::PARAM_INT);
$stmt->bindValue(":off", $offsetProducts, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// ===== CATEGORIES pagination =====
$totalCategories = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$totalPagesCategories = max(1, (int)ceil($totalCategories / $perPage));

$pageCategories = min($page, $totalPagesCategories);
$offsetCategories = ($pageCategories - 1) * $perPage;

$stmtCat = $pdo->prepare("
  SELECT id, name, slug
  FROM categories
  ORDER BY id ASC
  LIMIT :lim OFFSET :off
");
$stmtCat->bindValue(":lim", $perPage, PDO::PARAM_INT);
$stmtCat->bindValue(":off", $offsetCategories, PDO::PARAM_INT);
$stmtCat->execute();
$categories = $stmtCat->fetchAll();

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
    .app-shell { max-width: 1150px; }
    .brand-badge { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 16px; }
    .card { border: 0; border-radius: 18px; box-shadow: 0 10px 25px rgba(15, 23, 42, .06); }
    .nav-pills .nav-link { border-radius: 14px; }
    .table > :not(caption) > * > * { vertical-align: middle; }
    .chip { font-size: .85rem; border-radius: 999px; padding: .25rem .6rem; }
    .truncate { max-width: 340px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .form-control, .form-select { border-radius: 14px; }
    .btn { border-radius: 14px; }
    .thumb { width: 44px; height: 44px; border-radius: 14px; object-fit: cover; border: 1px solid rgba(0,0,0,.08); background: #fff; }
    .soft { background: rgba(255,255,255,.65); border: 1px solid rgba(0,0,0,.06); }
    .toast { border-radius: 16px; }
    .fade-smooth { transition: opacity .18s ease, transform .18s ease; }
    .row-hide { opacity: 0; transform: translateY(4px); }
    /* Toast ให้ชัด อ่านง่าย */
.toast {
  border-radius: 18px;
  border: 1px solid rgba(0,0,0,.08);
  box-shadow: 0 18px 45px rgba(15, 23, 42, .18);
}

.toast-header {
  padding: .9rem 1rem;
}

.toast-body {
  padding: .9rem 1rem 1rem 1rem;
  font-size: 1.02rem;
  line-height: 1.4;
  color: #0f172a;
}

#confirmToast, #resultToast {
  min-width: 380px;
  max-width: 520px;
  background: #ffffff; /* ทำให้ทึบ ไม่จาง */
}

#resultTitle, .toast-header strong {
  font-size: 1.05rem;
}

#resultIcon {
  font-size: 1.25rem;
}
/* Toast Slide Out Animation */
@keyframes slideOutRight {
  0% {
    opacity: 1;
    transform: translateX(0);
  }
  100% {
    opacity: 0;
    transform: translateX(40px);
  }
}

.toast.slide-out {
  animation: slideOutRight 0.35s ease forwards;
}
/* ทำให้ input-group ของค้นหาโค้งมนแบบ pill */
.search-pill {
  border-radius: 16px;
  overflow: hidden; /* สำคัญ: ให้มุมมนทั้งกล่อง */
}
.search-pill .input-group-text,
.search-pill .form-control {
  border-radius: 0 !important; /* ให้กล่องรวมเป็นมุมเดียว */
}
.table-headbar{
  padding: .25rem 0;
}

/* ===== Pagination (Soft theme) ===== */
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

/* กล่องครอบตารางให้ดูเป็นกรอบ */
.table-box{
  background: rgba(255,255,255,.75);
  border: 1px solid rgba(0,0,0,.06);
  border-radius: 18px;
  box-shadow: 0 10px 25px rgba(15, 23, 42, .06);
  padding: 14px;
}

/* แถบบนของตาราง (ซ้าย: ทั้งหมด | ขวา: pagination) */
.table-topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding: 4px 2px 10px 2px;
}

/* ให้ pagination ดูเข้าธีมขึ้น (ไม่แข็ง) */
.pagination.pagination-dark{
  gap: 8px;
}
.pagination-dark .page-link{
  margin-left: 0 !important; /* ยกเลิก margin เดิมของคุณ */
  border-radius: 12px;
  background: #15181d;
  color: #fff;
  border: 1px solid rgba(255,255,255,.08);
}
.pagination-dark .page-item.active .page-link{
  background: #2ecc71;
  border-color: rgba(0,0,0,.0);
}
.pagination-dark .page-item.disabled .page-link{
  opacity: .35;
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
        <div class="text-muted small">Backpage • สมูทขึ้น + อัปโหลดรูปจริง</div>
      </div>
    </div>

    <div class="d-flex gap-2">
      <a class="btn btn-outline-dark" href="../index.php">
        <i class="bi bi-house-door me-1"></i> ไปหน้าร้าน
      </a>
      <a class="btn btn-outline-secondary" href="../logout.php">
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

      <ul class="nav nav-pills gap-2">
        <li class="nav-item">
          <a class="nav-link <?= $tab==="products" ? "active" : "" ?>" href="?tab=products&page=1">
            <i class="bi bi-bag-heart me-1"></i> สินค้า
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $tab==="categories" ? "active" : "" ?>" href="?tab=categories&page=1">
            <i class="bi bi-tags me-1"></i> ประเภทสินค้า
          </a>
        </li>
      </ul>
    </div>

    <hr class="my-2">

    <?php if ($tab === "products"): ?>
      <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mt-3">
        <div class="text-muted small">
          แสดง <span class="chip bg-light border" id="prodCount"><?= count($products) ?></span> รายการ
        </div>

        <div class="input-group soft search-pill mx-lg-auto" style="width: 520px; max-width: 60vw;">
          <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
          <input id="searchProducts" class="form-control border-0 bg-transparent"
                placeholder="ค้นหาสินค้า (ชื่อ / slug / ประเภท)">
        </div>

        <button class="btn btn-dark ms-lg-0" data-bs-toggle="modal" data-bs-target="#modalProductCreate">
          <i class="bi bi-plus-lg me-1"></i> เพิ่มสินค้า
        </button>
      </div>
      
      <div class="table-responsive mt-3">
        <div class="table-headbar d-flex align-items-center justify-content-between mb-2">
          <div class="text-muted small">
            ทั้งหมด <span class="fw-semibold"><?= (int)$totalProducts ?></span> รายการ
          </div>
          <?= renderPagination("products", $pageProducts, $totalPagesProducts) ?>
        </div>
        </div>

        <table class="table table-hover align-middle" id="tableProducts">
          <thead class="text-muted small">
            <tr>
              <th style="width: 70px;">ID</th>
              <th style="width: 70px;">รูป</th>
              <th>ชื่อสินค้า</th>
              <th style="width: 180px;">ประเภท</th>
              <th style="width: 120px;">ราคา</th>
              <th style="width: 100px;">สต็อก</th>
              <th style="width: 110px;">สถานะ</th>
              <th style="width: 140px;" class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <?php
                $searchBlob = strtolower((string)$p["id"] . " " . (string)$p["name"] . " " . (string)$p["slug"] . " " . (string)$p["category_name"]);
                $img = (string)($p["image_url"] ?? "");
              ?>
              <tr class="fade-smooth" data-search="<?= h($searchBlob) ?>">
                <td class="text-muted"><?= (int)$p["id"] ?></td>
                <td>
                  <?php if ($img !== ""): ?>
                    <img class="thumb" src="../<?= h($img) ?>" alt="img">
                  <?php else: ?>
                    <div class="thumb d-grid place-items-center text-muted" style="display:grid;place-items:center;">
                      <i class="bi bi-image"></i>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="fw-semibold"><?= h($p["name"]) ?></div>
                  <div class="text-muted small truncate">slug: <?= h($p["slug"]) ?></div>
                </td>
                <td><?= h($p["category_name"]) ?></td>
                <td><?= number_format((float)$p["price"], 2) ?></td>
                <td><?= (int)$p["stock"] ?></td>
                <td>
                  <?php $st = (string)$p["status"]; ?>
                  <?php if ($st === "active"): ?>
                    <span class="chip bg-success-subtle text-success border border-success-subtle">
                      <i class="bi bi-check-circle me-1"></i> active
                    </span>
                  <?php else: ?>
                    <span class="chip bg-secondary-subtle text-secondary border">
                      <i class="bi bi-dash-circle me-1"></i> <?= h($st) ?>
                    </span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <button
                    class="btn btn-outline-dark btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#modalProductEdit"
                    data-id="<?= (int)$p["id"] ?>"
                    data-category_id="<?= (int)$p["category_id"] ?>"
                    data-name="<?= h($p["name"]) ?>"
                    data-slug="<?= h($p["slug"]) ?>"
                    data-price="<?= h((string)$p["price"]) ?>"
                    data-stock="<?= h((string)$p["stock"]) ?>"
                    data-description="<?= h((string)$p["description"]) ?>"
                    data-status="<?= h((string)$p["status"]) ?>"
                  >
                    <i class="bi bi-pencil-square"></i>
                  </button>

                  <button
                    class="btn btn-outline-danger btn-sm js-confirm"
                    data-entity="product"
                    data-id="<?= (int)$p["id"] ?>"
                    data-name="<?= h($p["name"]) ?>"
                    data-back_tab="products"
                    data-action="delete"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!count($products)): ?>
              <tr><td colspan="8" class="text-center text-muted py-4">ยังไม่มีสินค้า</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($tab === "categories"): ?>
      <div class="d-flex flex-column flex-lg-row align-items-lg-center gap-3 mt-3">
        <div class="text-muted small">
          แสดง <span class="chip bg-light border" id="catCount"><?= count($categories) ?></span> รายการ
        </div>

        <div class="input-group soft search-pill mx-lg-auto" style="width: 520px; max-width: 60vw;">
          <span class="input-group-text bg-transparent border-0"><i class="bi bi-search"></i></span>
          <input id="searchCategories" class="form-control border-0 bg-transparent"
                placeholder="ค้นหาประเภทสินค้า (ชื่อ / slug)">
        </div>

        <button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#modalCategoryCreate">
          <i class="bi bi-plus-lg me-1"></i> เพิ่มประเภท
        </button>
      </div>

      <div class="table-responsive mt-3">
        <div class="d-flex justify-content-between align-items-center mt-2">
          <div class="text-muted small">
            ทั้งหมด <span class="fw-semibold"><?= (int)$totalCategories ?></span> รายการ
          </div>
          <?= renderPagination("categories", $pageCategories, $totalPagesCategories) ?>
        </div>

        <table class="table table-hover align-middle" id="tableCategories">
          <thead class="text-muted small">
            <tr>
              <th style="width: 70px;">ID</th>
              <th>ชื่อประเภท</th>
              <th style="width: 220px;">slug</th>
              <th style="width: 140px;" class="text-end">จัดการ</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
              <?php $searchBlob = strtolower((string)$c["id"] . " " . (string)$c["name"] . " " . (string)$c["slug"]); ?>
              <tr class="fade-smooth" data-search="<?= h($searchBlob) ?>">
                <td class="text-muted"><?= (int)$c["id"] ?></td>
                <td class="fw-semibold"><?= h($c["name"]) ?></td>
                <td class="text-muted"><?= h($c["slug"]) ?></td>
                <td class="text-end">
                  <button
                    class="btn btn-outline-dark btn-sm"
                    data-bs-toggle="modal"
                    data-bs-target="#modalCategoryEdit"
                    data-id="<?= (int)$c["id"] ?>"
                    data-name="<?= h($c["name"]) ?>"
                    data-slug="<?= h($c["slug"]) ?>"
                  >
                    <i class="bi bi-pencil-square"></i>
                  </button>

                  <button
                    class="btn btn-outline-danger btn-sm js-confirm"
                    data-entity="category"
                    data-id="<?= (int)$c["id"] ?>"
                    data-name="<?= h($c["name"]) ?>"
                    data-back_tab="categories"
                    data-action="delete"
                  >
                    <i class="bi bi-trash"></i>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!count($categories)): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีประเภทสินค้า</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </div>

  <div class="text-center text-muted small mt-4">
    <i class="bi bi-shield-lock me-1"></i> Admin Panel • Upload Image • Smooth UI
  </div>
</div>

<!-- Hidden delete form -->
<form id="hiddenDeleteForm" method="post" class="d-none">
  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
  <input type="hidden" name="entity" id="hd_entity">
  <input type="hidden" name="action" id="hd_action" value="delete">
  <input type="hidden" name="back_tab" id="hd_back_tab">
  <input type="hidden" name="id" id="hd_id">
</form>

<!-- Category Modals -->
<div class="modal fade" id="modalCategoryCreate" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="entity" value="category">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="back_tab" value="categories">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-tags me-2"></i>เพิ่มประเภทสินค้า</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">ชื่อประเภท</label>
          <input class="form-control" name="name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">slug</label>
          <input class="form-control" name="slug" placeholder="เช่น cake, bread (ปล่อยว่างจะเดาจากชื่อ)">
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
        <button class="btn btn-dark" type="submit"><i class="bi bi-check2 me-1"></i>บันทึก</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="modalCategoryEdit" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="entity" value="category">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="back_tab" value="categories">
      <input type="hidden" name="id" id="cat_edit_id">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขประเภทสินค้า</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">ชื่อประเภท</label>
          <input class="form-control" name="name" id="cat_edit_name" required>
        </div>
        <div class="mb-2">
          <label class="form-label">slug</label>
          <input class="form-control" name="slug" id="cat_edit_slug" required>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">ยกเลิก</button>
        <button class="btn btn-dark" type="submit"><i class="bi bi-check2 me-1"></i>บันทึก</button>
      </div>
    </form>
  </div>
</div>

<!-- Product Modals (multipart/form-data for upload) -->
<div class="modal fade" id="modalProductCreate" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="entity" value="product">
      <input type="hidden" name="action" value="create">
      <input type="hidden" name="back_tab" value="products">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-bag-plus me-2"></i>เพิ่มสินค้า</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">ประเภทสินค้า</label>
            <select class="form-select" name="category_id" required>
              <option value="">— เลือกประเภท —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">สถานะ</label>
            <select class="form-select" name="status">
              <option value="active">active</option>
              <option value="inactive">inactive</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">ชื่อสินค้า</label>
            <input class="form-control" name="name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">slug</label>
            <input class="form-control" name="slug" placeholder="ปล่อยว่างจะเดาจากชื่อ">
          </div>
          <div class="col-md-4">
            <label class="form-label">ราคา</label>
            <input class="form-control" name="price" type="number" step="0.01" min="0" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">สต็อก</label>
            <input class="form-control" name="stock" type="number" step="1" min="0" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">อัปโหลดรูปสินค้า</label>
            <input class="form-control" name="image_file" type="file" accept="image/png,image/jpeg,image/webp">
            <div class="text-muted small mt-1">รองรับ JPG/PNG/WEBP (สูงสุด 5MB)</div>
          </div>
          <div class="col-12">
            <label class="form-label">รายละเอียด</label>
            <textarea class="form-control" name="description" rows="3"></textarea>
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

<div class="modal fade" id="modalProductEdit" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="entity" value="product">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="back_tab" value="products">
      <input type="hidden" name="id" id="prod_edit_id">

      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>แก้ไขสินค้า</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">ประเภทสินค้า</label>
            <select class="form-select" name="category_id" id="prod_edit_category_id" required>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c["id"] ?>"><?= h($c["name"]) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">สถานะ</label>
            <select class="form-select" name="status" id="prod_edit_status">
              <option value="active">active</option>
              <option value="inactive">inactive</option>
            </select>
          </div>

          <div class="col-md-6">
            <label class="form-label">ชื่อสินค้า</label>
            <input class="form-control" name="name" id="prod_edit_name" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">slug</label>
            <input class="form-control" name="slug" id="prod_edit_slug" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">ราคา</label>
            <input class="form-control" name="price" id="prod_edit_price" type="number" step="0.01" min="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">สต็อก</label>
            <input class="form-control" name="stock" id="prod_edit_stock" type="number" step="1" min="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">เปลี่ยนรูปสินค้า (อัปโหลดใหม่)</label>
            <input class="form-control" name="image_file" type="file" accept="image/png,image/jpeg,image/webp">
            <div class="text-muted small mt-1">ถ้าไม่เลือกไฟล์ = ไม่เปลี่ยนรูป</div>
          </div>

          <div class="col-12">
            <label class="form-label">รายละเอียด</label>
            <textarea class="form-control" name="description" id="prod_edit_description" rows="3"></textarea>
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


<!-- Toast (Vertically centered) -->
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
  // Fill category edit modal
  document.getElementById('modalCategoryEdit')?.addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('cat_edit_id').value = b.getAttribute('data-id');
    document.getElementById('cat_edit_name').value = b.getAttribute('data-name');
    document.getElementById('cat_edit_slug').value = b.getAttribute('data-slug');
  });

  // Fill product edit modal
  document.getElementById('modalProductEdit')?.addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('prod_edit_id').value = b.getAttribute('data-id');
    document.getElementById('prod_edit_category_id').value = b.getAttribute('data-category_id');
    document.getElementById('prod_edit_name').value = b.getAttribute('data-name');
    document.getElementById('prod_edit_slug').value = b.getAttribute('data-slug');
    document.getElementById('prod_edit_price').value = b.getAttribute('data-price');
    document.getElementById('prod_edit_stock').value = b.getAttribute('data-stock');
    document.getElementById('prod_edit_description').value = b.getAttribute('data-description');
    document.getElementById('prod_edit_status').value = b.getAttribute('data-status');
  });

  // Smooth search (hide/show with subtle transition)
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
          // delay เล็กน้อยให้ transition ทำงานแล้วค่อยซ่อน
          setTimeout(() => { tr.style.display = "none"; }, 120);
        }
      });

      if (countEl) countEl.textContent = String(visible);
    };

    let t = null;
    input.addEventListener("input", () => {
      clearTimeout(t);
      t = setTimeout(run, 80); // debounce ให้ลื่น
    });
  }

  wireSearch("searchProducts", "tableProducts", "prodCount");
  wireSearch("searchCategories", "tableCategories", "catCount");


  function escapeHtml(s) {
    return String(s)
      .replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;").replaceAll("'", "&#039;");
  }
  
// ===== Confirm delete modal wiring =====
const hiddenForm = document.getElementById("hiddenDeleteForm");
const hdEntity = document.getElementById("hd_entity");
const hdAction = document.getElementById("hd_action");
const hdBackTab = document.getElementById("hd_back_tab");
const hdId = document.getElementById("hd_id");

const confirmDeleteModalEl = document.getElementById("confirmDeleteModal");
const confirmDeleteModal = confirmDeleteModalEl ? new bootstrap.Modal(confirmDeleteModalEl) : null;
const confirmDeleteText = document.getElementById("confirmDeleteText");

let pending = null;

document.querySelectorAll(".js-confirm").forEach(btn => {
  btn.addEventListener("click", () => {
    pending = {
      entity: btn.dataset.entity,
      action: btn.dataset.action || "delete",
      id: btn.dataset.id,
      back_tab: btn.dataset.back_tab,
      name: btn.dataset.name || ""
    };

    const entityText = pending.entity === "product" ? "สินค้า" : "ประเภทสินค้า";
    if (confirmDeleteText) {
      confirmDeleteText.innerHTML = `${entityText}: <span class="fw-semibold">${escapeHtml(pending.name)}</span>`;
    }

    confirmDeleteModal?.show();
  });
});

document.getElementById("btnConfirmDeleteYes")?.addEventListener("click", () => {
  if (!pending || !hiddenForm) return;

  hdEntity.value = pending.entity;
  hdAction.value = pending.action;
  hdBackTab.value = pending.back_tab;
  hdId.value = pending.id;

  confirmDeleteModal?.hide();
  hiddenForm.submit();
});

  // Result toast after server flash
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
  }, 350); // รอ animation จบ
}, 3000); // แสดง 3 วิ
    })();
  <?php endif; ?>
</script>
</body>
</html>
