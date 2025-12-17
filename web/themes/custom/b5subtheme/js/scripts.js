document.addEventListener('DOMContentLoaded', () => {
  
  // ==========================================
  // HELP OVERLAY
  // ==========================================
  
  const helpToggle = document.getElementById('helpToggle');
  const helpOverlay = document.getElementById('helpOverlay');
  const helpBackdrop = document.getElementById('helpOverlayBackdrop');
  
  if (helpToggle && helpOverlay && helpBackdrop) {
    
    helpOverlay.setAttribute('aria-hidden', 'true');
    helpToggle.setAttribute('aria-expanded', 'false');
    
    helpToggle.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
      // Toggle the class
      const active = helpOverlay.classList.toggle('show-overlay');
      
      // Force inline styles as a failsafe
      if (active) {
        helpOverlay.style.setProperty('display', 'block', 'important');
        helpOverlay.style.setProperty('opacity', '1', 'important');
        helpOverlay.style.setProperty('visibility', 'visible', 'important');
        helpOverlay.style.setProperty('pointer-events', 'auto', 'important');
        const panel = helpOverlay.querySelector('.help-panel');
        if (panel) {
          panel.style.setProperty('right', '5%', 'important');
        }
      } else {
        helpOverlay.style.removeProperty('display');
        helpOverlay.style.removeProperty('opacity');
        helpOverlay.style.removeProperty('visibility');
        helpOverlay.style.removeProperty('pointer-events');
        const panel = helpOverlay.querySelector('.help-panel');
        if (panel) {
          panel.style.removeProperty('right');
        }
      }
      
      // Update ARIA attributes
      helpOverlay.setAttribute('aria-hidden', String(!active));
      helpToggle.setAttribute('aria-expanded', String(active));
      
    });
    
    helpBackdrop.addEventListener('click', function() {
      helpOverlay.classList.remove('show-overlay');
      
      // Remove inline styles
      helpOverlay.style.removeProperty('display');
      helpOverlay.style.removeProperty('opacity');
      helpOverlay.style.removeProperty('visibility');
      helpOverlay.style.removeProperty('pointer-events');
      const panel = helpOverlay.querySelector('.help-panel');
      if (panel) {
        panel.style.removeProperty('right');
      }
      
      helpOverlay.setAttribute('aria-hidden', 'true');
      helpToggle.setAttribute('aria-expanded', 'false');
    });
    
  }
  
  // ==========================================
  // UTILITY BAR HOVER FIX
  // ==========================================
  // Fix hover functionality for utility bar items with Bootstrap text-white class
  const utilityBarItems = document.querySelectorAll('.utility-bar .hotline > a, .utility-bar .search-col > button, .utility-bar .exit-link > a, .utility-bar .get-help > button');
  
  utilityBarItems.forEach(item => {
    // Store original styles
    const originalStyles = {
      backgroundColor: item.style.backgroundColor || '',
      color: item.style.color || ''
    };
    
    // Handle mouse enter
    item.addEventListener('mouseenter', function() {
      // Override inline styles during hover
      this.style.setProperty('background-color', '#fff', 'important');
      this.style.setProperty('color', '#1263a0', 'important');
      
      // Also update all child elements
      const allElements = this.querySelectorAll('*');
      allElements.forEach(el => {
        el.style.setProperty('color', '#1263a0', 'important');
      });
    });
    
    // Handle mouse leave
    item.addEventListener('mouseleave', function() {
      // Restore original styles
      this.style.backgroundColor = originalStyles.backgroundColor;
      this.style.color = originalStyles.color;
      
      // Remove inline styles from child elements
      const allElements = this.querySelectorAll('*');
      allElements.forEach(el => {
        el.style.color = '';
      });
    });
    
    // Handle focus
    item.addEventListener('focus', function() {
      this.style.setProperty('background-color', '#fff', 'important');
      this.style.setProperty('color', '#1263a0', 'important');
      
      const allElements = this.querySelectorAll('*');
      allElements.forEach(el => {
        el.style.setProperty('color', '#1263a0', 'important');
      });
    });
    
    // Handle blur
    item.addEventListener('blur', function() {
      this.style.backgroundColor = originalStyles.backgroundColor;
      this.style.color = originalStyles.color;
      
      const allElements = this.querySelectorAll('*');
      allElements.forEach(el => {
        el.style.color = '';
      });
    });
  });
  
  // ==========================================
  // BUTTON TEXT COLOR FIX
  // ==========================================
  // Ensure white text is visible on primary and success buttons
  const primaryButtons = document.querySelectorAll('.btn-primary, .btn-success');
  
  primaryButtons.forEach(button => {
    // Set initial white color
    if (button.classList.contains('btn-primary') || button.classList.contains('btn-success')) {
      // Force white text color for non-hover state
      if (!button.matches(':hover')) {
        button.style.setProperty('color', '#fff', 'important');
        
        // Also update all child elements
        const allElements = button.querySelectorAll('*');
        allElements.forEach(el => {
          el.style.setProperty('color', '#fff', 'important');
        });
      }
    }
    
    // Handle hover states for primary buttons
    if (button.classList.contains('btn-primary')) {
      button.addEventListener('mouseenter', function() {
        this.style.setProperty('color', '#1263a0', 'important');
        const allElements = this.querySelectorAll('*');
        allElements.forEach(el => {
          el.style.setProperty('color', '#1263a0', 'important');
        });
      });
      
      button.addEventListener('mouseleave', function() {
        this.style.setProperty('color', '#fff', 'important');
        const allElements = this.querySelectorAll('*');
        allElements.forEach(el => {
          el.style.setProperty('color', '#fff', 'important');
        });
      });
    }
    
    // Success buttons keep white text on hover
    if (button.classList.contains('btn-success')) {
      button.addEventListener('mouseenter', function() {
        this.style.setProperty('color', '#fff', 'important');
        const allElements = this.querySelectorAll('*');
        allElements.forEach(el => {
          el.style.setProperty('color', '#fff', 'important');
        });
      });
      
      button.addEventListener('mouseleave', function() {
        this.style.setProperty('color', '#fff', 'important');
        const allElements = this.querySelectorAll('*');
        allElements.forEach(el => {
          el.style.setProperty('color', '#fff', 'important');
        });
      });
    }
  });
  
  // ==========================================
  // IMPACT CARDS FLIP + A11Y - ENHANCED VERSION
  // ==========================================
  
  /**
   * Check if device is mobile/tablet
   */
  function isMobileDevice() {
    const isMobile = window.innerWidth < 768;
    return isMobile;
  }

  /**
   * Check if a card has back content (more reliable detection)
   */
  function hasBackContent(card) {
    const backDetail = card.querySelector('.impact-card__back-detail');
    if (!backDetail) return false;
    
    const content = backDetail.textContent || backDetail.innerText || '';
    return content.trim().length > 0;
  }

  /**
   * Get close button from card
   */
  function getCloseButton(card) {
    return card.querySelector('.impact-card__back-close');
  }

  /**
   * Flip a card to the specified state
   */
  function flipCard(card, shouldFlip) {
    if (shouldFlip) {
      card.classList.add('is-flipped');
      card.setAttribute('aria-expanded', 'true');
      
      // Focus management for accessibility
      setTimeout(() => {
        const closeButton = card.querySelector('.impact-card__back-close');
        if (closeButton) {
          closeButton.focus();
        }
      }, 250); // Wait for flip animation
      
    } else {
      card.classList.remove('is-flipped');
      card.setAttribute('aria-expanded', 'false');
      
      // Return focus to the card itself
      setTimeout(() => {
        card.focus();
      }, 100);
    }
  }

  /**
   * Handle card click events
   */
  function handleCardClick(event) {
    const card = event.currentTarget;
    
    // On mobile, completely disable flip functionality and ensure links work
    if (isMobileDevice()) {
      // Remove any flip classes that might be present
      card.classList.remove('is-flipped');
      
      // Allow links to work normally - don't prevent default
      const clickedLink = event.target.closest('a');
      if (clickedLink && clickedLink.getAttribute('href')) {
        // Let the browser handle the link navigation normally
        return;
      }
      
      // If not clicking a link, do nothing on mobile
      return;
    }
    
    // Desktop flip functionality below this point
    
    // If clicking on the close button, only close the card
    if (event.target.closest('.impact-card__back-close')) {
      event.stopPropagation();
      flipCard(card, false);
      return;
    }
    
    // Don't flip if clicking on links or buttons in flipped state
    if (card.classList.contains('is-flipped')) {
      const target = event.target;
      if (target.tagName === 'A' || target.tagName === 'BUTTON' || target.closest('a, button')) {
        return; // Allow the link/button to work normally
      }
    }
    
    // For front-side links, prevent navigation and flip instead
    const frontLink = event.target.closest('.card-front a');
    if (frontLink && !card.classList.contains('is-flipped')) {
      event.preventDefault();
      event.stopPropagation();
      flipCard(card, true);
      return;
    }
    
    // Otherwise toggle flip state
    event.preventDefault();
    event.stopPropagation();
    
    const isCurrentlyFlipped = card.classList.contains('is-flipped');
    flipCard(card, !isCurrentlyFlipped);
  }

  /**
   * Handle keyboard events for accessibility
   */
  function handleCardKeydown(event) {
    const card = event.currentTarget;
    
    // Disable keyboard flip functionality on mobile
    if (isMobileDevice()) {
      return;
    }
    
    // Handle Enter and Space keys
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      
      const isCurrentlyFlipped = card.classList.contains('is-flipped');
      flipCard(card, !isCurrentlyFlipped);
    }
    
    // Handle Escape key to close flipped cards
    if (event.key === 'Escape' && card.classList.contains('is-flipped')) {
      event.preventDefault();
      flipCard(card, false);
    }
  }

  /**
   * Set up mobile-specific card behavior
   */
  function setupMobileCard(card) {
    // Remove any flip-related classes and attributes
    card.classList.remove('is-flipped');
    card.removeAttribute('role');
    card.removeAttribute('tabindex');
    card.removeAttribute('aria-expanded');
    
    // Add mobile-specific class for styling
    card.classList.add('mobile-card');
    
    // Ensure the card behaves as a normal clickable element
    card.style.cursor = 'pointer';
    
    // Find the main link inside the card
    const cardLink = card.querySelector('.impact-card__link');
    if (cardLink) {
      // Ensure the link is clickable and has proper styling
      cardLink.style.pointerEvents = 'auto';
      cardLink.style.display = 'block';
      cardLink.style.width = '100%';
      cardLink.style.height = '100%';
      
      // Remove any existing click handlers on the card itself
      card.onclick = null;
      
      // Optional: Add click handler to entire card that triggers the link
      card.addEventListener('click', function(e) {
        // If we didn't click directly on the link, trigger it
        if (e.target !== cardLink && !cardLink.contains(e.target)) {
          e.preventDefault();
          cardLink.click();
        }
      });
    }
    
    // Remove any flip-related event listeners by cloning the node
    const newCard = card.cloneNode(true);
    card.parentNode.replaceChild(newCard, card);
    
    // Re-setup the click handler on the new card
    const newCardLink = newCard.querySelector('.impact-card__link');
    if (newCardLink) {
      newCard.addEventListener('click', function(e) {
        if (e.target !== newCardLink && !newCardLink.contains(e.target)) {
          e.preventDefault();
          newCardLink.click();
        }
      });
    }
  }

  /**
   * Initialize impact cards
   */
  function initializeCards() {
    document.querySelectorAll('.impact-card').forEach(card => {
      
      // Clean up any existing event listeners and attributes
      card.removeAttribute('role');
      card.removeAttribute('tabindex');
      card.removeAttribute('aria-expanded');
      card.classList.remove('is-flipped');
      
      // On mobile, set up cards as normal clickable links
      if (isMobileDevice()) {
        setupMobileCard(card);
        return; // Skip flip functionality setup on mobile
      }
      
      // Desktop setup - only add flip functionality if card has back content
      if (!hasBackContent(card)) {
        return; // Skip cards without back content - let them work as normal links
      }
      
      // Set up accessibility attributes for flippable cards (desktop only)
      card.setAttribute('tabindex', '0');
      card.setAttribute('role', 'button');
      card.setAttribute('aria-expanded', 'false');
      
      // Get close button
      const closeBtn = getCloseButton(card);
      
      // Add main card event listeners
      card.addEventListener('click', handleCardClick);
      card.addEventListener('keydown', handleCardKeydown);
      
      // Add close button event listener
      if (closeBtn) {
        closeBtn.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          flipCard(card, false);
        });
      }
    });
  }

  // Initialize cards on page load
  initializeCards();

  /**
   * Close all flipped cards (utility function)
   */
  function closeAllFlippedCards() {
    const flippedCards = document.querySelectorAll('.impact-card.is-flipped');
    flippedCards.forEach(card => flipCard(card, false));
  }

  // ==========================================
  // GLOBAL EVENT HANDLERS
  // ==========================================
  
  // Close flipped cards when clicking outside
  document.addEventListener('click', (event) => {
    const clickedCard = event.target.closest('.impact-card');
    
    if (!clickedCard && !isMobileDevice()) {
      closeAllFlippedCards();
    }
  });

  // Close flipped cards on Escape key (global) - desktop only
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !isMobileDevice()) {
      closeAllFlippedCards();
    }
  });

  // Handle window resize - reinitialize cards when switching between mobile/desktop
  let resizeTimeout;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
      // Close all flipped cards when resizing
      closeAllFlippedCards();
      
      // Completely reinitialize all cards with proper mobile/desktop behavior
      initializeCards();
    }, 250);
  });

});