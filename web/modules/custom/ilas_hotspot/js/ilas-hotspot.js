/**
 * @file
 * ILAS Hotspot module JavaScript - Modern vanilla JS version.
 * 
 * Purpose: Creates interactive hotspots on an image that show popovers on click.
 * - Removes jQuery dependency
 * - Adds proper error handling
 * - Ensures Bootstrap 5 compatibility
 * - Provides debug mode for troubleshooting
 */

(function (Drupal) {
  'use strict';

  // Debug mode - set to true to see console logs
  const DEBUG_MODE = false;
  
  function debugLog(message, data = null) {
    if (DEBUG_MODE) {
      if (data) {
        console.log(`[ILAS Hotspot] ${message}`, data);
      } else {
        console.log(`[ILAS Hotspot] ${message}`);
      }
    }
  }

  // Error handler
  function handleError(error, context = '') {
    console.error(`[ILAS Hotspot Error] ${context}:`, error);
  }

  Drupal.behaviors.ilasHotspot = {
    attach: function (context, settings) {
      debugLog('Attaching ILAS Hotspot behavior');

      // Use Drupal's once() method for Drupal 9/10 compatibility
      const containers = once('ilas-hotspot', '.ilas-hotspot-container', context);
      
      if (containers.length === 0) {
        debugLog('No hotspot containers found');
        return;
      }

      debugLog(`Found ${containers.length} hotspot container(s)`);

      containers.forEach(function(container) {
        try {
          initializeHotspotContainer(container);
        } catch (error) {
          handleError(error, 'Container initialization');
        }
      });
    }
  };

  /**
   * Initialize a single hotspot container
   */
  function initializeHotspotContainer(container) {
    debugLog('Initializing container', container);

    const background = container.querySelector('.hotspot-background');
    const hotspotItems = container.querySelectorAll('.hotspot-item');

    if (!background) {
      handleError(new Error('No background image found'), 'Container setup');
      return;
    }

    debugLog(`Found ${hotspotItems.length} hotspot items`);

    // Check if Bootstrap is available
    if (typeof bootstrap === 'undefined' || !bootstrap.Popover) {
      handleError(new Error('Bootstrap Popover not available'), 'Bootstrap check');
      // Fallback: Use native tooltips
      initializeFallbackTooltips(container);
      return;
    }

    // Initialize Bootstrap popovers
    initializePopovers(container);

    // Position hotspots
    positionHotspots(background, hotspotItems);

    // Handle window resize
    let resizeTimeout;
    window.addEventListener('resize', function() {
      clearTimeout(resizeTimeout);
      resizeTimeout = setTimeout(function() {
        positionHotspots(background, hotspotItems);
      }, 250);
    });
  }

  /**
   * Initialize Bootstrap 5 popovers
   */
  function initializePopovers(container) {
    debugLog('Initializing Bootstrap popovers');

    const popoverTriggers = container.querySelectorAll('[data-bs-toggle="popover"]');
    const popoverInstances = [];

    popoverTriggers.forEach(function(trigger) {
      try {
        // Get the category from the parent hotspot item
        const hotspotItem = trigger.closest('.hotspot-item');
        const category = hotspotItem ? hotspotItem.getAttribute('data-category') : '';
        
        // Create popover instance
        const popoverInstance = new bootstrap.Popover(trigger, {
          html: true,
          trigger: 'click',
          container: 'body',
          placement: trigger.getAttribute('data-bs-placement') || 'auto',
          content: trigger.getAttribute('data-bs-content') || '',
          title: trigger.getAttribute('data-bs-title') || '',
          customClass: category ? 'popover-' + category : ''
        });

        popoverInstances.push({
          element: trigger,
          instance: popoverInstance
        });

        debugLog('Popover initialized for element', trigger);

        // Handle click to close other popovers
        trigger.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();

          // Close other popovers
          popoverInstances.forEach(function(item) {
            if (item.element !== trigger) {
              try {
                item.instance.hide();
              } catch (error) {
                debugLog('Error hiding popover', error);
              }
            }
          });
        });

      } catch (error) {
        handleError(error, 'Popover initialization');
      }
    });

    // Close popovers when clicking outside
    document.addEventListener('click', function(e) {
      if (!e.target.closest('.ilas-hotspot-container') && !e.target.closest('.popover')) {
        popoverInstances.forEach(function(item) {
          try {
            item.instance.hide();
          } catch (error) {
            debugLog('Error hiding popover on outside click', error);
          }
        });
      }
    });
  }

  /**
   * Fallback tooltip implementation if Bootstrap is not available
   */
  function initializeFallbackTooltips(container) {
    debugLog('Using fallback tooltips (Bootstrap not available)');

    const triggers = container.querySelectorAll('[data-bs-toggle="popover"]');

    triggers.forEach(function(trigger) {
      const title = trigger.getAttribute('data-bs-title') || 'Info';
      const content = trigger.getAttribute('data-bs-content') || '';

      // Set native title attribute as fallback
      trigger.setAttribute('title', `${title}: ${content}`);

      // Add click handler for custom tooltip
      trigger.addEventListener('click', function(e) {
        e.preventDefault();
        var tempEl = document.createElement('div');
        tempEl.innerHTML = content;
        alert(`${title}\n\n${tempEl.textContent}`);
      });
    });
  }

  /**
   * Position hotspots on the background image
   */
  function positionHotspots(background, hotspotItems) {
    debugLog('Positioning hotspots');

    if (!background || !hotspotItems.length) {
      return;
    }

    // Predefined positions for each category
    const positions = {
      'housing': { left: '18%', top: '81%' },
      'health': { left: '64%', top: '88%' },
      'consumer-rights': { left: '30%', top: '6%' },
      'individual-rights': { left: '90%', top: '56%' },
      'older-adults': { left: '3.5%', top: '37%' },
      'family': { left: '76%', top: '13%' }
    };

    hotspotItems.forEach(function(hotspot) {
      const category = hotspot.getAttribute('data-category');
      
      if (category && positions[category]) {
        hotspot.style.left = positions[category].left;
        hotspot.style.top = positions[category].top;
        debugLog(`Positioned ${category} hotspot at`, positions[category]);
      } else {
        debugLog(`No position defined for category: ${category}`);
      }
    });
  }

  // Expose debug function globally for testing
  if (DEBUG_MODE) {
    window.ilasHotspotDebug = {
      checkBootstrap: function() {
        console.log('Bootstrap available:', typeof bootstrap !== 'undefined');
        console.log('Bootstrap Popover available:', typeof bootstrap !== 'undefined' && bootstrap.Popover);
      },
      listContainers: function() {
        const containers = document.querySelectorAll('.ilas-hotspot-container');
        console.log(`Found ${containers.length} hotspot containers:`, containers);
      },
      listHotspots: function() {
        const hotspots = document.querySelectorAll('.hotspot-item');
        console.log(`Found ${hotspots.length} hotspots:`, hotspots);
      }
    };
    
    debugLog('Debug functions available in window.ilasHotspotDebug');
  }

})(Drupal);