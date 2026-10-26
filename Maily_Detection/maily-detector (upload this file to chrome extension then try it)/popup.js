// popup.js — Maily Detector

const SPACE_BASE = 'https://meowslouise-spammail-detector.hf.space';
const GRADIO_NEW = `${SPACE_BASE}/gradio_api/call/predict`;
const GRADIO_OLD = `${SPACE_BASE}/run/predict`;

const STATUS_COLOR = {
  'NOT SPAM':     '#3B6D11',
  'SPAM':         '#A32D2D',
  'SUBSCRIPTION': '#185FA5',
};

const SUBSCRIPTION_STEPS = [
  'Log in to your account',
  'Go to Settings → Billing',
  'Click "Cancel Subscription"',
  'Confirm cancellation',
  'Check your email for confirmation',
];

let hasScanned = false;

// ── Thai detection ────────────────────────────────────────────────────────
function thaiRatio(text) {
  const thai = (text.match(/[\u0E00-\u0E7F]/g) || []).length;
  const total = text.replace(/\s/g, '').length;
  return total === 0 ? 0 : thai / total;
}

// ── Parse the raw string Gradio returns ──────────────────────────────────
// Raw example: "EMAIL ANALYSIS RESULT\nCATEGORY : SUBSCRIPTION\nCONFIDENCE : HIGH (100%)\nREASON : Detected subscription-related terms"
function parseGradioString(raw) {
  const upper = raw.toUpperCase();

  // Extract label
  let label = null;
  if (upper.includes('NOT SPAM'))     label = 'NOT SPAM';
  else if (upper.includes('SPAM'))    label = 'SPAM';
  else if (upper.includes('SUBSCRIPTION')) label = 'SUBSCRIPTION';

  // Extract confidence % from e.g. "(87%)" or "(87.43%)" or "CONFIDENCE : 87.43"
  let confidence = null;
  const pctMatch = raw.match(/\((\d+(?:\.\d+)?)%\)/);
  if (pctMatch) {
    confidence = parseFloat(pctMatch[1]);
  } else {
    const confMatch = raw.match(/CONFIDENCE\s*[:\-]\s*[\w]+\s*\(?(\d+(?:\.\d+)?)/i);
    if (confMatch) confidence = parseFloat(confMatch[1]);
  }

  // Extract reason after REASON :
  let reason = null;
  const reasonMatch = raw.match(/REASON\s*[:\-]\s*(.+?)(?:\n|$)/i);
  if (reasonMatch) reason = reasonMatch[1].trim();

  return { label, confidence, reason };
}

// ── Normalize output from any Gradio format ───────────────────────────────
function parseOutput(output) {
  // Gradio Label component: { label, confidences: [{label, confidence}] }
  if (output && typeof output === 'object' && output.label) {
    let label = output.label;
    if (label.toUpperCase().includes('NOT SPAM'))     label = 'NOT SPAM';
    else if (label.toUpperCase().includes('SPAM'))    label = 'SPAM';
    else if (label.toUpperCase().includes('SUBSCRIPTION')) label = 'SUBSCRIPTION';

    const conf = output.confidences?.find(c =>
      c.label.toUpperCase().replace('LABEL_', '') === label ||
      c.label === output.label
    );
    return { label, confidence: conf ? conf.confidence * 100 : null, reason: null };
  }

  // Plain string output (what this Space actually returns)
  if (typeof output === 'string') {
    return parseGradioString(output);
  }

  throw new Error('Cannot parse API response');
}

// ── UI helpers ────────────────────────────────────────────────────────────
function setLoading(on) {
  const btn   = document.getElementById('scanBtn');
  const label = document.getElementById('btnLabel');
  btn.disabled = on;
  if (on) {
    document.getElementById('btnIcon').outerHTML =
      '<span id="btnIcon" class="spinner"></span>';
    label.textContent = '...';
  } else {
    const el = document.getElementById('btnIcon');
    if (el) el.outerHTML =
      `<svg id="btnIcon" width="24" height="24" viewBox="0 0 24 24" fill="none">
        <circle cx="11" cy="11" r="7" stroke="white" stroke-width="2"/>
        <line x1="16.5" y1="16.5" x2="21" y2="21" stroke="white" stroke-width="2" stroke-linecap="round"/>
      </svg>`;
    label.textContent = hasScanned ? 'Rescan' : 'Scan';
    btn.disabled = false;
  }
}

function hideAll() {
  document.getElementById('resultArea').style.display = 'none';
  document.getElementById('warnCard').style.display   = 'none';
  document.getElementById('errorCard').style.display  = 'none';
}

function renderDetail(label, reason) {
  const box = document.getElementById('detailText');

  if (label === 'SUBSCRIPTION') {
    const stepsHtml = SUBSCRIPTION_STEPS.map((s, i) =>
      `<div class="step-row">
        <span class="step-num">${i + 1}</span>
        <span class="step-text">${s}</span>
      </div>`
    ).join('');
    box.innerHTML = `
      <p class="step-label">How to cancel your subscription:</p>
      <div class="steps-list">${stepsHtml}</div>
    `;
  } else {
    // Use reason from API if available, else fallback
    const fallback = label === 'SPAM'
      ? 'This email contains patterns commonly associated with spam or phishing. Exercise caution before clicking any links or replying.'
      : 'This email shows no signs of spam or phishing. It appears to be a legitimate message.';
    box.innerHTML = `<p class="detail-reason">${reason || fallback}</p>`;
  }
}

function showResult(label, confidence, reason) {
  hideAll();
  const color = STATUS_COLOR[label] || '#888';
  const pct   = confidence !== null ? Math.round(confidence) : null;

  document.getElementById('statusDot').style.background = color;
  document.getElementById('statusLabel').textContent    = label;
  document.getElementById('statusLabel').style.color   = color;

  if (pct !== null) {
    document.getElementById('confBar').style.width      = pct + '%';
    document.getElementById('confBar').style.background = color;
    document.getElementById('confPct').textContent      = pct + '%';
  } else {
    // Hide bar if no confidence data
    document.getElementById('confBar').style.width = '0%';
    document.getElementById('confPct').textContent = 'N/A';
  }

  renderDetail(label, reason);

  document.getElementById('resultArea').style.display = 'flex';
  document.getElementById('hint').style.display       = 'none';
}

function showWarn(msg) {
  hideAll();
  const el = document.getElementById('warnCard');
  el.innerHTML = `<b>⚠️ Warning:</b> ${msg}`;
  el.style.display = 'block';
}

function showError(msg) {
  hideAll();
  const el = document.getElementById('errorCard');
  el.innerHTML = `<b>❌ Error:</b> ${msg}`;
  el.style.display = 'block';
}

// ── Gradio API calls ──────────────────────────────────────────────────────
async function callGradioNew(text) {
  const res = await fetch(GRADIO_NEW, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: [text] })
  });
  if (!res.ok) throw new Error(`POST ${res.status}`);
  const { event_id } = await res.json();
  if (!event_id) throw new Error('No event_id');

  const stream  = await fetch(`${GRADIO_NEW}/${event_id}`);
  if (!stream.ok) throw new Error(`STREAM ${stream.status}`);

  const reader  = stream.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    if (buffer.includes('event: complete')) {
      const match = buffer.match(/event: complete[\s\S]*?data: (\[[\s\S]*?\])/);
      if (match) return parseOutput(JSON.parse(match[1])[0]);
    }
  }
  throw new Error('Stream ended without result');
}

