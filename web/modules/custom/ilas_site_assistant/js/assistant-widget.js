/**
 * @file
 * ILAS Site Assistant Widget
 *
 * A vanilla JavaScript chat widget for the Idaho Legal Aid Services website.
 * Provides FAQ search, resource discovery, and navigation assistance.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Site Assistant Widget Manager
   */
  const SiteAssistant = {
    config: null,
    widget: null,
    chatContainer: null,
    inputField: null,
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

      // Focus input on page load.
      this.inputField.focus();
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
                aria-label="${Drupal.t('Open Site Assistant')}"
                aria-expanded="false"
                aria-controls="assistant-panel">
          <span class="toggle-icon-open"><i class="fas fa-comment-dots" aria-hidden="true"></i></span>
          <span class="toggle-icon-close"><i class="fas fa-times" aria-hidden="true"></i></span>
          <span class="toggle-label">${Drupal.t('Help')}</span>
        </button>

        <div class="assistant-panel"
             id="assistant-panel"
             role="dialog"
             aria-modal="true"
             aria-label="${Drupal.t('Site Assistant')}"
             hidden>
          <header class="assistant-panel-header">
            <h2 class="panel-title">
              <i class="fas fa-comment-dots" aria-hidden="true"></i>
              ${Drupal.t('Site Assistant')}
            </h2>
            <button type="button"
                    class="panel-close-btn"
                    aria-label="${Drupal.t('Close assistant')}">
              <i class="fas fa-times" aria-hidden="true"></i>
            </button>
          </header>

          <div class="assistant-disclaimer">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            <span>${this.config.disclaimer || Drupal.t('I can help you find information. I cannot provide legal advice.')}</span>
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

      // Focus input.
      setTimeout(() => {
        this.inputField.focus();
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
      const message = this.config.welcomeMessage || Drupal.t('Hello! I can help you find information on our website. What are you looking for today?');
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
      };

      const message = actionMessages[action] || action;

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
          html += this.renderResourceResults(response.results, response.fallback_url);
          break;

        case 'navigation':
          html += this.renderNavigation(response);
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
    renderResourceResults: function (results, fallbackUrl) {
      if (!results || results.length === 0) {
        return `<p><a href="${fallbackUrl}" class="result-link" data-assistant-track="resource_click">${Drupal.t('Browse all resources')}</a></p>`;
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
      chat.appendChild(messageEl);

      // Scroll to bottom.
      chat.scrollTop = chat.scrollHeight;

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
      chat.appendChild(typing);
      chat.scrollTop = chat.scrollHeight;
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
          event: 'ilas_assistant_' + eventType,
          event_category: 'Site Assistant',
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
