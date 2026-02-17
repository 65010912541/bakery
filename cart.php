<?php
declare(strict_types=1);
require __DIR__ . "/auth.php";
require_login("cart.php");
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ตะกร้าสินค้า | Bakery</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/cart.css">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="page-cart">

<?php require __DIR__ . "/partials/nav.php"; ?>

<main class="container my-4">
  <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3">
    <div>
      <div class="text-muted">ตรวจสอบก่อนยืนยันคำสั่งซื้อ</div>
      <h1 class="h4 fw-semibold mb-0">🧺 ตะกร้าสินค้า</h1>
    </div>

    <div class="d-flex gap-2">
      <button class="btn btn-outline-danger rounded-pill" type="button" onclick="clearCart()">
        ล้างตะกร้า
      </button>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-8">
      <div class="card-soft p-3">
        <div id="cartEmpty" class="text-center py-5 d-none">
          <div class="h5 fw-semibold mb-2">ตะกร้ายังว่างอยู่ 🥺</div>
          <div class="text-muted mb-3">ลองเลือกเมนูที่ชอบ แล้วกลับมาดูที่นี่ได้เลย</div>
          <a href="index.php" class="btn btn-brand rounded-pill px-4">ไปเลือกเบเกอรี่</a>
        </div>

        <div id="cartList" class="d-none">
          <div class="table-responsive">
            <table class="table align-middle mb-0 table-cart">
              <thead>
                <tr>
                  <th>รายการ</th>
                  <th class="text-end">ราคา/ชิ้น</th>
                  <th class="text-center">จำนวน</th>
                  <th class="text-end">รวม</th>
                  <th class="text-end">จัดการ</th>
                </tr>
              </thead>
              <tbody id="cartTbody"></tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card-soft p-3 sticky-summary">
        <h2 class="h6 fw-semibold mb-3">สรุปคำสั่งซื้อ</h2>

        <div class="d-flex justify-content-between mb-2">
          <div class="text-muted">จำนวนชิ้น</div>
          <div class="fw-semibold" id="sumCount">0</div>
        </div>

        <div class="d-flex justify-content-between mb-2">
          <div class="text-muted">ยอดรวม</div>
          <div class="fw-bold" id="sumTotal">0 บาท</div>
        </div>

        <hr class="my-3">

        <button class="btn btn-brand w-100 rounded-pill" type="button" id="checkoutBtn" disabled onclick="openCheckout()">
        ยืนยันสั่งซื้อ
        </button>

        <div class="small text-muted mt-2">
          * ตอนนี้เป็นตัวอย่าง (ยังไม่บันทึกลงฐานข้อมูล)
        </div>
      </div>
    </div>
  </div>
</main>

<!-- Checkout Modal (ปรับ layout ใหม่) -->
<div class="modal fade" id="checkoutModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content rounded-4 overflow-hidden">

      <div class="modal-header border-0 px-4 py-3">
        <div>
          <div class="text-muted small">ข้อมูลผู้สั่งซื้อ</div>
          <h5 class="modal-title fw-semibold mb-0">ยืนยันคำสั่งซื้อ</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body px-4 pb-4 pt-0">
        <div class="row g-3">

          <!-- LEFT: Customer form -->
          <div class="col-12 col-md-7">
            <div class="checkout-card">
              <div class="checkout-card-title">รายละเอียดผู้รับ</div>

              <div class="mb-2">
                <label class="form-label">ชื่อ-นามสกุล</label>
                <input id="coName" class="form-control" placeholder="เช่น เปรมชัย">
              </div>

              <div class="mb-2">
                <label class="form-label">เบอร์โทร</label>
                <input id="coPhone" class="form-control" placeholder="เช่น 09xxxxxxxx">
              </div>

              <div class="mb-2">
                <label class="form-label">ที่อยู่จัดส่ง</label>
                <textarea id="coAddress" class="form-control" rows="3"
                  placeholder="บ้านเลขที่/ถนน/ตำบล/อำเภอ/จังหวัด/รหัสไปรษณีย์"></textarea>
              </div>

              <div class="mb-0">
                <label class="form-label">หมายเหตุรวม (ถ้ามี)</label>
                <textarea id="coNote" class="form-control" rows="2" placeholder="เช่น ส่งช่วงบ่าย"></textarea>
              </div>
            </div>
          </div>

          <!-- RIGHT: Payment -->
          <div class="col-12 col-md-5">
            <div class="checkout-card checkout-pay">
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div>
                  <div class="checkout-card-title mb-1">ชำระผ่าน PromptPay</div>
                  <div class="text-muted small">สแกน QR เพื่อโอนเงิน (ยอดเงินตามจริง)</div>
                </div>
                <span class="badge text-bg-light border rounded-pill px-3 py-2">PromptPay</span>
              </div>

              <div class="pay-summary mt-3">
                <div class="d-flex justify-content-between small text-muted">
                  <span>จำนวน</span>
                  <span><b id="coQty">0</b> ชิ้น</span>
                </div>
                <div class="d-flex justify-content-between mt-1">
                  <span class="text-muted">ยอดชำระ</span>
                  <span class="fw-bold" id="coTotal">0 บาท</span>
                </div>
              </div>

              <div class="small text-muted mt-2">
                ยอดชำระจริง: <b id="ppAmountText">-</b>
              </div>

              <div class="qr-wrap my-3">
                <div id="ppQr" class="qr-box"></div>
              </div>

              <div class="mb-2">
                <label class="form-label">แนบสลิปการโอน <span class="text-danger">*</span></label>
                <input id="coSlip" type="file" class="form-control" accept="image/*">
                <div class="small text-muted mt-1">รองรับ jpg/png/webp (ไม่เกิน 5MB)</div>
              </div>

              <div class="small text-danger mt-2 d-none" id="coErr"></div>

              <div class="d-grid mt-3">
                <button id="coBtn" type="button" class="btn btn-dark rounded-pill" onclick="placeOrder()">
                  ยืนยันสั่งซื้อ
                </button>
              </div>

              <div class="small text-muted mt-2">
                * หลังโอนเสร็จ ระบบจะรอตรวจสอบสลิป
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="cartToast" class="toast align-items-center text-bg-dark border-0" role="alert">
    <div class="d-flex">
      <div class="toast-body" id="toastMsg">อัปเดตตะกร้าแล้ว</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const CART_KEY = "bakery_cart";

