/**
 * Topic Cards Visual Enhancements
 *
 * Handles hover/focus state classes and detects clamped descriptions.
 * Navigation is handled natively by the stretched <a> tag overlay.
 */

(function (Drupal) {
  'use strict';

  /**
   * Initialize topic card enhancements.
   */
  Drupal.behaviors.topicCards = {
    attach: function (context, settings) {
      // Find all topic cards
      const topicCards = context.querySelectorAll('.topic-card');

      topicCards.forEach(function(card) {
        // Skip if already processed
        if (card.dataset.topicCardProcessed) {
          return;
        }

        // Mark as processed
        card.dataset.topicCardProcessed = 'true';

        // Get the anchor overlay for focus events
        const anchor = card.querySelector('.topic-card__link-overlay');

        // Mobile: check clamping immediately on load (no hover needed)
        // This allows the fade gradient to show permanently on mobile
        if (window.innerWidth < 768) {
          checkDescriptionClamping(card, true);
        }

        // Add mouse enter/leave for enhanced hover indication
        card.addEventListener('mouseenter', function() {
          if (!anchor || !anchor.matches(':focus')) {
            card.classList.add('topic-card--hover');
          }
          // Check if description is clamped on hover
          checkDescriptionClamping(card);
        });

        card.addEventListener('mouseleave', function() {
          card.classList.remove('topic-card--hover');
        });

        // Remove hover class when anchor is focused (keyboard navigation)
        if (anchor) {
          anchor.addEventListener('focus', function() {
            card.classList.remove('topic-card--hover');
            // Check if description is clamped on focus
            checkDescriptionClamping(card);
          });
        }
      });
    }
  };

  /**
   * Check if description text is being clamped and add class accordingly.
   * @param {boolean} immediate - Skip delay (use for mobile where description is already visible)
   */
  function checkDescriptionClamping(card, immediate) {
    const description = card.querySelector('.topic-card__description');
    if (!description) {
      return;
    }

    // Check the first child element inside, since that's where line-clamp is applied
    // Drupal wraps field content in p or div depending on field settings
    const textElement = description.firstElementChild || description;

    function doCheck() {
      // Compare scrollHeight to clientHeight to detect overflow
      // scrollHeight is the full content height, clientHeight is visible height
      const isClamped = textElement.scrollHeight > textElement.clientHeight + 5; // 5px tolerance

      if (isClamped) {
        description.classList.add('is-clamped');
      } else {
        description.classList.remove('is-clamped');
      }
    }

    if (immediate) {
      // Mobile: check immediately since description is already visible
      doCheck();
    } else {
      // Desktop: wait for CSS transition to complete (300ms) before checking
      setTimeout(doCheck, 350);
    }
  }

})(Drupal);