async function callGradioOld(text) {
  const res = await fetch(GRADIO_OLD, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ data: [text] })
  });
  if (!res.ok) throw new Error(`OLD ${res.status}`);
  const result = await res.json();
  return parseOutput(result.data[0]);
}

// ── Core scan ─────────────────────────────────────────────────────────────
async function doScan(emailText) {
  setLoading(true);
  try {
    let result;
    try { result = await callGradioNew(emailText); }
    catch { result = await callGradioOld(emailText); }

    hasScanned = true;
    setLoading(false);
    showResult(result.label, result.confidence, result.reason);
  } catch (err) {
    setLoading(false);
    showError(err.message.includes('fetch')
      ? 'Cannot connect to HuggingFace Space. Make sure the Space is running.'
      : err.message);
  }
  document.getElementById('scanBtn').onclick = scanEmail;
}

// ── Entry point ───────────────────────────────────────────────────────────
async function scanEmail() {
  setLoading(true);
  hideAll();

  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  if (!tab.url?.includes('mail.google.com')) {
    setLoading(false);
    showError('Please open Gmail and click an email before scanning.');
    return;
  }

  let emailText;
  try {
    const resp = await chrome.tabs.sendMessage(tab.id, { action: 'getEmailText' });
    if (!resp.success) {
      setLoading(false);
      showError(resp.error || 'No email found. Click on an email first.');
      return;
    }
    emailText = resp.text;
  } catch {
    setLoading(false);
    showError('Could not read email. Try refreshing Gmail and scan again.');
    return;
  }

  const ratio = thaiRatio(emailText);
  if (ratio > 0.3) {
    setLoading(false);
    showWarn(`This email contains ${Math.round(ratio * 100)}% Thai text. The model was trained on English only — results may be inaccurate.`);
    const btn = document.getElementById('scanBtn');
    btn.disabled = false;
    document.getElementById('btnLabel').textContent = 'Scan Anyway';
    btn.onclick = () => doScan(emailText);
    return;
  }

  await doScan(emailText);
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('scanBtn').addEventListener('click', scanEmail);
});