function loadCart(){
  try{
    const raw = localStorage.getItem(CART_KEY);
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed) ? parsed : [];
  }catch{
    return [];
  }
}
function saveCart(cart){
  localStorage.setItem(CART_KEY, JSON.stringify(cart));
}

let cart = loadCart();
render();

function toast(msg){
  const el = document.getElementById("cartToast");
  const msgEl = document.getElementById("toastMsg");
  if (!el || !msgEl) return;
  msgEl.textContent = msg;
  new bootstrap.Toast(el).show();
}

function updateBadge(){
  const badge = document.querySelector(".cart-badge");
  if (!badge) return;
  const count = cart.reduce((sum, i) => sum + Number(i.qty || 0), 0);
  badge.textContent = String(count);
}

function formatMoney(n){
  return Number(n||0).toLocaleString("th-TH") + " บาท";
}

function render(){
  cart = loadCart();
  updateBadge();

  const emptyEl = document.getElementById("cartEmpty");
  const listEl  = document.getElementById("cartList");
  const tbody   = document.getElementById("cartTbody");
  const sumCount= document.getElementById("sumCount");
  const sumTotal= document.getElementById("sumTotal");
  const checkoutBtn = document.getElementById("checkoutBtn");

  if (cart.length === 0){
    emptyEl.classList.remove("d-none");
    listEl.classList.add("d-none");
    tbody.innerHTML = "";
    sumCount.textContent = "0";
    sumTotal.textContent = "0 บาท";
    checkoutBtn.disabled = true;
    return;
  }

  emptyEl.classList.add("d-none");
  listEl.classList.remove("d-none");

  let total = 0;
  let count = 0;

  tbody.innerHTML = cart.map((item, idx) => {
    const name = String(item.name ?? "สินค้า");
    const price = Number(item.price || 0);
    const qty = Number(item.qty || 1);
    const note = String(item.note ?? "").trim();

    const line = price * qty;
    total += line;
    count += qty;

    return `
      <tr>
        <td class="td-item">
          <div class="fw-semibold text-truncate">${escapeHtml(name)}</div>
          ${note ? `<div class="small text-muted mt-1 note">📝 ${escapeHtml(note)}</div>` : ``}
        </td>

        <td class="text-end fw-semibold">${price.toLocaleString("th-TH")} ฿</td>

        <td class="text-center">
          <div class="d-inline-flex align-items-center gap-1">
            <button class="btn btn-sm btn-outline-secondary rounded-pill px-2" onclick="stepQty(${idx}, -1)">−</button>
            <input class="form-control form-control-sm qty-pill"
                   type="number" min="1" value="${qty}"
                   onchange="setQty(${idx}, this.value)">
            <button class="btn btn-sm btn-outline-secondary rounded-pill px-2" onclick="stepQty(${idx}, 1)">+</button>
          </div>
        </td>

        <td class="text-end fw-bold">${line.toLocaleString("th-TH")} ฿</td>

        <td class="text-end">
          <button class="btn btn-sm btn-outline-danger rounded-pill" onclick="removeItem(${idx})">ลบ</button>
        </td>
      </tr>
    `;
  }).join("");

  sumCount.textContent = count.toLocaleString("th-TH");
  sumTotal.textContent = formatMoney(total);
  checkoutBtn.disabled = false;
}

function stepQty(index, delta){
  if (!cart[index]) return;
  let qty = Number(cart[index].qty || 1) + Number(delta);
  if (qty < 1) qty = 1;
  cart[index].qty = qty;
  saveCart(cart);
  render();
  toast("ปรับจำนวนแล้ว");
}

