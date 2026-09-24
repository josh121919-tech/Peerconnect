<?php
// message.php
date_default_timezone_set('Asia/Manila');
if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . "/../db.php";

// Signed in AND holding a real role. isset() alone let the role-less account
// through, because an empty string is still "set" — see the onboarding gap.
if (!isset($_SESSION['user_id'], $_SESSION['role'])
    || !in_array($_SESSION['role'], ['mentee', 'mentor', 'admin'], true)) {
  header("Location: " . url('welcomepage'));
  exit;
}

$myId    = (int)$_SESSION['user_id'];
$myRole  = $_SESSION['role'] ?? '';
$chatId  = is_string($_GET['chat'] ?? null) ? (int)$_GET['chat'] : 0;

// Conversations list — includes the other user's role/photo (for the avatar
// + role badge) and who sent the last message (for the "You: " prefix).
$conversations = MessageRepository::conversationsFor($con, $myId);

// Active user — plus expertise (mentors) / course (mentees) for the chat
// header's secondary line, real data pulled the same way other pages do.
$activeUser = null;
$refusal    = null;
if ($chatId) {
  $activeUser = MessageRepository::chatPartner($con, $chatId);
  MessageRepository::markReadFrom($con, $chatId, $myId);
  // The same rule the send endpoint applies, so the page never offers a
  // message box that every send from it would be refused.
  $refusal = $activeUser ? MessageService::refusal($con, $myId, $chatId) : null;
}

// Initial messages — includes is_read so my own sent bubbles can show a
// real (as-of-page-load) sent/read state instead of a fabricated one.
$initialMessages = $chatId ? MessageRepository::latestBetween($con, $myId, $chatId, 50) : [];

