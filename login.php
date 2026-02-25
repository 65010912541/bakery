<?php
declare(strict_types=1);
session_start();
require __DIR__ . "/config/db.php";

/**
 * กำหนดหน้าที่อนุญาตให้ redirect ไปได้ (Whitelist)
 * ถ้าต้องการให้กลับไป cart ได้ ค่อยเพิ่ม "cart.php" เข้าไป
 */
$allowedNext = ["index.php"];

$next = trim((string)($_GET["next"] ?? "index.php"));
if (!in_array($next, $allowedNext, true)) {
  $next = "index.php";
}

$error = "";
$success = "";

// แจ้งเตือนสมัครสำเร็จ (แสดงครั้งเดียว แล้วลบ query ทิ้ง)
if (isset($_GET["register"]) && $_GET["register"] === "success") {
  $success = "สมัครสมาชิกสำเร็จ กรุณาเข้าสู่ระบบ";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

  // ถ้าเป็นการกด login แล้ว ไม่ต้องโชว์ success จาก register
  $success = "";

  $username = trim((string)($_POST["username"] ?? ""));
  $password = (string)($_POST["password"] ?? "");
  $nextPost = trim((string)($_POST["next"] ?? "index.php"));

  // บังคับให้ไป index เสมอ
  $nextPost = "index.php";

  if ($username === "" || $password === "") {
    $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";
  } else {

    $stmt = $pdo->prepare("
      SELECT id, username, password_hash, full_name, phone
      FROM users
      WHERE username = :u
      LIMIT 1
    ");
    $stmt->execute([":u" => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, (string)$user["password_hash"])) {
      $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
    } else {

      session_regenerate_id(true);

      $_SESSION["user"] = [
        "id" => (int)$user["id"],
        "username" => (string)$user["username"],
        "full_name" => (string)($user["full_name"] ?? ""),
        "phone" => (string)($user["phone"] ?? "")
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
<title>เข้าสู่ระบบ | Bakery</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
<link rel="stylesheet" href="assets/css/auth.css">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
</head>

<body class="page-auth">

<div class="auth-center">
  <div class="auth-card p-4 p-md-5">

    <div class="text-center mb-4">
      <div class="auth-badge mx-auto mb-2">🔐</div>
      <div class="fw-semibold" style="color:#d9776c;">HokKao(69) Bakery</div>
      <h1 class="h4 fw-semibold mb-1">ยินดีต้อนรับ</h1>
      <div class="text-muted small">เข้าสู่ระบบเพื่อสั่งเบเกอรี่</div>
    </div>

    <?php if ($error !== ""): ?>
      <div class="alert alert-danger rounded-4 mb-3">
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
      <div class="alert alert-success rounded-4 mb-3" id="successBox">
        <?= htmlspecialchars($success) ?>
      </div>

      <script>
        // ลบ register=success ออกจาก URL เพื่อไม่ให้เด้งกลับมาแล้วยังขึ้นซ้ำ
        if (history.replaceState) {
          const url = new URL(window.location.href);
          url.searchParams.delete("register");
          history.replaceState({}, "", url);
        }
      </script>
    <?php endif; ?>

    <form method="post" action="login.php">
      <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

      <div class="mb-3">
        <label class="form-label">Username</label>
        <input class="form-control form-control-lg auth-input"
              name="username"
              autocomplete="username"
              placeholder="กรอกชื่อผู้ใช้"
              required>
      </div>

      <div class="mb-4">
        <label class="form-label">Password</label>
        <input class="form-control form-control-lg auth-input"
              type="password"
              name="password"
              autocomplete="current-password"
              placeholder="กรอกรหัสผ่าน"
              required>
      </div>

      <button class="btn btn-brand w-100 rounded-pill btn-lg">
        เข้าสู่ระบบ
      </button>
    </form>

    <div class="auth-divider my-4"><span>หรือ</span></div>

    <!-- Google Login -->
    <button type="button" class="btn btn-google w-100 rounded-pill btn-lg" id="btnGoogle">
      <img src="https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/google.svg"
           width="20" class="me-2" alt="Google">
      เข้าสู่ระบบด้วย Google
    </button>

    <div class="text-center small text-muted mt-4">
      ยังไม่มีบัญชี?
      <a class="fw-semibold link-dark" href="register.php">
        สมัครสมาชิก
      </a>
    </div>

  </div>
</div>

<script type="module">
import { auth } from "./assets/js/firebase-init.js";
import { GoogleAuthProvider, signInWithPopup }
  from "https://www.gstatic.com/firebasejs/10.12.5/firebase-auth.js";

document.getElementById("btnGoogle")?.addEventListener("click", async () => {
  try {
    const provider = new GoogleAuthProvider();
    const result = await signInWithPopup(auth, provider);

    const idToken = await result.user.getIdToken();

    const res = await fetch("/project/api/firebase_login.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ idToken, next: "index.php" })
    });

    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { data = null; }

    if (!res.ok || !data || !data.ok) {
      console.error("API error:", res.status, text);
      alert("Login ไม่สำเร็จ");
      return;
    }

    window.location.href = "index.php";

  } catch (e) {
    console.error("Firebase popup error:", e);
    alert(`Google Login ล้มเหลว\n${e.code || ""}\n${e.message || e}`);
  }
});
</script>

</body>
</html>
