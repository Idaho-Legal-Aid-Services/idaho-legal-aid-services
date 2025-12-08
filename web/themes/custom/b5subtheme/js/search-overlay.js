(function (Drupal, once) {
  'use strict';

  /**
   * Utility Bar Search Overlay functionality
   */
  Drupal.behaviors.searchOverlay = {
    attach: function (context, settings) {
      // Only initialize once on the main utility bar
      if (context === document) {
        once('search-overlay-init', '.utility-bar', context).forEach(function() {
          new UtilityBarSearch();
        });
      }
    }
  };

  /**
   * UtilityBarSearch class for managing search overlay in utility bar
   */
  class UtilityBarSearch {
    constructor() {
      this.utilityBar = document.querySelector('.utility-bar');
      this.searchOverlay = document.getElementById('searchOverlay');
      this.searchToggle = document.getElementById('searchToggle');
      this.utilityBarContent = document.getElementById('utilityBarContent');
      this.searchInput = null;
      this.isOpen = false;

      if (!this.searchOverlay || !this.searchToggle || !this.utilityBarContent) {
        return;
      }

      this.init();
    }

    init() {
      // Move search overlay inside utility bar container if not already there
      const container = this.utilityBar.querySelector('.container');
      if (this.searchOverlay.parentElement !== container) {
        container.appendChild(this.searchOverlay);
      }

      // Hide search overlay initially and ensure it takes full container height
      this.searchOverlay.style.display = 'none';
      this.searchOverlay.style.height = '100%';
      this.searchOverlay.setAttribute('aria-hidden', 'true');

      // Cache DOM elements
      this.searchInput = this.searchOverlay.querySelector('.search-input');

      // Bind events
      this.bindEvents();
    }

    bindEvents() {
      // Search toggle button
      this.searchToggle.addEventListener('click', (e) => {
        e.preventDefault();
        this.toggleSearch();
      });

      // Close button in search overlay
      const closeButton = this.searchOverlay.querySelector('.search-close');
      if (closeButton) {
        closeButton.addEventListener('click', (e) => {
          e.preventDefault();
          this.closeSearch();
        });
      }

      // Handle ESC key
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && this.isOpen) {
          this.closeSearch();
        }
      });

      // Handle form submission (for Enter key)
      const searchForm = this.searchOverlay.querySelector('form');
      if (searchForm) {
        searchForm.addEventListener('submit', (e) => {
          e.preventDefault();
          const query = this.searchInput.value.trim();
          if (query.length > 0) {
            // Redirect to search page with query
            window.location.href = `/search?keys=${encodeURIComponent(query)}`;
          }
        });
      }
    }

    toggleSearch() {
      if (this.isOpen) {
        this.closeSearch();
      } else {
        this.openSearch();
      }
    }

    openSearch() {
      // Hide utility bar content
      this.utilityBarContent.style.display = 'none';

      // Show search overlay
      this.searchOverlay.style.display = 'flex';
      this.searchOverlay.setAttribute('aria-hidden', 'false');

      // Update toggle button
      this.searchToggle.setAttribute('aria-expanded', 'true');

      // Focus search input
      if (this.searchInput) {
        setTimeout(() => {
          this.searchInput.focus();
        }, 100);
      }

      this.isOpen = true;
    }

    closeSearch() {
      // Show utility bar content
      this.utilityBarContent.style.display = 'flex';

      // Hide search overlay
      this.searchOverlay.style.display = 'none';
      this.searchOverlay.setAttribute('aria-hidden', 'true');

      // Update toggle button
      this.searchToggle.setAttribute('aria-expanded', 'false');

      // Clear search input
      if (this.searchInput) {
        this.searchInput.value = '';
      }

      // Return focus to toggle button
      this.searchToggle.focus();

      this.isOpen = false;
    }
  }

})(Drupal, once);
