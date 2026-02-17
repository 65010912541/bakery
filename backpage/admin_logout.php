<?php
declare(strict_types=1);
session_start();

unset($_SESSION["admin"]);
session_regenerate_id(true);

header("Location: admin_login.php");
exit;
