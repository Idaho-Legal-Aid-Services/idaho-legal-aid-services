/**
 * Topic Cards Accessible Navigation
 * 
 * Handles keyboard and mouse navigation for topic cards using the 
 * single interactive wrapper pattern for accessibility compliance.
 */

(function (Drupal) {
  'use strict';

  /**
   * Initialize topic card navigation.
   */
  Drupal.behaviors.topicCards = {
    attach: function (context, settings) {
      // Find all topic cards with role="link"
      const topicCards = context.querySelectorAll('.topic-card[role="link"][data-href]');
      
      topicCards.forEach(function(card) {
        // Skip if already processed
        if (card.dataset.topicCardProcessed) {
          return;
        }
        
        // Mark as processed
        card.dataset.topicCardProcessed = 'true';
        
        // Add mouse click handler
        card.addEventListener('click', handleCardNavigation);
        
        // Add keyboard navigation handler
        card.addEventListener('keydown', function(e) {
          // Handle Enter and Space keys
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            handleCardNavigation(e);
          }
        });
        
        // Add mouse enter/leave for enhanced focus indication
        card.addEventListener('mouseenter', function() {
          if (!card.matches(':focus')) {
            card.classList.add('topic-card--hover');
          }
        });
        
        card.addEventListener('mouseleave', function() {
          card.classList.remove('topic-card--hover');
        });
        
        // Remove hover class when focused (keyboard navigation)
        card.addEventListener('focus', function() {
          card.classList.remove('topic-card--hover');
        });
      });
    }
  };

  /**
   * Handle card navigation for both mouse and keyboard.
   */
  function handleCardNavigation(e) {
    const card = e.currentTarget;
    const href = card.dataset.href;
    
    if (!href) {
      console.warn('Topic card missing data-href attribute:', card);
      return;
    }
    
    // Check for modifier keys (Ctrl/Cmd for new tab)
    const openInNewTab = e.ctrlKey || e.metaKey || e.shiftKey;
    
    try {
      if (openInNewTab) {
        // Open in new tab/window
        window.open(href, '_blank', 'noopener,noreferrer');
      } else {
        // Navigate in current window
        window.location.href = href;
      }
    } catch (error) {
      console.error('Error navigating to:', href, error);
      // Fallback: try to navigate anyway
      window.location.href = href;
    }
  }

})(Drupal);