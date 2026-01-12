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

      // Track whether the most recent interaction was pointer vs keyboard.
      // Used to decide whether to blur on click navigation (pointer only).
      once('dropdown-pointer-tracking', 'body', context).forEach(function () {
        window.dropdownLastInputWasPointer = false;

        // Pointer movement counts as pointer usage (covers "first action is hover")
        document.addEventListener('pointermove', function (e) {
          if (e.pointerType === 'mouse' || e.pointerType === 'pen' || e.pointerType === 'touch') {
            window.dropdownLastInputWasPointer = true;
          }
        }, { capture: true, passive: true });

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Tab' || e.key === 'Enter' || e.key === ' ') {
            window.dropdownLastInputWasPointer = false;
          }
        }, { capture: true });
      });

      // Initialize dropdowns with hover behavior
      once('dropdown-menu', '.centered-logo-navbar .nav-item.dropdown', context).forEach(function (dropdown) {
        var toggle = dropdown.querySelector('.dropdown-toggle');
        var menu = dropdown.querySelector('.dropdown-menu');

        if (!toggle || !menu) {
          return;
        }

        // Timer for deferred hide - stored on the element for per-dropdown control
        dropdown._hideTimer = null;

        /**
         * Get or create Bootstrap Dropdown instance
         */
        var bsDropdown = null;
        if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
          bsDropdown = bootstrap.Dropdown.getOrCreateInstance(toggle, { popperConfig: null });
          bsDropdown.hide();
        }

        function getDropdownInstance() {
          return bsDropdown;
        }

        /**
         * Check if dropdown should remain open.
         * Pointer hover keeps it open.
         * Keyboard focus keeps it open (ONLY if last input was keyboard).
         */
        function shouldKeepOpen() {
          // Pointer hover keeps it open
          if (dropdown.matches(':hover')) {
            return true;
          }
          // Keyboard focus keeps it open (ONLY if the last input was keyboard)
          if (!window.dropdownLastInputWasPointer && dropdown.contains(document.activeElement)) {
            return true;
          }
          return false;
        }

        /**
         * Schedule a hide with deferred shouldKeepOpen check.
         * The check happens at hide time, not at schedule time.
         */
        function scheduleHide(delay) {
          if (typeof delay === 'undefined') {
            delay = 150; // Default delay for smooth UX
          }
          clearTimeout(dropdown._hideTimer);
          dropdown._hideTimer = setTimeout(function () {
            if (shouldKeepOpen()) {
              return;
            }
            hideDropdown();
          }, delay);
        }

        /**
         * Show dropdown using Bootstrap API.
         * NOTE: No focus/blur here - focus management is keyboard-only.
         */
        function showDropdown() {
          if (!isDesktop()) {
            return;
          }
          clearTimeout(dropdown._hideTimer);
          dropdown.classList.add('dropdown-hover');
          var instance = getDropdownInstance();
          if (instance) {
            instance.show();
          }
        }

        /**
         * Hide dropdown immediately (no delay, no check).
         * Use scheduleHide() for deferred hide with shouldKeepOpen check.
         */
        function hideDropdown() {
          if (!isDesktop()) {
            return;
          }
          clearTimeout(dropdown._hideTimer);
          dropdown.classList.remove('dropdown-hover');
          var instance = getDropdownInstance();
          if (instance) {
            instance.hide();
          }

          // KEY FIX: If last input was pointer, blur any focused element inside dropdown.
          // Focus is what produces the "stuck hover" highlight via :focus styling.
          if (window.dropdownLastInputWasPointer) {
            var ae = document.activeElement;
            if (ae && dropdown.contains(ae) && typeof ae.blur === 'function') {
              ae.blur();
            }
          }
        }

        // Hover open - on dropdown container
        dropdown.addEventListener('mouseenter', showDropdown);

        // Hover close - schedule hide with shouldKeepOpen check
        dropdown.addEventListener('mouseleave', function () {
          scheduleHide(150);
        });

        // Cancel pending hide when entering menu (prevents flicker)
        menu.addEventListener('mouseenter', function () {
          clearTimeout(dropdown._hideTimer);
        });

        // Hide when leaving menu - schedule with check
        menu.addEventListener('mouseleave', function () {
          scheduleHide(150);
        });

        // Focus behavior for keyboard accessibility
        // Only opens on keyboard focus (:focus-visible), not mouse click
        toggle.addEventListener('focus', function () {
          if (isDesktop()) {
            setTimeout(function () {
              if (document.activeElement === toggle && toggle.matches(':focus-visible')) {
                showDropdown();
              }
            }, 100);
          }
        });

        // Focus leaves dropdown area - schedule hide with shouldKeepOpen check
        // This handles keyboard navigation away AND any programmatic blur
        dropdown.addEventListener('focusout', function () {
          if (isDesktop()) {
            scheduleHide(50);
          }
        });

        // Click on toggle navigates to parent page (desktop)
        toggle.addEventListener('click', function (e) {
          if (!isDesktop()) {
            return;
          }

          var href = this.getAttribute('href');
          if (!href || href === '#') {
            return;
          }

          // Clean up hover state
          dropdown.classList.remove('dropdown-hover');

          // Blur only for pointer clicks (preserve keyboard focus)
          if (window.dropdownLastInputWasPointer) {
            this.blur();
          }

          // If Bootstrap dropdown toggle, take over navigation
          var isBootstrapToggle =
            this.classList.contains('dropdown-toggle') ||
            this.hasAttribute('data-bs-toggle') ||
            this.getAttribute('aria-haspopup') === 'true';

          if (isBootstrapToggle) {
            e.preventDefault();
            e.stopPropagation();
            window.location.assign(href);
          }
        });
      });

      // Handle breakpoint crossing - close all dropdowns when crossing 1200px
      once('dropdown-menu-breakpoint', 'body', context).forEach(function () {
        var desktopQuery = window.matchMedia('(min-width: 1200px)');

        function closeAllDropdowns() {
          document.querySelectorAll('.centered-logo-navbar .dropdown-menu.show').forEach(function (menu) {
            var toggle = menu.previousElementSibling;
            if (toggle && typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
              var instance = bootstrap.Dropdown.getInstance(toggle);
              if (instance) {
                instance.hide();
              }
            }
          });

          document.querySelectorAll('.centered-logo-navbar .dropdown.show').forEach(function (d) {
            d.classList.remove('show');
          });
          document.querySelectorAll('.centered-logo-navbar .dropdown-hover').forEach(function (d) {
            d.classList.remove('dropdown-hover');
          });

          document.querySelectorAll('.centered-logo-navbar .dropdown-toggle[aria-expanded="true"]').forEach(function (t) {
            t.setAttribute('aria-expanded', 'false');
          });
        }

        desktopQuery.addEventListener('change', closeAllDropdowns);
      });
    }
  };

})(Drupal, once);
