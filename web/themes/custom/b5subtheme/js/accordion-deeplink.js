/**
 * @file
 * Accordion deep-link navigation behavior.
 *
 * Handles URL hash navigation for accordion items:
 * - On page load, if URL contains a hash matching an accordion item anchor,
 *   the item is auto-expanded and scrolled into view.
 * - Supports both FAQ accordions and standard accordions.
 * - Works with Search API and chatbot deep-links.
 *
 * Example URLs:
 * - /faq#how-do-i-file-for-eviction-defense
 * - /resources#tenant-rights
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Configuration constants.
   */
  const CONFIG = {
    scrollOffset: 100,      // Pixels to offset scroll (for fixed headers)
    scrollDelay: 150,       // Delay before scrolling (allows accordion to open)
    animationDuration: 300, // Duration of scroll animation
  };

  /**
   * Selectors for accordion items.
   */
  const SELECTORS = {
    accordionItem: '.accordion-item[id]',
    faqItem: '.faq-item[id]',
    collapseElement: '.accordion-collapse',
    accordionButton: '.accordion-button',
  };

  /**
   * Opens an accordion item and scrolls to it.
   *
   * @param {HTMLElement} accordionItem - The accordion item element.
   */
  function openAndScrollToItem(accordionItem) {
    const collapseEl = accordionItem.querySelector(SELECTORS.collapseElement);
    const button = accordionItem.querySelector(SELECTORS.accordionButton);

    if (!collapseEl || !button) {
      return;
    }

    // Use Bootstrap's Collapse API if available
    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
      const bsCollapse = bootstrap.Collapse.getOrCreateInstance(collapseEl, {
        toggle: false,
      });
      bsCollapse.show();
    } else {
      // Fallback: manually add show class
      collapseEl.classList.add('show');
      button.classList.remove('collapsed');
      button.setAttribute('aria-expanded', 'true');
    }

    // Scroll to the item after animation
    setTimeout(function () {
      const rect = accordionItem.getBoundingClientRect();
      const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
      const targetY = rect.top + scrollTop - CONFIG.scrollOffset;

      window.scrollTo({
        top: targetY,
        behavior: 'smooth',
      });

      // Focus the button for accessibility
      button.focus({ preventScroll: true });
    }, CONFIG.scrollDelay);
  }

  /**
   * Finds an accordion item by its anchor ID.
   *
   * @param {string} hash - The URL hash (with or without #).
   * @returns {HTMLElement|null} - The matching accordion item or null.
   */
  function findAccordionByHash(hash) {
    // Remove leading # if present
    const anchor = hash.replace(/^#/, '');

    if (!anchor) {
      return null;
    }

    // Try to find element by ID
    const element = document.getElementById(anchor);

    if (!element) {
      return null;
    }

    // Check if it's an accordion item
    if (element.matches(SELECTORS.accordionItem) || element.matches(SELECTORS.faqItem)) {
      return element;
    }

    return null;
  }

  /**
   * Handles hash change events for deep-linking.
   */
  function handleHashChange() {
    const hash = window.location.hash;

    if (!hash) {
      return;
    }

    const accordionItem = findAccordionByHash(hash);

    if (accordionItem) {
      openAndScrollToItem(accordionItem);
    }
  }

  /**
   * Drupal behavior for accordion deep-linking.
   */
  Drupal.behaviors.accordionDeeplink = {
    attach: function (context) {
      // Only run once on document
      once('accordion-deeplink', 'html', context).forEach(function () {
        // Handle initial page load with hash
        if (window.location.hash) {
          // Wait for DOM and Bootstrap to be ready
          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', handleHashChange);
          } else {
            // Small delay to ensure accordions are initialized
            setTimeout(handleHashChange, 100);
          }
        }

        // Handle hash changes (e.g., clicking internal links)
        window.addEventListener('hashchange', handleHashChange);
      });
    },
  };

  /**
   * Expose utility function for programmatic use (e.g., by chatbot).
   *
   * Usage: Drupal.accordionDeeplink.openItem('eviction-rights');
   */
  Drupal.accordionDeeplink = {
    openItem: function (anchor) {
      const accordionItem = findAccordionByHash(anchor);
      if (accordionItem) {
        openAndScrollToItem(accordionItem);
        // Update URL hash without triggering scroll
        history.pushState(null, null, '#' + anchor);
        return true;
      }
      return false;
    },

    /**
     * Get all available anchors on the page.
     *
     * @returns {Array} - Array of anchor IDs.
     */
    getAnchors: function () {
      const items = document.querySelectorAll(
        SELECTORS.accordionItem + ', ' + SELECTORS.faqItem
      );
      return Array.from(items).map(function (item) {
        return item.id;
      }).filter(Boolean);
    },
  };

})(Drupal, once);
