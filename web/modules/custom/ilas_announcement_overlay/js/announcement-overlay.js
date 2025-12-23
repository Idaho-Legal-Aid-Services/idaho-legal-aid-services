/**
 * @file
 * JavaScript for the ILAS Announcement Overlay.
 *
 * Provides accessible overlay popup functionality with:
 * - Focus trap within the overlay when open
 * - ESC key to close
 * - Click on backdrop to close (with mobile scroll protection)
 * - Proper ARIA state management
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Announcement Overlay behavior.
   */
  Drupal.behaviors.ilasAnnouncementOverlay = {
    attach: function (context) {
      // Find all announcement triggers that haven't been processed.
      var triggers = once('announcement-overlay', '[data-announcement-trigger]', context);

      triggers.forEach(function (trigger) {
        var blockId = trigger.getAttribute('data-announcement-trigger');
        var overlay = document.querySelector('[data-announcement-overlay="' + blockId + '"]');

        if (!overlay) {
          return;
        }

        var closeButton = overlay.querySelector('[data-announcement-close="' + blockId + '"]');
        var backdrop = overlay.querySelector('[data-announcement-backdrop="' + blockId + '"]');
        var panel = overlay.querySelector('.announcement-overlay-panel');

        // Store reference to the trigger button for returning focus.
        var lastFocusedElement = null;

        /**
         * Get all focusable elements within the overlay.
         */
        function getFocusableElements() {
          var focusableSelectors = [
            'a[href]',
            'button:not([disabled])',
            'textarea:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            '[tabindex]:not([tabindex="-1"])'
          ];
          return overlay.querySelectorAll(focusableSelectors.join(', '));
        }

        /**
         * Open the overlay popup.
         */
        function openOverlay() {
          // Store the currently focused element.
          lastFocusedElement = document.activeElement;

          // Show the overlay.
          overlay.classList.add('is-open');
          overlay.setAttribute('aria-hidden', 'false');
          trigger.setAttribute('aria-expanded', 'true');

          // Prevent body scroll.
          document.body.classList.add('announcement-overlay-open');

          // Focus the close button or first focusable element.
          setTimeout(function () {
            var focusableElements = getFocusableElements();
            if (closeButton) {
              closeButton.focus();
            } else if (focusableElements.length > 0) {
              focusableElements[0].focus();
            }
          }, 100);

          // Add event listeners.
          document.addEventListener('keydown', handleKeydown);
        }

        /**
         * Close the overlay popup.
         */
        function closeOverlay() {
          // Hide the overlay.
          overlay.classList.remove('is-open');
          overlay.setAttribute('aria-hidden', 'true');
          trigger.setAttribute('aria-expanded', 'false');

          // Restore body scroll.
          document.body.classList.remove('announcement-overlay-open');

          // Return focus to the trigger button, then blur it to prevent
          // the hover/focus state from making the button appear white.
          if (lastFocusedElement) {
            lastFocusedElement.focus();
            // Blur after a brief delay so screen readers announce the focus
            // but visual users don't see the focus state lingering.
            setTimeout(function () {
              if (document.activeElement === lastFocusedElement) {
                lastFocusedElement.blur();
              }
            }, 100);
          }

          // Remove event listeners.
          document.removeEventListener('keydown', handleKeydown);
        }

        /**
         * Handle keyboard events.
         */
        function handleKeydown(event) {
          // Close on ESC.
          if (event.key === 'Escape' || event.keyCode === 27) {
            event.preventDefault();
            closeOverlay();
            return;
          }

          // Focus trap on Tab.
          if (event.key === 'Tab' || event.keyCode === 9) {
            var focusableElements = getFocusableElements();
            if (focusableElements.length === 0) {
              event.preventDefault();
              return;
            }

            var firstElement = focusableElements[0];
            var lastElement = focusableElements[focusableElements.length - 1];

            if (event.shiftKey) {
              // Shift + Tab: If on first element, go to last.
              if (document.activeElement === firstElement) {
                event.preventDefault();
                lastElement.focus();
              }
            } else {
              // Tab: If on last element, go to first.
              if (document.activeElement === lastElement) {
                event.preventDefault();
                firstElement.focus();
              }
            }
          }
        }

        /**
         * Handle backdrop click.
         * Use touchstart tracking to prevent accidental closes on mobile scroll.
         */
        var touchStartY = null;

        function handleBackdropTouchStart(event) {
          touchStartY = event.touches[0].clientY;
        }

        function handleBackdropClick(event) {
          // Only close if clicked directly on backdrop (not panel content).
          if (event.target === backdrop) {
            // On touch devices, check if this was a scroll vs tap.
            if (event.type === 'touchend' && touchStartY !== null) {
              var touchEndY = event.changedTouches[0].clientY;
              var delta = Math.abs(touchEndY - touchStartY);
              // If the user scrolled more than 10px, don't close.
              if (delta > 10) {
                touchStartY = null;
                return;
              }
            }
            closeOverlay();
          }
          touchStartY = null;
        }

        // Bind events.
        trigger.addEventListener('click', function (event) {
          event.preventDefault();
          openOverlay();
        });

        if (closeButton) {
          closeButton.addEventListener('click', function (event) {
            event.preventDefault();
            closeOverlay();
          });
        }

        if (backdrop) {
          backdrop.addEventListener('click', handleBackdropClick);
          backdrop.addEventListener('touchstart', handleBackdropTouchStart, { passive: true });
          backdrop.addEventListener('touchend', handleBackdropClick);
        }

        // Also close when clicking outside the panel (on the overlay container itself).
        overlay.addEventListener('click', function (event) {
          if (event.target === overlay) {
            closeOverlay();
          }
        });
      });
    }
  };

})(Drupal, once);
