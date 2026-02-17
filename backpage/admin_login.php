<?php
declare(strict_types=1);
session_start();

require __DIR__ . "/../config/db.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

// หน้า admin ที่จะไปหลัง login สำเร็จ
$DEFAULT_NEXT = "backpage_products.php";

// whitelist (อยู่โฟลเดอร์เดียวกับไฟล์ admin อื่น)
$allowedNext = [
  "backpage_products.php",
  "backpage_categories.php",
  "backpage_orders.php",
  "backpage_customers.php",
  "backpage_admins.php",
];

$next = trim((string)($_GET["next"] ?? $DEFAULT_NEXT));
if (!in_array($next, $allowedNext, true)) $next = $DEFAULT_NEXT;

$error = "";

// ถ้า login แล้วให้เด้งไปเลย
if (!empty($_SESSION["admin"]["id"])) {
  header("Location: " . $next);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim((string)($_POST["username"] ?? ""));
  $password = (string)($_POST["password"] ?? "");
  $nextPost = trim((string)($_POST["next"] ?? $DEFAULT_NEXT));
  if (!in_array($nextPost, $allowedNext, true)) $nextPost = $DEFAULT_NEXT;

  if ($username === "" || $password === "") {
    $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";
  } else {
    $stmt = $pdo->prepare("
      SELECT id, username, password_hash, full_name, role, status
      FROM admins
      WHERE username = :u
      LIMIT 1
    ");
    $stmt->execute([":u" => $username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
      !$admin ||
      (string)$admin["status"] !== "active" ||
      !password_verify($password, (string)$admin["password_hash"])
    ) {
      $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    } else {
      session_regenerate_id(true);

      $_SESSION["admin"] = [
        "id" => (int)$admin["id"],
        "username" => (string)$admin["username"],
        "full_name" => (string)($admin["full_name"] ?? ""),
        "role" => (string)($admin["role"] ?? "admin"),
        "status" => (string)($admin["status"] ?? "active"),
      ];

      header("Location: " . $nextPost);
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>เข้าสู่ระบบแอดมิน | Bakery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/style.css">
<link rel="stylesheet" href="../assets/css/auth.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body class="page-auth">

<div class="auth-center">
  <div class="auth-card p-4 p-md-5">

    <div class="text-center mb-4">
      <div class="auth-badge mx-auto mb-2">🛡️</div>
      <h1 class="h4 fw-semibold mb-1">Login</h1>
      <div class="text-muted small">เข้าสู่ระบบสำหรับผู้ดูแลเท่านั้น</div>
    </div>

    <?php if ($error !== ""): ?>
      <div class="alert alert-danger rounded-4 mb-3">
        <?= h($error) ?>
      </div>
    <?php endif; ?>

    <form method="post" action="admin_login.php">
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <div class="mb-3">
        <label class="form-label">Username</label>
        <input class="form-control form-control-lg auth-input"
               name="username"
               autocomplete="username"
               required>
      </div>

      <div class="mb-4">
        <label class="form-label">Password</label>
        <input class="form-control form-control-lg auth-input"
               type="password"
               name="password"
               autocomplete="current-password"
               required>
      </div>

      <button class="btn btn-brand w-100 rounded-pill btn-lg">
        เข้าสู่ระบบ
      </button>
    </form>

    <div class="text-center small text-muted">
      <a class="fw-semibold link-dark" href="../index.php">กลับไปหน้าร้าน</a>
    </div>

  </div>
</div>

</body>
</html>
