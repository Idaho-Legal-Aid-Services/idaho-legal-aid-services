/**
 * @file
 * Scroll behaviors for sticky navbar, back-to-top button, and back button fallback.
 *
 * Uses Drupal.behaviors + once() for BigPipe/AJAX compatibility.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Set --scrollbar-width CSS custom property on :root
   * Required for full-bleed elements using calc(100vw - var(--scrollbar-width))
   * This prevents horizontal overflow caused by 100vw including scrollbar width
   */
  function setScrollbarWidth() {
    var scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.documentElement.style.setProperty('--scrollbar-width', scrollbarWidth + 'px');
  }

  // Set on load and resize (scrollbar may appear/disappear based on content height)
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', setScrollbarWidth);
  } else {
    setScrollbarWidth();
  }
  window.addEventListener('resize', setScrollbarWidth);

  /**
   * Scroll behavior for navbar shrinking and back-to-top button visibility
   */
  Drupal.behaviors.scrollBehaviors = {
    attach: function (context, settings) {
      // Only attach scroll listener once per page (not per AJAX update)
      once('scroll-behaviors', 'body', context).forEach(function () {
        // Ensure scrollbar width is set (in case of AJAX content changes)
        setScrollbarWidth();

        var navbar = document.querySelector('.centered-logo-navbar');
        var backToTopBtn = document.querySelector('.back-to-top');
        var scrollThreshold = 100;
        var shrinkThreshold = 50;

        if (!navbar && !backToTopBtn) {
          return;
        }

        /**
         * Handle scroll events
         */
        function handleScroll() {
          var scrolled = window.pageYOffset || document.documentElement.scrollTop;

          // Handle navbar shrinking
          if (navbar) {
            if (scrolled > shrinkThreshold) {
              navbar.classList.add('navbar-shrink');
            } else {
              navbar.classList.remove('navbar-shrink');
            }
          }

          // Handle back-to-top button visibility
          if (backToTopBtn) {
            if (scrolled > scrollThreshold) {
              backToTopBtn.classList.add('show');
            } else {
              backToTopBtn.classList.remove('show');
            }
          }
        }

        // Attach scroll listener with passive option for performance
        window.addEventListener('scroll', handleScroll, { passive: true });

        // Initial check on page load
        handleScroll();
      });
    }
  };

  /**
   * Back-to-top button click behavior
   */
  Drupal.behaviors.backToTop = {
    attach: function (context, settings) {
      once('back-to-top', '.back-to-top', context).forEach(function (backToTopBtn) {
        backToTopBtn.addEventListener('click', function (e) {
          e.preventDefault();
          window.scrollTo({
            top: 0,
            behavior: 'smooth'
          });
        });
      });
    }
  };

  /**
   * Back button progressive enhancement.
   * Uses browser history only when referrer is same-origin and meaningful.
   * Falls back to href attribute for direct page access (bookmarks, Google, etc.).
   */
  Drupal.behaviors.backButtonFallback = {
    attach: function (context, settings) {
      once('back-button-fallback', '[data-back-fallback]', context).forEach(function (button) {
        button.addEventListener('click', function (e) {
          var referrer = document.referrer;
          var currentOrigin = window.location.origin;
          var currentUrl = window.location.href;

          // Only use history.back() if:
          // 1. There is a referrer
          // 2. Referrer is same-origin (not from external site)
          // 3. Referrer is not the current page (avoid loops)
          // 4. Browser has history to go back to
          if (
            referrer &&
            referrer.indexOf(currentOrigin) === 0 &&
            referrer !== currentUrl &&
            window.history.length > 1
          ) {
            e.preventDefault();
            window.history.back();
          }
          // Otherwise, let the default href navigation happen
        });
      });
    }
  };

})(Drupal, once);
