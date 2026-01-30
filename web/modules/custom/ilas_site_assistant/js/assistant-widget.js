/**
 * @file
 * Aila Chat Widget
 *
 * Aila (eye-luh) is the Idaho Legal Aid Services chat assistant.
 * The name is derived from ILAS. Provides FAQ search, resource discovery,
 * and navigation assistance.
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
    messageHistory: [],

    /**
     * Initialize the assistant.
     */
    init: function (config) {
      this.config = config;
      this.isPageMode = config.pageMode || false;

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
            <span>${this.config.disclaimer || (Drupal.t('I can help you find information on our website. But I') + ' <strong>' + Drupal.t('cannot') + '</strong> ' + Drupal.t('provide legal advice.'))}</span>
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
            <a href="${this.config.canonicalUrls.hotline}" class="footer-link" data-assistant-track="hotline_click">
              ${Drupal.t('Call Hotline')}
            </a>
            <span class="footer-divider">|</span>
            <a href="${this.config.canonicalUrls.apply}" class="footer-link" data-assistant-track="apply_click">
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
      const panel = this.widget.querySelector('#assistant-panel');
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

      // Return focus to toggle.
      toggle.focus();
    },

    /**
     * Create a focus trap within an element.
     */
    createFocusTrap: function (element) {
      const focusableElements = element.querySelectorAll(
        'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
      );
      const firstFocusable = focusableElements[0];
      const lastFocusable = focusableElements[focusableElements.length - 1];

      element.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab') return;

        if (e.shiftKey) {
          if (document.activeElement === firstFocusable) {
            lastFocusable.focus();
            e.preventDefault();
          }
        } else {
          if (document.activeElement === lastFocusable) {
            firstFocusable.focus();
            e.preventDefault();
          }
        }
      });
    },

    /**
     * Show welcome message.
     */
    showWelcomeMessage: function () {
      const message = this.config.welcomeMessage || Drupal.t('Hi, I\'m Aila (eye-luh), your guide to Idaho Legal Aid Services. How can I help you today?');
      this.addMessage('assistant', message);
    },

    /**
     * Send a message to the API.
     */
    sendMessage: function () {
      const input = this.isPageMode
        ? document.getElementById('assistant-input')
        : this.widget.querySelector('.assistant-input');

      const message = input.value.trim();
      if (!message) return;

      // Add user message to chat.
      this.addMessage('user', message);

      // Clear input.
      input.value = '';

      // Show typing indicator.
      this.showTyping();

      // Send to API.
      this.callApi('/message', {
        method: 'POST',
        body: JSON.stringify({
          message: message,
          context: {
            history: this.messageHistory.slice(-5),
          },
        }),
      })
        .then(response => {
          this.hideTyping();
          this.handleResponse(response);
        })
        .catch(error => {
          this.hideTyping();
          this.addMessage('assistant', Drupal.t('Sorry, something went wrong. Please try again or contact us directly.'));
          console.error('ILAS Assistant API error:', error);
        });
    },

    /**
     * Handle quick action buttons.
     */
    handleQuickAction: function (action) {
      // Messages must match IntentRouter patterns.
      const actionMessages = {
        forms: Drupal.t('Find a form'),
        guides: Drupal.t('Find a guide'),
        faq: Drupal.t('Show me FAQs'),
        apply: Drupal.t('Apply for help'),
        hotline: Drupal.t('Call the hotline'),
        topics: Drupal.t('What services do you offer?'),
        // Topic/service area actions.
        topic_housing: Drupal.t('Help with housing'),
        topic_family: Drupal.t('Help with family law'),
        topic_seniors: Drupal.t('Help for seniors'),
        topic_benefits: Drupal.t('Help with benefits'),
        topic_consumer: Drupal.t('Help with debt'),
        topic_civil_rights: Drupal.t('Help with discrimination'),
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
      };

      const message = actionMessages[action] || action;

      // Track suggestion click events for debugging.
      this.trackEvent('suggestion_click', action);

      // Add as user message.
      this.addMessage('user', message);

      // Show typing.
      this.showTyping();

      // Send to API.
      this.callApi('/message', {
        method: 'POST',
        body: JSON.stringify({
          message: message,
          context: { quickAction: action },
        }),
      })
        .then(response => {
          this.hideTyping();
          this.handleResponse(response);
        })
        .catch(error => {
          this.hideTyping();
          this.addMessage('assistant', Drupal.t('Sorry, something went wrong.'));
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
        html += `<p>${this.escapeHtml(response.message)}</p>`;
      }

      // Handle different response types.
      switch (response.type) {
        case 'faq':
          html += this.renderFaqResults(response.results, response.fallback_url);
          break;

        case 'resources':
          html += this.renderResourceResults(response.results, response.fallback_url, response.fallback_label);
          if (response.disclaimer) {
            html += `<p class="resource-disclaimer"><em>${this.escapeHtml(response.disclaimer)}</em></p>`;
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
            html += `<p class="eligibility-caveat"><em>${this.escapeHtml(response.caveat)}</em></p>`;
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

        case 'form_finder_clarify':
        case 'guide_finder_clarify':
          // Multi-turn finder: show topic chips for narrowing down.
          if (response.topic_suggestions) {
            html += this.renderTopicSuggestions(response.topic_suggestions);
          }
          // Show "Browse All ..." fallback link.
          if (response.primary_action && response.primary_action.url) {
            html += `<p class="form-finder-fallback"><a href="${response.primary_action.url}" class="result-link" data-assistant-track="resource_click">${this.escapeHtml(response.primary_action.label)}</a></p>`;
          }
          break;

        case 'fallback':
          // Show topic suggestions first if present.
          if (response.topic_suggestions) {
            html += this.renderTopicSuggestions(response.topic_suggestions);
          }
          if (response.suggestions) {
            html += this.renderSuggestions(response.suggestions);
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

      this.addMessage('assistant', html, true);
    },

    /**
     * Render FAQ results.
     */
    renderFaqResults: function (results, fallbackUrl) {
      if (!results || results.length === 0) {
        return `<p><a href="${fallbackUrl}" class="result-link" data-assistant-track="resource_click">${Drupal.t('Browse all FAQs')}</a></p>`;
      }

      let html = '<div class="faq-results">';
      results.forEach(faq => {
        html += `
          <div class="faq-result">
            <h4 class="faq-question">${this.escapeHtml(faq.question)}</h4>
            <p class="faq-answer">${this.escapeHtml(faq.answer)}</p>
            <a href="${faq.url}" class="faq-link" data-assistant-track="resource_click">${Drupal.t('Read more')}</a>
          </div>
        `;
      });
      html += '</div>';
      return html;
    },

    /**
     * Render resource results.
     */
    renderResourceResults: function (results, fallbackUrl, fallbackLabel) {
      if (!results || results.length === 0) {
        const label = fallbackLabel || Drupal.t('Browse all resources');
        return `<p><a href="${fallbackUrl}" class="result-link" data-assistant-track="resource_click">${label}</a></p>`;
      }

      let html = '<ul class="resource-results">';
      results.forEach(resource => {
        const icon = resource.type === 'form' ? 'file-alt' : (resource.type === 'guide' ? 'book' : 'file');
        html += `
          <li class="resource-result">
            <a href="${resource.url}" class="resource-link" data-assistant-track="resource_click">
              <i class="fas fa-${icon}" aria-hidden="true"></i>
              <span class="resource-title">${this.escapeHtml(resource.title)}</span>
              ${resource.has_file ? '<span class="badge">PDF</span>' : ''}
            </a>
            ${resource.description ? `<p class="resource-desc">${this.escapeHtml(resource.description)}</p>` : ''}
          </li>
        `;
      });
      html += '</ul>';
      return html;
    },

    /**
     * Render navigation response.
     */
    renderNavigation: function (response) {
      let html = '';
      if (response.url) {
        html += `
          <p>
            <a href="${response.url}" class="cta-button" data-assistant-track="resource_click">
              ${response.cta || Drupal.t('Go to page')}
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
          </p>
        `;
      }
      return html;
    },

    /**
     * Render the apply CTA response with three application methods.
     */
    renderApplyCta: function (response) {
      let html = '<div class="apply-methods">';

      if (response.apply_methods && response.apply_methods.length > 0) {
        response.apply_methods.forEach(method => {
          const iconClass = method.icon || 'arrow-right';
          html += `<div class="apply-method apply-method--${this.escapeHtml(method.method)}">`;
          html += `<h4 class="apply-method__heading"><i class="fas fa-${iconClass}" aria-hidden="true"></i> ${this.escapeHtml(method.heading)}</h4>`;
          html += `<p class="apply-method__desc">${this.escapeHtml(method.description)}</p>`;
          html += `<a href="${method.cta_url}" class="cta-button" data-assistant-track="apply_cta_click">${this.escapeHtml(method.cta_label)} <i class="fas fa-arrow-right" aria-hidden="true"></i></a>`;

          if (method.secondary_label && method.secondary_url) {
            html += ` <a href="${method.secondary_url}" class="apply-method__secondary-link" data-assistant-track="apply_secondary_click">${this.escapeHtml(method.secondary_label)}</a>`;
          }

          html += `</div>`;
        });
      }

      html += '</div>';

      if (response.followup) {
        html += `<p class="apply-followup"><em>${this.escapeHtml(response.followup)}</em></p>`;
      }

      return html;
    },

    /**
     * Render topic info.
     */
    renderTopicInfo: function (topic, serviceAreaUrl) {
      let html = '';
      if (topic && topic.service_areas && topic.service_areas.length > 0) {
        html += '<p>' + Drupal.t('Related service areas:') + '</p><ul>';
        topic.service_areas.forEach(area => {
          html += `<li>${this.escapeHtml(area.name)}</li>`;
        });
        html += '</ul>';
      }
      if (serviceAreaUrl) {
        html += `
          <p>
            <a href="${serviceAreaUrl}" class="cta-button" data-assistant-track="resource_click">
              ${Drupal.t('Learn more')}
              <i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
          </p>
        `;
      }
      return html;
    },

    /**
     * Render escalation actions.
     */
    renderEscalation: function (actions) {
      if (!actions || actions.length === 0) return '';

      let html = '<div class="escalation-actions">';
      actions.forEach(action => {
        const trackType = action.type + '_click';
        html += `
          <a href="${action.url}" class="escalation-btn" data-assistant-track="${trackType}">
            ${this.escapeHtml(action.label)}
          </a>
        `;
      });
      html += '</div>';
      return html;
    },

    /**
     * Render suggestions.
     */
    renderSuggestions: function (suggestions) {
      if (!suggestions || suggestions.length === 0) return '';

      let html = '<div class="inline-suggestions">';
      suggestions.forEach(suggestion => {
        html += `
          <button type="button" class="inline-suggestion-btn" data-action="${suggestion.action}">
            ${this.escapeHtml(suggestion.label)}
          </button>
        `;
      });
      html += '</div>';
      return html;
    },

    /**
     * Render topic suggestions (for fallback).
     */
    renderTopicSuggestions: function (suggestions) {
      if (!suggestions || suggestions.length === 0) return '';

      let html = '<div class="topic-suggestions">';
      suggestions.forEach(suggestion => {
        html += `
          <button type="button" class="topic-suggestion-btn" data-action="${suggestion.action}">
            ${this.escapeHtml(suggestion.label)}
          </button>
        `;
      });
      html += '</div>';
      return html;
    },

    /**
     * Render links (for policy responses and eligibility).
     */
    renderLinks: function (links) {
      if (!links || links.length === 0) return '';

      let html = '<div class="response-links">';
      links.forEach(link => {
        const trackType = (link.type || 'link') + '_click';
        html += `
          <a href="${link.url}" class="response-link-btn" data-assistant-track="${trackType}">
            ${this.escapeHtml(link.label)}
          </a>
        `;
      });
      html += '</div>';
      return html;
    },

    /**
     * Render service areas (for services overview).
     */
    renderServiceAreas: function (areas) {
      if (!areas || areas.length === 0) return '';

      let html = '<div class="service-areas-grid">';
      areas.forEach(area => {
        html += `
          <a href="${area.url}" class="service-area-btn" data-assistant-track="service_area_click">
            ${this.escapeHtml(area.label)}
          </a>
        `;
      });
      html += '</div>';
      return html;
    },

    /**
     * Add a message to the chat.
     */
    addMessage: function (sender, content, isHtml = false) {
      const chat = this.isPageMode
        ? document.getElementById('assistant-chat')
        : this.widget.querySelector('.assistant-chat');

      if (!chat) return;

      const messageEl = document.createElement('div');
      messageEl.className = `chat-message chat-message--${sender}`;

      const contentEl = document.createElement('div');
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
      messageEl.querySelectorAll('.inline-suggestion-btn, .topic-suggestion-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
          this.handleQuickAction(e.currentTarget.dataset.action);
        });
      });

      // Bind tracking to new links.
      messageEl.querySelectorAll('[data-assistant-track]').forEach(link => {
        link.addEventListener('click', (e) => {
          const trackType = e.currentTarget.dataset.assistantTrack;
          this.trackClick(trackType, e.currentTarget.href);
        });
      });
    },

    /**
     * Show typing indicator.
     */
    showTyping: function () {
      const chat = this.isPageMode
        ? document.getElementById('assistant-chat')
        : this.widget.querySelector('.assistant-chat');

      const typing = document.createElement('div');
      typing.className = 'chat-message chat-message--assistant chat-message--typing';
      typing.id = 'typing-indicator';
      typing.innerHTML = `
        <div class="typing-indicator">
          <span></span><span></span><span></span>
        </div>
      `;

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
      const typing = document.getElementById('typing-indicator');
      if (typing) {
        typing.remove();
      }
    },

    /**
     * Call the API.
     */
    callApi: function (endpoint, options = {}) {
      const url = this.config.apiBase + endpoint;
      const defaultOptions = {
        method: 'GET',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': this.config.csrfToken,
        },
        credentials: 'same-origin',
      };

      const fetchOptions = { ...defaultOptions, ...options };

      return fetch(url, fetchOptions)
        .then(response => {
          if (!response.ok) {
            throw new Error('API request failed');
          }
          return response.json();
        });
    },

    /**
     * Track an event.
     */
    trackEvent: function (eventType, eventValue = '') {
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
      this.callApi('/message', {
        method: 'POST',
        body: JSON.stringify({
          message: '__track__',
          context: {
            track: {
              type: eventType,
              value: eventValue,
            },
          },
        }),
      }).catch(() => {
        // Silent fail for tracking.
      });
    },

    /**
     * Track a click event.
     */
    trackClick: function (eventType, url) {
      // Extract path from URL.
      let path = url;
      try {
        const urlObj = new URL(url, window.location.origin);
        path = urlObj.pathname;
      } catch (e) {
        // Use as-is if URL parsing fails.
      }

      this.trackEvent(eventType, path);
    },

    /**
     * Escape HTML to prevent XSS.
     */
    escapeHtml: function (text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    },
  };

  /**
   * Drupal behavior.
   */
  Drupal.behaviors.ilasSiteAssistant = {
    attach: function (context, settings) {
      if (!settings.ilasSiteAssistant) return;

      once('ilas-site-assistant', 'body', context).forEach(() => {
        SiteAssistant.init(settings.ilasSiteAssistant);
      });
    },
  };

})(Drupal, drupalSettings, once);
