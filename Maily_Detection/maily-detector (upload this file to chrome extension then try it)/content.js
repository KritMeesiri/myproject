// content.js — runs inside mail.google.com
// Extracts the email body text from the open email in Gmail

function getGmailEmailText() {
  // Gmail renders the open email in a div with class "a3s aiL" or "ii gt"
  // We try multiple selectors to handle different Gmail layouts

  const selectors = [
    '.a3s.aiL',       // Main email body (most common)
    '.a3s.aXjCH',     // Alternative body class
    '.ii.gt .a3s',    // Nested inside thread container
    '[data-message-id] .a3s', // By message attribute
  ];

  let emailBody = null;
  for (const selector of selectors) {
    const el = document.querySelector(selector);
    if (el && el.innerText.trim().length > 0) {
      emailBody = el;
      break;
    }
  }

  if (!emailBody) {
    return null;
  }

  // Get subject line too for better context
  const subjectEl = document.querySelector('h2[data-thread-perm-id], .hP');
  const subject = subjectEl ? subjectEl.innerText.trim() : '';

  const body = emailBody.innerText.trim();
  const fullText = subject ? `Subject: ${subject}\n\n${body}` : body;

  // Truncate to ~2000 chars to avoid huge payloads (DistilBERT max 512 tokens anyway)
  return fullText.slice(0, 2000);
}

// Listen for messages from popup.js
chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
  if (request.action === 'getEmailText') {
    const text = getGmailEmailText();
    if (text) {
      sendResponse({ success: true, text: text });
    } else {
      sendResponse({
        success: false,
        error: 'ไม่พบ email ที่เปิดอยู่ กรุณาคลิกเปิด email ก่อนแล้วลอง Scan ใหม่'
      });
    }
  }
  return true; // Keep channel open for async
});