$colors = ['#F87171', '#FB923C', '#FACC15', '#4ADE80', '#60A5FA', '#C084FC', '#F472B6'];
function avatarColor(int $id): string
{
  global $colors;
  return $colors[$id % count($colors)];
}
function initials(string $name): string
{
  $parts = explode(' ', trim($name));
  return strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
function timeAgo(string $datetime): string
{
  $diff = time() - strtotime($datetime);
  if ($diff < 60) return 'Just now';
  if ($diff < 3600) return floor($diff / 60) . 'm ago';
  if ($diff < 86400) return date('g:i a', strtotime($datetime));
  if ($diff < 172800) return 'Yesterday';
  return date('M j', strtotime($datetime));
}

$acColor = $activeUser ? avatarColor($activeUser['id']) : '#087FC1';
// "View Profile" only has a real destination today when I'm a mentee
// looking at a mentor's conversation — there's no mentor-facing "view
// mentee profile" page yet, so the button is omitted rather than faked
// for the reverse direction.
$canViewProfile = $activeUser && $myRole === 'mentee' && $activeUser['role'] === 'mentor';
/*
 * Which shell goes around the chat. An admin keeps the admin sidebar and
 * topbar they have on every other admin page — before, Messages put them in
 * the member shell, whose sidebar lists a different, shorter menu. Members
 * keep theirs. The chat itself is the same markup either way.
 */
$active_page = 'messages';
if ($myRole === 'admin') {
  $current_page = 'messages';                  // highlights Messages in the admin sidebar
  include __DIR__ . '/../admin/layout.php';
?>
  <style>
    /* The chat sizes itself to the page, so only the two panels scroll. */
    main.flex-1 { overflow: hidden !important; display: flex; flex-direction: column; min-height: 0; }

    /* The heading the member shell styles for us, in the admin shell's type. */
    .page-hd { margin-bottom: 14px; flex-shrink: 0; }
    .page-hd h1 { margin: 0; font-size: 25px; font-weight: 700; letter-spacing: -.02em; color: var(--forest); }
    .page-hd p { margin: 2px 0 0; font-size: 13px; color: var(--gray-400); }

    .msg-layout { height: calc(100vh - 150px); }
  </style>
  <?php
  include __DIR__ . '/includes/chat_styles.php';
  include __DIR__ . '/includes/chat_body.php';
  include __DIR__ . '/includes/chat_script.php';
  include __DIR__ . '/../admin/admin_footer.php';
  return;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Messages — PeerConnect</title>
  <?php include __DIR__ . '/includes/style.php'; ?>
  <?php include __DIR__ . '/includes/chat_styles.php'; ?>
</head>

<body>
  <div class="app">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main fade-in">
      <?php // Shared with the admin shell. ?>
      <!-- Page header -->
      <div class="page-hd" style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
          <h1>Messages</h1>
          <p>Connect with your mentors and mentees.</p>
        </div>      </div>

      <!-- Messages layout -->
      <div class="msg-layout<?= $chatId ? ' has-active-chat' : '' ?>">

        <!-- Conversation panel -->
        <div class="conv-panel">
          <div class="conv-header">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <span style="font-size:14px;font-weight:700;color:var(--forest);">Conversations</span>
              <?php if ($myRole === 'mentee'): ?>
                <a href="<?= htmlspecialchars(url('mentee-find')) ?>" style="width:28px;height:28px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:center;color:var(--gray-500);" title="Start a new conversation — find a mentor">
                  <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="9" />
                    <path d="M12 8v8M8 12h8" />
                  </svg>
                </a>
              <?php endif; ?>
            </div>
            <div class="search-wrap">
              <svg style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);pointer-events:none;" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="7" />
                <path d="M21 21l-4.35-4.35" />
              </svg>
              <input class="search-input" id="searchInput" type="text" placeholder="Search conversations...">
            </div>
            <div class="msg-tabs">
              <button type="button" class="msg-tab active" data-tab="all" onclick="setConvTab('all')">All</button>
              <button type="button" class="msg-tab" data-tab="unread" onclick="setConvTab('unread')">Unread</button>
            </div>
          </div>

          <div class="conv-list" id="convList">
            <?php if (empty($conversations)): ?>
              <div style="padding:32px;text-align:center;font-size:13px;color:var(--gray-400);">No conversations yet.</div>
            <?php else: ?>
              <?php foreach ($conversations as $c):
                $isActive = $c['id'] == $chatId;
                $preview = ($c['last_sender_id'] == $myId ? 'You: ' : '') . ($c['last_msg'] ?? '');
              ?>
                <a href="?chat=<?= $c['id'] ?>" class="conv-item <?= $isActive ? 'active' : '' ?>" data-name="<?= htmlspecialchars(strtolower($c['name'])) ?>" data-unread="<?= (int)$c['unread'] > 0 ? 1 : 0 ?>">
                  <div class="msg-avatar" style="background:<?= avatarColor($c['id']) ?>20;color:<?= avatarColor($c['id']) ?>;">
                    <?php if (!empty($c['profile_image'])): ?>
                      <img src="<?= htmlspecialchars($c['profile_image']) ?>" alt="">
                    <?php else: ?>
                      <?= initials($c['name']) ?>
                    <?php endif; ?>
                  </div>
                  <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;font-weight:700;color:var(--gray-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($c['name']) ?></div>
                    <div style="font-size:11.5px;color:var(--gray-400);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;"><?= htmlspecialchars($preview) ?></div>
                  </div>
                  <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                    <span style="font-size:10px;color:var(--gray-400);"><?= timeAgo($c['last_time']) ?></span>
                    <?php if ($c['unread'] > 0): ?>
                      <span class="conv-unread-badge"><?= (int)$c['unread'] ?></span>
                    <?php endif; ?>
                  </div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Chat pane -->
        <div class="chat-pane">
          <?php if (!$activeUser): ?>
            <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;">
              <div style="width:56px;height:56px;border-radius:50%;background:var(--mint-faint);color:var(--forest);display:flex;align-items:center;justify-content:center;">
                <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v7A2.5 2.5 0 0 1 17.5 16H12l-5 4v-4h-.5A2.5 2.5 0 0 1 4 13.5v-7Z" /></svg>
              </div>
              <p style="font-weight:600;color:var(--gray-600);margin:0;">No conversation selected</p>
              <p style="font-size:12px;color:var(--gray-400);margin:0;">Pick a conversation from the left to start chatting.</p>
            </div>
          <?php else:
            $ac = $activeUser;
            $acColor = avatarColor($ac['id']);
            $roleLabel = $ac['role'] === 'mentor' ? 'Mentor' : ($ac['role'] === 'mentee' ? 'Mentee' : ucfirst($ac['role']));
            $subLabel = $ac['role'] === 'mentor' ? ($ac['expertise'] ?: '') : ($ac['course'] ?: '');
          ?>
            <!-- Chat header -->
            <div class="chat-header">
              <a href="<?= htmlspecialchars(url('messages')) ?>" class="chat-back-link" aria-label="Back to conversations">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6" /></svg>
              </a>
              <div class="msg-avatar" style="width:44px;height:44px;background:<?= $acColor ?>20;color:<?= $acColor ?>;">
                <?php if (!empty($ac['profile_image'])): ?>
                  <img src="<?= htmlspecialchars($ac['profile_image']) ?>" alt="">
                <?php else: ?>
                  <?= initials($ac['name']) ?>
                <?php endif; ?>
              </div>
              <div style="flex:1;min-width:0;">
                <div style="font-size:14px;font-weight:700;color:var(--gray-900);"><?= htmlspecialchars($ac['name']) ?></div>
                <div style="font-size:11.5px;color:var(--gray-500);margin-top:2px;">
                  <span style="color:var(--forest);font-weight:600;"><?= htmlspecialchars($roleLabel) ?></span><?= $subLabel ? ' · ' . htmlspecialchars($subLabel) : '' ?>
                </div>
              </div>
              <?php if ($canViewProfile): ?>
                <a href="<?= htmlspecialchars(url('mentee-view-mentor') . '?id=' . $ac['id']) ?>" class="btn btn-ghost" style="font-size:12.5px;padding:8px 14px;flex-shrink:0;">
                  <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M16 19c0-2.2-1.8-4-4-4s-4 1.8-4 4M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" /></svg>
                  View Profile
                </a>
              <?php endif; ?>
            </div>

            <!-- Messages area -->
            <div class="msgs-area" id="msgsArea">
              <?php
              $lastDate = '';
              foreach ($initialMessages as $msg):
                $isMe = ($msg['sender_id'] == $myId);
                $timeStr = date('g:i a', strtotime($msg['created_at']));
                if ($timeStr !== $lastDate) {
                  $lastDate = $timeStr; ?>
                  <div class="time-label"><?= $timeStr ?></div>
                <?php } ?>
                <div class="msg-row <?= $isMe ? 'me' : 'other' ?>">
                  <?php if (!$isMe): ?>
                    <div class="msg-avatar" style="width:26px;height:26px;font-size:9px;background:<?= $acColor ?>20;color:<?= $acColor ?>;margin-bottom:2px;">
                      <?= initials($ac['name']) ?>
                    </div>
                  <?php endif; ?>
                  <div class="bubble-wrap">
                    <div class="bubble"><?= htmlspecialchars($msg['content']) ?></div>
                    <?php if ($isMe): ?>
                      <div class="bubble-meta">
                        <?php if ((int)$msg['is_read'] === 1): ?>
                          <svg class="read-tick" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m2 12 5 5L18 6M9 17l1 1L21 7" /></svg>
                        <?php else: ?>
                          <svg fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l5 5L20 7" /></svg>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <!-- Composer -->
            <?php if ($refusal !== null): ?>
              <div class="composer" role="note" style="justify-content:center;font-size:12.5px;color:var(--gray-500);">
                <?= htmlspecialchars($refusal) ?>
              </div>
            <?php else: ?>
              <div class="composer">
                <textarea class="composer-input" id="msgInput" placeholder="Type a message..." rows="1" maxlength="<?= MessageService::MAX_LENGTH ?>"></textarea>
                <button id="sendBtn" title="Send" class="send-btn">
                  <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                    <line x1="22" y1="2" x2="11" y2="13" />
                    <polygon points="22 2 15 22 11 13 2 9 22 2" />
                  </svg>
                </button>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>

      </div><!-- /msg-layout -->
    </main>
  </div>

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

  <script>
    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('collapsed');
    }

    function toggleProfileMenu() {
      document.getElementById('profileMenu').classList.toggle('open');
    }
    document.addEventListener('click', function(e) {
      const m = document.getElementById('profileMenu');
      if (m && !e.target.closest('#profileMenu') && !e.target.closest('button[onclick="toggleProfileMenu()"]'))
        m.classList.remove('open');
    });
  </script>
</body>

</html>
