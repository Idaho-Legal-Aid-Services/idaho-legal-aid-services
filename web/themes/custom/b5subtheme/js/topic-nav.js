/**
 * @file
 * Topic navigation dropdown functionality for Forms and Guides landing pages.
 *
 * Provides a "Jump to Topic" dropdown that navigates directly to topic pages
 * when a topic is selected and the Go button is clicked, or on direct selection.
 */

(function (Drupal, once) {
  'use strict';

  /**
   * Behavior for topic navigation dropdowns.
   */
  Drupal.behaviors.topicNav = {
    attach: function (context) {
      // Handle both forms and guides navigation dropdowns
      const selectors = [
        '#forms-topic-nav',
        '#guides-topic-nav'
      ];

      selectors.forEach(function (selector) {
        const dropdown = context.querySelector(selector);
        if (!dropdown) {
          return;
        }

        // Only attach once
        once('topic-nav', dropdown).forEach(function (select) {
          const basePath = select.dataset.basePath || '';
          const goButton = context.querySelector(selector + '-go');

          /**
           * Navigate to the selected topic page.
           */
          function navigateToTopic() {
            const selectedValue = select.value;
            if (selectedValue) {
              window.location.href = basePath + '/' + selectedValue;
            }
          }

          // Navigate on Go button click
          if (goButton) {
            goButton.addEventListener('click', navigateToTopic);
          }

          // Also navigate on Enter key when dropdown is focused
          select.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' && select.value) {
              event.preventDefault();
              navigateToTopic();
            }
          });

          // Optional: Navigate immediately on selection change (more direct UX)
          // Uncomment the following if you want instant navigation without clicking Go:
          // select.addEventListener('change', function () {
          //   if (select.value) {
          //     navigateToTopic();
          //   }
          // });
        });
      });
    }
  };

})(Drupal, once);
