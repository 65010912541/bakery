<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/config/db.php";


// ---- รับค่าจาก query string สำหรับค้นหา/กรองหมวด
$q = trim((string)($_GET["q"] ?? ""));
$cat = trim((string)($_GET["cat"] ?? "")); // slug ของหมวด

// ---- ดึงหมวดหมู่
$categories = $pdo->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetchAll();

// ---- สร้าง SQL ดึงสินค้า + รูปแรก (ถ้ามี)
$sql = "
  SELECT
    p.id, p.name, p.slug, p.price, p.stock,
    c.name AS category_name, c.slug AS category_slug,
    (
      SELECT pi.image_url
      FROM product_images pi
      WHERE pi.product_id = p.id
      ORDER BY pi.sort_order ASC, pi.id ASC
      LIMIT 1
    ) AS image_url
  FROM products p
  JOIN categories c ON c.id = p.category_id
  WHERE p.status = 'active'
";

$params = [];

if ($q !== "") {
  $sql .= " AND p.name LIKE :q ";
  $params[":q"] = "%" . $q . "%";
}

if ($cat !== "") {
  $sql .= " AND c.slug = :cat ";
  $params[":cat"] = $cat;
}

$sql .= " ORDER BY p.created_at DESC LIMIT 24 ";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// placeholder รูป (ถ้าไม่มีรูปสินค้าใน DB)
$placeholder = "https://images.unsplash.com/photo-1542826438-bd32f43d626f?auto=format&fit=crop&w=800&q=60";
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>HokKao(69) Bakery</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body>

<?php require __DIR__ . "/partials/nav.php"; ?>

<header class="container my-4">
  <div class="hero-shop p-4 p-md-5 position-relative overflow-hidden">

    <?php if (isset($_SESSION["user"])): ?>
      <div class="hero-user-badge">
        👋 สวัสดี,
        <strong>
          <?= htmlspecialchars($_SESSION["user"]["full_name"] ?: $_SESSION["user"]["username"]) ?>
        </strong>
      </div>
    <?php endif; ?>

    <div class="row align-items-center g-4">
      <div class="col-md-7">
        <div class="hero-chip mb-3">อบสดใหม่ทุกวัน • ส่งไว • โฮมเมด</div>

        <h1 class="fw-semibold mb-2" style="line-height:1.15;">
          เบเกอรี่โฮมเมด<br>นุ่ม หอม อร่อย
        </h1>

        <p class="text-muted mb-4">
          เลือกซื้อขนมปัง เค้ก คุกกี้ —
          เหมือนเลือกเมนูในคาเฟ่ แต่สั่งออนไลน์ได้เลย
        </p>

        <div class="d-flex gap-2 flex-wrap">
          <a class="btn btn-brand px-4" href="#products">ช้อปตอนนี้</a>
          <a class="btn btn-ghost px-4" href="index.php">ดูทั้งหมด</a>
        </div>
      </div>
    </div>
  </div>
</header>


<main class="container mb-5">

<!-- 🔍 Search -->
<div class="d-flex justify-content-end mb-3">
  <form method="get" action="index.php"
        class="d-flex align-items-center gap-2">

    <?php if ($cat !== ""): ?>
      <input type="hidden" name="cat" value="<?= htmlspecialchars($cat) ?>">
    <?php endif; ?>

    <input type="text"
           name="q"
           value="<?= htmlspecialchars($q) ?>"
           class="form-control form-control-sm"
           style="width:220px;"
           placeholder="ค้นหาเบเกอรี่...">

    <button class="btn btn-sm btn-brand">
      <i class="bi bi-search"></i>
    </button>

  </form>
