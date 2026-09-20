<?php

/**
 * chat_styles.php — the styling of the conversation list and the chat
 * itself. Both shells around it (the member one and the admin one) set
 * the colours it uses, so the same markup looks at home in either.
 */
?>
  <style>
    /* ── Messages layout ── */
    .msg-layout {
      display: grid;
      grid-template-columns: 320px 1fr;
      gap: 16px;
      height: calc(100vh - 160px);
      min-height: 0;
    }

    .chat-back-link {
      display: none;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border-radius: var(--radius-sm);
      color: var(--gray-500);
      flex-shrink: 0;
    }

    /* Mobile: show one panel at a time (list, or the open chat) instead of
       squeezing both into the same 320px-fixed grid. */
    @media (max-width: 860px) {
      .msg-layout {
        grid-template-columns: 1fr;
        height: calc(100vh - 200px);
      }

      .msg-layout .conv-panel,
      .msg-layout .chat-pane {
        display: flex;
      }

      .msg-layout.has-active-chat .conv-panel {
        display: none;
      }

      .msg-layout:not(.has-active-chat) .chat-pane {
        display: none;
      }

      .chat-back-link {
        display: inline-flex;
      }
    }

    /* Below 700px #sidebar becomes a fixed 68px bottom tab bar (see
       design_system.php) that the 860px calc() above doesn't leave room
       for — the composer ends up rendered underneath it. Subtract enough
       to clear the topbar + page heading + bottom tab bar. */
    @media (max-width: 700px) {
      .msg-layout {
        height: calc(100vh - 275px);
      }
    }

    /* ── Conversation panel ── */
    .conv-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .conv-header {
      padding: 16px 18px 12px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }

    .conv-list {
      overflow-y: auto;
      flex: 1;
    }

    .conv-item {
      display: flex;
      align-items: center;
      gap: 11px;
      padding: 12px 16px;
      cursor: pointer;
      transition: background .12s;
      text-decoration: none;
      color: inherit;
      border-bottom: 1px solid var(--border);
    }

    .conv-item:hover {
      background: var(--mint-faint);
    }

    .conv-item.active {
      background: var(--mint-faint);
      box-shadow: inset 3px 0 0 var(--mint);
    }

    .conv-unread-badge {
      min-width: 18px;
      height: 18px;
      padding: 0 5px;
      border-radius: 999px;
      background: var(--mint);
      color: #fff;
      font-size: 10.5px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    /* ── Search + tabs ── */
    .search-wrap {
      position: relative;
      margin-top: 10px;
    }

    .search-input {
      width: 100%;
      padding: 9px 12px 9px 32px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      font-size: 12.5px;
      background: var(--gray-50);
      outline: none;
      color: var(--gray-900);
      transition: border-color .14s, background .14s;
      font-family: inherit;
    }

    .search-input:focus {
      border-color: var(--mint);
      background: var(--surface);
    }

    .search-input::placeholder {
      color: var(--gray-400);
    }

    .msg-tabs {
      display: flex;
      gap: 6px;
      margin-top: 10px;
    }

    .msg-tab {
      flex: 1;
      text-align: center;
      padding: 7px 0;
      border-radius: var(--radius-sm);
      border: 1px solid transparent;
      background: none;
      color: var(--gray-500);
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
    }

    .msg-tab.active {
      background: var(--mint-faint);
      color: var(--forest);
      border-color: var(--mint-soft);
    }

    /* ── Chat pane ── */
    .chat-pane {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      display: flex;
      flex-direction: column;
      overflow: hidden;
    }

    .chat-header {
      padding: 14px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 12px;
      flex-shrink: 0;
    }

    /* ── Message bubbles ── */
    .msgs-area {
      flex: 1;
      overflow-y: auto;
      padding: 20px;
      display: flex;
      flex-direction: column;
      gap: 4px;
      scroll-behavior: smooth;
      background: var(--bg);
    }

    .msg-row {
      display: flex;
      align-items: flex-end;
      gap: 8px;
      animation: msgIn .2s ease;
    }

    .msg-row.me {
      justify-content: flex-end;
    }

    .msg-row.other {
      justify-content: flex-start;
    }

    @keyframes msgIn {
      from {
        opacity: 0;
        transform: translateY(6px) scale(.97);
      }

      to {
        opacity: 1;
        transform: none;
      }
    }

    .bubble-wrap {
      display: flex;
      flex-direction: column;
      max-width: 64%;
    }

    .msg-row.me .bubble-wrap {
      align-items: flex-end;
    }

    .bubble {
      padding: 10px 14px;
      border-radius: var(--radius-lg);
      font-size: 13px;
      line-height: 1.55;
      word-break: break-word;
    }

    .me .bubble {
      background: var(--forest);
      color: #fff;
      border-bottom-right-radius: 4px;
    }

    .other .bubble {
      background: var(--surface);
      color: var(--gray-900);
      border: 1px solid var(--border);
      border-bottom-left-radius: 4px;
    }

    .bubble-meta {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 10.5px;
      color: var(--gray-400);
      margin-top: 3px;
      padding: 0 3px;
    }

    .bubble-meta svg {
      width: 13px;
      height: 13px;
    }

    .read-tick {
      color: var(--mint);
    }

    .time-label {
      text-align: center;
      font-size: 11px;
      color: var(--gray-400);
      margin: 12px 0 4px;
    }

    /* ── Composer ── */
    .composer {
      padding: 14px 20px;
      border-top: 1px solid var(--border);
      display: flex;
      align-items: flex-end;
      gap: 10px;
      flex-shrink: 0;
    }

    .composer-input {
      flex: 1;
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 11px 16px;
      font-family: inherit;
      font-size: 13px;
      outline: none;
      resize: none;
      color: var(--gray-900);
      background: var(--gray-50);
      transition: border-color .14s, background .14s;
      max-height: 120px;
      overflow-y: auto;
      line-height: 1.45;
    }

    .composer-input:focus {
      border-color: var(--mint);
      background: var(--surface);
    }

    .composer-input::placeholder {
      color: var(--gray-400);
    }

    .send-btn {
      width: 40px;
      height: 40px;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      border-radius: 50%;
      background: var(--forest);
      color: #fff;
      border: none;
      cursor: pointer;
      transition: background .14s;
    }

    .send-btn:hover {
      background: var(--forest-2);
    }

    .send-btn:disabled {
      opacity: .6;
      cursor: default;
    }

    /* ── Toast ── */
    .toast {
      position: fixed;
      bottom: 28px;
      left: 50%;
      transform: translateX(-50%) translateY(12px);
      background: var(--forest);
      color: #fff;
      padding: 10px 20px;
      border-radius: var(--radius);
      font-size: 13px;
      opacity: 0;
      transition: opacity .2s, transform .2s;
      pointer-events: none;
      z-index: 999;
    }

    .toast.show {
      opacity: 1;
      transform: translateX(-50%) translateY(0);
    }

    .msg-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      flex-shrink: 0;
      overflow: hidden;
    }

    .msg-avatar img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
  </style>
