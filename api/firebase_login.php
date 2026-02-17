<?php
declare(strict_types=1);

session_start();
header("Content-Type: application/json; charset=utf-8");

require __DIR__ . "/../config/db.php";
require __DIR__ . "/../vendor/autoload.php";

use Kreait\Firebase\Factory;

$cfg = require __DIR__ . "/../config/firebase.php";

$input = json_decode(file_get_contents("php://input"), true);
$idToken = (string)($input["idToken"] ?? "");
$next = (string)($input["next"] ?? "cart.php");

// กัน open redirect
if (!preg_match('/^[a-zA-Z0-9_\-\/]+\.php$/', $next)) $next = "cart.php";

if ($idToken === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing idToken"]);
  exit;
}

try {
  $auth = (new Factory())
    ->withServiceAccount($cfg["service_account"])
    ->createAuth();

  $verified = $auth->verifyIdToken($idToken);

  $uid = (string)$verified->claims()->get("sub");
  $email = (string)($verified->claims()->get("email") ?? "");
  $name  = (string)($verified->claims()->get("name") ?? "");

  if ($email === "") {
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "No email in token"]);
    exit;
  }

  // หา user เดิมด้วย email หรือ google_id
  $stmt = $pdo->prepare("SELECT id, username, full_name, phone, email, google_id, login_type
                         FROM users
                         WHERE email = :e OR google_id = :gid
                         LIMIT 1");
  $stmt->execute([":e" => $email, ":gid" => $uid]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    // สร้าง user ใหม่แบบ google
    $ins = $pdo->prepare("
      INSERT INTO users (username, email, google_id, login_type, full_name, password_hash)
      VALUES (:u, :e, :gid, 'google', :n, '')
    ");
    $ins->execute([
      ":u" => $email,
      ":e" => $email,
      ":gid" => $uid,
      ":n" => ($name !== "" ? $name : $email),
    ]);

    $userId = (int)$pdo->lastInsertId();
    $user = [
      "id" => $userId,
      "username" => $email,
      "full_name" => ($name !== "" ? $name : $email),
      "phone" => "",
      "email" => $email,
      "google_id" => $uid,
      "login_type" => "google",
    ];
  }

  session_regenerate_id(true);
  $_SESSION["user"] = [
    "id" => (int)$user["id"],
    "username" => (string)$user["username"],
    "full_name" => (string)($user["full_name"] ?? ""),
    "phone" => (string)($user["phone"] ?? ""),
    "email" => $email,
    "login_type" => "google",
  ];

  echo json_encode(["ok" => true, "next" => $next]);
  exit;

} catch (Throwable $e) {
  http_response_code(401);
  echo json_encode(["ok" => false, "error" => "Invalid token"]);
  exit;
}
