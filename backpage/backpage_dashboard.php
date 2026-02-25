<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION["admin"]["id"])) {
  header("Location: admin_login.php?next=" . urlencode("admin_dashboard.php"));
  exit;
}
require __DIR__ . "/../config/db.php";

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, "UTF-8"); }

// ====== Filters (date range) ======
$tz = new DateTimeZone("Asia/Bangkok");
$today = (new DateTime("now", $tz))->format("Y-m-d");

$to   = (string)($_GET["to"] ?? $today);
$from = (string)($_GET["from"] ?? (new DateTime("-29 days", $tz))->format("Y-m-d"));
$toDt   = DateTime::createFromFormat("Y-m-d", $to, $tz) ?: new DateTime("now", $tz);
$fromDt = DateTime::createFromFormat("Y-m-d", $from, $tz) ?: new DateTime("-29 days", $tz);

// normalize
if ($fromDt > $toDt) { $tmp = $fromDt; $fromDt = $toDt; $toDt = $tmp; }
$from = $fromDt->format("Y-m-d");
$to   = $toDt->format("Y-m-d");

// inclusive end (00:00 next day)
$fromTs = $from . " 00:00:00";
$toNext = (clone $toDt)->modify("+1 day")->format("Y-m-d") . " 00:00:00";

// ====== Helpers to safely detect datetime column ======
function detectOrderDateColumn(PDO $pdo): string {
  // try common columns in orders: created_at, ordered_at, createdAt etc.
  $candidates = ["created_at","order_date","ordered_at","createdAt","created"];
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r)=>strtolower((string)$r["Field"]), $cols);
    foreach ($candidates as $c) {
      if (in_array(strtolower($c), $names, true)) return $c;
    }
  } catch (Throwable $e) {}
  // fallback: no date filter
  return "";
}
$orderDateCol = detectOrderDateColumn($pdo);

// ====== WHERE clause for date filter (if we have a datetime column) ======
$whereDate = "";
$paramsDate = [];
if ($orderDateCol !== "") {
  $whereDate = " AND o.`{$orderDateCol}` >= :fromTs AND o.`{$orderDateCol}` < :toNext ";
  $paramsDate = [":fromTs"=>$fromTs, ":toNext"=>$toNext];
}

// ====== KPI Cards ======
$kpi = [
  "orders" => 0,
  "revenue" => 0.0,
  "items" => 0,
  "avg" => 0.0
];

try {
  // Only orders with slip verified (ตามเงื่อนไขที่คุณใช้ในหน้า orders)
  $sqlKpi = "
    SELECT
      COUNT(*) AS orders,
      COALESCE(SUM(o.total_amount),0) AS revenue,
      COALESCE(SUM(o.total_qty),0) AS items,
      COALESCE(AVG(o.total_amount),0) AS avg_order
    FROM orders o
    WHERE o.payment_status='verified'
    {$whereDate}
  ";
  $st = $pdo->prepare($sqlKpi);
  $st->execute($paramsDate);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  $kpi["orders"] = (int)($row["orders"] ?? 0);
  $kpi["revenue"] = (float)($row["revenue"] ?? 0);
  $kpi["items"] = (int)($row["items"] ?? 0);
  $kpi["avg"] = (float)($row["avg_order"] ?? 0);
} catch (Throwable $e) {}

// ====== Status distribution ======
$statusLabels = ["pending","confirmed","shipped","completed","cancelled"];
$statusCounts = array_fill_keys($statusLabels, 0);

try {
  $sqlStatus = "
    SELECT o.status, COUNT(*) c
    FROM orders o
    WHERE o.payment_status='verified'
    {$whereDate}
    GROUP BY o.status
  ";
  $st = $pdo->prepare($sqlStatus);
  $st->execute($paramsDate);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $s = strtolower(trim((string)($r["status"] ?? "")));
    if ($s === "") $s = "pending";
    if (!isset($statusCounts[$s])) $statusCounts[$s] = 0;
    $statusCounts[$s] += (int)($r["c"] ?? 0);
  }
} catch (Throwable $e) {}

// ====== Top products (by qty) ======
$topNames = [];
$topQty = [];
$topMoney = [];

