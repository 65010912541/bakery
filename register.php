<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/config/db.php";

$error = "";
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $full_name = trim($_POST["full_name"] ?? "");

    if ($username === "" || $email === "" || $password === "" || $full_name === "") {
        $error = "กรุณากรอกข้อมูลให้ครบ";
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (username,email,password_hash,full_name,login_type)
                VALUES (:u,:e,:p,:f,'local')
            ");
            $stmt->execute([
                ":u"=>$username,
                ":e"=>$email,
                ":p"=>$hash,
                ":f"=>$full_name
            ]);

            header("Location: login.php?register=success");
            exit;

        } catch (PDOException $e) {
            $error = "Username หรือ Email ซ้ำ";
        }

        }
        }
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>สมัครสมาชิก | Bakery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/auth.css">

<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body class="page-auth">

<div class="auth-center">

  <div class="auth-card p-4 p-md-5">

    <div class="text-center mb-4">
      <div class="auth-badge mx-auto mb-2">✨</div>
      <h1 class="h4 fw-semibold mb-1">สมัครสมาชิก</h1>
      <div class="text-muted small">สร้างบัญชีเพื่อสั่งซื้อสินค้า</div>
    </div>

    <?php if($error): ?>
      <div class="alert alert-danger rounded-4">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if($success): ?>
      <div class="alert alert-success rounded-4">
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <form method="post">
      
      <div class="mb-3">
        <label class="form-label">ชื่อ-นามสกุล</label>
        <input class="form-control form-control-lg auth-input"
                name="full_name"
                required>
      </div>

      <div class="mb-3">
        <label class="form-label">Email</label>
        <input class="form-control form-control-lg auth-input"
               type="email"
               name="email"
               required>
      </div>

      <div class="mb-3">
        <label class="form-label">Username</label>
        <input class="form-control form-control-lg auth-input"
               name="username"
               required>
      </div>

      <div class="mb-4">
        <label class="form-label">Password</label>
        <input class="form-control form-control-lg auth-input"
               type="password"
               name="password"
               required>
      </div>

      <button class="btn btn-brand w-100 rounded-pill btn-lg">
        สมัครสมาชิก
      </button>
    </form>

    <div class="text-center small text-muted mt-4">
      มีบัญชีแล้ว?
      <a class="fw-semibold link-dark" href="login.php">
        เข้าสู่ระบบ
      </a>
    </div>

  </div>

</div>

</body>
</html>
