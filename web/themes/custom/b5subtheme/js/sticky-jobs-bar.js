/**
 * @file
 * Sticky Jobs Bar behavior for Employment page.
 *
 * Shows a fixed CTA bar when user scrolls past the intro "View Open Positions" button.
 * Hides the bar when the actual job listings section is visible (no need for CTA when already there).
 *
 * Uses IntersectionObserver for efficient scroll detection.
 * Follows patterns from: scroll-behaviors.js, back-to-top, help-overlay.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.stickyJobsBar = {
    attach: function (context, settings) {
      // Only run once per page load
      once('sticky-jobs-bar', '#sticky-jobs-bar', context).forEach(function (stickyBar) {
        var introCta = document.getElementById('intro-cta-trigger');
        var jobListings = document.getElementById('current-openings');

        // Bail if required elements don't exist
        if (!introCta || !jobListings) {
          return;
        }

        // State tracking
        var introCtaVisible = true;
        var jobListingsVisible = false;

        /**
         * Update sticky bar visibility based on observed elements.
         * Show bar when: intro CTA is NOT visible AND job listings are NOT visible
         * Also manages tabindex to prevent focus when bar is hidden.
         */
        function updateVisibility() {
          var shouldShow = !introCtaVisible && !jobListingsVisible;
          var focusableElements = stickyBar.querySelectorAll('a, button');

          if (shouldShow) {
            stickyBar.classList.add('is-visible');
            stickyBar.setAttribute('aria-hidden', 'false');
            // Restore focusability when visible
            focusableElements.forEach(function (el) {
              el.removeAttribute('tabindex');
            });
          } else {
            stickyBar.classList.remove('is-visible');
            stickyBar.setAttribute('aria-hidden', 'true');
            // Prevent focus when hidden
            focusableElements.forEach(function (el) {
              el.setAttribute('tabindex', '-1');
            });
          }
        }

        /**
         * Observer for the intro CTA button.
         * When it leaves the viewport (scrolls up out of view), show the sticky bar.
         */
        var introObserver = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (entry) {
              introCtaVisible = entry.isIntersecting;
              updateVisibility();
            });
          },
          {
            // Trigger when element fully leaves viewport
            threshold: 0,
            // Small negative margin to trigger slightly before element is completely gone
            rootMargin: '-50px 0px 0px 0px'
          }
        );

        /**
         * Observer for the job listings section.
         * When it enters the viewport, hide the sticky bar (user has found what they're looking for).
         */
        var listingsObserver = new IntersectionObserver(
          function (entries) {
            entries.forEach(function (entry) {
              jobListingsVisible = entry.isIntersecting;
              updateVisibility();
            });
          },
          {
            // Trigger when any part of the section is visible
            threshold: 0,
            // Positive top margin so bar hides slightly before listings are fully visible
            rootMargin: '100px 0px 0px 0px'
          }
        );

        // Start observing
        introObserver.observe(introCta);
        listingsObserver.observe(jobListings);

        // Set initial state (hidden with tabindex=-1) before observers fire
        updateVisibility();

        /**
         * Handle smooth scroll when clicking the sticky bar CTA.
         * Respects prefers-reduced-motion.
         */
        var ctaLink = stickyBar.querySelector('.sticky-jobs-bar__cta');
        if (ctaLink) {
          ctaLink.addEventListener('click', function (e) {
            e.preventDefault();

            var targetId = ctaLink.getAttribute('href');
            var targetElement = document.querySelector(targetId);

            if (targetElement) {
              var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

              // Account for sticky header height
              var header = document.querySelector('.site-header');
              var headerHeight = header ? header.offsetHeight : 0;
              var targetPosition = targetElement.getBoundingClientRect().top + window.pageYOffset - headerHeight - 20;

              window.scrollTo({
                top: targetPosition,
                behavior: prefersReducedMotion ? 'auto' : 'smooth'
              });

              // Set focus to the target section for accessibility
              targetElement.setAttribute('tabindex', '-1');
              targetElement.focus({ preventScroll: true });
            }
          });
        }

        /**
         * Cleanup observers on page unload (good practice for SPAs/AJAX).
         */
        window.addEventListener('unload', function () {
          introObserver.disconnect();
          listingsObserver.disconnect();
        });
      });
    }
  };

})(Drupal, once);