function setQty(index, value){
  if (!cart[index]) return;
  let qty = Number(value);
  if (!Number.isFinite(qty) || qty < 1) qty = 1;
  cart[index].qty = qty;
  saveCart(cart);
  render();
  toast("อัปเดตจำนวนแล้ว");
}

function removeItem(index){
  if (!cart[index]) return;
  cart.splice(index, 1);
  saveCart(cart);
  render();
  toast("ลบรายการแล้ว");
}

function clearCart(){
  cart = [];
  saveCart(cart);
  render();
  toast("ล้างตะกร้าแล้ว");
}

function checkout(){
  const totalQty = cart.reduce((s,i)=>s+Number(i.qty||0),0);
  const totalAmount = cart.reduce((s,i)=>s+Number(i.qty||0)*Number(i.price||0),0);
  console.log("ORDER", {items: cart, totalQty, totalAmount});
  alert("ตัวอย่าง: ยืนยันสั่งซื้อแล้ว ✅\n(ดูรายละเอียดใน Console)");
}

function escapeHtml(str) {
  return String(str)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

async function openCheckout(){
  cart = loadCart();
  if (cart.length === 0) return;

  const totalQty = cart.reduce((s,i)=>s+Number(i.qty||0),0);
  const totalAmount = cart.reduce((s,i)=>s+Number(i.qty||0)*Number(i.price||0),0);

  document.getElementById("coQty").textContent = String(totalQty);
  document.getElementById("coTotal").textContent = totalAmount.toLocaleString("th-TH") + " บาท";

  const err = document.getElementById("coErr");
  err.classList.add("d-none");
  err.textContent = "";

  try{
    await loadPromptPayQr();
  }catch(e){
    err.textContent = e.message || "สร้าง QR ไม่สำเร็จ";
    err.classList.remove("d-none");
  }

  new bootstrap.Modal(document.getElementById("checkoutModal")).show();
}

function placeOrder(){
  const name = document.getElementById("coName").value.trim();
  const phone = document.getElementById("coPhone").value.trim();
  const address = document.getElementById("coAddress").value.trim();
  const note = document.getElementById("coNote").value.trim();
  const slip = document.getElementById("coSlip")?.files?.[0] || null;

  const err = document.getElementById("coErr");
  const btn = document.getElementById("coBtn");

  if (!name || !phone || !address){
    err.textContent = "กรุณากรอกชื่อ / เบอร์ / ที่อยู่ให้ครบ";
    err.classList.remove("d-none");
    return;
  }

  if (!slip){
    err.textContent = "กรุณาแนบสลิปการโอนเงิน";
    err.classList.remove("d-none");
    return;
  }

  cart = loadCart();
  if (cart.length === 0){
    err.textContent = "ตะกร้าว่าง";
    err.classList.remove("d-none");
    return;
  }

  err.classList.add("d-none");
  err.textContent = "";

  btn.disabled = true;
  btn.textContent = "กำลังบันทึกคำสั่งซื้อ...";

  const fd = new FormData();
  fd.append("customer_name", name);
  fd.append("customer_phone", phone);
  fd.append("customer_address", address);
  fd.append("note", note);
  fd.append("payment_method", "promptpay");
  fd.append("items", JSON.stringify(cart.map(i => ({
    id: i.id,
    qty: i.qty,
    note: i.note || ""
  }))));

  fd.append("payment_slip", slip);

  fetch("checkout.php", { method: "POST", body: fd })
    .then(r => r.json().catch(() => ({ ok:false, message:"ตอบกลับไม่ถูกต้อง" })))
    .then(res => {
      if (!res.ok) throw new Error(res.message || "สั่งซื้อไม่สำเร็จ");
      localStorage.removeItem(CART_KEY);
      window.location.href = "order_success.php?order_no=" + encodeURIComponent(res.order_no);
    })
    .catch(e => {
      err.textContent = e.message || "เกิดข้อผิดพลาด";
      err.classList.remove("d-none");
    })
    .finally(() => {
      btn.disabled = false;
      btn.textContent = "✅ ยืนยันสั่งซื้อ";
    });
}


async function loadPromptPayQr(){
  cart = loadCart();
  const res = await fetch("api/promptpay_qr.php", {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify({
      items: cart.map(i => ({ id: i.id, qty: i.qty }))
    })
  });

  const data = await res.json().catch(()=>null);
  if (!res.ok || !data || !data.ok){
    throw new Error((data && data.message) ? data.message : "สร้าง QR ไม่สำเร็จ");
  }

  document.getElementById("ppAmountText").textContent =
    Number(data.amount).toLocaleString("th-TH") + " บาท";

  const box = document.getElementById("ppQr");
  box.innerHTML = "";
  new QRCode(box, { text: data.payload, width: 220, height: 220 });
}

</script>
</body>
</html>
