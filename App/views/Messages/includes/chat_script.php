<?php

/**
 * chat_script.php — sending a message, and drawing the ones that arrive.
 *
 * The surrounding shell brings its own sidebar and profile-menu script;
 * this is only the chat.
 *
 * Expects from the including page: $myId, $chatId, $activeUser, $acColor.
 */
?>
  <div class="toast" id="toast"></div>

  <script>
    const MY_ID = <?= (int)$myId ?>;
    const CHAT_ID = <?= (int)$chatId ?>;
    const CSRF_TOKEN = "<?= csrf_token() ?>";
    const CHAT_COLOR = '<?= $acColor ?>';
    const CHAT_NAME = <?= json_encode($activeUser['name'] ?? '') ?>;

    function initials(name) {
      const parts = name.trim().split(' ');
      return (parts[0][0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
    }

    function showToast(msg, ms = 2800) {
      const t = document.getElementById('toast');
      t.textContent = msg;
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), ms);
    }

    function scrollBottom() {
      const a = document.getElementById('msgsArea');
      if (a) a.scrollTop = a.scrollHeight;
    }

    const ta = document.getElementById('msgInput');
    if (ta) {
      ta.addEventListener('input', () => {
        ta.style.height = 'auto';
        ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
      });
      ta.addEventListener('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });
    }
    document.getElementById('sendBtn')?.addEventListener('click', sendMessage);

    let isSending = false;

    function sendMessage() {
      if (!CHAT_ID || isSending) return;
      const text = ta.value.trim();
      if (!text) return;
      isSending = true;
      document.getElementById('sendBtn').disabled = true;
      fetch('send_message.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
          },
          body: `receiver_id=${CHAT_ID}&message=${encodeURIComponent(text)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
        })
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            ta.value = '';
            ta.style.height = 'auto';
            // Drawn by the check for new messages rather than from this
            // answer. Sending can take a few seconds (an email may go out),
            // and the check every few seconds had often drawn the message
            // already, so it appeared twice. Fetching it the same way also
            // keeps a reply saved just before it, which used to be skipped.
            pollMessages(true);
          } else {
            // The endpoint says why (too long, too many, not allowed).
            showToast(data.error || 'Failed to send. Try again.');
          }
        })
        .catch(() => showToast('Network error.'))
        .finally(() => {
          isSending = false;
          const btn = document.getElementById('sendBtn');
          if (btn) btn.disabled = false;
          if (ta) ta.focus();
        });
    }

    // The last time label on the page, so a message in the same minute joins it.
    let lastRenderedTime = <?= json_encode($lastDate ?? '') ?>;

    // Every message drawn since the page loaded, by id: each is drawn once.
    const shownIds = new Set();

    // "10:36 pm", as the server writes it, read off the stored time itself so
    // a browser set to another time zone labels it the same.
    function timeLabel(when) {
      const m = /\b(\d{1,2}):(\d{2})/.exec(String(when || '').split(' ')[1] || '');
      const now = new Date();
      const h = m ? Number(m[1]) : now.getHours();
      const min = m ? m[2] : String(now.getMinutes()).padStart(2, '0');
      return (h % 12 || 12) + ':' + min + ' ' + (h < 12 ? 'am' : 'pm');
    }

    function appendMessage(msg) {
      const area = document.getElementById('msgsArea');
      if (!area) return;
      const id = Number(msg.id) || 0;
      if (id) {
        if (shownIds.has(id)) return;
        shownIds.add(id);
      }
      const isMe = msg.sender_id == MY_ID;
      const timeStr = timeLabel(msg.created_at);
      if (timeStr !== lastRenderedTime) {
        lastRenderedTime = timeStr;
        const lbl = document.createElement('div');
        lbl.className = 'time-label';
        lbl.textContent = timeStr;
        area.appendChild(lbl);
      }
      const row = document.createElement('div');
      row.className = `msg-row ${isMe ? 'me' : 'other'}`;
      if (!isMe) {
        const av = document.createElement('div');
        av.className = 'msg-avatar';
        av.style.cssText = `width:26px;height:26px;font-size:9px;background:${CHAT_COLOR}20;color:${CHAT_COLOR};margin-bottom:2px;`;
        av.textContent = initials(CHAT_NAME);
        row.appendChild(av);
      }
      const wrap = document.createElement('div');
      wrap.className = 'bubble-wrap';
      const bubble = document.createElement('div');
      bubble.className = 'bubble';
      bubble.textContent = msg.content;
      wrap.appendChild(bubble);
      if (isMe) {
        const meta = document.createElement('div');
        meta.className = 'bubble-meta';
        meta.innerHTML = msg.is_read
          ? '<svg class="read-tick" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2 12 5 5L18 6M9 17l1 1L21 7"/></svg>'
          : '<svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l5 5L20 7"/></svg>';
        wrap.appendChild(meta);
      }
      row.appendChild(wrap);
      area.appendChild(row);
      scrollBottom();
    }

    let lastMsgId = <?= !empty($initialMessages) ? end($initialMessages)['id'] : 0 ?>;
    let pollInFlight = false;
    let pollAgain = false;

    // Fetches and draws messages newer than the last one fetched. With
    // `soon`, a check that is already under way is followed by another as
    // soon as it ends: it may have left before the message just sent was saved.
    function pollMessages(soon = false) {
      if (!CHAT_ID) return;
      if (pollInFlight) {
        if (soon) pollAgain = true;
        return;
      }
      pollInFlight = true;
      fetch(`get_messages.php?chat_id=${CHAT_ID}&last_id=${lastMsgId}`)
        .then(r => r.json())
        .then(data => {
          if (data.messages?.length > 0) {
            data.messages.forEach(msg => {
              appendMessage(msg);
              lastMsgId = Math.max(lastMsgId, msg.id);
            });
          }
        })
        .catch(() => {})
        .finally(() => {
          pollInFlight = false;
          if (pollAgain) {
            pollAgain = false;
            pollMessages();
          }
        });
    }
    if (CHAT_ID) {
      scrollBottom();
      pollMessages();
      setInterval(() => pollMessages(), 3000);
      document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pollMessages();
      });
    }

    let convTab = 'all';

    function applyConvFilter() {
      const q = document.getElementById('searchInput').value.toLowerCase();
      document.querySelectorAll('.conv-item').forEach(item => {
        const matchesSearch = item.dataset.name.includes(q);
        const matchesTab = convTab === 'all' || item.dataset.unread === '1';
        item.style.display = (matchesSearch && matchesTab) ? '' : 'none';
      });
    }

    function setConvTab(tab) {
      convTab = tab;
      document.querySelectorAll('.msg-tab').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.tab === tab);
      });
      applyConvFilter();
    }

    document.getElementById('searchInput')?.addEventListener('input', applyConvFilter);
  </script>
