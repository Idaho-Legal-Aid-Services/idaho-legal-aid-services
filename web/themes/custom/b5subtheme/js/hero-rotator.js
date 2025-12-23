/**
 * @file
 * Hero Image Randomizer for the homepage.
 *
 * Randomly selects one hero image from a configured set on each page load.
 * Uses client-side JavaScript to work with Drupal's page caching.
 *
 * The server always renders image #1 (for SEO and caching). This script
 * picks a random image and swaps it in with a fade transition. If the
 * random selection is image #1, no swap occurs (no unnecessary flash).
 */

(function (Drupal) {
  'use strict';

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
        // No rotation data means only one image configured.
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

      // If we selected image #0, no swap needed - server already rendered it.
      if (randomIndex === 0) {
        return;
      }

      const selectedImage = heroImages[randomIndex];

      if (!selectedImage || !selectedImage.url) {
        console.warn('Hero rotator: Invalid image data at index', randomIndex);
        return;
      }

      // Find the img element within the hero container.
      const img = heroContainer.querySelector('img');
      if (!img) {
        console.warn('Hero rotator: No img element found');
        return;
      }

      // Swap with a fade transition for smooth UX.
      // First, set up the transition style.
      img.style.transition = 'opacity 0.2s ease-out';

      // Fade out.
      img.style.opacity = '0';

      // After fade out, swap the image and fade back in.
      setTimeout(function () {
        // Update src.
        img.src = selectedImage.url;

        // Update srcset if present (remove it - we're using a specific URL).
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

        // If inside a <picture> element, remove <source> elements.
        const picture = img.closest('picture');
        if (picture) {
          const sources = picture.querySelectorAll('source');
          sources.forEach(function (source) {
            source.remove();
          });
        }

        // Wait for image to load, then fade in.
        if (img.complete) {
          img.style.opacity = '1';
        } else {
          img.onload = function () {
            img.style.opacity = '1';
          };
          // Fallback in case onload doesn't fire (e.g., cached image).
          setTimeout(function () {
            img.style.opacity = '1';
          }, 100);
        }

        // Mark for debugging.
        img.dataset.heroRotated = 'true';
        img.dataset.heroIndex = randomIndex.toString();
      }, 200); // Match the fade-out duration.
    }
  };

})(Drupal);
