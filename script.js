/* ==========================================================================
   WiFi hotspot captive portal — front-end logic
   --------------------------------------------------------------------------
   This file is deliberately framework-free so it loads instantly on any
   phone that just joined the WiFi. Every place you need to connect a real
   backend is marked with  // BACKEND:  — search for that to find them all.
   ========================================================================== */

/* ---------- 0. Per-deployment settings ------------------------------------ */
/* This is the one line to edit when you set this site up for a new hotspot
   owner — everything that shows their support number reads from here.
   Format: country code + number, no spaces, no leading 0 or +. */
const SUPPORT_PHONE = '254700000000';

const callLink = document.getElementById('callLink');
const whatsappLink = document.getElementById('whatsappLink');
if (callLink) callLink.href = `tel:+${SUPPORT_PHONE}`;
if (whatsappLink) {
  const message = encodeURIComponent("Hi, I need help connecting to WiFi.");
  whatsappLink.href = `https://wa.me/${SUPPORT_PHONE}?text=${message}`;
}

/* ---------- 1. Read what the router told us ----------------------------- */
/* MikroTik / hotspot gateways redirect here with query params like:
   ?mac=...&ip=...&username=...&hotspot=STATION_ID&linklogin=...
   We use these to (a) show the network name and (b) send them back to the
   backend so it knows WHICH device to authorize once a voucher is valid. */

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

/* Brand name is set directly in the HTML markup now (two-tone wordmark).
   BACKEND: if you want this configurable per hotspot site, fetch it from
   a /config endpoint and set brandName's innerHTML (not textContent, so
   the accent <span> survives). */

/* Handles arriving back here with ?connected=1 after a successful payment.
   Called at the very end of this file, once every element below has been
   declared — calling it here would throw, since markConnected() reaches
   into consts (like sessionCard) that don't exist yet at this point. */
function handleConnectedRedirect() {
  if (params.get('connected') === '1') {
    showStatus('ok', `Connected. Voucher ${params.get('code') || ''} is active on this device.`);
    markConnected();
  }
}

/* Support linking straight to the Recent vouchers tab, e.g. from index.html. */
if (params.get('tab') === 'recent') {
  document.querySelector('.tab[data-tab="recent"]').click();
}

/* ---------- 2. Tabs ------------------------------------------------------ */

const tabs = document.querySelectorAll('.tab');
const panels = {
  connect: document.getElementById('panel-connect'),
  recent: document.getElementById('panel-recent')
};

tabs.forEach(tab => {
  tab.addEventListener('click', () => {
    if (!tab.dataset.tab) return; // real links (e.g. Buy a plan) navigate normally

    tabs.forEach(t => {
      t.classList.remove('is-active');
      t.setAttribute('aria-selected', 'false');
    });
    tab.classList.add('is-active');
    tab.setAttribute('aria-selected', 'true');

    Object.values(panels).forEach(p => {
      p.classList.remove('is-active');
      p.hidden = true;
    });
    const target = panels[tab.dataset.tab];
    target.hidden = false;
    target.classList.add('is-active');

    if (tab.dataset.tab === 'recent') renderRecentVouchers();
  });
});

/* ---------- 3. Voucher / M-Pesa message submission ----------------------- */

const voucherForm = document.getElementById('voucherForm');
const voucherInput = document.getElementById('voucherInput');
const voucherError = document.getElementById('voucherError');
const connectBtn = document.getElementById('connectBtn');
const statusBox = document.getElementById('statusBox');

voucherForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const value = voucherInput.value.trim();

  if (!value) {
    voucherError.hidden = false;
    voucherInput.focus();
    return;
  }
  voucherError.hidden = true;

  setBusy(connectBtn, true, 'Checking…');
  showStatus('pending', 'Checking your voucher…');

  try {
    const result = await redeemVoucher(value);
    if (result.ok) {
      showStatus('ok', `Connected. You have ${result.timeLeft || 'access'} remaining.`);
      saveVoucherLocally(value, 'active');
      markConnected();
    } else {
      showStatus('error', result.message || 'That code was not recognized. Check it and try again.');
    }
  } catch (err) {
    showStatus('error', 'Could not reach the network. Try again in a moment.');
  } finally {
    setBusy(connectBtn, false, 'Connect now');
  }
});

/* BACKEND: replace this stub with a real call to your server, e.g.
   POST /api/redeem { code, mac, ip, hotspot }
   Your server validates the code, then calls the router (RouterOS API or
   FreeRADIUS) to authorize session.mac, and returns success/failure here. */
async function redeemVoucher(code) {
  const res = await fetch('api/redeem.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ code, ...session })
  });
  return res.json(); // { ok: boolean, message?: string, timeLeft?: string }
}

function showStatus(kind, message) {
  statusBox.hidden = false;
  statusBox.className = `status ${kind}`;
  statusBox.textContent = message;
}

function setBusy(button, busy, label) {
  button.disabled = busy;
  button.querySelector('.btn-label').textContent = label;
}

