/**
 * @file
 * Content section tabs - deep link support.
 *
 * Enables URL hash-based deep linking to specific tabs on the Employment page.
 * Also updates the URL hash when tabs are changed for shareable links.
 *
 * Example: /employment#for-attorneys opens the Attorneys tab directly.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.contentSectionTabs = {
    attach: function (context) {
      once('content-tabs-deeplink', '.content-tabs-nav', context).forEach(function (nav) {
        var tabButtons = nav.querySelectorAll('.content-tab');

        /**
         * Handle initial page load with hash in URL.
         * Activates the corresponding tab if hash matches a panel ID.
         */
        function handleInitialHash() {
          var hash = window.location.hash;
          if (!hash || hash.length <= 1) {
            return;
          }

          // Remove the leading # and try to find a matching tab
          var hashValue = hash.substring(1);

          // Try direct match (hash is the panel ID without 'panel-' prefix)
          var targetTab = nav.querySelector('[data-bs-target="#panel-' + hashValue + '"]');

          // If not found, try with 'panel-' prefix already in hash
          if (!targetTab && hashValue.indexOf('panel-') === 0) {
            targetTab = nav.querySelector('[data-bs-target="#' + hashValue + '"]');
          }

          if (targetTab && typeof bootstrap !== 'undefined') {
            var bsTab = new bootstrap.Tab(targetTab);
            bsTab.show();

            // Scroll to the tabs section after a brief delay
            setTimeout(function () {
              var tabsSection = document.getElementById('about-working-here');
              if (tabsSection) {
                var header = document.querySelector('.site-header');
                var headerHeight = header ? header.offsetHeight : 0;
                var targetPosition = tabsSection.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20;

                var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({
                  top: targetPosition,
                  behavior: prefersReducedMotion ? 'auto' : 'smooth'
                });
              }
            }, 100);
          }
        }

        /**
         * Update URL hash when tab changes.
         * Enables shareable links to specific tabs.
         */
        function setupHashUpdates() {
          tabButtons.forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function (event) {
              var panelId = event.target.getAttribute('data-bs-target');
              if (panelId) {
                // Extract the section ID from panel-{section-id}
                var sectionId = panelId.replace('#panel-', '');
                // Update URL without triggering a page scroll
                history.replaceState(null, null, '#' + sectionId);
              }
            });
          });
        }

        /**
         * Handle browser back/forward navigation.
         * Activates the tab corresponding to the new hash.
         */
        function setupPopstateHandler() {
          window.addEventListener('popstate', function () {
            handleInitialHash();
          });
        }

        // Initialize
        handleInitialHash();
        setupHashUpdates();
        setupPopstateHandler();
      });
    }
  };

})(Drupal, once);
