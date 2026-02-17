<?php
declare(strict_types=1);
session_start();

function require_login(string $next = "cart.php"): void {
  if (!isset($_SESSION["user"])) {
    header("Location: login.php?next=" . urlencode($next));
    exit;
  }
}

function current_user(): ?array {
  return $_SESSION["user"] ?? null;
}
