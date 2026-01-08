/**
 * @file
 * Enhanced dropdown menu behavior that works with Bootstrap 5.
 *
 * Provides hover functionality on desktop while preserving Bootstrap's
 * built-in keyboard navigation, Popper positioning, and ARIA handling.
 *
 * Uses Bootstrap's Dropdown API instead of manual class toggling.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.dropdownMenu = {
    attach: function (context, settings) {

      // Only apply hover behavior on desktop (>= 1200px)
      function isDesktop() {
        return window.innerWidth >= 1200;
      }

      // FIX (Cause C): Track whether the most recent interaction was pointer vs keyboard.
      // We want to blur only for pointer clicks (avoid harming keyboard UX).
      // This is set up once per page load via the 'body' once handler.
      once('dropdown-pointer-tracking', 'body', context).forEach(function () {
        window.dropdownLastInputWasPointer = false;

        document.addEventListener('pointerdown', function () {
          window.dropdownLastInputWasPointer = true;
        }, true);

        document.addEventListener('keydown', function (e) {
          // Any keyboard interaction should disable pointer assumption.
          // Tab/Enter/Space are the common ones that trigger click or focus movement.
          if (e.key === 'Tab' || e.key === 'Enter' || e.key === ' ') {
            window.dropdownLastInputWasPointer = false;
          }
        }, true);
      });

      // Initialize dropdowns with hover behavior
      once('dropdown-menu', '.centered-logo-navbar .nav-item.dropdown', context).forEach(function (dropdown) {
        var toggle = dropdown.querySelector('.dropdown-toggle');
        var menu = dropdown.querySelector('.dropdown-menu');

        if (!toggle || !menu) {
          return;
        }

        var hoverTimeout = null;

        /**
         * Get or create Bootstrap Dropdown instance
         * Initialize immediately and ensure clean starting state
         */
        var bsDropdown = null;
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
          bsDropdown = bootstrap.Dropdown.getOrCreateInstance(toggle, { popperConfig: null });
          // Ensure dropdown starts in a clean hidden state
          bsDropdown.hide();
        }

        function getDropdownInstance() {
          return bsDropdown;
        }

        /**
         * Show dropdown using Bootstrap API
         */
        function showDropdown() {
          if (!isDesktop()) {
            return;
          }
          clearTimeout(hoverTimeout);
          dropdown.classList.add('dropdown-hover');
          var instance = getDropdownInstance();
          if (instance) {
            instance.show();
          }
        }

        /**
         * Hide dropdown using Bootstrap API with small delay
         * Delay prevents flickering when moving mouse between toggle and menu
         */
        function hideDropdown() {
          if (!isDesktop()) {
            return;
          }
          hoverTimeout = setTimeout(function () {
            dropdown.classList.remove('dropdown-hover');
            var instance = getDropdownInstance();
            if (instance) {
              instance.hide();
            }
          }, 150);
        }

        /**
         * Cancel pending hide when re-entering dropdown area
         */
        function cancelHide() {
          clearTimeout(hoverTimeout);
        }

        // Hover open - on dropdown container
        dropdown.addEventListener('mouseenter', showDropdown);

        // Hover close - on dropdown container
        dropdown.addEventListener('mouseleave', hideDropdown);

        // Cancel hide when entering menu (prevents flicker)
        menu.addEventListener('mouseenter', cancelHide);

        // Hide when leaving menu
        menu.addEventListener('mouseleave', hideDropdown);

        // Focus behavior for keyboard accessibility (only on keyboard focus, not mouse click)
        toggle.addEventListener('focus', function () {
          if (isDesktop()) {
            // Small delay to avoid conflict with click
            setTimeout(function () {
              if (document.activeElement === toggle && toggle.matches(':focus-visible')) {
                showDropdown();
              }
            }, 100);
          }
        });

        // Blur handler - hide dropdown when focus leaves the entire dropdown area
        dropdown.addEventListener('focusout', function (e) {
          if (isDesktop()) {
            // Check if focus moved outside the dropdown entirely
            setTimeout(function () {
              if (!dropdown.contains(document.activeElement)) {
                hideDropdown();
              }
            }, 10);
          }
        });

        // Click on toggle navigates to parent page (desktop)
        // FIX (Cause C): Hybrid approach - blur only for pointer clicks, programmatic
        // navigation only when Bootstrap would intercept the click.
        toggle.addEventListener('click', function (e) {
          if (!isDesktop()) {
            // On mobile, let Bootstrap handle the click-to-toggle behavior
            return;
          }

          var href = this.getAttribute('href');
          if (!href || href === '#') {
            return;
          }

          // Clean up hover-open state regardless of input type
          dropdown.classList.remove('dropdown-hover');

          // Pointer click focus should not "stick" visually after navigation.
          // Keyboard users should KEEP focus (accessibility).
          if (window.dropdownLastInputWasPointer) {
            this.blur();
          }

          // If this link is wired as a Bootstrap dropdown toggle, normal navigation
          // may not happen (Bootstrap intercepts clicks). Take over navigation explicitly.
          var isBootstrapToggle =
            this.classList.contains('dropdown-toggle') ||
            this.hasAttribute('data-bs-toggle') ||
            this.getAttribute('aria-haspopup') === 'true';

          if (isBootstrapToggle) {
            e.preventDefault();
            e.stopPropagation();
            window.location.assign(href);
          }
          // Else: do NOT preventDefault — let the browser handle normal navigation.
        });
      });

      // Handle breakpoint crossing - close all dropdowns when crossing 1200px
      // Uses matchMedia for efficiency (fires once on threshold cross, not every resize tick)
      once('dropdown-menu-breakpoint', 'body', context).forEach(function () {
        var desktopQuery = window.matchMedia('(min-width: 1200px)');

        /**
         * Force-close all dropdowns and clean up state
         * Prevents "stuck" dropdowns when crossing breakpoints
         */
        function closeAllDropdowns() {
          // Close via Bootstrap API
          document.querySelectorAll('.centered-logo-navbar .dropdown-menu.show').forEach(function (menu) {
            var toggle = menu.previousElementSibling;
            if (toggle && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
              var instance = bootstrap.Dropdown.getInstance(toggle);
              if (instance) {
                instance.hide();
              }
            }
          });

          // Clean up any stuck CSS classes
          document.querySelectorAll('.centered-logo-navbar .dropdown.show').forEach(function (d) {
            d.classList.remove('show');
          });
          document.querySelectorAll('.centered-logo-navbar .dropdown-hover').forEach(function (d) {
            d.classList.remove('dropdown-hover');
          });

          // Reset aria-expanded attributes
          document.querySelectorAll('.centered-logo-navbar .dropdown-toggle[aria-expanded="true"]').forEach(function (t) {
            t.setAttribute('aria-expanded', 'false');
          });
        }

        // Listen for breakpoint crossing (fires exactly once when threshold is crossed)
        desktopQuery.addEventListener('change', closeAllDropdowns);
      });
    }
  };

})(Drupal, once);