</div>

  <!-- Categories -->
  <section class="mb-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex gap-2 flex-wrap">
        <a class="cat-pill <?= $cat === "" ? "active" : "" ?>"
           href="index.php<?= $q!=="" ? ("?q=".urlencode($q)) : "" ?>">
           ทั้งหมด
        </a>

        <?php foreach ($categories as $c): ?>
          <?php
            $href = "index.php?cat=" . urlencode($c["slug"]);
            if ($q !== "") $href .= "&q=" . urlencode($q);
          ?>
          <a class="cat-pill <?= $cat === $c["slug"] ? "active" : "" ?>" href="<?= htmlspecialchars($href) ?>">
            <?= htmlspecialchars($c["name"]) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="text-muted small">
        พบ <?= count($products) ?> รายการ
      </div>
    </div>
  </section>

  <!-- Products Grid -->
  <section id="products">
    <?php if (count($products) === 0): ?>
      <div class="alert alert-light border rounded-4">
        ไม่พบสินค้าในเงื่อนไขที่เลือก ลองค้นหาด้วยคำอื่นนะ 🙂
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($products as $p): ?>
          <?php
            $img = $p["image_url"] ? $p["image_url"] : $placeholder;
            $outOfStock = ((int)$p["stock"] <= 0);
          ?>
          <div class="col-6 col-md-4 col-lg-3">
            <div class="card product-card h-100">
              <div class="img-wrap">
                <img class="product-img" src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($p["name"]) ?>">
                <?php if ($outOfStock): ?>
                  <span class="tag tag-out">หมด</span>
                <?php else: ?>
                  <span class="tag tag-in">พร้อมส่ง</span>
                <?php endif; ?>
              </div>

              <div class="card-body p-3">
                <div class="text-muted small mb-1"><?= htmlspecialchars($p["category_name"]) ?></div>
                <div class="fw-semibold text-truncate"><?= htmlspecialchars($p["name"]) ?></div>

                <div class="d-flex justify-content-between align-items-center mt-2">
                  <div class="price"><?= number_format((float)$p["price"], 0) ?> ฿</div>
                  <button type="button"
                            class="btn btn-link p-0 link-muted small"
                            data-id="<?= $p['id'] ?>"
                            onclick="openProductModal(this)">
                    ดูรายละเอียด
                    </button>
                </div>

                <button class="btn btn-brand"
                        type="button"
                        <?= $outOfStock ? "disabled" : "" ?>
                        onclick="openAddModal(
                        <?= (int)$p['id'] ?>,
                        '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>',
                        <?= (float)$p['price'] ?>,
                        <?= (int)$p['stock'] ?>
                        )">
                สั่งเมนูนี้
                </button>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>


</main>

<!-- Product Detail Modal -->
<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content rounded-4">

      <div class="modal-header border-0">
        <h5 class="modal-title fw-semibold">รายละเอียดสินค้า</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div id="modalContent">
          <div class="text-center p-4">กำลังโหลด...</div>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Add to Cart Modal (เลือกจำนวน/หมายเหตุ) -->
<div class="modal fade" id="addCartModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4">

      <div class="modal-header border-0">
        <div>
          <div class="text-muted small">เพิ่มลงตะกร้า</div>
          <h5 class="modal-title fw-semibold mb-0" id="addCartTitle">สินค้า</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body pt-0">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="text-muted">ราคา/ชิ้น</div>
          <div class="fw-bold" id="addCartPrice">0 ฿</div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-3">
          <div class="text-muted">จำนวน</div>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="stepQty(-1)">−</button>
            <input type="number" class="form-control text-center" id="addCartQty" value="1" min="1" style="width:90px;">
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="stepQty(1)">+</button>
          </div>
        </div>

        <div class="mb-2">
          <label class="form-label text-muted">หมายเหตุ (ถ้ามี)</label>
          <textarea class="form-control" id="addCartNote" rows="2" placeholder="เช่น ไม่หวาน, ไม่ใส่ถั่ว, แพ็กแยกชิ้น"></textarea>
        </div>

        <div class="d-flex justify-content-between align-items-center mt-3">
          <div class="text-muted">รวม</div>
          <div class="fw-bold" id="addCartLineTotal">0 บาท</div>
        </div>

        <div class="d-grid mt-3">
          <button type="button" class="btn btn-brand" id="confirmAddBtn" onclick="confirmAddToCart()">
            ยืนยันเพิ่มลงตะกร้า
          </button>
        </div>

        <div class="small text-muted mt-2" id="addCartHint"></div>
      </div>

    </div>
  </div>
</div>


<footer class="footer-shop py-4">
  <div class="container d-flex flex-column flex-md-row justify-content-between gap-2">
    <div>© <?= date("Y") ?> Bakery</div>
    <div class="text-muted">Minimal • Shop UI • Bootstrap 5 • MySQL</div>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ---------------------------
   Product Modal (ของเดิมคุณ)
--------------------------- */
function openProductModal(element) {
  const id = element.getAttribute("data-id");
  const modalEl = document.getElementById("productModal");
  const contentEl = document.getElementById("modalContent");
  if (!modalEl || !contentEl) return;

  const modal = new bootstrap.Modal(modalEl);
  modal.show();

  contentEl.innerHTML = "<div class='text-center p-4'>กำลังโหลด...</div>";
  fetch("get_product.php?id=" + encodeURIComponent(id))
    .then(res => res.text())
    .then(html => { contentEl.innerHTML = html; })
    .catch(() => { contentEl.innerHTML = "<div class='text-danger'>เกิดข้อผิดพลาด</div>"; });
}

