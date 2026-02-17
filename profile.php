<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/config/db.php";

if (empty($_SESSION["user"]["id"])) {
  header("Location: login.php?next=profile.php");
  exit;
}

$userId = (int)$_SESSION["user"]["id"];

$error = "";
$success = "";

$stmt = $pdo->prepare("
  SELECT id, username, email, full_name, phone, login_type
  FROM users
  WHERE id = :id
  LIMIT 1
");
$stmt->execute([":id" => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
  session_destroy();
  header("Location: login.php");
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $action = (string)($_POST["action"] ?? "");

  if ($action === "update_profile") {
    $fullName = trim((string)($_POST["full_name"] ?? ""));
    $email    = trim((string)($_POST["email"] ?? ""));
    $phone    = trim((string)($_POST["phone"] ?? ""));

    if ($fullName === "" || $email === "") {
      $error = "กรุณากรอกชื่อ-นามสกุล และอีเมล";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $error = "รูปแบบอีเมลไม่ถูกต้อง";
    } else {
      try {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1");
        $chk->execute([":e" => $email, ":id" => $userId]);

        if ($chk->fetch()) {
          $error = "อีเมลนี้ถูกใช้งานแล้ว";
        } else {
          $upd = $pdo->prepare("
            UPDATE users
            SET full_name = :f, email = :e, phone = :p
            WHERE id = :id
          ");
          $upd->execute([
            ":f" => $fullName,
            ":e" => $email,
            ":p" => $phone,
            ":id" => $userId
          ]);

          $success = "บันทึกโปรไฟล์เรียบร้อย";
          $_SESSION["user"]["full_name"] = $fullName;
          $_SESSION["user"]["phone"] = $phone;

          $user["full_name"] = $fullName;
          $user["email"] = $email;
          $user["phone"] = $phone;
        }
      } catch (PDOException $e) {
        $error = "บันทึกไม่สำเร็จ กรุณาลองใหม่";
      }
    }
  }

  if ($action === "change_password") {
    if (($user["login_type"] ?? "local") !== "local") {
      $error = "บัญชีนี้เข้าสู่ระบบด้วย Google ไม่สามารถเปลี่ยนรหัสผ่านในระบบได้";
    } else {
      $current = (string)($_POST["current_password"] ?? "");
      $new1    = (string)($_POST["new_password"] ?? "");
      $new2    = (string)($_POST["new_password2"] ?? "");

      if ($current === "" || $new1 === "" || $new2 === "") {
        $error = "กรุณากรอกข้อมูลเปลี่ยนรหัสผ่านให้ครบ";
      } elseif ($new1 !== $new2) {
        $error = "รหัสผ่านใหม่ไม่ตรงกัน";
      } elseif (mb_strlen($new1) < 6) {
        $error = "รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร";
      } else {
        $ps = $pdo->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $ps->execute([":id" => $userId]);
        $row = $ps->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, (string)$row["password_hash"])) {
          $error = "รหัสผ่านปัจจุบันไม่ถูกต้อง";
        } else {
          try {
            $newHash = password_hash($new1, PASSWORD_DEFAULT);
            $up = $pdo->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
            $up->execute([":h" => $newHash, ":id" => $userId]);
            $success = "เปลี่ยนรหัสผ่านเรียบร้อย";
          } catch (PDOException $e) {
            $error = "เปลี่ยนรหัสผ่านไม่สำเร็จ กรุณาลองใหม่";
          }
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>โปรไฟล์ | Bakery</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

</head>

<!-- ✅ ให้เหมือนหน้า index: ถ้า index ใช้ class อะไร ให้ใช้ class เดียวกัน -->
<body class="page-home">

<?php require __DIR__ . "/partials/nav.php"; ?>

<!-- ✅ ใช้ container แบบหน้า index -->
<main class="container py-4 py-md-5">

  <!-- Header / Hero -->
  <div class="profile-hero mb-4">
    <div class="d-flex align-items-center gap-3">
      <div class="profile-badge">👤</div>

      <div class="flex-grow-1">
        <div class="d-flex flex-wrap align-items-center gap-2">
          <h1 class="h5 mb-0 fw-semibold">โปรไฟล์ของฉัน</h1>
          <span class="muted-pill">@<?= htmlspecialchars((string)$user["username"]) ?></span>
          <?php if (($user["login_type"] ?? "local") !== "local"): ?>
            <span class="muted-pill">Google</span>
          <?php endif; ?>
        </div>
        <div class="text-muted small mt-1">
          จัดการข้อมูลส่วนตัวและความปลอดภัยของบัญชี
        </div>
      </div>

      <a class="btn btn-outline-dark d-none d-md-inline-flex" href="index.php">กลับหน้าแรก</a>
    </div>

    <?php if ($error !== ""): ?>
      <div class="alert alert-danger rounded-4 mt-3 mb-0">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
      <div class="alert alert-success rounded-4 mt-3 mb-0">
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="row g-4">
    <!-- โปรไฟล์ -->
    <div class="col-12 col-lg-7">
      <div class="card card-soft">
        <div class="card-header py-3 px-4">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-semibold">ข้อมูลส่วนตัว</div>
              <div class="text-muted small">แก้ไขชื่อ อีเมล และเบอร์โทร</div>
            </div>
            <div class="muted-pill">🧾 Profile</div>
          </div>
        </div>

        <div class="card-body p-4">
          <form method="post" class="mb-0">
            <input type="hidden" name="action" value="update_profile">

            <div class="mb-3">
              <label class="form-label">Username (แก้ไม่ได้)</label>
              <input class="form-control" value="<?= htmlspecialchars((string)$user["username"]) ?>" disabled>
            </div>

            <div class="mb-3">
              <label class="form-label">ชื่อ-นามสกุล</label>
              <input class="form-control"
                     name="full_name"
                     value="<?= htmlspecialchars((string)($user["full_name"] ?? "")) ?>"
                     required>
            </div>

            <div class="mb-3">
              <label class="form-label">Email</label>
              <input class="form-control"
                     type="email"
                     name="email"
                     value="<?= htmlspecialchars((string)($user["email"] ?? "")) ?>"
                     required>
            </div>

            <div class="mb-4">
              <label class="form-label">เบอร์โทร</label>
              <input class="form-control"
                     name="phone"
                     value="<?= htmlspecialchars((string)($user["phone"] ?? "")) ?>"
                     placeholder="เช่น 0999999999">
            </div>

            <button class="btn btn-brand w-100">
              บันทึกโปรไฟล์
            </button>
          </form>
        </div>
      </div>
    </div>

    <!-- เปลี่ยนรหัสผ่าน -->
    <div class="col-12 col-lg-5">
      <div class="card card-soft">
        <div class="card-header py-3 px-4">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-semibold">ความปลอดภัย</div>
              <div class="text-muted small">เปลี่ยนรหัสผ่านของบัญชี</div>
            </div>
            <div class="muted-pill">🔒 Security</div>
          </div>
        </div>

        <div class="card-body p-4">
          <?php if (($user["login_type"] ?? "local") !== "local"): ?>
            <div class="alert alert-warning rounded-4 mb-0">
              บัญชีนี้เข้าสู่ระบบด้วย Google <br>
              การเปลี่ยนรหัสผ่านให้ทำผ่าน Google Account
            </div>
          <?php else: ?>
            <form method="post" class="mb-0">
              <input type="hidden" name="action" value="change_password">

              <div class="mb-3">
                <label class="form-label">รหัสผ่านปัจจุบัน</label>
                <input class="form-control"
                       type="password"
                       name="current_password"
                       autocomplete="current-password"
                       required>
              </div>

              <div class="mb-3">
                <label class="form-label">รหัสผ่านใหม่</label>
                <input class="form-control"
                       type="password"
                       name="new_password"
                       autocomplete="new-password"
                       required>
                <div class="text-muted small mt-2">อย่างน้อย 6 ตัวอักษร</div>
              </div>

              <div class="mb-4">
                <label class="form-label">ยืนยันรหัสผ่านใหม่</label>
                <input class="form-control"
                       type="password"
                       name="new_password2"
                       autocomplete="new-password"
                       required>
              </div>

              <button class="btn btn-outline-dark w-100">
                เปลี่ยนรหัสผ่าน
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <a class="btn btn-outline-dark w-100 mt-4 d-md-none" href="index.php">กลับหน้าแรก</a>
    </div>
  </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
