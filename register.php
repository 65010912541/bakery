<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/config/db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  $full_name = trim((string)($_POST["full_name"] ?? ""));
  $email     = trim((string)($_POST["email"] ?? ""));
  $phone     = trim((string)($_POST["phone"] ?? ""));
  $username  = trim((string)($_POST["username"] ?? ""));
  $password  = (string)($_POST["password"] ?? "");
  $confirm   = (string)($_POST["confirm_password"] ?? "");

  // validate
  if ($full_name === "" || $email === "" || $phone === "" || $username === "" || $password === "" || $confirm === "") {
    $error = "กรุณากรอกข้อมูลให้ครบ";
  } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $error = "รูปแบบอีเมลไม่ถูกต้อง";
  } else {
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? "";
    if (!preg_match('/^[0-9]{9,10}$/', $phoneDigits)) {
      $error = "กรุณากรอกเบอร์โทรให้ถูกต้อง (ตัวเลข 9-10 หลัก)";
    } elseif (mb_strlen($password) < 6) {
      $error = "รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร";
    } elseif (!hash_equals($password, $confirm)) {
      $error = "รหัสผ่านและยืนยันรหัสผ่านไม่ตรงกัน";
    } else {

      $hash = password_hash($password, PASSWORD_DEFAULT);

      try {
        $stmt = $pdo->prepare("
          INSERT INTO users (username, email, password_hash, full_name, phone, login_type)
          VALUES (:u, :e, :p, :f, :ph, 'local')
        ");
        $stmt->execute([
          ":u"  => $username,
          ":e"  => $email,
          ":p"  => $hash,
          ":f"  => $full_name,
          ":ph" => $phoneDigits
        ]);

        header("Location: login.php?register=success");
        exit;

      } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) == 1062) {
          $error = "Username หรือ Email นี้ถูกใช้งานแล้ว";
        } else {
          $error = "เกิดข้อผิดพลาด กรุณาลองใหม่";
        }
      }
    }
  }
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>สมัครสมาชิก | HokKao(69) Bakery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/auth.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body class="page-auth page-register">

<div class="auth-center">
  <div class="auth-card p-4 p-md-5">

    <div class="text-center mb-4">
      <div class="auth-badge mx-auto mb-2">✨</div>
      <div class="fw-semibold brand-title">HokKao(69) Bakery</div>
      <h1 class="h4 fw-semibold mb-1">สมัครสมาชิก</h1>
      <div class="text-muted small">สร้างบัญชีเพื่อสั่งเบเกอรี่</div>
    </div>

    <?php if ($error !== ""): ?>
      <div class="alert alert-danger rounded-4 mb-3">
        <?= h($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>

      <!-- 2 columns on md+ -->
      <div class="row g-3">

        <!-- ซ้าย (3 ช่อง) -->
        <div class="col-12 col-md-6">
          <label class="form-label">ชื่อ-นามสกุล</label>
          <input class="form-control form-control-lg auth-input"
                 name="full_name"
                 placeholder="กรอกชื่อ-นามสกุล"
                 value="<?= h((string)($_POST["full_name"] ?? "")) ?>"
                 required>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control form-control-lg auth-input"
                 type="email"
                 name="email"
                 placeholder="example@email.com"
                 value="<?= h((string)($_POST["email"] ?? "")) ?>"
                 required>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">เบอร์โทรศัพท์</label>
          <input class="form-control form-control-lg auth-input"
                 type="tel"
                 name="phone"
                 inputmode="numeric"
                 placeholder="เช่น 0812345678"
                 value="<?= h((string)($_POST["phone"] ?? "")) ?>"
                 required>
          <div class="form-text text-muted">กรอกเป็นตัวเลข 9-10 หลัก</div>
        </div>

        <!-- ขวา (3 ช่อง) -->
        <div class="col-12 col-md-6">
          <label class="form-label">Username</label>
          <input class="form-control form-control-lg auth-input"
                 name="username"
                 placeholder="ตั้งชื่อผู้ใช้"
                 value="<?= h((string)($_POST["username"] ?? "")) ?>"
                 required>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">Password</label>
          <input class="form-control form-control-lg auth-input"
                 type="password"
                 name="password"
                 placeholder="ตั้งรหัสผ่าน (อย่างน้อย 6 ตัว)"
                 required>
        </div>

        <div class="col-12 col-md-6">
          <label class="form-label">ยืนยันรหัสผ่าน</label>
          <input class="form-control form-control-lg auth-input"
                 type="password"
                 name="confirm_password"
                 placeholder="กรอกรหัสผ่านอีกครั้ง"
                 required>
        </div>

      </div>

      <button class="btn btn-brand w-100 rounded-pill btn-lg mt-4">
        สมัครสมาชิก
      </button>
    </form>

    <div class="text-center small text-muted mt-4">
      มีบัญชีแล้ว?
      <a class="fw-semibold link-dark" href="login.php">เข้าสู่ระบบ</a>
    </div>

  </div>
</div>

</body>
</html>