try {
  // NOTE: order_items has: product_name, qty, line_total (from your user page)
  $sqlTop = "
    SELECT
      oi.product_name,
      COALESCE(SUM(oi.qty),0) AS q,
      COALESCE(SUM(oi.line_total),0) AS amt
    FROM order_items oi
    INNER JOIN orders o ON o.id = oi.order_id
    WHERE o.payment_status='verified'
    {$whereDate}
    GROUP BY oi.product_name
    ORDER BY q DESC
    LIMIT 10
  ";
  $st = $pdo->prepare($sqlTop);
  $st->execute($paramsDate);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $topNames[] = (string)($r["product_name"] ?? "");
    $topQty[]   = (int)($r["q"] ?? 0);
    $topMoney[] = (float)($r["amt"] ?? 0);
  }
} catch (Throwable $e) {}

// ====== Orders & Revenue over time (daily) ======
$days = [];
$ordersDaily = [];
$revenueDaily = [];

if ($orderDateCol !== "") {
  // build day list
  $cursor = clone $fromDt;
  $end = clone $toDt;
  while ($cursor <= $end) {
    $days[] = $cursor->format("Y-m-d");
    $ordersDaily[] = 0;
    $revenueDaily[] = 0.0;
    $cursor->modify("+1 day");
  }

  try {
    $sqlDaily = "
      SELECT
        DATE(o.`{$orderDateCol}`) AS d,
        COUNT(*) AS c,
        COALESCE(SUM(o.total_amount),0) AS amt
      FROM orders o
      WHERE o.payment_status='verified'
        AND o.`{$orderDateCol}` >= :fromTs AND o.`{$orderDateCol}` < :toNext
      GROUP BY DATE(o.`{$orderDateCol}`)
      ORDER BY d ASC
    ";
    $st = $pdo->prepare($sqlDaily);
    $st->execute([":fromTs"=>$fromTs, ":toNext"=>$toNext]);
    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $map[(string)$r["d"]] = ["c"=>(int)$r["c"], "amt"=>(float)$r["amt"]];
    }
    foreach ($days as $i=>$d) {
      if (isset($map[$d])) {
        $ordersDaily[$i] = $map[$d]["c"];
        $revenueDaily[$i] = $map[$d]["amt"];
      }
    }
  } catch (Throwable $e) {}
}

// ====== Admin display name ======
$adminDisplay = trim((string)($_SESSION["admin"]["full_name"] ?? ""));
if ($adminDisplay === "") $adminDisplay = trim((string)($_SESSION["admin"]["username"] ?? "Admin"));

