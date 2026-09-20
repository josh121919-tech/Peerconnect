<?php

/**
 * chat_body.php — the page heading, the conversation list and the chat
 * pane, as both the member page and the admin page show them.
 *
 * Expects from the including page: $chatId, $conversations, $activeUser,
 * $initialMessages, $myId, $refusal, $canViewProfile and $acColor.
 */
?>
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
