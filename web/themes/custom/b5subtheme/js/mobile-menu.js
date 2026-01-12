/**
 * @file
 * Mobile slide-out menu functionality.
 */

(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.mobileMenu = {
    attach: function (context, settings) {
      // Check if we're in the main document context (not an AJAX fragment)
      if (context === document || context.contains(document.body)) {
        // Only run once per full page load, not per element
        if (document.body.hasAttribute('data-mobile-menu-initialized')) {
          return;
        }
        document.body.setAttribute('data-mobile-menu-initialized', 'true');
        const mobileMenuOverlay = document.getElementById('mobileMenuOverlay');
        const mobileMenuPanel = document.getElementById('mobileMenuPanel');
        const mobileMenuClose = document.getElementById('mobileMenuClose');
        const mobileMenuBackdrop = document.getElementById('mobileMenuBackdrop');
        const hamburgerButton = document.querySelector('.navbar-toggler');

        if (!mobileMenuOverlay || !hamburgerButton) {
          return;
        }

        // Get background content elements for inert handling
        const mainContent = document.querySelector('main.main-content');
        const header = document.querySelector('.site-header');
        const footer = document.querySelector('footer');

        // Check if browser supports inert attribute
        const supportsInert = 'inert' in HTMLElement.prototype;

        // Helper: Set background content as inert (prevents focus and AT interaction)
        function setBackgroundInert(inert) {
          const elements = [mainContent, header, footer];
          elements.forEach(function(el) {
            if (!el) return;
            if (inert) {
              if (supportsInert) {
                el.setAttribute('inert', '');
              } else {
                // Fallback for browsers without inert support
                el.setAttribute('aria-hidden', 'true');
              }
            } else {
              if (supportsInert) {
                el.removeAttribute('inert');
              } else {
                el.removeAttribute('aria-hidden');
              }
            }
          });
        }

        // Open mobile menu
        function openMobileMenu() {
          mobileMenuOverlay.classList.add('active');
          mobileMenuOverlay.setAttribute('aria-hidden', 'false');
          hamburgerButton.setAttribute('aria-expanded', 'true');

          // Prevent body scrolling
          document.body.style.overflow = 'hidden';
          document.body.classList.add('mobile-menu-open');

          // Make background content inert
          setBackgroundInert(true);

          // Focus first focusable element in menu panel
          const firstFocusable = mobileMenuPanel.querySelector(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
          );
          if (firstFocusable) {
            firstFocusable.focus();
          }
        }

        // Close mobile menu
        function closeMobileMenu() {
          mobileMenuOverlay.classList.remove('active');
          mobileMenuOverlay.setAttribute('aria-hidden', 'true');
          hamburgerButton.setAttribute('aria-expanded', 'false');

          // Restore body scrolling
          document.body.style.overflow = '';
          document.body.classList.remove('mobile-menu-open');

          // Remove inert from background content
          setBackgroundInert(false);

          // Return focus to hamburger button
          hamburgerButton.focus();
        }

        // Hamburger button click
        hamburgerButton.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          
          if (mobileMenuOverlay.classList.contains('active')) {
            closeMobileMenu();
          } else {
            openMobileMenu();
          }
        });

        // Close button click
        mobileMenuClose.addEventListener('click', function(e) {
          e.preventDefault();
          closeMobileMenu();
        });

        // Backdrop click to close
        mobileMenuBackdrop.addEventListener('click', function(e) {
          if (e.target === mobileMenuBackdrop) {
            closeMobileMenu();
          }
        });

        // Escape key to close
        document.addEventListener('keydown', function(e) {
          if (e.key === 'Escape' && mobileMenuOverlay.classList.contains('active')) {
            closeMobileMenu();
          }
        });

        // Focus trap - keep Tab cycling within menu when open
        document.addEventListener('keydown', function(e) {
          if (e.key !== 'Tab' || !mobileMenuOverlay.classList.contains('active')) {
            return;
          }

          var focusableElements = mobileMenuPanel.querySelectorAll(
            'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
          );

          if (focusableElements.length === 0) return;

          var firstFocusable = focusableElements[0];
          var lastFocusable = focusableElements[focusableElements.length - 1];

          if (e.shiftKey) {
            // Shift + Tab: if on first element, wrap to last
            if (document.activeElement === firstFocusable) {
              e.preventDefault();
              lastFocusable.focus();
            }
          } else {
            // Tab: if on last element, wrap to first
            if (document.activeElement === lastFocusable) {
              e.preventDefault();
              firstFocusable.focus();
            }
          }
        });

        // Handle submenu toggles (+ button clicks)
        const submenuToggles = document.querySelectorAll('.menu-toggle[aria-expanded]');
        submenuToggles.forEach(function(toggle) {
          toggle.addEventListener('click', function(e) {
            e.preventDefault();
            
            const submenu = toggle.parentNode.parentNode.querySelector('.mobile-submenu');
            const isExpanded = toggle.getAttribute('aria-expanded') === 'true';
            
            if (submenu) {
              if (isExpanded) {
                // Close submenu
                toggle.setAttribute('aria-expanded', 'false');
                submenu.classList.remove('show');
              } else {
                // Close other submenus first
                submenuToggles.forEach(function(otherToggle) {
                  if (otherToggle !== toggle) {
                    otherToggle.setAttribute('aria-expanded', 'false');
                    const otherSubmenu = otherToggle.parentNode.parentNode.querySelector('.mobile-submenu');
                    if (otherSubmenu) {
                      otherSubmenu.classList.remove('show');
                    }
                  }
                });
                
                // Open this submenu
                toggle.setAttribute('aria-expanded', 'true');
                submenu.classList.add('show');
              }
            }
          });
        });

        // Handle main menu text clicks (navigation links)
        const menuTextLinks = document.querySelectorAll('.menu-text-link');
        menuTextLinks.forEach(function(link) {
          link.addEventListener('click', function(e) {
            // Only close menu if it's a real navigation (not #)
            if (link.getAttribute('href') && link.getAttribute('href') !== '#') {
              setTimeout(function() {
                closeMobileMenu();
              }, 150);
            }
          });
        });

        // Handle standalone menu items (no submenus)
        const standaloneMenuLinks = document.querySelectorAll('.mobile-nav-link.no-submenu');
        standaloneMenuLinks.forEach(function(link) {
          link.addEventListener('click', function() {
            // Close menu when navigating
            setTimeout(function() {
              closeMobileMenu();
            }, 150);
          });
        });

        // Handle submenu item clicks
        const submenuLinks = document.querySelectorAll('.mobile-submenu-link');
        submenuLinks.forEach(function(link) {
          link.addEventListener('click', function() {
            // Close menu when navigating
            setTimeout(function() {
              closeMobileMenu();
            }, 150);
          });
        });
      }
    }
  };

})(Drupal, once);