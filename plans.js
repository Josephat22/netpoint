/* ==========================================================================
   Buy-a-plan page — front-end logic
   --------------------------------------------------------------------------
   Same rule as script.js: every place you need to connect a real backend
   is marked  // BACKEND:
   ========================================================================== */

/* ---------- 1. Router session params (carried through to purchase) ------- */

const params = new URLSearchParams(window.location.search);
const session = {
  mac: params.get('mac') || '',
  ip: params.get('ip') || '',
  username: params.get('username') || '',
  hotspot: params.get('hotspot') || '',
  linklogin: params.get('linklogin') || ''
};

document.getElementById('networkName').textContent =
  session.hotspot ? session.hotspot : 'WiFi network';

const copyYearEl = document.getElementById('copyYear');
if (copyYearEl) copyYearEl.textContent = new Date().getFullYear();

/* ---------- 2. Plan list --------------------------------------------------- */

/* Small inline icon set — no external icon library, keeps the page self-contained. */
const ICONS = {
  signal: '<svg class="plan-icon" viewBox="0 0 20 16" fill="currentColor"><rect x="1" y="10" width="3" height="5" rx="1"/><rect x="6" y="7" width="3" height="8" rx="1"/><rect x="11" y="4" width="3" height="11" rx="1"/><rect x="16" y="1" width="3" height="14" rx="1"/></svg>',
  infinity: '<svg class="plan-icon" viewBox="0 0 30 16" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="9" cy="8" r="5.2"/><circle cx="21" cy="8" r="5.2"/></svg>',
  clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>',
  cart: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.8h7.6a2 2 0 0 0 2-1.6L21 8H6"/><circle cx="9" cy="20" r="1"/><circle cx="17" cy="20" r="1"/></svg>',
  star: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.3 6.9.6-5.2 4.6 1.6 6.8L12 16.9 5.8 20.3l1.6-6.8L2.2 8.9l6.9-.6z"/></svg>'
};

/* BACKEND: fetch this from /api/plans instead of hardcoding it, so prices
   and data caps can change without redeploying the front-end.
   type: 'data' shows the signal-bar icon, 'unlimited' shows the infinity icon.
   popular: true highlights one card — set it on whichever plan sells best. */
const plans = [
  { id: 'p1', data: '500MB',     price: 5,   duration: '1 hour',   type: 'data' },
  { id: 'p2', data: '1GB',       price: 10,  duration: '2 hours',  type: 'data' },
  { id: 'p3', data: '2GB',       price: 10,  duration: '2 hours',  type: 'data' },
  { id: 'p4', data: '5GB',       price: 20,  duration: '6 hours',  type: 'data' },
  { id: 'p5', data: '10GB',      price: 30,  duration: '12 hours', type: 'data' },
  { id: 'p6', data: 'Unlimited', price: 50,  duration: '12 hours', type: 'unlimited', popular: true },
  { id: 'p7', data: 'Unlimited', price: 100, duration: '24 hours', type: 'unlimited' },
  { id: 'p8', data: 'Unlimited', price: 300, duration: '7 days',   type: 'unlimited' }
];

const planList = document.getElementById('planList');

function renderPlans() {
  planList.innerHTML = '';
  plans.forEach(plan => {
    const li = document.createElement('li');
    li.className = plan.popular ? 'plan-card popular' : 'plan-card';
    const ribbon = plan.popular
      ? `<span class="popular-ribbon">${ICONS.star} Popular</span>`
      : '';
    const typeIcon = plan.type === 'unlimited' ? ICONS.infinity : ICONS.signal;
    li.innerHTML = `
      ${ribbon}
      <div class="plan-card-top">
        <span class="plan-badge">${plan.price}/=</span>
        ${typeIcon}
      </div>
      <p class="plan-data">${plan.data}</p>
      <p class="plan-meta">${ICONS.clock} Valid ${plan.duration}</p>
      <button class="btn plan-buy" type="button" data-plan="${plan.id}">${ICONS.cart} Buy now</button>
    `;
    planList.appendChild(li);
  });
}
renderPlans();

