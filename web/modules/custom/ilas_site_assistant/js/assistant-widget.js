/**
 * @file
 * Aila Chat Widget
 *
 * Aila (eye-luh) is the Idaho Legal Aid Services chat assistant.
 * The name is derived from ILAS. Provides FAQ search, resource discovery,
 * and navigation assistance.
 *
 * Hardening (v2):
 * - AbortController fetch timeout (15 s default).
 * - Status-aware error handling: 429 (reads Retry-After), 403, 5xx, offline,
 *   timeout — each with user-facing recovery guidance.
 * - isSending guard prevents duplicate sends / race conditions.
 * - Focus trap lifecycle: single listener, removed on close, dynamic
 *   focusable query (handles elements added after open).
 * - Typing indicator has role="status" + aria-label for screen readers.
 * - sanitizeUrl blocks javascript: / data: / vbscript: schemes.
 * - escapeAttr for all attribute-context interpolations.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  // Enable for scroll-related debug logging.
  var SCROLL_DEBUG = false;

  /**
   * Shared Scroll Manager.
   *
   * Tracks whether the user is near the bottom of a scroll container and
   * conditionally auto-scrolls when new content arrives. Shows a "Jump to
   * latest" button when the user has scrolled away and new messages appear.
   *
   * @param {HTMLElement} container - The scrollable chat message list.
   * @param {number} threshold - Pixel distance from bottom considered "at bottom".
   */
  function ScrollManager(container, threshold) {
    this.container = container;
    this.threshold = threshold || 80;
    this.isAtBottom = true;
    this.hasNewContent = false;
    this.jumpBtn = null;
    this.sentinel = null;

    this._init();
  }

  ScrollManager.prototype = {
    _init: function () {
      // Create bottom sentinel.
      this.sentinel = document.createElement('div');
      this.sentinel.className = 'chat-bottom-sentinel';
      this.sentinel.setAttribute('aria-hidden', 'true');
      this.container.appendChild(this.sentinel);

      // Create jump-to-latest button.
      this.jumpBtn = document.createElement('button');
      this.jumpBtn.type = 'button';
      this.jumpBtn.className = 'chat-jump-btn';
      this.jumpBtn.setAttribute('aria-label', Drupal.t('Jump to latest messages'));
      this.jumpBtn.innerHTML = '<i class="fas fa-arrow-down" aria-hidden="true"></i> ' + Drupal.t('New messages');
      this.jumpBtn.hidden = true;

      // Insert button inside the chat container after the sentinel.
      // Uses position: sticky to float at the bottom of the visible area.
      this.container.appendChild(this.jumpBtn);

      // Live announcer for screen readers when jump button appears.
      this.liveAnnouncer = document.createElement('div');
      this.liveAnnouncer.className = 'visually-hidden';
      this.liveAnnouncer.setAttribute('role', 'status');
      this.liveAnnouncer.setAttribute('aria-live', 'polite');
      this.container.appendChild(this.liveAnnouncer);

      // Bind events.
      var self = this;
      this.container.addEventListener('scroll', function () {
        self._onScroll();
      }, { passive: true });

      this.jumpBtn.addEventListener('click', function () {
        self.scrollToBottom();
        self._hideJumpBtn();
      });
    },

    /**
     * Check if user is near the bottom.
     */
    _checkIsAtBottom: function () {
      var c = this.container;
      var distance = c.scrollHeight - c.scrollTop - c.clientHeight;
      this.isAtBottom = distance <= this.threshold;
      if (SCROLL_DEBUG) {
        console.log('[ScrollManager] distance=' + distance + ' isAtBottom=' + this.isAtBottom);
      }
      return this.isAtBottom;
    },

    /**
     * Handle user scroll events.
     */
    _onScroll: function () {
      this._checkIsAtBottom();
      if (this.isAtBottom) {
        this._hideJumpBtn();
        this.hasNewContent = false;
      }
    },

    /**
     * Call after appending a new message to the container.
     * Conditionally scrolls or shows the jump button.
     *
     * @param {HTMLElement} [messageEl] - The newly appended message element.
     *   Used to detect tall messages and scroll to their top instead of the
     *   container bottom.
     */
    onNewContent: function (messageEl) {
      // Use the cached isAtBottom from the last scroll event, which reflects
      // the state just before the append.
      if (this.isAtBottom) {
        this._scrollToBottomRaf(messageEl || null);
      } else {
        this.hasNewContent = true;
        this._showJumpBtn();
      }
    },

    /**
     * Scroll container to bottom using requestAnimationFrame to wait for layout.
     *
     * When a message element is provided and is taller than the visible area,
     * scrolls to the top of that message so the user sees the beginning.
     * Otherwise scrolls to the container bottom as usual.
     *
     * @param {HTMLElement|null} messageEl - The newly appended message element.
     */
    _scrollToBottomRaf: function (messageEl) {
      var self = this;
      requestAnimationFrame(function () {
        if (messageEl && messageEl.offsetHeight > self.container.clientHeight) {
          // Message is taller than the visible area: scroll to show its
          // beginning so the user can start reading from the top.
          var rect = messageEl.getBoundingClientRect();
          var containerRect = self.container.getBoundingClientRect();
          self.container.scrollTop += rect.top - containerRect.top;
          if (SCROLL_DEBUG) {
            console.log('[ScrollManager] scrolled to top of tall message');
          }
        } else {
          // Message fits within viewport: scroll to bottom as usual.
          self.container.scrollTop = self.container.scrollHeight;
          if (SCROLL_DEBUG) {
            console.log('[ScrollManager] auto-scrolled to bottom');
          }
        }
        // Re-evaluate position after programmatic scroll.
        self._checkIsAtBottom();
      });
    },

    /**
     * Programmatic scroll to bottom (e.g., for jump button click).
     */
    scrollToBottom: function () {
      this.container.scrollTop = this.container.scrollHeight;
      this.isAtBottom = true;
      this.hasNewContent = false;
    },

    _showJumpBtn: function () {
      this.jumpBtn.hidden = false;
      this.liveAnnouncer.textContent = Drupal.t('New messages available.');
    },

    _hideJumpBtn: function () {
      this.jumpBtn.hidden = true;
      this.liveAnnouncer.textContent = '';
    },
  };

  /**
   * Safely focus an element without causing scroll jumps.
   */
  function safeFocus(el) {
    if (!el) return;
    try {
      el.focus({ preventScroll: true });
    } catch (e) {
      el.focus();
    }
  }

  /**
   * Aila Chat Widget Manager
   */
  const SiteAssistant = {
    config: null,
    widget: null,
    chatContainer: null,
    inputField: null,
    scrollManager: null,
    isOpen: false,
    isPageMode: false,
    isSending: false,
    messageHistory: [],
    conversationId: null,
    lastResponseRequestId: null,
    activeSelection: null,
    csrfTokenPromise: null,
    _displayMessages: [],
    _focusTrapHandler: null,
    _focusTrapElement: null,

    /**
     * Generate an ephemeral conversation ID (UUID v4).
     *
     * Lives only in this browser tab's JS state. Not persisted.
     */
    generateConversationId: function () {
      if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
      }
      // Fallback for older browsers.
      return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = Math.random() * 16 | 0;
        return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
      });
    },

    /**
     * Returns true when the value is a UUID v4 string.
     */
    isUuidV4: function (value) {
      return typeof value === 'string'
        && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(value);
    },

    /**
     * Normalize a chat message into the structured v3 display contract.
     *
     * @param {string} sender - user|assistant.
     * @param {string|Object} payload - Raw text or structured message payload.
     * @return {Object|null} Normalized message object or null.
     */
    normalizeDisplayMessage: function (sender, payload) {
      var role = sender === 'assistant' ? 'assistant' : 'user';

      if (typeof payload === 'string') {
        return {
          role: role,
          kind: 'text',
          text: payload,
        };
      }

      if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
        return null;
      }

      if (payload.kind === 'text') {
        return {
          role: role,
          kind: 'text',
          text: typeof payload.text === 'string' ? payload.text : '',
        };
      }

      if (role === 'assistant' && payload.kind === 'response') {
        return {
          role: role,
          kind: 'response',
          response: this.snapshotAssistantResponse(payload.response || {}),
        };
      }

      if (role === 'assistant' && payload.kind === 'recovery') {
        return {
          role: role,
          kind: 'recovery',
          errorCode: typeof payload.errorCode === 'string' ? payload.errorCode : '',
          lastMessageText: typeof payload.lastMessageText === 'string' ? payload.lastMessageText : '',
        };
      }

      return null;
    },

    /**
     * Clone the subset of response data needed to restore assistant turns.
     *
     * @param {Object} response - Raw response object.
     * @return {Object} JSON-safe response snapshot.
     */
    snapshotAssistantResponse: function (response) {
      var allowedKeys = [
        'type',
        'message',
        'results',
        'fallback_url',
        'fallback_label',
        'disclaimer',
        'caveat',
        'url',
        'cta',
        'topic',
        'service_area_url',
        'links',
        'actions',
        'service_areas',
        'suggestions',
        'topic_suggestions',
        'primary_action',
        'followup',
        'apply_methods',
        'text_fallback'
      ];
      var snapshot = {};

      if (!response || typeof response !== 'object') {
        return snapshot;
      }

      allowedKeys.forEach(function (key) {
        if (Object.prototype.hasOwnProperty.call(response, key)) {
          snapshot[key] = response[key];
        }
      });

      try {
        return JSON.parse(JSON.stringify(snapshot));
      } catch (e) {
        return {
          type: typeof snapshot.type === 'string' ? snapshot.type : '',
          message: typeof snapshot.message === 'string' ? snapshot.message : '',
        };
      }
    },

    /**
     * Convert legacy rich assistant HTML into a readable text fallback.
     *
     * @param {string} html - Legacy stored assistant markup.
     * @return {string} Human-readable fallback text.
     */
    extractLegacyAssistantText: function (html) {
      var input = String(html || '');
      var output = '';
      var suppressedTag = '';
      var whitespaceChars = ' \t\r\n\f';
      var tagNameChars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789:-';

      function isWhitespace(char) {
        return whitespaceChars.indexOf(char) !== -1;
      }

      function isClosingTag(tagText) {
        var index = 0;
        while (index < tagText.length && isWhitespace(tagText.charAt(index))) {
          index++;
        }
        return tagText.charAt(index) === '/';
      }

      function readTagName(tagText) {
        var index = 0;
        while (index < tagText.length && isWhitespace(tagText.charAt(index))) {
          index++;
        }
        if (tagText.charAt(index) === '/') {
          index++;
        }
        while (index < tagText.length && isWhitespace(tagText.charAt(index))) {
          index++;
        }

        var start = index;
        while (index < tagText.length && tagNameChars.indexOf(tagText.charAt(index)) !== -1) {
          index++;
        }

        return tagText.slice(start, index).toLowerCase();
      }

      function appendSpace() {
        if (output && output.charAt(output.length - 1) !== ' ') {
          output += ' ';
        }
      }

      for (var i = 0; i < input.length; i++) {
        if (input.charAt(i) === '<') {
          var tagEnd = i + 1;
          var quote = '';

          while (tagEnd < input.length) {
            var tagChar = input.charAt(tagEnd);
            if (quote) {
              if (tagChar === quote) {
                quote = '';
              }
            } else if (tagChar === '"' || tagChar === '\'') {
              quote = tagChar;
            } else if (tagChar === '>') {
              break;
            }
            tagEnd++;
          }

          if (tagEnd >= input.length) {
            break;
          }

          var tagText = input.slice(i + 1, tagEnd);
          var tagName = readTagName(tagText);
          var closingTag = isClosingTag(tagText);

          if (suppressedTag) {
            if (closingTag && tagName === suppressedTag) {
              suppressedTag = '';
            }
          } else if (tagName === 'script' || tagName === 'style') {
            if (!closingTag) {
              suppressedTag = tagName;
            }
          } else {
            appendSpace();
          }

          i = tagEnd;
          continue;
        }

        if (!suppressedTag) {
          output += input.charAt(i);
        }
      }

      var text = output
        .replace(/&nbsp;|&#160;/gi, ' ')
        .replace(/\s+/g, ' ')
        .trim();

      if (text) {
        return text;
      }

      return Drupal.t('Previous assistant response restored as text.');
    },

    /**
     * Normalize stored messages from schema v1/v2/v3.
     *
     * @param {Object} message - Persisted message entry.
     * @return {Object|null} Normalized v3 message or null.
     */
    migrateStoredDisplayMessage: function (message) {
      if (!message || typeof message !== 'object') {
        return null;
      }

      var role = message.role === 'assistant' ? 'assistant' : (message.role === 'user' ? 'user' : '');
      if (!role) {
        return null;
      }

      if (typeof message.kind === 'string') {
        return this.normalizeDisplayMessage(role, message);
      }

      if (typeof message.content === 'string') {
        if (role === 'assistant' && message.isHtml) {
          return this.normalizeDisplayMessage(role, {
            kind: 'text',
            text: this.extractLegacyAssistantText(message.content),
          });
        }

        return this.normalizeDisplayMessage(role, {
          kind: 'text',
          text: message.content,
        });
      }

      return null;
    },

    /**
     * Persist a normalized display message and enforce the history cap.
     *
     * @param {Object} message - Normalized v3 message payload.
     */
    storeDisplayMessage: function (message) {
      if (!this._displayMessages || !message) {
        return;
      }

      this._displayMessages.push(message);
      if (this._displayMessages.length > 50) {
        this._displayMessages = this._displayMessages.slice(-50);
      }
      this.saveState();
    },

    /**
     * Save widget state to sessionStorage for cross-navigation persistence.
     *
     * Stores conversation ID, display messages, and panel open/close state.
     * Wrapped in try/catch for private browsing and quota-exceeded safety.
     */
    saveState: function () {
      try {
        var state = {
          v: 3,
          conversationId: this.conversationId,
          lastResponseRequestId: this.lastResponseRequestId,
          activeSelection: this.activeSelection,
          messages: this._displayMessages || [],
          isOpen: this.isOpen,
          savedAt: Date.now()
        };
        sessionStorage.setItem('ilas_assistant_state', JSON.stringify(state));
      } catch (e) {
        // sessionStorage unavailable (private browsing, quota exceeded).
      }
    },

    /**
     * Load and validate stored widget state from sessionStorage.
     *
     * Returns the parsed state object or null if missing, stale (>30 min),
     * corrupt, or schema-incompatible. Removes stale entries on detection.
     *
     * @return {Object|null} Validated state or null.
     */
    loadState: function () {
      try {
        var raw = sessionStorage.getItem('ilas_assistant_state');
        if (!raw) return null;

        var state = JSON.parse(raw);

        // Schema version check.
        if (!state || (state.v !== 1 && state.v !== 2 && state.v !== 3)) return null;

        // Staleness check: 30 minutes (matches server CONVERSATION_STATE_TTL).
        if (!state.savedAt || (Date.now() - state.savedAt) > 1800000) {
          sessionStorage.removeItem('ilas_assistant_state');
          return null;
        }

        // Validate conversationId is UUID v4.
        if (!state.conversationId || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i.test(state.conversationId)) {
          return null;
        }

        // Validate messages is an array.
        if (!Array.isArray(state.messages)) return null;
        state.messages = state.messages
          .map(this.migrateStoredDisplayMessage.bind(this))
          .filter(function (message) {
            return !!message;
          });
        state.v = 3;

        if (state.lastResponseRequestId && !this.isUuidV4(state.lastResponseRequestId)) {
          state.lastResponseRequestId = null;
        }

        if (state.activeSelection) {
          state.activeSelection = this.normalizeSelection(state.activeSelection);
        } else {
          state.activeSelection = null;
        }

        return state;
      } catch (e) {
        return null;
      }
    },

    /**
     * Remove stored widget state from sessionStorage.
     *
     * Called on session expiry to prevent restoring a stale conversation.
     */
    clearState: function () {
      try {
        sessionStorage.removeItem('ilas_assistant_state');
      } catch (e) {
        // Ignore.
      }
    },

    /**
     * Replay saved messages into the DOM from a restored state.
     *
     * Uses addMessage() which handles DOM creation, scroll management,
     * event rebinding on suggestion/recovery buttons, and messageHistory
     * rebuild for user messages. Feedback thumbs are omitted because the
     * original request IDs are no longer available.
     *
     * @param {Array} messages - Array of structured v3 display messages.
     */
    restoreMessages: function (messages) {
      var maxRestore = 50;
      var count = 0;
      for (var i = 0; i < messages.length && count < maxRestore; i++) {
        var msg = this.migrateStoredDisplayMessage(messages[i]);
        if (!msg) continue;

        this.addMessage(msg.role, msg);
        count++;
      }
    },

    /**
     * Fetch a session-bound CSRF token from assistant bootstrap endpoint.
     *
     * Called lazily before the first POST to /message. Caches the promise
     * so concurrent calls share a single fetch. Pass forceRefresh=true
     * to discard the cache (e.g. after a 403 retry).
     *
     * @param {boolean} forceRefresh - Discard cached token.
     * @return {Promise<string>} Resolves with the CSRF token string.
     */
    fetchCsrfToken: function (forceRefresh) {
      if (this.csrfTokenPromise && !forceRefresh) {
        return this.csrfTokenPromise;
      }

      var self = this;
      var bootstrapUrl = ((this.config && this.config.apiBase) || '/assistant/api') + '/session/bootstrap';
      this.csrfTokenPromise = fetch(bootstrapUrl, {
        method: 'GET',
        credentials: 'same-origin',
      })
        .then(function (response) {
          if (!response.ok) {
            var error = new Error('Failed to fetch CSRF token: ' + response.status);
            error.status = response.status;
            error.retryAfter = response.headers.get('Retry-After');
            throw error;
          }
          return response.text();
        })
        .then(function (token) {
          self.config.csrfToken = token;
          return token;
        })
        .catch(function (error) {
          self.csrfTokenPromise = null;
          throw error;
        });

      return this.csrfTokenPromise;
    },

    /**
     * Initialize the assistant.
     */
    init: function (config) {
      this.config = config;
      this.isPageMode = config.pageMode || false;
      this._displayMessages = [];

      // Attempt to restore previous conversation from sessionStorage.
      var restored = this.loadState();

      if (restored) {
        this.conversationId = restored.conversationId;
        this.lastResponseRequestId = restored.lastResponseRequestId || null;
        this.activeSelection = restored.activeSelection || null;
      } else {
        this.conversationId = this.generateConversationId();
        this.lastResponseRequestId = null;
        this.activeSelection = null;
      }

      if (this.isPageMode) {
        this.initPageMode();
      } else {
        this.initWidgetMode();
      }

      this.bindEvents();

      if (restored && restored.messages.length > 0) {
        this.restoreMessages(restored.messages);
        // Restore panel open state for widget mode.
        if (!this.isPageMode && restored.isOpen) {
          this.openPanel();
        }
      } else {
        this.showWelcomeMessage();
      }
    },

    /**
     * Initialize page mode (dedicated /assistant page).
     */
    initPageMode: function () {
      this.chatContainer = document.getElementById('assistant-chat');
      this.inputField = document.getElementById('assistant-input');

      if (!this.chatContainer || !this.inputField) {
        console.error('ILAS Assistant: Page elements not found');
        return;
      }

      // Initialize scroll manager (60px threshold for page mode).
      this.scrollManager = new ScrollManager(this.chatContainer, 60);

      // Focus input on page load without scrolling.
      safeFocus(this.inputField);
    },

    /**
     * Initialize widget mode (floating button).
     */
    initWidgetMode: function () {
      this.createWidget();
    },

    /**
     * Create the floating widget HTML.
     */
    createWidget: function () {
      // Check if widget already exists.
      if (document.getElementById('ilas-assistant-widget-container')) {
        return;
      }

      const container = document.createElement('div');
      container.id = 'ilas-assistant-widget-container';
      container.className = 'ilas-assistant-widget-container';
      container.innerHTML = this.getWidgetHTML();

      document.body.appendChild(container);

      this.widget = container;
      this.chatContainer = container.querySelector('.assistant-chat');
      this.inputField = container.querySelector('.assistant-input');

      // Initialize scroll manager (80px threshold for widget).
      this.scrollManager = new ScrollManager(this.chatContainer, 80);

      // Bind widget-specific events.
      this.bindWidgetEvents();
    },

    /**
     * Get widget HTML template.
     */
    getWidgetHTML: function () {
      var hotlineUrl = this.sanitizeUrl(this.config.canonicalUrls.hotline);
      var applyUrl = this.sanitizeUrl(this.config.canonicalUrls.apply);
      var disclaimer = this.config.disclaimer
        ? this.escapeHtml(this.config.disclaimer)
        : this.escapeHtml(Drupal.t('I search our website for you — I\'m not a lawyer and can\'t give legal advice.'));

      return `
        <button type="button"
                class="assistant-toggle-btn"
                id="assistant-toggle"
                aria-label="${Drupal.t('Open Aila Chat')}"
                aria-expanded="false"
                aria-controls="assistant-panel">
          <span class="toggle-icon-open"><i class="fas fa-comment-dots" aria-hidden="true"></i></span>
          <span class="toggle-icon-close"><i class="fas fa-times" aria-hidden="true"></i></span>
          <span class="toggle-label">${Drupal.t('Chat')}</span>
        </button>

        <div class="assistant-panel"
             id="assistant-panel"
             role="dialog"
             aria-modal="true"
             aria-label="${Drupal.t('Aila Chat')}"
             hidden>
          <header class="assistant-panel-header">
            <h2 class="panel-title">
              <i class="fas fa-comment-dots" aria-hidden="true"></i>
              ${Drupal.t('Chat')}
            </h2>
            <button type="button"
                    class="panel-close-btn"
                    aria-label="${Drupal.t('Close chat')}">
              <i class="fas fa-times" aria-hidden="true"></i>
            </button>
          </header>

          <div class="assistant-disclaimer">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <span>${disclaimer}</span>
          </div>

          <div class="assistant-chat" role="log" aria-live="polite" aria-atomic="false"></div>

          <div class="assistant-quick-actions">
            <button type="button" class="quick-action-btn" data-action="forms"
                    aria-label="${this.escapeAttr(Drupal.t('Search forms'))}">
              <i class="fas fa-file-alt" aria-hidden="true"></i> ${Drupal.t('Forms')}
            </button>
            <button type="button" class="quick-action-btn" data-action="guides"
                    aria-label="${this.escapeAttr(Drupal.t('Browse guides'))}">
              <i class="fas fa-book" aria-hidden="true"></i> ${Drupal.t('Guides')}
            </button>
            <button type="button" class="quick-action-btn" data-action="faq"
                    aria-label="${this.escapeAttr(Drupal.t('View FAQs'))}">
              <i class="fas fa-question-circle" aria-hidden="true"></i> ${Drupal.t('FAQs')}
            </button>
            <button type="button" class="quick-action-btn" data-action="apply"
                    aria-label="${this.escapeAttr(Drupal.t('Apply for help'))}">
              <i class="fas fa-hands-helping" aria-hidden="true"></i> ${Drupal.t('Apply')}
            </button>
          </div>

          <form class="assistant-input-form">
            <input type="text"
                   class="assistant-input"
                   placeholder="${Drupal.t('Type your question...')}"
                   autocomplete="off"
                   maxlength="500"
                   aria-describedby="widget-input-hint">
            <span id="widget-input-hint" class="visually-hidden">${Drupal.t('Press Enter to send your message')}</span>
            <button type="submit" class="assistant-send-btn" aria-label="${Drupal.t('Send')}">
              <i class="fas fa-paper-plane" aria-hidden="true"></i>
            </button>
          </form>

          <footer class="assistant-panel-footer">
            <a href="${this.escapeAttr(hotlineUrl)}" class="footer-link" data-assistant-track="hotline_click">
              ${Drupal.t('Call Hotline')}
            </a>
            <span class="footer-divider">|</span>
            <a href="${this.escapeAttr(applyUrl)}" class="footer-link" data-assistant-track="apply_click">
              ${Drupal.t('Apply for Help')}
            </a>
          </footer>
        </div>
      `;
    },

    /**
     * Bind widget-specific events.
     */
    bindWidgetEvents: function () {
      const toggle = this.widget.querySelector('#assistant-toggle');
      const closeBtn = this.widget.querySelector('.panel-close-btn');

      toggle.addEventListener('click', () => this.togglePanel());
      closeBtn.addEventListener('click', () => this.closePanel());

      // Close on Escape key.
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.isOpen) {
          this.closePanel();
        }
      });

      // Quick action buttons.
      this.widget.querySelectorAll('.quick-action-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const button = e.currentTarget;
          const action = button.dataset.action;
          this.handleQuickAction(action, this.extractSelectionFromButton(button), this.getButtonDisplayLabel(button));
        });
      });
    },

    /**
     * Bind common events (page and widget mode).
     */
    bindEvents: function () {
      // Form submission.
      const forms = document.querySelectorAll('.assistant-input-form');
      forms.forEach(form => {
        form.addEventListener('submit', (e) => {
          e.preventDefault();
          this.sendMessage();
        });
      });

      // Suggestion buttons (page mode).
      document.querySelectorAll('.suggestion-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const button = e.currentTarget;
          const action = button.dataset.action;
          const url = button.dataset.url;
          if (url) {
            this.trackClick(action + '_click', url);
            window.location.href = url;
          } else {
            this.handleQuickAction(action, this.extractSelectionFromButton(button), this.getButtonDisplayLabel(button));
          }
        });
      });

      // Track footer link clicks.
      document.querySelectorAll('[data-assistant-track]').forEach(link => {
        link.addEventListener('click', (e) => {
          const trackType = e.currentTarget.dataset.assistantTrack;
          this.trackClick(trackType, e.currentTarget.href);
        });
      });
    },

    /**
     * Toggle the panel open/closed.
     */
    togglePanel: function () {
      if (this.isOpen) {
        this.closePanel();
      } else {
        this.openPanel();
      }
    },

    /**
     * Open the panel.
     */
    openPanel: function () {
      const toggle = this.widget.querySelector('#assistant-toggle');
      const panel = this.widget.querySelector('#assistant-panel');

      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      this.widget.classList.add('is-open');
      this.isOpen = true;
      this.saveState();

      // Warn if offline on open.
      if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        this.addMessage('assistant', Drupal.t('You appear to be offline. Please check your connection.'));
      }

      // Focus input without causing scroll jumps.
      setTimeout(() => {
        safeFocus(this.inputField);
      }, 100);

      // Track open event.
      this.trackEvent('chat_open');

      // Create focus trap.
      this.createFocusTrap(panel);
    },

    /**
     * Close the panel.
     */
    closePanel: function () {
      const toggle = this.widget.querySelector('#assistant-toggle');
      const panel = this.widget.querySelector('#assistant-panel');

      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      this.widget.classList.remove('is-open');
      this.isOpen = false;
      this.saveState();

      // Remove focus trap to prevent listener accumulation.
      this.destroyFocusTrap();

      // Return focus to toggle.
      toggle.focus();
    },

    /**
     * Create a focus trap within an element.
     *
     * Queries focusable elements dynamically on each Tab press so that
     * elements added after the panel opens (e.g. suggestion buttons in
     * new messages) are included in the trap.
     *
     * Only one trap listener exists at a time — destroyFocusTrap removes it.
     */
    createFocusTrap: function (element) {
      // Remove any existing trap first to prevent accumulation.
      this.destroyFocusTrap();

      this._focusTrapElement = element;
      this._focusTrapHandler = function (e) {
        if (e.key !== 'Tab') return;

        // Query focusable elements dynamically each time.
        var focusables = element.querySelectorAll(
          'button:not([disabled]):not([hidden]), [href]:not([hidden]), input:not([disabled]):not([hidden]), select:not([disabled]):not([hidden]), textarea:not([disabled]):not([hidden]), [tabindex]:not([tabindex="-1"]):not([hidden])'
        );

        if (focusables.length === 0) return;

        var first = focusables[0];
        var last = focusables[focusables.length - 1];

        if (e.shiftKey) {
          if (document.activeElement === first) {
            last.focus();
            e.preventDefault();
          }
        } else {
          if (document.activeElement === last) {
            first.focus();
            e.preventDefault();
          }
        }
      };

      element.addEventListener('keydown', this._focusTrapHandler);
    },

    /**
     * Remove the focus trap listener.
     */
    destroyFocusTrap: function () {
      if (this._focusTrapHandler && this._focusTrapElement) {
        this._focusTrapElement.removeEventListener('keydown', this._focusTrapHandler);
      }
      this._focusTrapHandler = null;
      this._focusTrapElement = null;
    },

    /**
     * Show welcome message.
     */
    showWelcomeMessage: function () {
      const message = this.config.welcomeMessage || Drupal.t('Hi, I\'m Aila (eye-luh). I can help you find forms, guides, and answers on our website. What are you looking for?');
      this.addMessage('assistant', message);
    },

    /**
     * Send a message to the API.
     *
     * Guarded by isSending to prevent duplicate sends and race conditions.
     */
    sendMessage: function () {
      if (this.isSending) return;

      const input = this.isPageMode
        ? document.getElementById('assistant-input')
        : this.widget.querySelector('.assistant-input');

      const message = input.value.trim();
      if (!message) return;

      // Lock sending state.
      this.isSending = true;
      this.setSendingState(true);

      // Add user message to chat.
      this.addMessage('user', message);

      // Clear input.
      input.value = '';

      // Show typing indicator.
      this.showTyping();

      var self = this;

      // Send to API.
      this.callApi('/message', {
        method: 'POST',
        body: JSON.stringify({
          message: message,
          conversation_id: this.conversationId,
        }),
      })
        .then(function (response) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.handleResponse(response);
          if (self.isOpen || self.isPageMode) {
            safeFocus(self.inputField);
          }
        })
        .catch(function (error) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.emitAssistantError(error, 'message_send', error && error.status >= 500);
          if (error.status === 403) {
            self.addRecoveryMessage(error, message);
          } else {
            self.addMessage('assistant', self.getErrorMessage(error));
            if (self.isOpen || self.isPageMode) {
              safeFocus(self.inputField);
            }
          }
          console.error('ILAS Assistant API error:', error);
        });
    },

    /**
     * Normalize a structured selection payload for request/session use.
     */
    normalizeSelection: function (selection) {
      if (!selection || typeof selection !== 'object') {
        return null;
      }

      var buttonId = String(selection.button_id || '').trim();
      var label = String(selection.label || '').trim();
      var parentButtonId = String(selection.parent_button_id || '').trim();
      var source = String(selection.source || '').trim();

      if (!buttonId || !label || !source) {
        return null;
      }

      return {
        button_id: buttonId,
        label: label,
        parent_button_id: parentButtonId,
        source: source
      };
    },

    /**
     * Return the human-visible label from a button element.
     */
    getButtonDisplayLabel: function (button) {
      if (!button || typeof button.textContent !== 'string') {
        return '';
      }
      return button.textContent.replace(/\s+/g, ' ').trim();
    },

    /**
     * Extract a normalized selection payload from a rendered button.
     */
    extractSelectionFromButton: function (button) {
      if (!button) {
        return null;
      }

      return this.normalizeSelection({
        button_id: button.dataset.selectionButtonId || button.dataset.action || '',
        label: button.dataset.selectionLabel || this.getButtonDisplayLabel(button),
        parent_button_id: button.dataset.selectionParentId || '',
        source: button.dataset.selectionSource || 'widget_button'
      });
    },

    /**
     * Render HTML data attributes for a selection payload.
     */
    renderSelectionDataAttrs: function (selection, fallbackAction, fallbackLabel) {
      var normalized = this.normalizeSelection(selection) || this.normalizeSelection({
        button_id: fallbackAction || '',
        label: fallbackLabel || '',
        parent_button_id: '',
        source: 'widget_fallback'
      });

      if (!normalized) {
        return '';
      }

      return ' data-selection-button-id="' + this.escapeAttr(normalized.button_id) + '"'
        + ' data-selection-label="' + this.escapeAttr(normalized.label) + '"'
        + ' data-selection-parent-id="' + this.escapeAttr(normalized.parent_button_id) + '"'
        + ' data-selection-source="' + this.escapeAttr(normalized.source) + '"';
    },

    /**
     * Infer the active branch from a response when the server omits it.
     */
    inferActiveSelectionFromResponse: function (response) {
      if (!response || !Array.isArray(response.topic_suggestions)) {
        return null;
      }

      var parentIds = {};
      response.topic_suggestions.forEach(function (suggestion) {
        if (!suggestion || !suggestion.selection || typeof suggestion.selection !== 'object') {
          return;
        }
        var parentId = String(suggestion.selection.parent_button_id || '').trim();
        if (parentId) {
          parentIds[parentId] = true;
        }
      });

      var keys = Object.keys(parentIds);
      if (keys.length !== 1) {
        return null;
      }

      var labelMap = {
        forms: Drupal.t('Forms'),
        guides: Drupal.t('Guides'),
        topics: Drupal.t('Services')
      };

      return this.normalizeSelection({
        button_id: keys[0],
        label: labelMap[keys[0]] || keys[0],
        parent_button_id: '',
        source: 'response_menu'
      });
    },

    /**
     * Handle quick action buttons.
     */
    handleQuickAction: function (action, selection, displayLabel) {
      if (this.isSending) return;

      // Messages must match IntentRouter patterns.
      const actionMessages = {
        forms: Drupal.t('Find a form'),
        guides: Drupal.t('Find a guide'),
        faq: Drupal.t('Show me FAQs'),
        apply: Drupal.t('Apply for help'),
        hotline: Drupal.t('Call the hotline'),
        topics: Drupal.t('What services do you offer?'),
        // Form Finder topic actions.
        forms_housing: Drupal.t('Find housing and eviction forms'),
        forms_family: Drupal.t('Find family and divorce forms'),
        forms_consumer: Drupal.t('Find consumer and debt forms'),
        forms_seniors: Drupal.t('Find senior and estate planning forms'),
        forms_safety: Drupal.t('Find protection order forms'),
        forms_employment: Drupal.t('Find employment forms'),
        forms_benefits: Drupal.t('Find public benefits forms'),
        // Guide Finder topic actions.
        guides_housing: Drupal.t('Find housing and eviction guides'),
        guides_family: Drupal.t('Find family and divorce guides'),
        guides_consumer: Drupal.t('Find consumer and debt guides'),
        guides_seniors: Drupal.t('Find senior and estate planning guides'),
        guides_employment: Drupal.t('Find employment guides'),
        guides_benefits: Drupal.t('Find public benefits guides'),
        guides_safety: Drupal.t('Find protection order guides'),
        // TopIntentsPack service area intents.
        topic_housing: Drupal.t('Tell me about housing legal help'),
        topic_family: Drupal.t('Tell me about family legal help'),
        topic_consumer: Drupal.t('Tell me about consumer legal help'),
        topic_seniors: Drupal.t('Tell me about seniors legal help'),
        topic_health: Drupal.t('Tell me about health and benefits legal help'),
        topic_civil_rights: Drupal.t('Tell me about civil rights legal help'),
        topic_employment: Drupal.t('Tell me about employment legal help'),
        // TopIntentsPack sub-topic intents.
        topic_family_custody: Drupal.t('I need custody information'),
        topic_family_divorce: Drupal.t('I need divorce information'),
        topic_family_child_support: Drupal.t('I need child support information'),
        topic_family_protection_order: Drupal.t('I need protection order information'),
        topic_housing_eviction: Drupal.t('I need eviction help'),
        topic_housing_foreclosure: Drupal.t('I need foreclosure help'),
        topic_consumer_debt_collection: Drupal.t('I need debt collection help'),
        topic_consumer_bankruptcy: Drupal.t('I need bankruptcy information'),
        // TopIntentsPack navigation intents.
        eligibility: Drupal.t('Do I qualify for help?'),
        risk_detector: Drupal.t('Take the legal risk assessment'),
        offices_contact: Drupal.t('Find an office near me'),
        legal_advice_line: Drupal.t('Call the legal advice line'),
        apply_for_help: Drupal.t('Apply for help'),
        forms_finder: Drupal.t('Find a form'),
        guides_finder: Drupal.t('Find a guide'),
        services_overview: Drupal.t('What services do you offer?'),
        feedback: Drupal.t('Give feedback'),
        donations: Drupal.t('How can I donate?'),
        faq: Drupal.t('Show me FAQs'),
      };
      const requestContextQuickActions = ['apply', 'hotline', 'forms', 'guides', 'faq', 'topics'];
      const normalizedSelection = this.normalizeSelection(selection);
      const message = displayLabel || (normalizedSelection && normalizedSelection.label) || actionMessages[action] || action;

      // Track suggestion click events for debugging.
      this.trackEvent('suggestion_click', action);

      // Lock sending state.
      this.isSending = true;
      this.setSendingState(true);

      // Add as user message.
      this.addMessage('user', message);
      if (normalizedSelection) {
        this.activeSelection = normalizedSelection;
        this.saveState();
      }

      // Show typing.
      this.showTyping();

      var self = this;

      // Send to API.
      const payload = {
        message: message,
        conversation_id: this.conversationId,
      };
      if (requestContextQuickActions.indexOf(action) !== -1 || normalizedSelection) {
        payload.context = {};
        if (requestContextQuickActions.indexOf(action) !== -1) {
          payload.context.quickAction = action;
        }
        if (normalizedSelection) {
          payload.context.selection = normalizedSelection;
        }
      }

      this.callApi('/message', {
        method: 'POST',
        body: JSON.stringify(payload),
      })
        .then(function (response) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.handleResponse(response);
          if (self.isOpen || self.isPageMode) {
            safeFocus(self.inputField);
          }
        })
        .catch(function (error) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.emitAssistantError(error, 'quick_action', error && error.status >= 500);
          if (error.status === 403) {
            self.addRecoveryMessage(error, message);
          } else {
            self.addMessage('assistant', self.getErrorMessage(error));
            if (self.isOpen || self.isPageMode) {
              safeFocus(self.inputField);
            }
          }
        });
    },

    /**
     * Handle API response.
     */
    handleResponse: function (response) {
      if (!response) return;

      var didUpdateState = false;

      if (this.isUuidV4(response.request_id || '')) {
        this.lastResponseRequestId = response.request_id;
        didUpdateState = true;
      } else if (this.lastResponseRequestId !== null) {
        this.lastResponseRequestId = null;
        didUpdateState = true;
      }

      if (Object.prototype.hasOwnProperty.call(response, 'active_selection')) {
        this.activeSelection = this.normalizeSelection(response.active_selection);
        didUpdateState = true;
      } else {
        var inferredSelection = this.inferActiveSelectionFromResponse(response);
        if (inferredSelection) {
          this.activeSelection = inferredSelection;
          didUpdateState = true;
        }
      }

      if (didUpdateState) {
        this.saveState();
      }

      // Track topic if present.
      if (response.type === 'topic' && response.topic) {
        this.trackEvent('topic_selected', String(response.topic.id || ''));
      }

      var msgEl = this.addMessage('assistant', {
        kind: 'response',
        response: response,
      });

      // Append feedback controls for substantive response types.
      var noFeedbackTypes = ['greeting', 'clarify', 'form_finder_clarify', 'guide_finder_clarify'];
      if (msgEl && noFeedbackTypes.indexOf(response.type) === -1) {
        this.appendFeedback(msgEl, response.type || '');
      }
    },

    /**
     * Classify results into rendering modes based on score distribution.
     *
     * @param {Array} results - Array of result objects with optional score.
     * @return {string} One of: 'empty', 'single_best', 'best_match', 'ambiguous'.
     */
    classifyResults: function (results) {
      if (!results || results.length === 0) {
        return 'empty';
      }
      if (results.length === 1) {
        return 'single_best';
      }
      // Check score gap between first and second result.
      var first = results[0].score || 0;
      var second = results[1].score || 0;
      if (first > 0 && second > 0) {
        var gap = (first - second) / first;
        if (gap >= 0.30) {
          return 'best_match';
        }
      }
      return 'ambiguous';
    },

    /**
     * Create a DOM element with optional class and text content.
     */
    createElement: function (tagName, className, text) {
      var element = document.createElement(tagName);
      if (className) {
        element.className = className;
      }
      if (typeof text === 'string') {
        element.textContent = text;
      }
      return element;
    },

    /**
     * Create a Font Awesome icon element.
     */
    createIconElement: function (iconName) {
      var safeIcon = String(iconName || 'arrow-right').replace(/[^a-z0-9_-]/gi, '') || 'arrow-right';
      var icon = document.createElement('i');
      icon.className = 'fas fa-' + safeIcon;
      icon.setAttribute('aria-hidden', 'true');
      return icon;
    },

    /**
     * Sanitize a token for use in CSS modifier classes.
     */
    sanitizeClassToken: function (value) {
      return String(value || '').trim().replace(/[^a-z0-9_-]/gi, '-');
    },

    /**
     * Build a tracked anchor element.
     */
    createTrackedLink: function (url, className, label, trackType) {
      var link = document.createElement('a');
      link.href = this.sanitizeUrl(url);
      if (className) {
        link.className = className;
      }
      if (trackType) {
        link.setAttribute('data-assistant-track', trackType);
      }
      if (typeof label === 'string') {
        link.textContent = label;
      }
      return link;
    },

    /**
     * Apply normalized selection metadata to a suggestion button.
     */
    applySelectionDataAttrs: function (element, selection, fallbackAction, fallbackLabel) {
      var normalized = this.normalizeSelection(selection) || this.normalizeSelection({
        button_id: fallbackAction || '',
        label: fallbackLabel || '',
        parent_button_id: '',
        source: 'widget_fallback',
      });

      if (!element || !normalized) {
        return;
      }

      element.dataset.selectionButtonId = normalized.button_id;
      element.dataset.selectionLabel = normalized.label;
      element.dataset.selectionParentId = normalized.parent_button_id;
      element.dataset.selectionSource = normalized.source;
    },

    /**
     * Append a text paragraph to a message fragment.
     */
    appendMessageParagraph: function (container, text, className, emphasize) {
      if (typeof text !== 'string' || text === '') {
        return;
      }

      var paragraph = this.createElement('p', className || '');
      if (emphasize) {
        var emphasis = document.createElement('em');
        emphasis.textContent = text;
        paragraph.appendChild(emphasis);
      } else {
        paragraph.textContent = text;
      }
      container.appendChild(paragraph);
    },

    /**
     * Render an assistant response into DOM nodes.
     */
    renderAssistantResponse: function (response) {
      var fragment = document.createDocumentFragment();
      var renderedInlineSuggestions = false;
      var renderedTopicSuggestions = false;

      if (response.message) {
        this.appendMessageParagraph(fragment, response.message);
      }

      switch (response.type) {
        case 'faq':
          fragment.appendChild(this.renderFaqResults(response.results, response.fallback_url));
          break;

        case 'resources':
          fragment.appendChild(this.renderResourceResults(response.results, response.fallback_url, response.fallback_label));
          if (response.disclaimer) {
            this.appendMessageParagraph(fragment, response.disclaimer, 'resource-disclaimer', true);
          }
          break;

        case 'navigation':
          fragment.appendChild(this.renderNavigation(response));
          break;

        case 'apply_cta':
          fragment.appendChild(this.renderApplyCta(response));
          break;

        case 'topic':
          fragment.appendChild(this.renderTopicInfo(response.topic, response.service_area_url));
          break;

        case 'escalation':
          if (response.links && response.links.length > 0) {
            fragment.appendChild(this.renderLinks(response.links));
          }
          if (response.actions) {
            fragment.appendChild(this.renderEscalation(response.actions));
          }
          break;

        case 'eligibility':
          if (response.caveat) {
            this.appendMessageParagraph(fragment, response.caveat, 'eligibility-caveat', true);
          }
          if (response.links && response.links.length > 0) {
            fragment.appendChild(this.renderLinks(response.links));
          }
          break;

        case 'services_overview':
          if (response.service_areas && response.service_areas.length > 0) {
            fragment.appendChild(this.renderServiceAreas(response.service_areas));
          }
          if (response.url) {
            fragment.appendChild(this.renderNavigation(response));
          }
          break;

        case 'forms_inventory':
        case 'guides_inventory':
        case 'services_inventory':
        case 'form_finder_clarify':
        case 'guide_finder_clarify':
          if (response.topic_suggestions) {
            try {
              var chipsNode = this.renderTopicSuggestions(response.topic_suggestions);
              if (!chipsNode || !chipsNode.childElementCount) {
                throw new Error('Empty chip render');
              }
              fragment.appendChild(chipsNode);
              renderedTopicSuggestions = true;
            } catch (e) {
              console.warn('ILAS Assistant: chip render failed, using text fallback', e);
              var fallbackMode = this.resolveTopicSuggestionFallbackMode(response);
              if (fallbackMode === 'none') {
                fallbackMode = 'generic_text';
                this.appendMessageParagraph(
                  fragment,
                  Drupal.t('The topic buttons did not load. You can use the link below or refresh the page and try again.'),
                  'chip-fallback-text'
                );
              } else if (response.text_fallback) {
                this.appendMessageParagraph(fragment, response.text_fallback, 'chip-fallback-text');
              }
              this.emitAssistantError(e, 'chip_render', false, {
                responseType: response.type || '',
                fallbackMode: fallbackMode,
                renderedFallback: fallbackMode !== 'none',
                path: this.currentPagePath(),
              });
              this.trackEvent('ui_fallback_used', response.type || '', {
                responseType: response.type || '',
                fallbackMode: fallbackMode,
                renderedFallback: fallbackMode !== 'none',
                path: this.currentPagePath(),
              });
            }
          }
          if (response.primary_action && response.primary_action.url) {
            fragment.appendChild(this.renderPrimaryActionLink(response.primary_action));
          }
          break;

        case 'ui_troubleshooting':
          if (response.links && response.links.length > 0) {
            fragment.appendChild(this.renderLinks(response.links));
          }
          if (response.followup) {
            this.appendMessageParagraph(fragment, response.followup, 'ui-troubleshoot-tip', true);
          }
          this.trackEvent('ui_troubleshooting', 'displayed');
          break;

        case 'fallback':
          if (response.topic_suggestions) {
            var topicSuggestions = this.renderTopicSuggestions(response.topic_suggestions);
            if (topicSuggestions.childElementCount) {
              fragment.appendChild(topicSuggestions);
              renderedTopicSuggestions = true;
            }
          }
          if (response.actions) {
            fragment.appendChild(this.renderEscalation(response.actions));
          }
          break;

        case 'greeting':
          if (response.suggestions) {
            var inlineSuggestions = this.renderSuggestions(response.suggestions);
            if (inlineSuggestions.childElementCount) {
              fragment.appendChild(inlineSuggestions);
              renderedInlineSuggestions = true;
            }
          }
          break;
      }

      if (response.suggestions && !renderedInlineSuggestions && !renderedTopicSuggestions) {
        var suggestions = this.renderSuggestions(response.suggestions);
        if (suggestions.childElementCount) {
          fragment.appendChild(suggestions);
        }
      }

      return fragment;
    },

    /**
     * Render semantic source indicator for vector-sourced results.
     *
     * @param {Object} result - A result object with optional source field.
     * @return {HTMLElement|null} DOM element for the indicator, or null.
     */
    renderSourceIndicator: function (result) {
      if (!result || result.source !== 'vector') {
        return null;
      }
      var wrapper = this.createElement('span', 'source-indicator');
      wrapper.setAttribute('aria-label', Drupal.t('Found via similar questions'));

      var icon = document.createElement('span');
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = '\uD83D\uDCA1';
      wrapper.appendChild(icon);
      wrapper.appendChild(document.createTextNode(' ' + Drupal.t('Based on similar questions')));

      return wrapper;
    },

    /**
     * Truncate text to a character limit, breaking at word boundary.
     *
     * @param {string} text - The text to truncate.
     * @param {number} limit - Maximum characters.
     * @return {string} Truncated text with ellipsis if needed.
     */
    truncateText: function (text, limit) {
      if (!text || text.length <= limit) {
        return text || '';
      }
      var truncated = text.substring(0, limit);
      var lastSpace = truncated.lastIndexOf(' ');
      if (lastSpace > limit * 0.6) {
        truncated = truncated.substring(0, lastSpace);
      }
      return truncated + '\u2026';
    },

    /**
     * Render FAQ results.
     */
    renderFaqResults: function (results, fallbackUrl) {
      if (!results || results.length === 0) {
        var fallbackParagraph = document.createElement('p');
        fallbackParagraph.appendChild(
          this.createTrackedLink(fallbackUrl, 'result-link', Drupal.t('Browse all FAQs'), 'resource_click')
        );
        return fallbackParagraph;
      }

      var self = this;
      var mode = this.classifyResults(results);
      var container = this.createElement('div', 'faq-results');

      results.forEach(function (faq, index) {
        var isBest = (index === 0 && (mode === 'single_best' || mode === 'best_match'));
        var isSecondary = (!isBest && (mode === 'single_best' || mode === 'best_match'));
        var sourceIndicator = self.renderSourceIndicator(faq);
        var safeUrl = faq && faq.url ? faq.url : '#';
        var readMoreText = Drupal.t('Read more on page') + ' \u2192';

        if (isBest) {
          var bestCard = self.createElement('div', 'faq-result faq-result--best');
          bestCard.appendChild(self.createElement('span', 'best-match-label', Drupal.t('Best match')));
          if (sourceIndicator) {
            bestCard.appendChild(sourceIndicator);
          }
          bestCard.appendChild(self.createElement('h4', 'faq-question', String(faq.question || '')));
          bestCard.appendChild(self.createElement('p', 'faq-answer', self.truncateText(String(faq.answer || ''), 120)));
          bestCard.appendChild(self.createTrackedLink(safeUrl, 'faq-link', readMoreText, 'resource_click'));
          container.appendChild(bestCard);
        } else if (isSecondary) {
          if (index === 1) {
            container.appendChild(self.createElement('p', 'also-helpful-heading', Drupal.t('Also helpful:')));
          }
          var secondaryCard = self.createElement('div', 'faq-result faq-result--secondary');
          if (sourceIndicator) {
            secondaryCard.appendChild(sourceIndicator);
          }
          secondaryCard.appendChild(self.createTrackedLink(safeUrl, 'faq-link', String(faq.question || ''), 'resource_click'));
          container.appendChild(secondaryCard);
        } else {
          var resultCard = self.createElement('div', 'faq-result');
          if (sourceIndicator) {
            resultCard.appendChild(sourceIndicator);
          }
          resultCard.appendChild(self.createElement('h4', 'faq-question', String(faq.question || '')));
          resultCard.appendChild(self.createElement('p', 'faq-answer', self.truncateText(String(faq.answer || ''), 120)));
          resultCard.appendChild(self.createTrackedLink(safeUrl, 'faq-link', readMoreText, 'resource_click'));
          container.appendChild(resultCard);
        }
      });
      return container;
    },

    /**
     * Render resource results.
     */
    renderResourceResults: function (results, fallbackUrl, fallbackLabel) {
      if (!results || results.length === 0) {
        var fallback = document.createElement('p');
        var label = fallbackLabel || Drupal.t('Browse all resources');
        fallback.appendChild(this.createTrackedLink(fallbackUrl, 'result-link', label, 'resource_click'));
        return fallback;
      }

      var self = this;
      var mode = this.classifyResults(results);
      var list = this.createElement('ul', 'resource-results');

      results.forEach(function (resource, index) {
        var isBest = (index === 0 && (mode === 'single_best' || mode === 'best_match'));
        var isSecondary = (!isBest && (mode === 'single_best' || mode === 'best_match'));
        var icon = resource.type === 'form' ? 'file-alt' : (resource.type === 'guide' ? 'book' : 'file');
        var sourceIndicator = self.renderSourceIndicator(resource);
        var safeUrl = resource && resource.url ? resource.url : '#';

        function buildResourceLink(className) {
          var link = self.createTrackedLink(safeUrl, className, null, 'resource_click');
          link.appendChild(self.createIconElement(icon));
          link.appendChild(self.createElement('span', 'resource-title', String(resource.title || '')));
          if (resource.has_file) {
            link.appendChild(self.createElement('span', 'badge', 'PDF'));
          }
          return link;
        }

        if (isBest) {
          var bestItem = self.createElement('li', 'resource-result resource-result--best');
          bestItem.appendChild(self.createElement('span', 'best-match-label', Drupal.t('Best match')));
          if (sourceIndicator) {
            bestItem.appendChild(sourceIndicator);
          }
          bestItem.appendChild(buildResourceLink('resource-link'));
          if (resource.description) {
            bestItem.appendChild(self.createElement('p', 'resource-desc', String(resource.description)));
          }
          list.appendChild(bestItem);
          if (results.length > 1) {
            var divider = self.createElement('li', 'also-helpful-heading', Drupal.t('Also helpful:'));
            divider.setAttribute('aria-hidden', 'true');
            list.appendChild(divider);
          }
        } else if (isSecondary) {
          var secondaryItem = self.createElement('li', 'resource-result resource-result--secondary');
          if (sourceIndicator) {
            secondaryItem.appendChild(sourceIndicator);
          }
          secondaryItem.appendChild(buildResourceLink('resource-link resource-link--secondary'));
          list.appendChild(secondaryItem);
        } else {
          var item = self.createElement('li', 'resource-result');
          if (sourceIndicator) {
            item.appendChild(sourceIndicator);
          }
          item.appendChild(buildResourceLink('resource-link'));
          if (resource.description) {
            item.appendChild(self.createElement('p', 'resource-desc', String(resource.description)));
          }
          list.appendChild(item);
        }
      });
      return list;
    },

    /**
     * Render navigation response.
     */
    renderNavigation: function (response) {
      var fragment = document.createDocumentFragment();
      if (response.url) {
        var paragraph = document.createElement('p');
        var ctaText = response.cta || Drupal.t('Go to page');
        var link = this.createTrackedLink(response.url, 'cta-button', null, 'resource_click');
        link.appendChild(document.createTextNode(ctaText + ' '));
        link.appendChild(this.createIconElement('arrow-right'));
        paragraph.appendChild(link);
        fragment.appendChild(paragraph);
      }
      return fragment;
    },

    /**
     * Render the apply CTA response with three application methods.
     */
    renderApplyCta: function (response) {
      var self = this;
      var fragment = document.createDocumentFragment();
      var container = this.createElement('div', 'apply-methods');

      if (response.apply_methods && response.apply_methods.length > 0) {
        response.apply_methods.forEach(function (method) {
          var iconClass = self.sanitizeClassToken(method.icon || 'arrow-right') || 'arrow-right';
          var methodClass = self.sanitizeClassToken(method.method || '');
          var cardClass = 'apply-method' + (methodClass ? ' apply-method--' + methodClass : '');
          var card = self.createElement('div', cardClass);
          var heading = self.createElement('h4', 'apply-method__heading');

          heading.appendChild(self.createIconElement(iconClass));
          heading.appendChild(document.createTextNode(' ' + String(method.heading || '')));
          card.appendChild(heading);
          card.appendChild(self.createElement('p', 'apply-method__desc', String(method.description || '')));

          var cta = self.createTrackedLink(method.cta_url, 'cta-button', null, 'apply_cta_click');
          cta.appendChild(document.createTextNode(String(method.cta_label || '') + ' '));
          cta.appendChild(self.createIconElement('arrow-right'));
          card.appendChild(cta);

          if (method.secondary_label && method.secondary_url) {
            card.appendChild(document.createTextNode(' '));
            card.appendChild(
              self.createTrackedLink(
                method.secondary_url,
                'apply-method__secondary-link',
                String(method.secondary_label),
                'apply_secondary_click'
              )
            );
          }

          container.appendChild(card);
        });
      }

      fragment.appendChild(container);

      if (response.followup) {
        this.appendMessageParagraph(fragment, response.followup, 'apply-followup', true);
      }

      return fragment;
    },

    /**
     * Render topic info.
     */
    renderTopicInfo: function (topic, serviceAreaUrl) {
      var self = this;
      var fragment = document.createDocumentFragment();
      if (topic && topic.service_areas && topic.service_areas.length > 0) {
        fragment.appendChild(this.createElement('p', '', Drupal.t('Related service areas:')));
        var list = document.createElement('ul');
        topic.service_areas.forEach(function (area) {
          list.appendChild(self.createElement('li', '', String(area.name || '')));
        });
        fragment.appendChild(list);
      }
      if (serviceAreaUrl) {
        var paragraph = document.createElement('p');
        var link = this.createTrackedLink(serviceAreaUrl, 'cta-button', null, 'resource_click');
        link.appendChild(document.createTextNode(Drupal.t('Learn more') + ' '));
        link.appendChild(this.createIconElement('arrow-right'));
        paragraph.appendChild(link);
        fragment.appendChild(paragraph);
      }
      return fragment;
    },

    /**
     * Render escalation actions.
     */
    renderEscalation: function (actions) {
      if (!actions || actions.length === 0) return document.createDocumentFragment();

      var self = this;
      var container = this.createElement('div', 'escalation-actions');
      actions.forEach(function (action) {
        var trackType = self.normalizeTrackToken((action.type || 'link') + '_click') || 'link_click';
        container.appendChild(
          self.createTrackedLink(action.url, 'escalation-btn', String(action.label || ''), trackType)
        );
      });
      return container;
    },

    /**
     * Render suggestions.
     */
    renderSuggestions: function (suggestions) {
      if (!suggestions || suggestions.length === 0) {
        return this.createElement('div', 'inline-suggestions');
      }

      var self = this;
      var container = this.createElement('div', 'inline-suggestions');
      suggestions.forEach(function (suggestion) {
        var button = self.createElement('button', 'inline-suggestion-btn', String(suggestion.label || ''));
        button.type = 'button';
        button.dataset.action = String(suggestion.action || '');
        self.applySelectionDataAttrs(button, suggestion.selection, suggestion.action, suggestion.label);
        container.appendChild(button);
      });
      return container;
    },

    /**
     * Render topic suggestions (for fallback).
     */
    renderTopicSuggestions: function (suggestions) {
      if (!suggestions || suggestions.length === 0) {
        return this.createElement('div', 'topic-suggestions');
      }

      var self = this;
      var container = this.createElement('div', 'topic-suggestions');
      suggestions.forEach(function (suggestion) {
        var button = self.createElement('button', 'topic-suggestion-btn', String(suggestion.label || ''));
        button.type = 'button';
        button.dataset.action = String(suggestion.action || '');
        self.applySelectionDataAttrs(button, suggestion.selection, suggestion.action, suggestion.label);
        container.appendChild(button);
      });
      return container;
    },

    /**
     * Render links (for policy responses and eligibility).
     */
    renderLinks: function (links) {
      if (!links || links.length === 0) return document.createDocumentFragment();

      var self = this;
      var container = this.createElement('div', 'response-links');
      links.forEach(function (link) {
        var trackType = self.normalizeTrackToken((link.type || 'link') + '_click') || 'link_click';
        container.appendChild(
          self.createTrackedLink(link.url, 'response-link-btn', String(link.label || ''), trackType)
        );
      });
      return container;
    },

    /**
     * Render service areas (for services overview).
     */
    renderServiceAreas: function (areas) {
      if (!areas || areas.length === 0) return document.createDocumentFragment();

      var self = this;
      var container = this.createElement('div', 'service-areas-grid');
      areas.forEach(function (area) {
        container.appendChild(
          self.createTrackedLink(area.url, 'service-area-btn', String(area.label || ''), 'service_area_click')
        );
      });
      return container;
    },

    /**
     * Render a stored display message into its content container.
     */
    renderDisplayMessage: function (contentEl, message) {
      if (!contentEl || !message) {
        return;
      }

      switch (message.kind) {
        case 'response':
          contentEl.appendChild(this.renderAssistantResponse(message.response || {}));
          break;

        case 'recovery':
          contentEl.appendChild(this.buildRecoveryMessageContent(message.errorCode, message.lastMessageText));
          break;

        case 'text':
        default:
          contentEl.textContent = typeof message.text === 'string' ? message.text : '';
          break;
      }
    },

    /**
     * Render the persistent "Browse all ..." primary action link.
     */
    renderPrimaryActionLink: function (primaryAction) {
      var paragraph = this.createElement('p', 'form-finder-fallback');
      if (!primaryAction || !primaryAction.url) {
        return paragraph;
      }

      paragraph.appendChild(
        this.createTrackedLink(
          primaryAction.url,
          'result-link',
          String(primaryAction.label || ''),
          'resource_click'
        )
      );
      return paragraph;
    },

    /**
     * Build the recovery UI for a stored assistant recovery message.
     */
    buildRecoveryMessageContent: function (errorCode, lastMessageText) {
      var recoveryText;
      var showRetry = false;

      switch (String(errorCode || '')) {
        case 'csrf_missing':
        case 'csrf_invalid':
          recoveryText = Drupal.t('Security session could not be verified. Choose Try again to resend, or Refresh page to restart your secure session.');
          showRetry = true;
          break;
        case 'csrf_expired':
        case 'session_expired':
          recoveryText = Drupal.t('Your secure session has expired. Refresh page to restart your secure session.');
          break;
        default:
          recoveryText = Drupal.t('Your session could not be verified. Refresh the page to start a new secure session.');
          break;
      }

      var container = this.createElement('div', 'recovery-message');
      container.setAttribute('role', 'alert');
      container.appendChild(this.createElement('p', 'recovery-text', recoveryText));

      var actions = this.createElement('div', 'recovery-actions');
      if (showRetry) {
        var retryButton = this.createElement('button', 'recovery-btn--retry');
        retryButton.type = 'button';
        retryButton.dataset.retryMessage = typeof lastMessageText === 'string' ? lastMessageText : '';
        retryButton.setAttribute('aria-label', Drupal.t('Try sending your message again'));
        retryButton.appendChild(this.createIconElement('redo'));
        retryButton.appendChild(document.createTextNode(' ' + Drupal.t('Try again')));
        actions.appendChild(retryButton);
      }

      var refreshButton = this.createElement('button', 'recovery-btn--refresh');
      refreshButton.type = 'button';
      refreshButton.setAttribute('aria-label', Drupal.t('Refresh this page to start a new session'));
      refreshButton.appendChild(this.createIconElement('sync-alt'));
      refreshButton.appendChild(document.createTextNode(' ' + Drupal.t('Refresh page')));
      actions.appendChild(refreshButton);

      container.appendChild(actions);
      return container;
    },

    /**
     * Add a message to the chat.
     */
    addMessage: function (sender, payload) {
      var chat = this.isPageMode
        ? document.getElementById('assistant-chat')
        : this.widget.querySelector('.assistant-chat');

      if (!chat) return;

      var message = this.normalizeDisplayMessage(sender, payload);
      if (!message) return null;

      var messageEl = document.createElement('div');
      messageEl.className = 'chat-message chat-message--' + sender;

      var contentEl = document.createElement('div');
      contentEl.className = 'message-content';
      this.renderDisplayMessage(contentEl, message);

      messageEl.appendChild(contentEl);

      // Insert message before the sentinel so it stays at the end.
      if (this.scrollManager && this.scrollManager.sentinel) {
        chat.insertBefore(messageEl, this.scrollManager.sentinel);
      } else {
        chat.appendChild(messageEl);
      }

      // Conditionally scroll: only if user is near bottom.
      // Pass the message element so the scroll manager can detect tall
      // messages and scroll to their top instead of the container bottom.
      if (this.scrollManager) {
        this.scrollManager.onNewContent(messageEl);
      } else {
        // Fallback for edge case where scroll manager isn't ready.
        chat.scrollTop = chat.scrollHeight;
      }

      // Store in history (text only).
      if (sender === 'user' && message.kind === 'text') {
        this.messageHistory.push({ role: 'user', content: message.text });
      }

      // Bind events to new inline buttons.
      var self = this;
      messageEl.querySelectorAll('.inline-suggestion-btn, .topic-suggestion-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          var button = e.currentTarget;
          self.handleQuickAction(button.dataset.action, self.extractSelectionFromButton(button), self.getButtonDisplayLabel(button));
        });
      });

      // Bind recovery buttons.
      messageEl.querySelectorAll('.recovery-btn--retry').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          var retryMsg = e.currentTarget.getAttribute('data-retry-message');
          self.retrySend(retryMsg);
        });
      });
      messageEl.querySelectorAll('.recovery-btn--refresh').forEach(function (btn) {
        btn.addEventListener('click', function () {
          window.location.reload();
        });
      });

      // Bind tracking to new links.
      messageEl.querySelectorAll('[data-assistant-track]').forEach(function (link) {
        link.addEventListener('click', function (e) {
          var trackType = e.currentTarget.dataset.assistantTrack;
          self.trackClick(trackType, e.currentTarget.href);
        });
      });

      // Track display message for session persistence.
      this.storeDisplayMessage(message);

      return messageEl;
    },

    /**
     * Append helpful/not-helpful feedback controls to an assistant message.
     *
     * @param {HTMLElement} messageEl - The chat message element.
     * @param {string} responseType - The response type token (e.g. 'faq', 'resources').
     */
    appendFeedback: function (messageEl, responseType) {
      var self = this;
      var controls = document.createElement('div');
      controls.className = 'feedback-controls';

      var label = document.createElement('span');
      label.className = 'feedback-label';
      label.textContent = Drupal.t('Was this helpful?');
      controls.appendChild(label);

      var helpfulBtn = document.createElement('button');
      helpfulBtn.type = 'button';
      helpfulBtn.className = 'feedback-btn feedback-btn--helpful';
      helpfulBtn.setAttribute('aria-label', Drupal.t('Helpful'));
      helpfulBtn.innerHTML = '<i class="fas fa-thumbs-up" aria-hidden="true"></i>';
      controls.appendChild(helpfulBtn);

      var notHelpfulBtn = document.createElement('button');
      notHelpfulBtn.type = 'button';
      notHelpfulBtn.className = 'feedback-btn feedback-btn--not-helpful';
      notHelpfulBtn.setAttribute('aria-label', Drupal.t('Not helpful'));
      notHelpfulBtn.innerHTML = '<i class="fas fa-thumbs-down" aria-hidden="true"></i>';
      controls.appendChild(notHelpfulBtn);

      function handleFeedback(eventType, clickedBtn) {
        self.trackEvent(eventType, responseType, {
          responseType: responseType,
          responseRequestId: self.lastResponseRequestId,
        });
        helpfulBtn.disabled = true;
        notHelpfulBtn.disabled = true;
        clickedBtn.classList.add('feedback-btn--selected');
        label.textContent = Drupal.t('Thanks for your feedback');
        controls.classList.add('feedback-controls--submitted');
      }

      helpfulBtn.addEventListener('click', function () {
        handleFeedback('feedback_helpful', helpfulBtn);
      });
      notHelpfulBtn.addEventListener('click', function () {
        handleFeedback('feedback_not_helpful', notHelpfulBtn);
      });

      messageEl.appendChild(controls);
    },

    /**
     * Show typing indicator with ARIA status for screen readers.
     */
    showTyping: function () {
      var chat = this.isPageMode
        ? document.getElementById('assistant-chat')
        : this.widget.querySelector('.assistant-chat');

      var typing = document.createElement('div');
      typing.className = 'chat-message chat-message--assistant chat-message--typing';
      typing.id = 'typing-indicator';
      typing.setAttribute('role', 'status');
      typing.setAttribute('aria-label', Drupal.t('Aila is typing'));
      typing.innerHTML = '<div class="typing-indicator" aria-hidden="true">' +
        '<span></span><span></span><span></span>' +
        '</div>';

      // Insert before sentinel so it stays at the end.
      if (this.scrollManager && this.scrollManager.sentinel) {
        chat.insertBefore(typing, this.scrollManager.sentinel);
      } else {
        chat.appendChild(typing);
      }

      // Conditionally scroll.
      if (this.scrollManager) {
        this.scrollManager.onNewContent(typing);
      } else {
        chat.scrollTop = chat.scrollHeight;
      }
    },

    /**
     * Hide typing indicator.
     */
    hideTyping: function () {
      var typing = document.getElementById('typing-indicator');
      if (typing) {
        typing.remove();
      }
    },

    /**
     * Call the API with AbortController timeout and status-aware errors.
     *
     * @param {string} endpoint - API path (appended to config.apiBase).
     * @param {Object} options - fetch options (method, body, etc.).
     * @return {Promise} Resolves with parsed JSON; rejects with enriched Error
     *   containing .type ('offline'|'timeout') or .status (HTTP code) and
     *   optionally .retryAfter (seconds from Retry-After header).
     */
    callApi: function (endpoint, options, _isRetry) {
      options = options || {};
      var url = this.config.apiBase + endpoint;
      var self = this;

      // Offline check.
      if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        var offlineErr = new Error('Offline');
        offlineErr.type = 'offline';
        return Promise.reject(offlineErr);
      }

      var method = (options.method || 'GET').toUpperCase();
      var needsCsrf = method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS';
      // /track no longer requires CSRF; only /message does.
      var isCsrfRoute = endpoint !== '/track';

      // For write methods on CSRF-protected routes, ensure token exists.
      var tokenReady = (needsCsrf && isCsrfRoute && !this.config.csrfToken)
        ? this.fetchCsrfToken()
        : Promise.resolve(this.config.csrfToken);

      return tokenReady.then(function (csrfToken) {
        // AbortController for timeout.
        var controller = new AbortController();
        var timeoutMs = options.timeout || 15000;
        var timeoutId = setTimeout(function () {
          controller.abort();
        }, timeoutMs);

        var headers = Object.assign({
          'Content-Type': 'application/json',
        }, options.headers || {});
        if (needsCsrf && isCsrfRoute && csrfToken && !headers['X-CSRF-Token']) {
          headers['X-CSRF-Token'] = csrfToken;
        }

        var defaultOptions = {
          method: 'GET',
          headers: headers,
          credentials: 'same-origin',
          signal: controller.signal,
        };

        var fetchOptions = Object.assign({}, defaultOptions, options, {
          headers: headers,
        });
        delete fetchOptions.timeout;

        return fetch(url, fetchOptions)
          .then(function (response) {
            clearTimeout(timeoutId);
            if (!response.ok) {
              return response.json()
                .catch(function () { return {}; })
                .then(function (body) {
                  var error = new Error(body.message || ('API request failed: ' + response.status));
                  error.status = response.status;
                  error.errorCode = body.error_code || '';
                  if (response.status === 429) {
                    var retryHeader = response.headers.get('Retry-After');
                    if (retryHeader) {
                      error.retryAfter = retryHeader;
                    }
                  }
                  throw error;
                });
            }
            return response.json();
          })
          .catch(function (error) {
            clearTimeout(timeoutId);
            if (error.name === 'AbortError') {
              var timeoutError = new Error('Request timed out');
              timeoutError.type = 'timeout';
              throw timeoutError;
            }

            // On 403 for CSRF-protected routes, retry once with fresh token.
            if (error.status === 403 && needsCsrf && isCsrfRoute && !_isRetry) {
              return self.fetchCsrfToken(true).then(function () {
                return self.callApi(endpoint, options, true);
              });
            }

            throw error;
          });
      });
    },

    /**
     * Send a /track request with one silent recovery retry for missing proof.
     *
     * Browsers should normally satisfy /track protection through same-origin
     * Origin/Referer headers. If those headers are stripped, retry once with a
     * fresh session-bound X-CSRF-Token from the assistant bootstrap endpoint.
     *
     * @param {Object} payload - Track event payload.
     * @param {boolean} _isRetry - Internal recursion guard.
     * @param {string} csrfToken - Optional recovery token for retry only.
     * @return {Promise} Resolves with parsed JSON or rejects with an Error.
     */
    callTrackApi: function (payload, _isRetry, csrfToken) {
      var self = this;
      var options = {
        method: 'POST',
        body: JSON.stringify(payload),
      };

      if (csrfToken) {
        options.headers = {
          'X-CSRF-Token': csrfToken,
        };
      }

      return this.callApi('/track', options)
        .catch(function (error) {
          var needsRecovery = error
            && error.status === 403
            && (error.errorCode === 'track_proof_missing' || error.errorCode === 'track_proof_invalid');

          if (!needsRecovery || _isRetry) {
            throw error;
          }

          return self.fetchCsrfToken(true).then(function (freshToken) {
            return self.callTrackApi(payload, true, freshToken);
          });
        });
    },

    /**
     * Return a user-friendly error message based on error type / HTTP status.
     *
     * @param {Error} error - The error from callApi.
     * @return {string} Translated message suitable for addMessage().
     */
    getErrorMessage: function (error) {
      if (!error) {
        return Drupal.t('Something went wrong. Please try again.');
      }

      // Offline.
      if (error.type === 'offline') {
        return Drupal.t('You appear to be offline. Please check your connection and try again.');
      }

      // Timeout.
      if (error.type === 'timeout') {
        return Drupal.t('The request took too long. Please try again.');
      }

      // 429 Too Many Requests.
      if (error.status === 429) {
        var msg = Drupal.t('I\'m getting a lot of requests right now.');
        if (error.retryAfter) {
          var seconds = parseInt(error.retryAfter, 10);
          if (!isNaN(seconds) && seconds > 0) {
            msg += ' ' + Drupal.t('Please wait @seconds seconds and try again.', { '@seconds': seconds });
          } else {
            msg += ' ' + Drupal.t('Please wait a moment and try again.');
          }
        } else {
          msg += ' ' + Drupal.t('Please wait a moment and try again.');
        }
        return msg;
      }

      // 403 Forbidden — branch on error code from server.
      if (error.status === 403) {
        switch (error.errorCode) {
          case 'csrf_missing':
            return Drupal.t('Security session could not be established. Choose Try again to resend, or Refresh page to restart your secure session.');
          case 'csrf_invalid':
            return Drupal.t('Your security token could not be verified. Choose Try again to resend, or Refresh page to restart your secure session.');
          case 'csrf_expired':
          case 'session_expired':
            return Drupal.t('Your secure session has expired. Refresh page to continue.');
          default:
            return Drupal.t('Access denied. Please refresh the page and try again.');
        }
      }

      // 5xx Server errors.
      if (error.status >= 500) {
        return Drupal.t('Our server is having trouble right now. Please try again in a few minutes, or reach us through our hotline.');
      }

      // Generic fallback.
      return Drupal.t('I\'m having trouble right now. You can try again, or reach us directly through our hotline.');
    },

    /**
     * Show an actionable recovery message for 403 errors.
     *
     * Renders error text and recovery buttons (retry / refresh) based on the
     * error code. Uses role="alert" for screen-reader announcement and focuses
     * the first button for keyboard users.
     *
     * @param {Error} error - The error from callApi (must have .status === 403).
     * @param {string} lastMessageText - The user's message to replay on retry.
     */
    addRecoveryMessage: function (error, lastMessageText) {
      var errorCode = (error && error.errorCode) || '';
      if (errorCode === 'csrf_expired' || errorCode === 'session_expired') {
        this.clearState();
      }

      this.addMessage('assistant', {
        kind: 'recovery',
        errorCode: errorCode,
        lastMessageText: lastMessageText || '',
      });

      // Focus the first recovery button for keyboard / screen-reader users.
      var self = this;
      requestAnimationFrame(function () {
        var chat = self.isPageMode
          ? document.getElementById('assistant-chat')
          : self.widget.querySelector('.assistant-chat');
        if (chat) {
          var firstBtn = chat.querySelector('.recovery-btn--retry, .recovery-btn--refresh');
          if (firstBtn) {
            firstBtn.focus();
          } else {
            setTimeout(function () {
              var retryBtn = chat.querySelector('.recovery-btn--retry, .recovery-btn--refresh');
              if (retryBtn) retryBtn.focus();
            }, 150);
          }
        }
      });
    },

    /**
     * Retry sending a message after a CSRF/session recovery.
     *
     * Force-refreshes the CSRF token, then re-sends the stored message.
     * On 403 failure the recovery UI is shown again (no auto-loop).
     *
     * @param {string} messageText - The message to re-send.
     */
    retrySend: function (messageText) {
      if (this.isSending || !messageText) return;

      this.isSending = true;
      this.setSendingState(true);
      this.showTyping();

      var self = this;

      // Force-refresh the CSRF token before retrying.
      this.fetchCsrfToken(true)
        .then(function () {
          return self.callApi('/message', {
            method: 'POST',
            body: JSON.stringify({
              message: messageText,
              conversation_id: self.conversationId,
            }),
          });
        })
        .then(function (response) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.handleResponse(response);
          if (self.isOpen || self.isPageMode) {
            safeFocus(self.inputField);
          }
        })
        .catch(function (error) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.emitAssistantError(error, 'message_retry', error && error.status >= 500);
          if (error.status === 403) {
            self.addRecoveryMessage(error, messageText);
          } else {
            self.addMessage('assistant', self.getErrorMessage(error));
            if (self.isOpen || self.isPageMode) {
              safeFocus(self.inputField);
            }
          }
          console.error('ILAS Assistant retry error:', error);
        });
    },

    /**
     * Toggle the sending-disabled state on send button and input.
     *
     * @param {boolean} sending - True to disable, false to re-enable.
     */
    setSendingState: function (sending) {
      var btns;
      if (this.isPageMode) {
        btns = document.querySelectorAll('.assistant-send-btn');
      } else if (this.widget) {
        btns = this.widget.querySelectorAll('.assistant-send-btn');
      }
      if (btns) {
        btns.forEach(function (btn) {
          btn.disabled = sending;
        });
      }
      if (this.inputField) {
        this.inputField.disabled = sending;
      }
    },

    /**
     * Normalize a safe low-cardinality tracking token.
     */
    normalizeTrackToken: function (value) {
      value = String(value || '').trim().toLowerCase();
      if (!value || !/^[a-z0-9:_-]{1,255}$/.test(value)) {
        return '';
      }
      return value;
    },

    /**
     * Normalize a tracking path to pathname-only form.
     */
    normalizeTrackPath: function (value) {
      value = String(value || '').trim();
      if (!value) {
        return '';
      }

      try {
        var parsed = new URL(value, window.location.origin);
        return parsed.pathname && parsed.pathname.charAt(0) === '/' ? parsed.pathname : '';
      } catch (e) {
        return value.charAt(0) === '/' ? value : '';
      }
    },

    /**
     * Normalize client-side tracking values to the approved contract.
     */
    normalizeTrackValue: function (eventType, eventValue) {
      switch (eventType) {
        case 'chat_open':
          return '';

        case 'resource_click':
        case 'hotline_click':
        case 'apply_click':
        case 'apply_cta_click':
        case 'apply_secondary_click':
        case 'service_area_click':
          return this.normalizeTrackPath(eventValue);

        case 'topic_selected':
          eventValue = String(eventValue || '').trim();
          return /^[0-9]+$/.test(eventValue) ? eventValue : '';

        default:
          return this.normalizeTrackToken(eventValue);
      }
    },

    /**
     * Returns a minimized page path for observability payloads.
     */
    currentPagePath: function () {
      if (typeof window === 'undefined' || !window.location) {
        return '';
      }
      return this.normalizeTrackPath(window.location.pathname || '');
    },

    /**
     * Normalizes optional observability metadata.
     */
    normalizeObservabilityMetadata: function (metadata) {
      var normalized = {};
      if (!metadata || typeof metadata !== 'object') {
        return normalized;
      }

      if (metadata.responseType) {
        normalized.responseType = this.normalizeTrackToken(metadata.responseType);
      }
      if (metadata.fallbackMode) {
        normalized.fallbackMode = this.normalizeTrackToken(metadata.fallbackMode);
      }
      if (metadata.path) {
        normalized.path = this.normalizeTrackPath(metadata.path);
      }
      if (typeof metadata.renderedFallback === 'boolean') {
        normalized.renderedFallback = metadata.renderedFallback;
      }

      return normalized;
    },

    /**
     * Classifies the available topic-suggestion fallback surfaces.
     */
    resolveTopicSuggestionFallbackMode: function (response) {
      var hasText = !!(response && response.text_fallback && String(response.text_fallback).trim());
      var hasLink = !!(response && response.primary_action && response.primary_action.url && response.primary_action.label);

      if (hasText && hasLink) {
        return 'text_and_link';
      }
      if (hasText) {
        return 'text';
      }
      if (hasLink) {
        return 'link';
      }
      return 'none';
    },

    /**
     * Track an event.
     */
    trackEvent: function (eventType, eventValue, metadata) {
      eventValue = this.normalizeTrackValue(eventType, eventValue);
      this.emitAssistantAction(eventType, eventValue, metadata);
      var payload = {
        event_type: eventType,
        event_value: eventValue,
      };
      var isFeedbackEvent = eventType === 'feedback_helpful' || eventType === 'feedback_not_helpful';
      var responseRequestId = metadata && this.isUuidV4(metadata.responseRequestId || '')
        ? metadata.responseRequestId
        : '';

      if (isFeedbackEvent && responseRequestId) {
        payload.conversation_id = this.isUuidV4(this.conversationId || '')
          ? this.conversationId
          : '';
        payload.response_request_id = responseRequestId;
        payload.response_type = this.normalizeTrackToken(metadata && metadata.responseType ? metadata.responseType : '');
      }

      // Assistant-originated telemetry must stay inside Drupal-owned analytics.
      return this.callTrackApi(payload).catch(function () {
        // Silent fail for tracking.
      });
    },

    /**
     * Emit a browser observability event without leaking raw user text.
     */
    emitObservabilityEvent: function (name, detail) {
      if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function' || typeof window.CustomEvent !== 'function') {
        return;
      }

      window.dispatchEvent(new window.CustomEvent(name, {
        detail: detail || {},
      }));
    },

    /**
     * Emit a minimized assistant error payload for browser observability.
     */
    emitAssistantError: function (error, feature, promptForFeedback, metadata) {
      this.emitObservabilityEvent('ilas:assistant:error', Object.assign({
        feature: feature || 'unknown',
        surface: this.isPageMode ? 'assistant-page' : 'assistant-widget',
        pageMode: this.isPageMode,
        status: error && error.status ? error.status : 0,
        type: error && error.type ? String(error.type) : '',
        errorCode: error && error.errorCode ? String(error.errorCode) : '',
        retryAfter: error && error.retryAfter ? String(error.retryAfter) : '',
        promptForFeedback: !!promptForFeedback,
      }, this.normalizeObservabilityMetadata(metadata)));
    },

    /**
     * Emit a minimized assistant action payload for browser observability.
     */
    emitAssistantAction: function (actionType, actionValue, metadata) {
      this.emitObservabilityEvent('ilas:assistant:action', Object.assign({
        actionType: this.normalizeTrackToken(actionType),
        actionValue: this.normalizeTrackValue(actionType, actionValue),
        surface: this.isPageMode ? 'assistant-page' : 'assistant-widget',
        pageMode: this.isPageMode,
      }, this.normalizeObservabilityMetadata(metadata)));
    },

    /**
     * Track a click event.
     */
    trackClick: function (eventType, url) {
      this.trackEvent(eventType, this.normalizeTrackPath(url));
    },

    /**
     * Escape HTML to prevent XSS in element content.
     */
    escapeHtml: function (text) {
      if (!text) return '';
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },

    /**
     * Escape a string for safe use in an HTML attribute value.
     *
     * Covers &, ", ', <, > — the five characters that can break out of
     * a quoted attribute context.
     *
     * @param {string} text - Raw string.
     * @return {string} Attribute-safe string.
     */
    escapeAttr: function (text) {
      if (!text || typeof text !== 'string') return '';
      return text
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    },

    /**
     * Validate and sanitize a URL, blocking dangerous schemes.
     *
     * Allows http:, https:, mailto:, tel:, and relative paths (/ or #).
     * Returns '#' for anything else (javascript:, data:, vbscript:, etc.).
     *
     * @param {string} url - Raw URL from API response or config.
     * @return {string} Sanitized URL or '#'.
     */
    sanitizeUrl: function (url) {
      if (!url || typeof url !== 'string') return '#';

      var trimmed = url.trim();
      if (!trimmed) return '#';

      // Allow relative paths.
      if (trimmed.charAt(0) === '/') return trimmed;
      // Allow fragment-only URLs.
      if (trimmed.charAt(0) === '#') return trimmed;

      // Validate scheme via URL parser.
      try {
        var parsed = new URL(trimmed, window.location.origin);
        var scheme = parsed.protocol.toLowerCase();
        if (scheme === 'http:' || scheme === 'https:' || scheme === 'mailto:' || scheme === 'tel:') {
          return trimmed;
        }
      } catch (e) {
        // URL parsing failed — reject.
      }

      return '#';
    },
  };

  /**
   * Drupal behavior.
   */
  Drupal.behaviors.ilasSiteAssistant = {
    attach: function (context, settings) {
      if (!settings.ilasSiteAssistant) return;

      once('ilas-site-assistant', 'body', context).forEach(function () {
        SiteAssistant.init(settings.ilasSiteAssistant);
      });
    },
  };

})(Drupal, drupalSettings, once);
