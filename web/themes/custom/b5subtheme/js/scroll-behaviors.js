/**
 * @file
 * Scroll behaviors for sticky navbar, back-to-top button, and back button fallback.
 *
 * Uses Drupal.behaviors + once() for BigPipe/AJAX compatibility.
 *
 * CHANGELOG (v3.0 - Direction-Aware Jitter Fix):
 * - Shrink only when scrolling DOWN past threshold
 * - Expand only when scrolling UP and near top
 * - Hysteresis gap larger than header height delta (120px shrink, 20px expand)
 * - Cooldown matches CSS transition duration (275ms)
 * - Short-page guard uses expanded-equivalent maxScroll to prevent flip-flop
 * - Header delta measured via off-screen clone (no layout impact)
 * - Initializes correct state on page load (handles scroll restoration)
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
   *
   * Direction-aware with hysteresis to prevent jitter:
   * - Shrink only when scrolling DOWN and past SHRINK_AT threshold
   * - Expand only when scrolling UP and near top (below EXPAND_AT)
   * - Cooldown prevents rapid toggling during CSS transition
   * - Short-page guard uses expanded-equivalent maxScroll calculation
   */
  Drupal.behaviors.scrollBehaviors = {
    attach: function (context, settings) {
      // Always update CSS var on every attach (AJAX content may change scrollbar)
      setScrollbarWidth();

      // Only add listeners once (use document for reliable targeting)
      once('scroll-behaviors', 'html', document).forEach(function () {
        var navbar = document.querySelector('.centered-logo-navbar');
        var backToTopBtn = document.querySelector('.back-to-top');

        // === Configuration ===
        // Thresholds tuned to prevent oscillation
        // Gap (120 - 20 = 100px) must be larger than header height delta (~50px)
        var SHRINK_AT = 120;        // Scroll down past this to shrink
        var EXPAND_AT = 20;         // Scroll up to near-top to expand
        var SAFETY_MARGIN = 10;     // Extra margin for short-page guard
        var EPS = 1;                // Ignore tiny fractional scroll changes
        var COOLDOWN_MS = 275;      // Must be >= CSS transition duration
        var BACK_TO_TOP_AT = 100;   // Show back-to-top button threshold

        // State tracking
        var lastY = window.scrollY || window.pageYOffset || 0;
        var ticking = false;
        var cooldownUntil = 0;
        var headerHeightDelta = 0;

        if (!navbar && !backToTopBtn) {
          return;
        }

        /**
         * Measure header height delta using a cloned navbar.
         * Uses off-screen clone to avoid scroll/layout impact.
         * Called once on initialization and on resize (breakpoint changes).
         */
        function measureHeaderDelta() {
          if (!navbar) {
            return 0;
          }

          // Clone the navbar so we can measure without affecting layout/scroll
          var clone = navbar.cloneNode(true);

          // Keep it in the same parent so inherited styles still apply
          clone.style.position = 'absolute';
          clone.style.left = '-99999px';
          clone.style.top = '0';
          clone.style.visibility = 'hidden';
          clone.style.pointerEvents = 'none';
          clone.style.height = 'auto';

          // Match width so wrapping/line breaks match the real navbar
          var rect = navbar.getBoundingClientRect();
          clone.style.width = rect.width + 'px';

          navbar.parentNode.appendChild(clone);

          // Measure expanded height
          clone.classList.remove('navbar-shrink');
          var expandedHeight = clone.offsetHeight;

          // Measure shrunk height
          clone.classList.add('navbar-shrink');
          var shrunkHeight = clone.offsetHeight;

          // Clean up
          clone.parentNode.removeChild(clone);

          return Math.max(0, expandedHeight - shrunkHeight);
        }

        // Measure header delta on initialization
        headerHeightDelta = measureHeaderDelta();

        /**
         * Calculate maximum scroll position (clamped to 0)
         */
        function getMaxScroll() {
          var docHeight = Math.max(
            document.body.scrollHeight,
            document.documentElement.scrollHeight
          );
          return Math.max(0, docHeight - window.innerHeight);
        }

        /**
         * Check if page has enough scroll range for shrinking behavior.
         * Uses stable "expanded-equivalent" maxScroll to prevent guard oscillation.
         */
        function shouldEnableShrinking() {
          var isShrunk = navbar ? navbar.classList.contains('navbar-shrink') : false;
          var maxScrollNow = getMaxScroll();

          // Estimate what maxScroll would be if we were expanded.
          // If currently shrunk, the expanded page would be taller by headerHeightDelta.
          var maxScrollExpanded = maxScrollNow + (isShrunk ? headerHeightDelta : 0);

          // Need enough scroll room: shrink threshold + header delta + safety margin
          var minRequired = SHRINK_AT + headerHeightDelta + SAFETY_MARGIN;
          return maxScrollExpanded > minRequired;
        }

        /**
         * Set navbar shrunk state with cooldown
         */
        function setShrunk(on) {
          if (navbar) {
            navbar.classList.toggle('navbar-shrink', on);
            cooldownUntil = performance.now() + COOLDOWN_MS;
          }
        }

        /**
         * Handle scroll events with direction detection
         */
        function handleScroll() {
          if (ticking) {
            return;
          }
          ticking = true;

          requestAnimationFrame(function () {
            ticking = false;

            var now = performance.now();
            var y = window.scrollY || window.pageYOffset || 0;

            // Handle navbar shrinking
            if (navbar) {
              // Short-page guard: use expanded-equivalent calculation
              if (!shouldEnableShrinking()) {
                // Short page: force expanded, skip direction logic
                if (navbar.classList.contains('navbar-shrink')) {
                  setShrunk(false);
                }
                lastY = y;
                // Still handle back-to-top below
              } else {
                // Determine scroll direction
                var dy = y - lastY;
                var dir = dy > EPS ? 'down' : (dy < -EPS ? 'up' : 'still');
                lastY = y;

                // Skip if within cooldown period
                if (now >= cooldownUntil) {
                  var isShrunk = navbar.classList.contains('navbar-shrink');

                  // Shrink only when moving DOWN and beyond SHRINK_AT
                  if (!isShrunk && dir === 'down' && y >= SHRINK_AT) {
                    setShrunk(true);
                  }
                  // Expand only when moving UP and very near the top
                  else if (isShrunk && dir === 'up' && y <= EXPAND_AT) {
                    setShrunk(false);
                  }
                }
              }
            }

            // Handle back-to-top button visibility (no direction check needed)
            if (backToTopBtn) {
              var scrolled = window.scrollY || window.pageYOffset || 0;
              if (scrolled > BACK_TO_TOP_AT) {
                backToTopBtn.classList.add('show');
              } else {
                backToTopBtn.classList.remove('show');
              }
            }
          });
        }

        /**
         * Handle resize events
         */
        var resizeTimeout;
        function handleResize() {
          clearTimeout(resizeTimeout);
          resizeTimeout = setTimeout(function () {
            // Recalculate scrollbar width
            setScrollbarWidth();

            // Re-measure header delta (may change at different breakpoints)
            headerHeightDelta = measureHeaderDelta();

            // Re-evaluate shrink state after resize
            // If this is now a short page, force expanded
            if (navbar && !shouldEnableShrinking() && navbar.classList.contains('navbar-shrink')) {
              setShrunk(false);
            }
          }, 100);
        }

        /**
         * Initialize correct state based on current scroll position.
         * Handles page loads that are already scrolled (anchor links, scroll restoration, refresh).
         */
        function initializeState() {
          if (!navbar) {
            return;
          }

          var y = window.scrollY || window.pageYOffset || 0;

          if (!shouldEnableShrinking()) {
            // Short page: force expanded
            navbar.classList.remove('navbar-shrink');
          } else if (y >= SHRINK_AT) {
            // Already scrolled past threshold: shrink immediately (no cooldown)
            navbar.classList.add('navbar-shrink');
          } else {
            // Near top: ensure expanded
            navbar.classList.remove('navbar-shrink');
          }

          // Sync lastY to current position
          lastY = y;
        }

        // Initialize state based on current scroll position
        initializeState();

        // Attach scroll listener with passive option for performance
        window.addEventListener('scroll', handleScroll, { passive: true });

        // Attach resize listener for short-page recalculation
        window.addEventListener('resize', handleResize, { passive: true });

        // Handle back-to-top initial state
        if (backToTopBtn) {
          var scrolled = window.scrollY || window.pageYOffset || 0;
          if (scrolled > BACK_TO_TOP_AT) {
            backToTopBtn.classList.add('show');
          }
        }
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

          // Check reduced motion preference
          var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

          window.scrollTo({
            top: 0,
            behavior: prefersReducedMotion ? 'auto' : 'smooth'
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