/* ---------- 3. Buy flow (M-Pesa STK push sheet) ---------------------------- */

const paySheet = document.getElementById('paySheet');
const sheetPlanLabel = document.getElementById('sheetPlanLabel');
const phoneInput = document.getElementById('phoneInput');
const phoneError = document.getElementById('phoneError');
const payError = document.getElementById('payError');
const sheetCancel = document.getElementById('sheetCancel');
const sheetPay = document.getElementById('sheetPay');

let activePlan = null;

planList.addEventListener('click', (e) => {
  const btn = e.target.closest('[data-plan]');
  if (!btn) return;
  activePlan = plans.find(p => p.id === btn.dataset.plan);
  sheetPlanLabel.textContent = `${activePlan.data} · ${activePlan.duration} — KES ${activePlan.price}`;
  phoneInput.value = '';
  phoneError.hidden = true;
  payError.hidden = true;
  paySheet.hidden = false;
  phoneInput.focus();
});

sheetCancel.addEventListener('click', () => {
  paySheet.hidden = true;
});

sheetPay.addEventListener('click', async () => {
  const phone = phoneInput.value.trim().replace(/\s+/g, '');
  if (!/^0?7\d{8}$/.test(phone)) {
    phoneError.hidden = false;
    return;
  }
  phoneError.hidden = true;
  payError.hidden = true;

  setBusy(true);
  try {
    const result = await requestPayment(activePlan, phone);
    if (!result.ok) {
      payError.textContent = result.message || 'Could not start the payment. Try again.';
      payError.hidden = false;
      return;
    }
    // Payment prompt sent — wait for the user to enter their M-Pesa PIN.
    await pollPaymentStatus(result.checkoutId);
  } catch (err) {
    payError.hidden = false;
  } finally {
    setBusy(false);
  }
});

function setBusy(busy) {
  sheetPay.disabled = busy;
  phoneInput.disabled = busy;
  sheetPay.querySelector('.btn-label').textContent = busy ? 'Waiting for payment…' : 'Send payment prompt';
}

/* BACKEND: call your server, which calls Safaricom's Daraja STK Push API.
   POST /api/pay { planId, phone, mac, ip }
   Return a checkoutId so the front-end can poll for the result — or better,
   push the result over a WebSocket / server-sent event instead of polling. */
async function requestPayment(plan, phone) {
  const res = await fetch('api/pay.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ planId: plan.id, phone, ...session })
  });
  return res.json(); // { ok: boolean, checkoutId?: string, message?: string }
}

/* Polls api/pay_status.php every 3s for up to 2 minutes — that's how long
   it can realistically take someone to notice the STK push, enter their
   PIN, or let it time out. Swap this for a WebSocket/SSE push from your
   backend later if you want the wait to feel more instant. */
async function pollPaymentStatus(checkoutId) {
  const maxAttempts = 40; // 40 x 3s = 2 minutes
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    await wait(3000);

    let result;
    try {
      const res = await fetch(`api/pay_status.php?checkout_id=${encodeURIComponent(checkoutId)}`);
      result = await res.json();
    } catch (err) {
      continue; // transient network hiccup — just try again next tick
    }

    if (result.pending) {
      continue;
    }

    if (result.ok && result.code) {
      saveVoucherLocally(result.code);
      const back = new URLSearchParams(session);
      back.set('connected', '1');
      back.set('code', result.code);
      window.location.href = `connect.html?${back.toString()}`;
      return;
    }

    // ok:false — payment failed or was cancelled. Stop polling and say so.
    payError.textContent = result.message || 'Payment was not completed. You can try again.';
    payError.hidden = false;
    return;
  }

  payError.textContent = 'This is taking longer than expected. Check your phone, or try again.';
  payError.hidden = false;
}

function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/* ---------- 4. Local voucher history (mirrors script.js) ------------------- */

const STORAGE_KEY = 'netpoint_recent_vouchers';

function saveVoucherLocally(code) {
  const list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
  list.unshift({ code, status: 'active', time: new Date().toISOString() });
  localStorage.setItem(STORAGE_KEY, JSON.stringify(list.slice(0, 10)));
}