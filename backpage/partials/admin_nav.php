<?php
// partials/admin_nav.php
// ใช้ตัวแปร $currentPage จากหน้าหลัก (ถ้าไม่ส่งมา จะเดาเอง)
$currentPage = $currentPage ?? basename($_SERVER["PHP_SELF"]);
?>
<ul class="nav nav-pills gap-2">
  <li class="nav-item">
    <a class="nav-link <?= $currentPage==='backpage_dashboard.php'?'active':'' ?>" href="backpage_dashboard.php">
      <i class="bi bi-graph-up-arrow me-1"></i> หน้าหลัก
    </a>
  </li>

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
    <a class="nav-link <?= $currentPage==="backpage_slips.php" ? "active" : "" ?>" href="backpage_slips.php?page=1">
      <i class="bi bi-receipt me-1"></i> ตรวจสอบสลิป
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