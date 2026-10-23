'use strict';
/* ============================================================
   Wildboar Gym — client logic (ES6+ / AJAX)
   Real-time feed polling, async check-in, QR member pass, clock.
   ============================================================ */

const WB = window.WB_DATA || { mode: 'none', csrf: '', once: '', qr: '', memberId: '', qrUrl: '', tab: '' };
const $id = (sel) => document.getElementById(sel);

function esc(s) {
  return String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function cleanText(s) { return String(s || '').trim().toUpperCase(); }

async function apiGet(url) {
  try {
    const r = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
    return await r.json();
  } catch (e) { return { ok: false, error: 'network' }; }
}
async function apiPost(url, body) {
  const fd = new URLSearchParams();
  for (const k in body) fd.append(k, body[k]);
  try {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: fd,
    });
    return { status: r.status, body: await r.json() };
  } catch (e) { return { status: 0, body: { ok: false, error: 'network' } }; }
}

/* Refill the single-use check-in token (anti-replay: tokens are one-shot). */
async function refillOnce() {
  const d = await apiGet('api/checkin.php?action=token');
  if (d.ok) { WB.once = d.once; }
}

/* ---------------- live clock (kiosk, PHT) ---------------- */
(function clock() {
  const c = $id('kioskClock');
  if (!c) return;
  const pht = new Intl.DateTimeFormat('en-PH', {
    hour: '2-digit', minute: '2-digit', second: '2-digit',
    hour12: true, timeZone: 'Asia/Manila',
  });
  const tick = () => {
    c.textContent = pht.format(new Date()) + ' PHT';
  };
  tick();
  setInterval(tick, 1000);
})();

/* ---------------- KIOSK mode ---------------- */
if (WB.mode === 'kiosk') {
  const input = $id('kioskMemberId');
  const btn = $id('kioskBtn');
  if (WB.qr) { input.value = WB.qr; }
  input.addEventListener('input', () => {
    input.value = input.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '');
  });

  async function refreshFeed() {
    const d = await apiGet('api/checkin.php?action=feed');
    if (!d.ok) return;
    $id('statToday').textContent = d.today;
    $id('statActive').textContent = d.active;
    const list = $id('kioskRecent');
    if (d.events && d.events.length) {
      list.innerHTML = d.events.map((ev) =>
        `<div class="small text-white-50">${esc(ev.time)} &mdash; <b>${esc(ev.name)}</b> <span class="text-secondary">(via ${esc(ev.method)})</span></div>`
      ).join('');
    } else {
      list.innerHTML = '<div class="small text-white-50">No check-ins yet today.</div>';
    }
  }
  refreshFeed();
  setInterval(refreshFeed, 5000);

  btn.addEventListener('click', async () => {
    const mid = cleanText(input.value);
    const msg = $id('kioskMsg');
    if (!mid) {
      msg.className = 'wb-err';
      msg.textContent = 'Enter your Member ID first.';
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Checking…';
    const res = await apiPost('api/checkin.php?action=checkin', {
      csrf: WB.csrf, once: WB.once, member_id: mid,
    });
    if (res.body.ok) {
      msg.className = 'wb-ok';
      msg.innerHTML = `<div class="fs-5 fw-bold">Welcome, ${esc(res.body.name)}!</div>`
        + `<div class="small">Checked in at ${esc(res.body.time)} &middot; ${res.body.today} today</div>`;
      btn.textContent = 'CHECKED IN';
      setTimeout(() => { btn.textContent = 'CHECK IN'; btn.disabled = false; }, 2000);
      await refillOnce();           /* fresh anti-replay token for the next member */
    } else {
      msg.className = 'wb-err';
      msg.textContent = res.body.error || 'Something went wrong.';
      btn.textContent = 'CHECK IN';
      btn.disabled = false;
    }
  });
}

/* ---------------- MEMBER portal ---------------- */
if (WB.mode === 'member') {
  if (window.QRCode && $id('qrcode')) {
    new QRCode($id('qrcode'), {
      text: WB.qrUrl || '', width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M,
    });
  }
  const btn = $id('memberCheckinBtn');
  if (btn) {
    btn.addEventListener('click', async () => {
      const msg = $id('memberMsg');
      btn.disabled = true;
      btn.textContent = 'Checking…';
      const res = await apiPost('api/checkin.php?action=checkin', {
        csrf: WB.csrf, once: WB.once, member_id: WB.memberId,
      });
      if (res.body.ok) {
        if ($id('myToday')) { $id('myToday').textContent = res.body.today; }
        msg.className = 'wb-ok';
        msg.innerHTML = `Checked in at <b>${esc(res.body.time)}</b> &#10003;`;
      } else {
        msg.className = 'wb-err';
        msg.textContent = res.body.error || 'Something went wrong.';
      }
      btn.disabled = false;
      btn.textContent = '&#10003; Check in now';
      await refillOnce();
    });
  }
  async function refreshMy() {
    const d = await apiGet('api/checkin.php?action=myfeed');
    if (!d.ok) return;
    if ($id('myToday') && d.memberToday !== undefined) {
      $id('myToday').textContent = d.memberToday;
    }
  }
  refreshMy();
  setInterval(refreshMy, 15000);
}

/* ---------------- ADMIN live + XXE self-test ---------------- */
if (WB.mode === 'admin') {
  async function refreshLive() {
    if (WB.tab !== 'live') return;
    const d = await apiGet('api/checkin.php?action=feed');
    if (!d.ok) return;
    const feed = $id('liveFeed');
    if (d.events && d.events.length) {
      const rows = d.events.map((ev) =>
        `<li class="list-group-item d-flex justify-content-between align-items-start">`
        + `<span><b>${esc(ev.name)}</b> <span class="text-muted">(via ${esc(ev.method)})</span></span>`
        + `<span class="text-muted small">${esc(ev.time)}</span></li>`).join('');
      feed.innerHTML = `<ul class="list-group list-group-flush">${rows}</ul>`;
    } else {
      feed.innerHTML = '<div class="text-muted">No check-ins yet today.</div>';
    }
  }
  refreshLive();
  setInterval(refreshLive, 4000);

  async function refreshLogs() {
    if (WB.tab !== 'logs') return;
    const q = (document.getElementById('lq') || {}).value || '';
    const d = (document.getElementById('ld') || {}).value || '';
    try {
      const r = await fetch('admin.php?tab=logs&partial=1&q=' + encodeURIComponent(q) + '&d=' + encodeURIComponent(d), { cache: 'no-store' });
      const tb = document.getElementById('logsRows');
      if (tb) { tb.innerHTML = await r.text(); }
    } catch (e) { /* keep last snapshot on network error */ }
  }
  refreshLogs();
  setInterval(refreshLogs, 6000);

  const st = $id('safetestBtn');
  if (st) {
    st.addEventListener('click', async () => {
      const out = $id('safetestOut');
      out.textContent = 'Running…';
      const d = await apiGet('api/report_xml.php?action=safetest');
      out.textContent = JSON.stringify(d, null, 2);
    });
  }
}
// Kiosk Real-Time Clock (Philippine Standard Time)
function startKioskClock() {
  const clockElem = document.getElementById('kioskClock');
  if (!clockElem) return;

  function update() {
    const now = new Date();
    clockElem.textContent = now.toLocaleTimeString('en-US', {
      timeZone: 'Asia/Manila',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hour12: true
    });
  }

  update();
  setInterval(update, 1000);
}

document.addEventListener('DOMContentLoaded', startKioskClock);