/* ---------------------------
   Cart (localStorage)
--------------------------- */
const CART_KEY = "bakery_cart";

function loadCart() {
  try {
    const raw = localStorage.getItem(CART_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}
function saveCart(cart) {
  localStorage.setItem(CART_KEY, JSON.stringify(cart));
}
let cart = loadCart();
updateCartBadge();

/* เก็บสินค้าที่กำลังจะเพิ่ม (จาก modal) */
let pendingItem = null;

function openAddModal(id, name, price, stock) {
  // กัน stock
  stock = Number(stock);
  if (!Number.isFinite(stock) || stock <= 0) return;

  pendingItem = {
    id: Number(id),
    name: String(name ?? "").trim() || "สินค้า",
    price: Number(price) || 0,
    stock
  };

  // set UI
  document.getElementById("addCartTitle").textContent = pendingItem.name;
  document.getElementById("addCartPrice").textContent = pendingItem.price.toLocaleString() + " ฿";

  const qtyEl = document.getElementById("addCartQty");
  qtyEl.min = "1";
  qtyEl.max = String(pendingItem.stock);
  qtyEl.value = "1";

  document.getElementById("addCartNote").value = "";
  document.getElementById("addCartHint").textContent = `คงเหลือ ${pendingItem.stock} ชิ้น`;

  updateLineTotal();

  const modal = new bootstrap.Modal(document.getElementById("addCartModal"));
  modal.show();
}

function stepQty(delta) {
  const qtyEl = document.getElementById("addCartQty");
  let qty = Number(qtyEl.value || 1);
  const min = Number(qtyEl.min || 1);
  const max = Number(qtyEl.max || 999);

  qty = qty + Number(delta);
  if (qty < min) qty = min;
  if (qty > max) qty = max;

  qtyEl.value = String(qty);
  updateLineTotal();
}

document.addEventListener("input", (e) => {
  if (e.target && e.target.id === "addCartQty") updateLineTotal();
});

function updateLineTotal() {
  if (!pendingItem) return;

  const qty = Number(document.getElementById("addCartQty").value || 1);
  const price = pendingItem.price || 0;
  const total = price * qty;

  document.getElementById("addCartLineTotal").textContent =
      total.toLocaleString() + " บาท";
}

function confirmAddToCart() {
  if (!pendingItem) return;

  const qtyEl = document.getElementById("addCartQty");
  const noteEl = document.getElementById("addCartNote");

  let qty = Number(qtyEl.value || 1);
  const max = Number(qtyEl.max || pendingItem.stock);

  if (!Number.isFinite(qty) || qty < 1) qty = 1;
  if (qty > max) {
    qty = max;
    qtyEl.value = max;
  }

  const note = String(noteEl.value ?? "").trim();

  addToCart(pendingItem.id, pendingItem.name, pendingItem.price, qty, note);

  const modalEl = document.getElementById("addCartModal");
  const modal = bootstrap.Modal.getInstance(modalEl);
  if (modal) modal.hide();

  pendingItem = null;
}

/**
 * เพิ่มเข้าตะกร้า
 * - ถ้าสินค้าเดิม + note เดิม -> รวมจำนวน
 * - ถ้า note ต่างกัน -> แยกบรรทัด (เหมือนคนละออเดอร์)
 */
function addToCart(id, name, price, qty, note) {
  id = Number(id);
  price = Number(price);
  qty = Number(qty);
  note = String(note ?? "").trim();

  if (!Number.isFinite(id) || id <= 0) return;
  if (!Number.isFinite(price) || price < 0) price = 0;
  if (!Number.isFinite(qty) || qty < 1) qty = 1;

  const keyNote = note; // ใช้ note เป็นตัวแยกบรรทัด
  const found = cart.find(i => Number(i.id) === id && String(i.note || "") === keyNote);

  if (found) {
    found.qty = Number(found.qty || 0) + qty;
  } else {
    cart.push({ id, name, price, qty, note: keyNote });
  }

  saveCart(cart);
  updateCartBadge();
}

function updateCartBadge() {
  const badgeEl = document.querySelector(".cart-badge");
  if (!badgeEl) return;

  const count = cart.reduce((sum, item) => sum + Number(item.qty || 0), 0);
  badgeEl.textContent = String(count);
}
</script>
</body>
</html>