// ====== UI helpers ======
function money(float $n): string { return number_format($n, 2); }
function statusThai(string $s): string {
  return match ($s) {
    "pending" => "รอดำเนินการ",
    "confirmed" => "ยืนยันออเดอร์",
    "shipped" => "จัดส่ง",
    "completed" => "สำเร็จ",
    "cancelled" => "ยกเลิก",
    default => "ไม่ทราบสถานะ",
  };
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard • Bakery Admin</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">

  <style>
    body { background: #f6f7fb; font-family: "Kanit", system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
    .app-shell { max-width: 1500px; }
    .card { border: 0; border-radius: 18px; box-shadow: 0 10px 25px rgba(15, 23, 42, .06); }
    .soft { background: rgba(255,255,255,.65); border: 1px solid rgba(0,0,0,.06); border-radius: 16px; }
    .brand-badge { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 16px; }
    .kpi { padding: 14px 16px; }
    .kpi .num { font-size: 1.35rem; font-weight: 700; letter-spacing: .2px; }
    .kpi .sub { color: #64748b; font-size: .9rem; }
    .chip { font-size: .85rem; border-radius: 999px; padding: .25rem .6rem; }
    .hint { color:#64748b; font-size:.9rem; }
    canvas { width:100% !important; height: 310px !important; }
    .mini-table td, .mini-table th { vertical-align: middle; }
  </style>
</head>
<body>

<div class="container py-4 app-shell">

  <div class="d-flex align-items-center justify-content-between mb-4">
    <div class="d-flex align-items-center gap-3">
      <div class="brand-badge bg-dark text-white">
        <i class="bi bi-shop fs-5"></i>
      </div>
      <div>
        <div class="fw-semibold">HokKao(69)Bakery Admin</div>
      </div>
    </div>

    <div class="d-flex gap-2">
        <?php
        $adminDisplay = trim((string)($_SESSION["admin"]["full_name"] ?? ""));
        if ($adminDisplay === "") $adminDisplay = trim((string)($_SESSION["admin"]["username"] ?? "Admin"));
        ?>

        <span class="btn btn-outline-dark disabled">
        <i class="bi bi-person-circle me-1"></i> <?= h($adminDisplay) ?>
        </span>
      <a class="btn btn-outline-secondary" href="admin_logout.php">
        <i class="bi bi-box-arrow-right me-1"></i> ออกจากระบบ
      </a>
    </div>
  </div>

  <div class="card p-3 p-md-4 mb-3">

  <!-- 🔹 หัวข้อ + เมนู -->
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-2">
    <div>
      <div class="fw-semibold fs-5">ภาพรวมการสั่งซื้อ</div>
      <div class="hint">
        ช่วงวันที่: 
        <span class="fw-semibold"><?= h($from) ?></span> 
        ถึง 
        <span class="fw-semibold"><?= h($to) ?></span>

        <?php if ($orderDateCol === ""): ?>
          <span class="chip bg-warning-subtle text-warning border ms-2">
            <i class="bi bi-info-circle me-1"></i>
            ไม่พบคอลัมน์วันที่ในตาราง orders (แสดงแบบรวมทั้งหมด)
          </span>
        <?php endif; ?>
      </div>
    </div>

    <!-- ✅ เมนู admin -->
    <?php require __DIR__ . "/partials/admin_nav.php"; ?>
  </div>

  <hr class="my-3">

  <!-- 🔹 ส่วน filter วันที่ -->
  <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center mb-3">
    <form class="d-flex gap-2 align-items-center" method="get">
      <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
      <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
      <button class="btn btn-dark" type="submit">
        <i class="bi bi-funnel me-1"></i>กรอง
      </button>
    </form>
  </div>

    <hr class="my-3">

    <div class="row g-3">
      <div class="col-6 col-lg-3">
        <div class="soft kpi">
          <div class="sub"><i class="bi bi-receipt-cutoff me-1"></i>ออเดอร์ (verified)</div>
          <div class="num"><?= (int)$kpi["orders"] ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="soft kpi">
          <div class="sub"><i class="bi bi-cash-coin me-1"></i>ยอดขายรวม</div>
          <div class="num">฿<?= money($kpi["revenue"]) ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="soft kpi">
          <div class="sub"><i class="bi bi-bag-check me-1"></i>ชิ้นรวม</div>
          <div class="num"><?= (int)$kpi["items"] ?></div>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="soft kpi">
          <div class="sub"><i class="bi bi-graph-up me-1"></i>เฉลี่ย/ออเดอร์</div>
          <div class="num">฿<?= money($kpi["avg"]) ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card p-3 p-md-4">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <div class="fw-semibold">กราฟออเดอร์ & ยอดขายรายวัน</div>
            <div class="text-muted small">เฉพาะออเดอร์ที่ตรวจสลิป “ยืนยันแล้ว”</div>
          </div>
          <span class="chip bg-light border"><i class="bi bi-bar-chart-line me-1"></i>Trend</span>
        </div>
        <hr class="my-3">
        <canvas id="chartTrend"></canvas>
        <?php if ($orderDateCol === ""): ?>
          <div class="text-muted small mt-2">* ไม่สามารถทำกราฟรายวันได้ เพราะไม่พบคอลัมน์วันที่ (เช่น created_at) ในตาราง orders</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card p-3 p-md-4 h-100">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <div class="fw-semibold">สัดส่วนสถานะ (Stepper)</div>
            <div class="text-muted small">pending / confirmed / shipped / completed / cancelled</div>
          </div>
          <span class="chip bg-light border"><i class="bi bi-pie-chart me-1"></i>Status</span>
        </div>
        <hr class="my-3">
        <canvas id="chartStatus" style="height: 310px !important;"></canvas>

        <div class="mt-3 small">
          <?php foreach ($statusCounts as $s=>$c): ?>
            <div class="d-flex justify-content-between">
              <span class="text-muted"><?= h(statusThai($s)) ?></span>
              <span class="fw-semibold"><?= (int)$c ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card p-3 p-md-4">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <div class="fw-semibold">Top 10 เมนูขายดี (จำนวนชิ้น)</div>
            <div class="text-muted small">จัดอันดับตาม qty รวม</div>
          </div>
          <span class="chip bg-light border"><i class="bi bi-award me-1"></i>Top</span>
        </div>
        <hr class="my-3">
        <canvas id="chartTop"></canvas>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card p-3 p-md-4">
        <div class="d-flex align-items-start justify-content-between gap-2">
          <div>
            <div class="fw-semibold">ตาราง Top เมนู</div>
            <div class="text-muted small">qty และยอดขายของเมนู</div>
          </div>
          <span class="chip bg-light border"><i class="bi bi-list-ul me-1"></i>List</span>
        </div>
        <hr class="my-3">

        <div class="table-responsive">
          <table class="table table-sm mini-table mb-0">
            <thead class="text-muted small">
              <tr>
                <th>#</th>
                <th>เมนู</th>
                <th class="text-end">qty</th>
                <th class="text-end">ยอด (฿)</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$topNames): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีข้อมูล</td></tr>
              <?php else: ?>
                <?php foreach ($topNames as $i=>$nm): ?>
                  <tr>
                    <td class="text-muted"><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= h($nm) ?></td>
                    <td class="text-end"><?= (int)($topQty[$i] ?? 0) ?></td>
                    <td class="text-end"><?= money((float)($topMoney[$i] ?? 0)) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

      </div>
    </div>
  </div>

  <div class="text-center text-muted small mt-4">
    <i class="bi bi-shield-lock me-1"></i> Admin Dashboard
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  // ====== Data from PHP ======
  const days = <?= json_encode($days, JSON_UNESCAPED_UNICODE) ?>;
  const ordersDaily = <?= json_encode($ordersDaily, JSON_UNESCAPED_UNICODE) ?>;
  const revenueDaily = <?= json_encode($revenueDaily, JSON_UNESCAPED_UNICODE) ?>;

  const statusLabels = <?= json_encode(array_map(fn($s)=>statusThai($s), array_keys($statusCounts)), JSON_UNESCAPED_UNICODE) ?>;
  const statusCounts = <?= json_encode(array_values($statusCounts), JSON_UNESCAPED_UNICODE) ?>;

  const topNames = <?= json_encode($topNames, JSON_UNESCAPED_UNICODE) ?>;
  const topQty = <?= json_encode($topQty, JSON_UNESCAPED_UNICODE) ?>;

  // ====== Trend Chart (Orders + Revenue) ======
  (function(){
    const el = document.getElementById("chartTrend");
    if (!el) return;

    // if no day labels -> show empty chart gracefully
    new Chart(el, {
      type: "line",
      data: {
        labels: days.length ? days : ["-"],
        datasets: [
          {
            label: "จำนวนออเดอร์",
            data: days.length ? ordersDaily : [0],
            tension: 0.35,
            yAxisID: "y1"
          },
          {
            label: "ยอดขาย (บาท)",
            data: days.length ? revenueDaily : [0],
            tension: 0.35,
            yAxisID: "y2"
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: "top" },
          tooltip: { mode: "index", intersect: false }
        },
        interaction: { mode: "index", intersect: false },
        scales: {
          y1: { beginAtZero: true, title: { display: true, text: "ออเดอร์" } },
          y2: { beginAtZero: true, position: "right", grid: { drawOnChartArea: false }, title: { display: true, text: "บาท" } }
        }
      }
    });
  })();

  // ====== Status Pie ======
  (function(){
    const el = document.getElementById("chartStatus");
    if (!el) return;

    new Chart(el, {
      type: "doughnut",
      data: {
        labels: statusLabels,
        datasets: [{ data: statusCounts }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: "bottom" } },
        cutout: "62%"
      }
    });
  })();

  // ====== Top Products Bar ======
  (function(){
    const el = document.getElementById("chartTop");
    if (!el) return;

    new Chart(el, {
      type: "bar",
      data: {
        labels: topNames.length ? topNames : ["-"],
        datasets: [{
          label: "จำนวนขาย (qty)",
          data: topNames.length ? topQty : [0]
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: true } },
        scales: {
          y: { beginAtZero: true }
        }
      }
    });
  })();
</script>
</body>
</html>