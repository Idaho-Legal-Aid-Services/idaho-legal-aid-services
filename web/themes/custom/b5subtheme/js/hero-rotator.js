/**
 * @file
 * Hero Image Rotator for the homepage.
 *
 * Randomly selects one hero image from a configured set on each page load.
 * Uses client-side JavaScript to work with Drupal's page caching.
 */

(function (Drupal) {
  'use strict';

  /**
   * Hero rotator behavior.
   *
   * Swaps the default hero image with a randomly selected one from the
   * configured set. This runs on page load and works with cached pages.
   */
  Drupal.behaviors.heroRotator = {
    attach: function (context) {
      // Only run once on initial page load.
      const heroContainer = context.getElementById
        ? context.getElementById('hero-rotator')
        : context.querySelector('#hero-rotator');

      if (!heroContainer) {
        return;
      }

      // Check if already processed.
      if (heroContainer.dataset.heroRotatorProcessed) {
        return;
      }
      heroContainer.dataset.heroRotatorProcessed = 'true';

      // Get the JSON data with all hero images.
      const dataScript = heroContainer.querySelector('#hero-rotator-data');
      if (!dataScript) {
        // No rotation data means only one image or no images configured.
        return;
      }

      let heroImages;
      try {
        heroImages = JSON.parse(dataScript.textContent);
      } catch (e) {
        console.warn('Hero rotator: Failed to parse image data', e);
        return;
      }

      if (!Array.isArray(heroImages) || heroImages.length <= 1) {
        // Need at least 2 images for rotation.
        return;
      }

      // Randomly select an image index.
      const randomIndex = Math.floor(Math.random() * heroImages.length);
      const selectedImage = heroImages[randomIndex];

      if (!selectedImage || !selectedImage.url) {
        console.warn('Hero rotator: Invalid image data at index', randomIndex);
        return;
      }

      // Find the img element within the hero container.
      // Handle both regular <img> and <picture><img> structures.
      const img = heroContainer.querySelector('img');
      if (!img) {
        console.warn('Hero rotator: No img element found');
        return;
      }

      // Swap the image attributes.
      // Use a small delay to ensure the DOM is ready and allow browser to
      // potentially start loading the default image (which may be correct).
      requestAnimationFrame(function () {
        // Update src.
        img.src = selectedImage.url;

        // Update srcset if the image had one (remove it since we're using
        // a specific styled image, not responsive sources).
        if (img.srcset) {
          img.removeAttribute('srcset');
        }

        // Update alt text.
        if (selectedImage.alt !== undefined) {
          img.alt = selectedImage.alt;
        }

        // Update dimensions if available (helps prevent CLS).
        if (selectedImage.width && selectedImage.height) {
          img.width = selectedImage.width;
          img.height = selectedImage.height;
        }

        // If this is inside a <picture> element, we need to update
        // or remove the <source> elements as well.
        const picture = img.closest('picture');
        if (picture) {
          const sources = picture.querySelectorAll('source');
          sources.forEach(function (source) {
            // Remove source elements since we're using a single styled URL.
            source.remove();
          });
        }

        // Mark the image as rotated for debugging.
        img.dataset.heroRotated = 'true';
        img.dataset.heroIndex = randomIndex.toString();
      });
    }
  };

})(Drupal);