function wait(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function markConnected() {
  const icon = document.getElementById('brandIcon');
  if (icon) {
    icon.classList.remove('is-connecting');
    icon.classList.add('is-connected');
  }
  startSessionStatus();
}

/* ---------- Session status: data remaining, time remaining, speed ---------- */

const sessionLocked = document.getElementById('sessionLocked');
const sessionLive = document.getElementById('sessionLive');
const dataRemainingLabel = document.getElementById('dataRemainingLabel');
const dataProgressFill = document.getElementById('dataProgressFill');
const timeRemainingLabel = document.getElementById('timeRemainingLabel');
const planSpeedLabel = document.getElementById('planSpeedLabel');
const speedTestBtn = document.getElementById('speedTestBtn');
const speedResult = document.getElementById('speedResult');

let statusPollHandle = null;

function startSessionStatus() {
  sessionLocked.hidden = true;
  sessionLive.hidden = false;
  refreshSessionStatus();
  clearInterval(statusPollHandle);
  statusPollHandle = setInterval(refreshSessionStatus, 30000); // poll every 30s
}

/* BACKEND: this is the one that makes the card real. Query your router:
   - MikroTik: RouterOS API `/ip/hotspot/active/print` gives bytes-in/out
     per session, and the matching hotspot user profile has the data cap
     and rate-limit (speed) you sold them.
   - FreeRADIUS: accounting records (radacct table) give bytes used; the
     reply attributes on the user/profile give the rate limit.
   Return whatever the device has left, keyed by session.mac. */
async function fetchSessionStatus() {
  const res = await fetch(`api/session_status.php?mac=${encodeURIComponent(session.mac)}`);
  return res.json(); // { ok, dataUsedMB, dataLimitMB, minutesRemaining, planSpeed }
}

async function refreshSessionStatus() {
  try {
    const status = await fetchSessionStatus();
    if (status.ok) {
      renderSessionStatus(status);
    }
    // If ok is false (e.g. no active session found yet), leave the last
    // known values on screen rather than showing broken/NaN numbers.
  } catch (err) {
    // Network error — same idea, just leave what's already showing.
  }
}

function renderSessionStatus(status) {
  const usedGB = (status.dataUsedMB / 1024).toFixed(1);
  const limitGB = (status.dataLimitMB / 1024).toFixed(1);
  const remainingGB = ((status.dataLimitMB - status.dataUsedMB) / 1024).toFixed(1);
  const percentUsed = Math.min(100, (status.dataUsedMB / status.dataLimitMB) * 100);

  dataRemainingLabel.textContent = `${remainingGB}GB of ${limitGB}GB`;
  dataProgressFill.style.width = `${percentUsed}%`;
  if (percentUsed > 90) {
    dataProgressFill.style.background = 'var(--danger)';
  } else if (percentUsed > 70) {
    dataProgressFill.style.background = 'var(--amber)';
  } else {
    dataProgressFill.style.background = 'var(--signal)';
  }

  const hours = Math.floor(status.minutesRemaining / 60);
  const mins = status.minutesRemaining % 60;
  timeRemainingLabel.textContent = hours > 0 ? `${hours}h ${mins}m` : `${mins}m`;

  planSpeedLabel.textContent = status.planSpeed;
}

/* Measures real download throughput by timing a fetch of speedtest-2mb.bin.
   Upload speed needs a server endpoint that accepts a POST and measures
   bytes received server-side — add one and time the equivalent fetch here
   if you want an upload figure too. */
async function runSpeedTest() {
  speedTestBtn.disabled = true;
  speedResult.hidden = false;
  speedResult.textContent = 'Testing…';

  const fileSizeBytes = 2 * 1024 * 1024; // matches speedtest-2mb.bin
  try {
    const start = performance.now();
    const res = await fetch(`speedtest-2mb.bin?cache=${Date.now()}`, { cache: 'no-store' });
    await res.blob();
    const seconds = (performance.now() - start) / 1000;
    const mbps = ((fileSizeBytes * 8) / seconds / 1e6).toFixed(1);
    speedResult.textContent = `${mbps} Mbps measured just now`;
  } catch (err) {
    speedResult.textContent = 'Could not measure speed right now.';
  } finally {
    speedTestBtn.disabled = false;
  }
}

speedTestBtn.addEventListener('click', runSpeedTest);

/* Now that every element and function above exists, it's safe to check
   whether we just arrived here from a successful payment. */
handleConnectedRedirect();

/* ---------- 4. Recent vouchers (local device history) --------------------- */
/* This is a convenience for the person using the phone, not the system of
   record — the backend database is. Stored per-device in localStorage. */

const recentList = document.getElementById('recentList');
const recentEmpty = document.getElementById('recentEmpty');
const STORAGE_KEY = 'netpoint_recent_vouchers';

function saveVoucherLocally(code, status) {
  const list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
  list.unshift({ code, status, time: new Date().toISOString() });
  localStorage.setItem(STORAGE_KEY, JSON.stringify(list.slice(0, 10)));
}

function renderRecentVouchers() {
  const list = JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
  recentList.innerHTML = '';
  recentEmpty.hidden = list.length > 0;

  list.forEach(entry => {
    const li = document.createElement('li');
    li.className = 'voucher-row';
    const time = new Date(entry.time).toLocaleString([], { hour: '2-digit', minute: '2-digit', month: 'short', day: 'numeric' });
    li.innerHTML = `
      <span class="voucher-code">${entry.code}</span>
      <span class="voucher-meta">${time}</span>
    `;
    recentList.appendChild(li);
  });
}