<?php
declare(strict_types=1);
require __DIR__ . "/auth.php"; // ถ้ามีไฟล์ auth.php ในโปรเจกต์
// ไม่บังคับต้องล็อกอินก็ได้ แต่ถ้าคุณอยากให้ดูออเดอร์ได้ แนะนำให้ล็อกอิน
// require_login("order_success.php?order_no=" . urlencode((string)($_GET["order_no"] ?? "")));

$orderNo = trim((string)($_GET["order_no"] ?? ""));
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>สั่งซื้อสำเร็จ | Bakery</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

  <style>
    body.page-success{
      font-family: "Kanit", sans-serif;
      background:
        radial-gradient(1200px 600px at 20% 0%, rgba(255, 213, 153, .35), transparent 60%),
        radial-gradient(900px 500px at 100% 20%, rgba(255, 160, 160, .25), transparent 55%),
        #f7f7fb;
      min-height: 100vh;
    }
    .success-wrap{ max-width: 720px; margin: 0 auto; }
    .success-card{
      background: rgba(255,255,255,.8);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(0,0,0,.06);
      border-radius: 22px;
      box-shadow: 0 12px 35px rgba(0,0,0,.08);
      overflow: hidden;
    }
    .success-hero{
      padding: 22px 22px 10px;
      display:flex; gap:14px; align-items:flex-start;
    }
    .success-badge{
      width: 56px; height: 56px;
      border-radius: 18px;
      background: #fff;
      border: 1px solid rgba(0,0,0,.07);
      box-shadow: 0 8px 18px rgba(0,0,0,.08);
      display:flex; align-items:center; justify-content:center;
      font-size: 28px;
      flex: 0 0 auto;
    }
    .success-body{ padding: 0 22px 22px; }
    .pill{
      display:inline-flex; align-items:center; gap:.45rem;
      padding: .4rem .75rem;
      border-radius: 999px;
      background: rgba(0,0,0,.04);
      border: 1px solid rgba(0,0,0,.06);
      color: rgba(0,0,0,.7);
      font-size: .9rem;
    }
    .mini-steps{
      display:grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 10px;
      margin-top: 14px;
    }
    .mini-step{
      border: 1px solid rgba(0,0,0,.06);
      background: rgba(0,0,0,.02);
      border-radius: 16px;
      padding: 12px;
      min-height: 78px;
    }
    .mini-step .t{ font-weight: 700; }
    .mini-step .d{ font-size: .85rem; color: rgba(0,0,0,.55); margin-top: 3px; }
    .mini-step.done{
      background: rgba(25,135,84,.08);
      border-color: rgba(25,135,84,.18);
    }
    .btn{ border-radius: 999px !important; padding: .85rem 1rem; font-weight: 600; }
  </style>
</head>

<body class="page-success">

<?php require __DIR__ . "/partials/nav.php"; ?>

<main class="container py-4 py-md-5">
  <div class="success-wrap">

    <div class="success-card">
      <div class="success-hero">
        <div class="success-badge">✅</div>

        <div class="flex-grow-1">
          <h1 class="h4 fw-semibold mb-1">สั่งซื้อสำเร็จ</h1>
          <div class="text-muted">ขอบคุณที่สั่งซื้อกับร้านเรา ระบบได้รับคำสั่งซื้อเรียบร้อยแล้ว</div>

          <?php if ($orderNo !== ""): ?>
            <div class="mt-3">
              <span class="pill">
                <i class="bi bi-receipt"></i>
                เลขออเดอร์: <b><?= htmlspecialchars($orderNo) ?></b>
              </span>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="success-body">

        <div class="mini-steps">
          <div class="mini-step done">
            <div class="t"><i class="bi bi-bag-check me-1"></i>รับออเดอร์แล้ว</div>
            <div class="d">คำสั่งซื้อถูกบันทึกในระบบ</div>
          </div>
          <div class="mini-step">
            <div class="t"><i class="bi bi-shield-check me-1"></i>ตรวจสอบการชำระเงิน</div>
            <div class="d">รอตรวจสลิป / ยืนยันยอดชำระ</div>
          </div>
          <div class="mini-step">
            <div class="t"><i class="bi bi-truck me-1"></i>จัดส่งสินค้า</div>
            <div class="d">เมื่อยืนยันแล้ว จะเริ่มจัดส่ง</div>
          </div>
        </div>

        <div class="alert alert-light border rounded-4 mt-4 mb-0">
          <div class="fw-semibold mb-1">ขั้นตอนถัดไป</div>
          <div class="text-muted small">
            คุณสามารถติดตามสถานะได้ที่หน้า <b>คำสั่งซื้อของฉัน</b> หากแนบสลิปแล้ว ระบบจะรอตรวจสอบก่อนยืนยันคำสั่งซื้อ
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-4">
          <a class="btn btn-dark px-4" href="orders.php">
            <i class="bi bi-clock-history me-1"></i> คำสั่งซื้อของฉัน
          </a>
          <a class="btn btn-outline-dark px-4" href="index.php">
            <i class="bi bi-shop me-1"></i> กลับหน้าร้าน
          </a>
          <a class="btn btn-outline-secondary px-4" href="cart.php">
            <i class="bi bi-cart3 me-1"></i> ดูตะกร้า
          </a>
        </div>

      </div>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
