<?php
declare(strict_types=1);

$DB_HOST = "localhost";
$DB_NAME = "bakery";
$DB_USER = "root";
$DB_PASS = "";

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4";

try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (PDOException $e) {
  http_response_code(500);
  exit("เชื่อมต่อฐานข้อมูลไม่สำเร็จ: " . htmlspecialchars($e->getMessage()));
}
