<?php
declare(strict_types=1);
?>

<nav class="navbar navbar-expand-lg nav-shop sticky-top">
  <div class="container py-2">

    <!-- Logo -->
    <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
      <span class="logo-mark">🥖</span>
      <span class="fw-semibold">HokKao(69) Bakery</span>
    </a>

    <!-- Hamburger -->
    <button class="navbar-toggler" type="button"
            data-bs-toggle="collapse"
            data-bs-target="#navShop">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navShop">

      <!-- Right Menu -->
      <div class="ms-auto d-flex gap-2 align-items-center">

        <!-- Cart -->
        <button type="button"
                class="btn btn-cart d-flex align-items-center gap-2 position-relative px-3"
                onclick="window.location.href='<?= isset($_SESSION['user']) ? 'cart.php' : 'login.php?next=cart.php' ?>'">

          <i class="bi bi-cart3 fs-5"></i>

          <span class="d-none d-sm-inline fw-semibold">
            ตะกร้าสินค้า
          </span>

          <span class="cart-badge position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
            0
          </span>
        </button>

        <!-- User Dropdown -->
        <div class="dropdown">
          <button class="btn btn-ghost"
                  type="button"
                  data-bs-toggle="dropdown">
            <i class="bi bi-person-circle fs-5"></i>
          </button>

          <ul class="dropdown-menu dropdown-menu-end shadow-sm rounded-4">

            <?php if (isset($_SESSION["user"])): ?>

              <li class="px-3 py-2">
                <div class="small text-muted">เข้าสู่ระบบเป็น</div>
                <div class="fw-semibold">
                  <?= htmlspecialchars($_SESSION["user"]["full_name"] ?: $_SESSION["user"]["username"]) ?>
                </div>
              </li>

              <li><hr class="dropdown-divider"></li>

              <li>
                <a class="dropdown-item" href="profile.php">
                  <i class="bi bi-person me-2"></i> ข้อมูลส่วนตัว
                </a>
              </li>

              <li>
                <a class="dropdown-item" href="orders.php">
                  <i class="bi bi-truck me-2"></i> คำสั่งซื้อของฉัน
                </a>
              </li>

              <li>
                <a class="dropdown-item" href="orders_history.php">
                  <i class="bi bi-receipt me-2"></i> ประวัติการซื้อ
                </a>
              </li>

              <li><hr class="dropdown-divider"></li>

              <li>
                <a class="dropdown-item text-danger" href="logout.php">
                  <i class="bi bi-box-arrow-right me-2"></i> ออกจากระบบ
                </a>
              </li>

            <?php else: ?>

              <li>
                <a class="dropdown-item" href="login.php?next=<?= urlencode('cart.php') ?>">
                  <i class="bi bi-box-arrow-in-right me-2"></i> เข้าสู่ระบบ
                </a>
              </li>

              <li>
                <a class="dropdown-item" href="register.php?next=<?= urlencode('cart.php') ?>">
                  <i class="bi bi-person-plus me-2"></i> สมัครสมาชิก
                </a>
              </li>

            <?php endif; ?>

          </ul>
        </div>

      </div>
    </div>
  </div>
</nav>
