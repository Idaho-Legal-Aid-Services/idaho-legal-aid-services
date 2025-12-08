(function (Drupal, once) {
  'use strict';

  /**
   * Utility Bar Search Overlay functionality with AJAX
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
      this.searchResults = null;
      this.searchLoading = null;
      this.searchTimeout = null;
      this.isOpen = false;
      this.abortController = null;
      
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
      this.searchResults = this.searchOverlay.querySelector('.search-results-dropdown');
      this.searchLoading = this.searchOverlay.querySelector('.search-loading');
      
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
      
      // Handle input changes for live search
      if (this.searchInput) {
        this.searchInput.addEventListener('input', (e) => {
          this.handleSearchInput(e.target.value);
        });
        
        // Handle keyboard navigation in results
        this.searchInput.addEventListener('keydown', (e) => {
          this.handleResultsNavigation(e);
        });
      }
      
      // Click outside results to close
      document.addEventListener('click', (e) => {
        if (this.isOpen && !this.searchOverlay.contains(e.target)) {
          this.hideResults();
        }
      });
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
      // Cancel any pending search
      if (this.abortController) {
        this.abortController.abort();
      }
      
      // Show utility bar content
      this.utilityBarContent.style.display = 'flex';
      
      // Hide search overlay
      this.searchOverlay.style.display = 'none';
      this.searchOverlay.setAttribute('aria-hidden', 'true');
      
      // Update toggle button
      this.searchToggle.setAttribute('aria-expanded', 'false');
      
      // Clear search input and results
      if (this.searchInput) {
        this.searchInput.value = '';
      }
      this.hideResults();
      
      // Return focus to toggle button
      this.searchToggle.focus();
      
      this.isOpen = false;
    }
    
    handleSearchInput(value) {
      // Clear existing timeout
      if (this.searchTimeout) {
        clearTimeout(this.searchTimeout);
      }
      
      // Cancel previous request
      if (this.abortController) {
        this.abortController.abort();
      }
      
      const query = value.trim();
      
      // Hide results if query is empty
      if (query.length === 0) {
        this.hideResults();
        return;
      }
      
      // Show loading after a short delay
      this.searchTimeout = setTimeout(() => {
        if (query.length >= 2) { // Minimum 2 characters
          this.performSearch(query);
        }
      }, 300); // 300ms debounce
    }
    
    async performSearch(query) {
      // Show loading
      this.showLoading();
      
      // Create new abort controller for this request
      this.abortController = new AbortController();
      
      try {
        // Make AJAX request to Drupal's search API
        const response = await fetch(`/search/node?keys=${encodeURIComponent(query)}&_format=json`, {
          method: 'GET',
          headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
          },
          signal: this.abortController.signal
        });
        
        if (!response.ok) {
          throw new Error('Search request failed');
        }
        
        const data = await response.json();
        this.displayResults(data, query);
        
      } catch (error) {
        if (error.name !== 'AbortError') {
          console.error('Search error:', error);
          this.showError();
        }
      } finally {
        this.hideLoading();
      }
    }
    
    displayResults(data, query) {
      const resultsContent = this.searchResults.querySelector('.search-results-content');
      
      if (!data || data.length === 0) {
        resultsContent.innerHTML = `
          <div class="search-no-results">
            <p>No results found for "<strong>${this.escapeHtml(query)}</strong>"</p>
          </div>
        `;
      } else {
        // Build results HTML
        const resultsHtml = data.slice(0, 5).map(item => {
          const title = this.highlightMatch(item.title || 'Untitled', query);
          const snippet = item.snippet ? this.highlightMatch(item.snippet, query) : '';
          const url = item.link || '#';
          
          return `
            <a href="${url}" class="search-result-item" tabindex="0">
              <div class="search-result-title">${title}</div>
              ${snippet ? `<div class="search-result-snippet">${snippet}</div>` : ''}
            </a>
          `;
        }).join('');
        
        resultsContent.innerHTML = resultsHtml;
        
        // Add "View all results" link if there are more results
        if (data.length > 5) {
          resultsContent.innerHTML += `
            <a href="/search?keys=${encodeURIComponent(query)}" class="search-view-all">
              View all ${data.length} results <i class="fa-solid fa-arrow-right"></i>
            </a>
          `;
        }
      }
      
      this.showResults();
    }
    
    highlightMatch(text, query) {
      const escaped = this.escapeHtml(text);
      const regex = new RegExp(`(${this.escapeRegex(query)})`, 'gi');
      return escaped.replace(regex, '<mark>$1</mark>');
    }
    
    showResults() {
      this.searchResults.style.display = 'block';
      // Position dropdown below the search bar
      const searchWrapper = this.searchOverlay.querySelector('.search-input-wrapper');
      const rect = searchWrapper.getBoundingClientRect();
      this.searchResults.style.top = `${rect.height + 5}px`;
    }
    
    hideResults() {
      this.searchResults.style.display = 'none';
    }
    
    showLoading() {
      if (this.searchLoading) {
        this.searchLoading.style.display = 'flex';
      }
    }
    
    hideLoading() {
      if (this.searchLoading) {
        this.searchLoading.style.display = 'none';
      }
    }
    
    showError() {
      const resultsContent = this.searchResults.querySelector('.search-results-content');
      resultsContent.innerHTML = `
        <div class="search-error">
          <p>An error occurred while searching. Please try again.</p>
        </div>
      `;
      this.showResults();
    }
    
    handleResultsNavigation(e) {
      const results = this.searchResults.querySelectorAll('.search-result-item, .search-view-all');
      const currentIndex = Array.from(results).findIndex(el => el === document.activeElement);
      
      switch(e.key) {
        case 'ArrowDown':
          e.preventDefault();
          if (currentIndex < results.length - 1) {
            results[currentIndex + 1].focus();
          } else if (currentIndex === -1 && results.length > 0) {
            results[0].focus();
          }
          break;
          
        case 'ArrowUp':
          e.preventDefault();
          if (currentIndex > 0) {
            results[currentIndex - 1].focus();
          } else if (currentIndex === 0) {
            this.searchInput.focus();
          }
          break;
      }
    }
    
    escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
    
    escapeRegex(text) {
      return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
  }

})(Drupal, once);