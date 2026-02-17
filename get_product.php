<?php
require __DIR__ . "/config/db.php";

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
  SELECT p.*, c.name AS category_name
  FROM products p
  JOIN categories c ON c.id = p.category_id
  WHERE p.id = ?
  LIMIT 1
");

$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
  echo "<div class='text-danger'>ไม่พบสินค้า</div>";
  exit;
}

// ดึงรูปทั้งหมด
$stmtImg = $pdo->prepare("
  SELECT image_url FROM product_images
  WHERE product_id = ?
  ORDER BY sort_order ASC
");
$stmtImg->execute([$id]);
$images = $stmtImg->fetchAll();

?>

<div class="row">
  <div class="col-md-6">

    <?php if(count($images) > 0): ?>
      <img src="<?= htmlspecialchars($images[0]['image_url']) ?>"
           class="img-fluid rounded-3 mb-3">
    <?php endif; ?>

  </div>

  <div class="col-md-6">
    <h4 class="fw-semibold"><?= htmlspecialchars($product['name']) ?></h4>

    <div class="text-muted mb-2">
      <?= htmlspecialchars($product['category_name']) ?>
    </div>

    <div class="fs-5 fw-bold mb-3">
      <?= number_format($product['price'],0) ?> บาท
    </div>

    <p class="text-muted">
      <?= nl2br(htmlspecialchars($product['description'])) ?>
    </p>

    <button class="btn btn-brand mt-3">
      เพิ่มรายการ
    </button>
  </div>
</div>
