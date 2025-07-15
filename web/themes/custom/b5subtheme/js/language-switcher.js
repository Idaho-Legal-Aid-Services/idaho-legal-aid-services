/**
 * @file
 * Language switcher functionality.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.languageSwitcher = {
    attach: function (context, settings) {
      const languageSwitcher = context.querySelector('.language-switcher-floating');
      if (!languageSwitcher || languageSwitcher.hasAttribute('data-language-switcher-processed')) {
        return;
      }
      languageSwitcher.setAttribute('data-language-switcher-processed', 'true');

      const toggleButton = languageSwitcher.querySelector('.language-switcher-toggle');
      const languageMenu = languageSwitcher.querySelector('.language-menu');
      let isOpen = false;

      // Check if admin toolbar is present
      const hasAdminToolbar = document.body.classList.contains('toolbar-tray-open') || 
                              document.querySelector('#toolbar-administration') !== null ||
                              document.body.classList.contains('toolbar-fixed') ||
                              document.body.classList.contains('toolbar-horizontal') ||
                              document.body.classList.contains('toolbar-vertical');

      // Only show language switcher if admin toolbar is not present
      if (!hasAdminToolbar) {
        setTimeout(() => {
          languageSwitcher.classList.add('show');
        }, 500);
      }

      // Toggle menu on button click
      toggleButton.addEventListener('click', function(e) {
        e.stopPropagation();
        isOpen = !isOpen;
        
        if (isOpen) {
          languageMenu.classList.add('show');
          toggleButton.setAttribute('aria-expanded', 'true');
        } else {
          languageMenu.classList.remove('show');
          toggleButton.setAttribute('aria-expanded', 'false');
        }
      });

      // Close menu when clicking outside
      document.addEventListener('click', function(e) {
        if (!languageSwitcher.contains(e.target) && isOpen) {
          isOpen = false;
          languageMenu.classList.remove('show');
          toggleButton.setAttribute('aria-expanded', 'false');
        }
      });

      // Close menu on Escape key
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isOpen) {
          isOpen = false;
          languageMenu.classList.remove('show');
          toggleButton.setAttribute('aria-expanded', 'false');
          toggleButton.focus();
        }
      });

      // Handle keyboard navigation within menu
      const menuLinks = languageMenu.querySelectorAll('a');
      if (menuLinks.length > 0) {
        menuLinks.forEach((link, index) => {
          link.addEventListener('keydown', function(e) {
            if (e.key === 'ArrowDown') {
              e.preventDefault();
              const nextIndex = (index + 1) % menuLinks.length;
              menuLinks[nextIndex].focus();
            } else if (e.key === 'ArrowUp') {
              e.preventDefault();
              const prevIndex = (index - 1 + menuLinks.length) % menuLinks.length;
              menuLinks[prevIndex].focus();
            }
          });
        });
      }
    }
  };

})(Drupal);