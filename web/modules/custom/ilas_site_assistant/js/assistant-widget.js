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
    },

    _hideJumpBtn: function () {
      this.jumpBtn.hidden = true;
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
    csrfTokenPromise: null,
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
     * Fetch a CSRF token from /session/token.
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
      this.csrfTokenPromise = fetch('/session/token', {
        method: 'GET',
        credentials: 'same-origin',
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Failed to fetch CSRF token: ' + response.status);
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
      this.conversationId = this.generateConversationId();

      if (this.isPageMode) {
        this.initPageMode();
      } else {
        this.initWidgetMode();
      }

      this.bindEvents();
      this.showWelcomeMessage();
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

          <div class="assistant-chat" role="log" aria-live="polite"></div>

          <div class="assistant-quick-actions">
            <button type="button" class="quick-action-btn" data-action="forms">
              <i class="fas fa-file-alt" aria-hidden="true"></i> ${Drupal.t('Forms')}
            </button>
            <button type="button" class="quick-action-btn" data-action="guides">
              <i class="fas fa-book" aria-hidden="true"></i> ${Drupal.t('Guides')}
            </button>
            <button type="button" class="quick-action-btn" data-action="faq">
              <i class="fas fa-question-circle" aria-hidden="true"></i> ${Drupal.t('FAQs')}
            </button>
            <button type="button" class="quick-action-btn" data-action="apply">
              <i class="fas fa-hands-helping" aria-hidden="true"></i> ${Drupal.t('Apply')}
            </button>
          </div>

          <form class="assistant-input-form">
            <input type="text"
                   class="assistant-input"
                   placeholder="${Drupal.t('Type your question...')}"
                   autocomplete="off"
                   maxlength="500">
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
          const action = e.currentTarget.dataset.action;
          this.handleQuickAction(action);
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
          const action = e.currentTarget.dataset.action;
          const url = e.currentTarget.dataset.url;
          if (url) {
            this.trackClick(action + '_click', url);
            window.location.href = url;
          } else {
            this.handleQuickAction(action);
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
          context: {
            history: this.messageHistory.slice(-5),
          },
        }),
      })
        .then(function (response) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.handleResponse(response);
        })
        .catch(function (error) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.addMessage('assistant', self.getErrorMessage(error));
          console.error('ILAS Assistant API error:', error);
        });
    },

    /**
     * Handle quick action buttons.
     */
    handleQuickAction: function (action) {
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

      const message = actionMessages[action] || action;

      // Track suggestion click events for debugging.
      this.trackEvent('suggestion_click', action);

      // Lock sending state.
      this.isSending = true;
      this.setSendingState(true);

      // Add as user message.
      this.addMessage('user', message);

      // Show typing.
      this.showTyping();

      var self = this;

      // Send to API.
      this.callApi('/message', {
        method: 'POST',
        body: JSON.stringify({
          message: message,
          conversation_id: this.conversationId,
          context: { quickAction: action },
        }),
      })
        .then(function (response) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.handleResponse(response);
        })
        .catch(function (error) {
          self.isSending = false;
          self.setSendingState(false);
          self.hideTyping();
          self.addMessage('assistant', self.getErrorMessage(error));
        });
    },

    /**
     * Handle API response.
     */
    handleResponse: function (response) {
      if (!response) return;

      // Track topic if present.
      if (response.type === 'topic' && response.topic) {
        this.trackEvent('topic_selected', response.topic.name || '');
      }

      // Build response message.
      let html = '';

      if (response.message) {
        html += '<p>' + this.escapeHtml(response.message) + '</p>';
      }

      // Handle different response types.
      switch (response.type) {
        case 'faq':
          html += this.renderFaqResults(response.results, response.fallback_url);
          break;

        case 'resources':
          html += this.renderResourceResults(response.results, response.fallback_url, response.fallback_label);
          if (response.disclaimer) {
            html += '<p class="resource-disclaimer"><em>' + this.escapeHtml(response.disclaimer) + '</em></p>';
          }
          break;

        case 'navigation':
          html += this.renderNavigation(response);
          break;

        case 'apply_cta':
          html += this.renderApplyCta(response);
          break;

        case 'topic':
          html += this.renderTopicInfo(response.topic, response.service_area_url);
          break;

        case 'escalation':
          // Render links array if present (new format).
          if (response.links && response.links.length > 0) {
            html += this.renderLinks(response.links);
          }
          if (response.actions) {
            html += this.renderEscalation(response.actions);
          }
          break;

        case 'eligibility':
          // Show caveat if present.
          if (response.caveat) {
            html += '<p class="eligibility-caveat"><em>' + this.escapeHtml(response.caveat) + '</em></p>';
          }
          if (response.links && response.links.length > 0) {
            html += this.renderLinks(response.links);
          }
          break;

        case 'services_overview':
          if (response.service_areas && response.service_areas.length > 0) {
            html += this.renderServiceAreas(response.service_areas);
          }
          if (response.url) {
            html += this.renderNavigation(response);
          }
          break;

        case 'forms_inventory':
        case 'guides_inventory':
        case 'services_inventory':
        case 'form_finder_clarify':
        case 'guide_finder_clarify':
          // Multi-turn finder: show topic chips for narrowing down.
          if (response.topic_suggestions) {
            html += this.renderTopicSuggestions(response.topic_suggestions);
          }
          // Show "Browse All ..." fallback link.
          if (response.primary_action && response.primary_action.url) {
            html += '<p class="form-finder-fallback"><a href="' + this.escapeAttr(this.sanitizeUrl(response.primary_action.url)) + '" class="result-link" data-assistant-track="resource_click">' + this.escapeHtml(response.primary_action.label) + '</a></p>';
          }
          break;

        case 'fallback':
          if (response.topic_suggestions) {
            html += this.renderTopicSuggestions(response.topic_suggestions);
          }
          if (response.actions) {
            html += this.renderEscalation(response.actions);
          }
          break;

        case 'greeting':
          if (response.suggestions) {
            html += this.renderSuggestions(response.suggestions);
          }
          break;
      }

      // Render TopIntentsPack chips for any response type that has them.
      if (response.suggestions && !html.includes('topic-suggestions') && !html.includes('inline-suggestions')) {
        html += this.renderSuggestions(response.suggestions);
      }

      this.addMessage('assistant', html, true);
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
     * Render semantic source indicator for vector-sourced results.
     *
     * @param {Object} result - A result object with optional source field.
     * @return {string} HTML string for the indicator, or empty string.
     */
    renderSourceIndicator: function (result) {
      if (!result || result.source !== 'vector') {
        return '';
      }
      return '<span class="source-indicator" aria-label="' + Drupal.t('Found via similar questions') + '">' +
        '<span aria-hidden="true">&#x1f4a1;</span> ' +
        Drupal.t('Based on similar questions') +
        '</span>';
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
        return '<p><a href="' + this.escapeAttr(this.sanitizeUrl(fallbackUrl)) + '" class="result-link" data-assistant-track="resource_click">' + Drupal.t('Browse all FAQs') + '</a></p>';
      }

      var self = this;
      var mode = this.classifyResults(results);
      var html = '<div class="faq-results">';

      results.forEach(function (faq, index) {
        var isBest = (index === 0 && (mode === 'single_best' || mode === 'best_match'));
        var isSecondary = (!isBest && (mode === 'single_best' || mode === 'best_match'));
        var sourceIndicator = self.renderSourceIndicator(faq);
        var safeUrl = self.escapeAttr(self.sanitizeUrl(faq.url));

        if (isBest) {
          // Elevated best-match card.
          var truncatedAnswer = self.truncateText(self.escapeHtml(faq.answer), 120);
          html += '<div class="faq-result faq-result--best">' +
            '<span class="best-match-label">' + Drupal.t('Best match') + '</span>' +
            sourceIndicator +
            '<h4 class="faq-question">' + self.escapeHtml(faq.question) + '</h4>' +
            '<p class="faq-answer">' + truncatedAnswer + '</p>' +
            '<a href="' + safeUrl + '" class="faq-link" data-assistant-track="resource_click">' + Drupal.t('Read more on page') + ' \u2192</a>' +
            '</div>';
        }
        else if (isSecondary) {
          // Show "Also helpful" heading before the first secondary result.
          if (index === 1) {
            html += '<p class="also-helpful-heading">' + Drupal.t('Also helpful:') + '</p>';
          }
          // Secondary: question + link only.
          html += '<div class="faq-result faq-result--secondary">' +
            sourceIndicator +
            '<a href="' + safeUrl + '" class="faq-link" data-assistant-track="resource_click">' + self.escapeHtml(faq.question) + '</a>' +
            '</div>';
        }
        else {
          // Ambiguous mode: all results equally with truncated answers.
          var truncatedAnswer = self.truncateText(self.escapeHtml(faq.answer), 120);
          html += '<div class="faq-result">' +
            sourceIndicator +
            '<h4 class="faq-question">' + self.escapeHtml(faq.question) + '</h4>' +
            '<p class="faq-answer">' + truncatedAnswer + '</p>' +
            '<a href="' + safeUrl + '" class="faq-link" data-assistant-track="resource_click">' + Drupal.t('Read more on page') + ' \u2192</a>' +
            '</div>';
        }
      });
      html += '</div>';
      return html;
    },

    /**
     * Render resource results.
     */
    renderResourceResults: function (results, fallbackUrl, fallbackLabel) {
      if (!results || results.length === 0) {
        var label = fallbackLabel ? this.escapeHtml(fallbackLabel) : Drupal.t('Browse all resources');
        return '<p><a href="' + this.escapeAttr(this.sanitizeUrl(fallbackUrl)) + '" class="result-link" data-assistant-track="resource_click">' + label + '</a></p>';
      }

      var self = this;
      var mode = this.classifyResults(results);
      var html = '<ul class="resource-results">';

      results.forEach(function (resource, index) {
        var isBest = (index === 0 && (mode === 'single_best' || mode === 'best_match'));
        var isSecondary = (!isBest && (mode === 'single_best' || mode === 'best_match'));
        var icon = resource.type === 'form' ? 'file-alt' : (resource.type === 'guide' ? 'book' : 'file');
        var sourceIndicator = self.renderSourceIndicator(resource);
        var safeUrl = self.escapeAttr(self.sanitizeUrl(resource.url));
        var badge = resource.has_file ? '<span class="badge">PDF</span>' : '';

        if (isBest) {
          html += '<li class="resource-result resource-result--best">' +
            '<span class="best-match-label">' + Drupal.t('Best match') + '</span>' +
            sourceIndicator +
            '<a href="' + safeUrl + '" class="resource-link" data-assistant-track="resource_click">' +
            '<i class="fas fa-' + icon + '" aria-hidden="true"></i>' +
            '<span class="resource-title">' + self.escapeHtml(resource.title) + '</span>' +
            badge +
            '</a>' +
            (resource.description ? '<p class="resource-desc">' + self.escapeHtml(resource.description) + '</p>' : '') +
            '</li>';
          // "Also helpful" heading after best match if there are more results.
          if (results.length > 1) {
            html += '<li class="also-helpful-heading" aria-hidden="true">' + Drupal.t('Also helpful:') + '</li>';
          }
        }
        else if (isSecondary) {
          // Secondary: link only, no description, no background.
          html += '<li class="resource-result resource-result--secondary">' +
            sourceIndicator +
            '<a href="' + safeUrl + '" class="resource-link resource-link--secondary" data-assistant-track="resource_click">' +
            '<i class="fas fa-' + icon + '" aria-hidden="true"></i>' +
            '<span class="resource-title">' + self.escapeHtml(resource.title) + '</span>' +
            badge +
            '</a>' +
            '</li>';
        }
        else {
          // Ambiguous: all equal.
          html += '<li class="resource-result">' +
            sourceIndicator +
            '<a href="' + safeUrl + '" class="resource-link" data-assistant-track="resource_click">' +
            '<i class="fas fa-' + icon + '" aria-hidden="true"></i>' +
            '<span class="resource-title">' + self.escapeHtml(resource.title) + '</span>' +
            badge +
            '</a>' +
            (resource.description ? '<p class="resource-desc">' + self.escapeHtml(resource.description) + '</p>' : '') +
            '</li>';
        }
      });
      html += '</ul>';
      return html;
    },

    /**
     * Render navigation response.
     */
    renderNavigation: function (response) {
      var html = '';
      if (response.url) {
        var safeUrl = this.escapeAttr(this.sanitizeUrl(response.url));
        var ctaText = response.cta ? this.escapeHtml(response.cta) : Drupal.t('Go to page');
        html += '<p>' +
          '<a href="' + safeUrl + '" class="cta-button" data-assistant-track="resource_click">' +
          ctaText +
          ' <i class="fas fa-arrow-right" aria-hidden="true"></i>' +
          '</a>' +
          '</p>';
      }
      return html;
    },

    /**
     * Render the apply CTA response with three application methods.
     */
    renderApplyCta: function (response) {
      var self = this;
      var html = '<div class="apply-methods">';

      if (response.apply_methods && response.apply_methods.length > 0) {
        response.apply_methods.forEach(function (method) {
          var iconClass = self.escapeAttr(method.icon || 'arrow-right');
          var methodClass = self.escapeAttr(method.method || '');
          var ctaUrl = self.escapeAttr(self.sanitizeUrl(method.cta_url));

          html += '<div class="apply-method apply-method--' + methodClass + '">';
          html += '<h4 class="apply-method__heading"><i class="fas fa-' + iconClass + '" aria-hidden="true"></i> ' + self.escapeHtml(method.heading) + '</h4>';
          html += '<p class="apply-method__desc">' + self.escapeHtml(method.description) + '</p>';
          html += '<a href="' + ctaUrl + '" class="cta-button" data-assistant-track="apply_cta_click">' + self.escapeHtml(method.cta_label) + ' <i class="fas fa-arrow-right" aria-hidden="true"></i></a>';

          if (method.secondary_label && method.secondary_url) {
            var secUrl = self.escapeAttr(self.sanitizeUrl(method.secondary_url));
            html += ' <a href="' + secUrl + '" class="apply-method__secondary-link" data-assistant-track="apply_secondary_click">' + self.escapeHtml(method.secondary_label) + '</a>';
          }

          html += '</div>';
        });
      }

      html += '</div>';

      if (response.followup) {
        html += '<p class="apply-followup"><em>' + this.escapeHtml(response.followup) + '</em></p>';
      }

      return html;
    },

    /**
     * Render topic info.
     */
    renderTopicInfo: function (topic, serviceAreaUrl) {
      var self = this;
      var html = '';
      if (topic && topic.service_areas && topic.service_areas.length > 0) {
        html += '<p>' + Drupal.t('Related service areas:') + '</p><ul>';
        topic.service_areas.forEach(function (area) {
          html += '<li>' + self.escapeHtml(area.name) + '</li>';
        });
        html += '</ul>';
      }
      if (serviceAreaUrl) {
        var safeUrl = this.escapeAttr(this.sanitizeUrl(serviceAreaUrl));
        html += '<p>' +
          '<a href="' + safeUrl + '" class="cta-button" data-assistant-track="resource_click">' +
          Drupal.t('Learn more') +
          ' <i class="fas fa-arrow-right" aria-hidden="true"></i>' +
          '</a>' +
          '</p>';
      }
      return html;
    },

    /**
     * Render escalation actions.
     */
    renderEscalation: function (actions) {
      if (!actions || actions.length === 0) return '';

      var self = this;
      var html = '<div class="escalation-actions">';
      actions.forEach(function (action) {
        var safeUrl = self.escapeAttr(self.sanitizeUrl(action.url));
        var trackType = self.escapeAttr((action.type || 'link') + '_click');
        html += '<a href="' + safeUrl + '" class="escalation-btn" data-assistant-track="' + trackType + '">' +
          self.escapeHtml(action.label) +
          '</a>';
      });
      html += '</div>';
      return html;
    },

    /**
     * Render suggestions.
     */
    renderSuggestions: function (suggestions) {
      if (!suggestions || suggestions.length === 0) return '';

      var self = this;
      var html = '<div class="inline-suggestions">';
      suggestions.forEach(function (suggestion) {
        html += '<button type="button" class="inline-suggestion-btn" data-action="' + self.escapeAttr(suggestion.action) + '">' +
          self.escapeHtml(suggestion.label) +
          '</button>';
      });
      html += '</div>';
      return html;
    },

    /**
     * Render topic suggestions (for fallback).
     */
    renderTopicSuggestions: function (suggestions) {
      if (!suggestions || suggestions.length === 0) return '';

      var self = this;
      var html = '<div class="topic-suggestions">';
      suggestions.forEach(function (suggestion) {
        html += '<button type="button" class="topic-suggestion-btn" data-action="' + self.escapeAttr(suggestion.action) + '">' +
          self.escapeHtml(suggestion.label) +
          '</button>';
      });
      html += '</div>';
      return html;
    },

    /**
     * Render links (for policy responses and eligibility).
     */
    renderLinks: function (links) {
      if (!links || links.length === 0) return '';

      var self = this;
      var html = '<div class="response-links">';
      links.forEach(function (link) {
        var safeUrl = self.escapeAttr(self.sanitizeUrl(link.url));
        var trackType = self.escapeAttr((link.type || 'link') + '_click');
        html += '<a href="' + safeUrl + '" class="response-link-btn" data-assistant-track="' + trackType + '">' +
          self.escapeHtml(link.label) +
          '</a>';
      });
      html += '</div>';
      return html;
    },

    /**
     * Render service areas (for services overview).
     */
    renderServiceAreas: function (areas) {
      if (!areas || areas.length === 0) return '';

      var self = this;
      var html = '<div class="service-areas-grid">';
      areas.forEach(function (area) {
        var safeUrl = self.escapeAttr(self.sanitizeUrl(area.url));
        html += '<a href="' + safeUrl + '" class="service-area-btn" data-assistant-track="service_area_click">' +
          self.escapeHtml(area.label) +
          '</a>';
      });
      html += '</div>';
      return html;
    },

    /**
     * Add a message to the chat.
     */
    addMessage: function (sender, content, isHtml) {
      isHtml = isHtml || false;
      var chat = this.isPageMode
        ? document.getElementById('assistant-chat')
        : this.widget.querySelector('.assistant-chat');

      if (!chat) return;

      var messageEl = document.createElement('div');
      messageEl.className = 'chat-message chat-message--' + sender;

      var contentEl = document.createElement('div');
      contentEl.className = 'message-content';

      if (isHtml) {
        contentEl.innerHTML = content;
      } else {
        contentEl.textContent = content;
      }

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
      if (sender === 'user') {
        this.messageHistory.push({ role: 'user', content: typeof content === 'string' ? content : '' });
      }

      // Bind events to new inline buttons.
      var self = this;
      messageEl.querySelectorAll('.inline-suggestion-btn, .topic-suggestion-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          self.handleQuickAction(e.currentTarget.dataset.action);
        });
      });

      // Bind tracking to new links.
      messageEl.querySelectorAll('[data-assistant-track]').forEach(function (link) {
        link.addEventListener('click', function (e) {
          var trackType = e.currentTarget.dataset.assistantTrack;
          self.trackClick(trackType, e.currentTarget.href);
        });
      });
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

        var headers = {
          'Content-Type': 'application/json',
        };
        if (needsCsrf && isCsrfRoute && csrfToken) {
          headers['X-CSRF-Token'] = csrfToken;
        }

        var defaultOptions = {
          method: 'GET',
          headers: headers,
          credentials: 'same-origin',
          signal: controller.signal,
        };

        var fetchOptions = Object.assign({}, defaultOptions, options);
        delete fetchOptions.timeout;

        return fetch(url, fetchOptions)
          .then(function (response) {
            clearTimeout(timeoutId);
            if (!response.ok) {
              var error = new Error('API request failed: ' + response.status);
              error.status = response.status;
              if (response.status === 429) {
                var retryHeader = response.headers.get('Retry-After');
                if (retryHeader) {
                  error.retryAfter = retryHeader;
                }
              }
              throw error;
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

      // 403 Forbidden.
      if (error.status === 403) {
        return Drupal.t('Access denied. Please refresh the page and try again.');
      }

      // 5xx Server errors.
      if (error.status >= 500) {
        return Drupal.t('Our server is having trouble right now. Please try again in a few minutes, or reach us through our hotline.');
      }

      // Generic fallback.
      return Drupal.t('I\'m having trouble right now. You can try again, or reach us directly through our hotline.');
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
     * Track an event.
     */
    trackEvent: function (eventType, eventValue) {
      eventValue = eventValue || '';
      // Push to dataLayer if available.
      if (window.dataLayer) {
        window.dataLayer.push({
          event: 'aila_chat_' + eventType,
          event_category: 'Aila Chat',
          event_action: eventType,
          event_label: eventValue,
        });
      }

      // Also log to server.
      this.callApi('/track', {
        method: 'POST',
        body: JSON.stringify({
          event_type: eventType,
          event_value: eventValue,
        }),
      }).catch(function () {
        // Silent fail for tracking.
      });
    },

    /**
     * Track a click event.
     */
    trackClick: function (eventType, url) {
      // Extract path from URL.
      var path = url;
      try {
        var urlObj = new URL(url, window.location.origin);
        path = urlObj.pathname;
      } catch (e) {
        // Use as-is if URL parsing fails.
      }

      this.trackEvent(eventType, path);
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
