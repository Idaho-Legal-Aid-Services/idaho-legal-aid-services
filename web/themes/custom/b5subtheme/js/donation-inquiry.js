(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.donationInquiry = {
    attach: function (context, settings) {
      // Check if form exists 
      const form = document.getElementById('ways-to-give-form');
      if (!form) return;
      const $form = $(form);
      const submissionEndpoint = form.getAttribute('action') || '/donation-inquiry/submit';
      
      let currentStep = 1;
      const totalSteps = 3;
      
      // Force proper initialization regardless of browser state
      function initializeForm() {
        console.log('Initializing donation inquiry form');
        
        // Ensure only step 1 is visible
        $form.find('.form-step').hide().removeClass('active');
        $form.find('[data-step="1"]').show().addClass('active');
        
        // Reset progress bar
        $form.find('.progress-bar')
          .css('width', '33%')
          .attr('aria-valuenow', 1);
        $form.find('.current-step').text(1);
        
        // Clear all form inputs
        $form.find('input[type="checkbox"]').prop('checked', false);
        $form.find('input[type="text"], input[type="email"], input[type="tel"], textarea').val('');
        
        // Hide all conditional sections
        $form.find('.conditional-section').hide().removeClass('visible');
        
        // Set initial button states
        $form.find('[data-step="1"] .next-step').prop('disabled', true);
        
        // Remove any error/success messages
        $form.find('.alert').remove();
        
        // Remove validation classes
        $form.find('.is-invalid').removeClass('is-invalid');
        $form.find('input').removeAttr('aria-invalid');
        
        currentStep = 1;
      }
      
      // Initialize immediately
      initializeForm();
      
      // Prevent back navigation away from form - keep user on donation form page
      if (window.location.pathname === '/donate/questions') {
        // Replace the current history entry to prevent back navigation
        window.history.replaceState({page: 'donation-form', step: 1}, '', '/donate/questions');
        
        // Add an extra history entry so back button resets form instead of leaving page
        window.history.pushState({page: 'donation-form', step: 1}, '', '/donate/questions');
      }
      
      // Handle back button to reset form instead of navigating away
      window.addEventListener('popstate', function(event) {
        if (window.location.pathname === '/donate/questions') {
          // Prevent actual navigation and just reset the form
          event.preventDefault();
          setTimeout(initializeForm, 100);
          // Immediately push the state back so user stays on page
          window.history.pushState({page: 'donation-form', step: 1}, '', '/donate/questions');
        }
      });
      
      function buildPayload() {
        return {
          interests: $form.find('input[name="interests[]"]:checked').map(function() {
            return $(this).val();
          }).get(),
          making_donation_issues: $form.find('input[name="making_donation_issues[]"]:checked').map(function() {
            return $(this).val();
          }).get(),
          making_donation_other: $form.find('#making-donation-other').val().trim(),
          existing_donation_issues: $form.find('input[name="existing_donation_issues[]"]:checked').map(function() {
            return $(this).val();
          }).get(),
          existing_donation_other: $form.find('#existing-donation-other').val().trim(),
          program_info_details: $form.find('#program-info-details').val().trim(),
          other_ways_options: $form.find('input[name="other_ways_options[]"]:checked').map(function() {
            return $(this).val();
          }).get(),
          other_ways_additional: $form.find('#other-ways-additional').val().trim(),
          first_name: $form.find('#first_name').val().trim(),
          last_name: $form.find('#last_name').val().trim(),
          email: $form.find('#email').val().trim(),
          phone: $form.find('#phone').val().trim(),
          address: $form.find('#address').val().trim()
        };
      }
      
      function showSubmissionMessage(type, message, details = []) {
        $form.find('.submission-alert').remove();
        var allowedTypes = ['success', 'danger', 'warning', 'info'];
        var safeType = allowedTypes.indexOf(type) !== -1 ? type : 'info';

        var $alert = $('<div>')
          .addClass('alert alert-' + safeType + ' submission-alert')
          .attr('role', 'alert');
        $alert.append($('<strong>').text(message));

        if (details.length) {
          var $ul = $('<ul>').addClass('mb-0 mt-2');
          details.forEach(function(item) {
            $ul.append($('<li>').text(item));
          });
          $alert.append($ul);
        }

        $form.find('[data-step="3"]').prepend($alert);
      }
      
      function highlightBackendErrors(errors = {}) {
        Object.keys(errors).forEach((key) => {
          let field = $form.find(`[name="${key}"]`);
          if (!field.length) {
            field = $form.find(`[name="${key}[]"]`);
          }
          if (field.length) {
            field.addClass('is-invalid').attr('aria-invalid', 'true');
          }
        });
      }
      
      function renderSuccess(message) {
        // Hide the content-body section above the form
        const $container = $form.closest('.donation-inquiry-form').prev('.container');
        if ($container.length) {
          $container.find('.content-body').hide();
        }

        // Build success message using safe DOM construction
        var $wrapper = $('<div>').addClass('submission-success-wrapper');
        var $alert = $('<div>').addClass('alert alert-success submission-alert').attr('role', 'alert');
        $alert.append($('<h3>').text('Thank you for contacting us!'));
        $alert.append($('<p>').text(message || 'We have received your inquiry and will respond soon.'));
        $alert.append(
          $('<p>').append(
            document.createTextNode('For immediate assistance, email '),
            $('<a>').attr('href', 'mailto:development@idaholegalaid.org').text('development@idaholegalaid.org'),
            document.createTextNode(' or call '),
            $('<a>').attr('href', 'tel:+12088072214').text('(208) 807-2214'),
            document.createTextNode('.')
          )
        );
        $wrapper.append($alert);
        $form.replaceWith($wrapper);
      }
      
      // Handle page show event (for browser refresh/back from other tabs)
      window.addEventListener('pageshow', function(event) {
        if (window.location.pathname === '/donate/questions') {
          setTimeout(initializeForm, 100);
        }
      });
      
      // Prevent multiple initialization
      if (form.dataset.donationInquiryInitialized) return;
      form.dataset.donationInquiryInitialized = 'true';
      
      // Handle checkbox changes in step 1
      $form.find('input[name="interests[]"]').on('change', function() {
        const anyChecked = $form.find('input[name="interests[]"]:checked').length > 0;
        $form.find('[data-step="1"] .next-step').prop('disabled', !anyChecked);
        console.log('Checkbox changed, any checked:', anyChecked);
      });
      
      // Handle next button
      $form.on('click', '.next-step', function(e) {
        e.preventDefault();
        console.log('Next button clicked, current step:', currentStep);
        
        if ($(this).prop('disabled')) {
          console.log('Button is disabled, ignoring click');
          return;
        }
        
        if (currentStep === 1) {
          // Show relevant conditional sections in step 2
          const selectedInterests = $form.find('input[name="interests[]"]:checked').map(function() {
            return this.value;
          }).get();
          
          $form.find('.conditional-section').hide().removeClass('visible');
          selectedInterests.forEach(function(interest) {
            $form.find(`.conditional-section[data-condition="${interest}"]`).show().addClass('visible');
          });
        }
        
        if (currentStep === 2) {
          // Validate step 2 - ensure at least one checkbox is selected in visible sections
          if (!validateStep2(form)) {
            return;
          }
        }
        
        // Validate current step before moving forward
        if (currentStep === 3) {
          if (!validateContactForm(form)) {
            return;
          }
        }
        
        moveToStep(currentStep + 1);
      });
      
      // Handle previous button
      $form.on('click', '.prev-step', function(e) {
        e.preventDefault();
        moveToStep(currentStep - 1);
      });
      
      // Handle form submission
      // Uses async/await to fetch a fresh CSRF token immediately before submit.
      // This ensures the token is always session-valid regardless of page caching.
      $form.on('submit', async function(e) {
        e.preventDefault();

        if (!validateContactForm(form)) {
          return;
        }

        const honeypotField = $form.find('input[name="website_url"]');
        if (honeypotField.length && honeypotField.val()) {
          console.warn('Donation inquiry honeypot triggered');
          return;
        }

        let recaptchaResponse = '';
        if (typeof grecaptcha !== 'undefined' && $form.find('.g-recaptcha').length) {
          recaptchaResponse = grecaptcha.getResponse();
          if (!recaptchaResponse) {
            showSubmissionMessage('warning', 'Please complete the reCAPTCHA verification.');
            return;
          }
        }

        const submitBtn = $form.find('button[type="submit"]');
        const originalText = submitBtn.text();
        submitBtn.prop('disabled', true).text('Submitting...');
        showSubmissionMessage('info', 'Submitting your request...');

        try {
          // Fetch a fresh CSRF token immediately before submission.
          // This bypasses any page caching issues (Drupal, Varnish, CDN).
          const tokenResponse = await fetch('/donation-inquiry/token', {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
          });

          if (!tokenResponse.ok) {
            throw new Error('Could not verify session. Please refresh the page.');
          }

          const tokenData = await tokenResponse.json();

          const payload = buildPayload();
          // Use the fresh token from the server, falling back to embedded token.
          payload.csrf_token = tokenData.token || $form.find('input[name="csrf_token"]').val();
          payload.source_url = $form.find('input[name="source_url"]').val() || window.location.href;
          payload.website_url = honeypotField.length ? honeypotField.val() : '';
          payload.recaptcha_token = recaptchaResponse;

          const response = await fetch(submissionEndpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
          });

          const body = await response.json().catch(() => ({}));

          if (response.ok && body.status === 'success') {
            renderSuccess(body.message);
            return;
          }

          const errorMessages = [];
          if (body && body.errors) {
            highlightBackendErrors(body.errors);
            Object.values(body.errors).forEach(msg => errorMessages.push(msg));
          }
          const message = body && body.message ? body.message : 'We could not send your request. Please try again.';
          showSubmissionMessage('danger', message, errorMessages);

        } catch (error) {
          console.error('Donation inquiry submission failed', error);
          showSubmissionMessage('danger', 'We were unable to submit your request. Please check your connection and try again.');
        } finally {
          submitBtn.prop('disabled', false).text(originalText);
          if (typeof grecaptcha !== 'undefined' && $form.find('.g-recaptcha').length) {
            grecaptcha.reset();
          }
        }
      });
      
      // Validate step 2 - ensure at least one checkbox is selected in visible sections
      function validateStep2(form) {
        let isValid = true;
        const visibleSections = $form.find('.conditional-section.visible');
        
        if (visibleSections.length === 0) {
          return true; // No sections visible, which shouldn't happen
        }
        
        let hasSelection = false;
        
        // Check each visible section for at least one selection
        visibleSections.each(function() {
          const section = $(this);
          const checkboxes = section.find('input[type="checkbox"]:checked');
          const textareas = section.find('textarea').filter(function() {
            return $(this).val().trim().length > 0;
          });
          
          if (checkboxes.length > 0 || textareas.length > 0) {
            hasSelection = true;
          }
        });
        
        if (!hasSelection) {
          isValid = false;
          
          // Remove any existing validation messages
          $form.find('.step2-validation-error').remove();
          
          $form.find('[data-step="2"]').prepend(`
            <div class="alert alert-warning step2-validation-error" role="alert">
              <strong>Please make a selection:</strong> You must select at least one option or provide additional information in the text area for your selected categories.
            </div>
          `);
          
          setTimeout(() => {
            $form.find('.step2-validation-error').remove();
          }, 8000);
        }
        
        return isValid;
      }
      
      // Validate contact form fields
      function validateContactForm(form) {
        let isValid = true;
        
        // Define required fields explicitly
        const requiredFieldSelectors = [
          '#first_name',
          '#last_name', 
          '#email',
          '#phone'
        ];
        
        const errorMessages = [];
        
        // Clear existing validation classes
        $form.find('.is-invalid').removeClass('is-invalid');
        
        requiredFieldSelectors.forEach(function(selector) {
          const field = $form.find(selector);
          const value = field.val().trim();
          const fieldName = field.prev('label').text().replace(' *', '') || field.attr('placeholder') || 'Field';
          
          if (!value) {
            field.addClass('is-invalid');
            errorMessages.push(`${fieldName} is required`);
            isValid = false;
          } else {
            field.removeClass('is-invalid');
            
            // Email validation
            if (field.attr('type') === 'email') {
              const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
              if (!emailRegex.test(value)) {
                field.addClass('is-invalid');
                errorMessages.push(`${fieldName} must be a valid email address`);
                isValid = false;
              }
            }
            
            // Phone validation (basic)
            if (field.attr('type') === 'tel') {
              const phoneRegex = /^[\d\s\-\+\(\)\.]+$/;
              if (!phoneRegex.test(value) || value.replace(/[^\d]/g, '').length < 10) {
                field.addClass('is-invalid');
                errorMessages.push(`${fieldName} must be a valid phone number`);
                isValid = false;
              }
            }
          }
        });
        
        if (!isValid) {
          // Remove existing validation messages
          $form.find('.step3-validation-error').remove();
          
          $form.find('[data-step="3"]').prepend(`
            <div class="alert alert-warning step3-validation-error" role="alert">
              <strong>Please correct the following errors:</strong>
              <ul class="mb-0 mt-2">
                ${errorMessages.map(msg => `<li>${msg}</li>`).join('')}
              </ul>
            </div>
          `);
          
          const firstInvalid = $form.find('input.is-invalid').first();
          if (firstInvalid.length) {
            firstInvalid.focus();
          }
          
          setTimeout(() => {
            $form.find('.step3-validation-error').remove();
          }, 8000);
        }
        
        return isValid;
      }
      
      // Remove invalid class on input
      $form.on('input', 'input.is-invalid', function() {
        $(this).removeClass('is-invalid');
      });
      
      function moveToStep(step) {
        if (step < 1 || step > totalSteps) return;
        
        $form.find('.form-step').hide().removeClass('active');
        $form.find(`[data-step="${step}"]`).show().addClass('active');
        
        // Update progress bar with accessibility attributes
        const progress = (step / totalSteps) * 100;
        $form.find('.progress-bar')
          .css('width', progress + '%')
          .attr('aria-valuenow', step);
        $form.find('.current-step').text(step);
        
        currentStep = step;
        
        // Scroll to top of form
        $('html, body').animate({
          scrollTop: $form.offset().top - 100
        }, 300);
      }
      
      // Handle Enter key in form fields
      $form.on('keypress', 'input', function(e) {
        if (e.which === 13) {
          e.preventDefault();
          const nextButton = $(this).closest('.form-step').find('.next-step');
          if (nextButton.length && !nextButton.prop('disabled')) {
            nextButton.click();
          }
        }
      });
      
      // Enhanced accessibility improvements
      $form.find('.next-step').attr({
        'aria-label': 'Continue to next step',
        'aria-describedby': 'step-progress'
      });
      
      $form.find('.prev-step').attr({
        'aria-label': 'Go back to previous step',
        'aria-describedby': 'step-progress'
      });
      
      // Add aria-invalid to invalid fields
      $form.on('input change', 'input', function() {
        const field = $(this);
        if (field.hasClass('is-invalid')) {
          field.attr('aria-invalid', 'true');
        } else {
          field.removeAttr('aria-invalid');
        }
      });
      
      // Improve keyboard navigation
      $form.on('keydown', 'input[type="checkbox"]', function(e) {
        if (e.which === 13 || e.which === 32) {
          e.preventDefault();
          $(this).prop('checked', !$(this).prop('checked')).trigger('change');
        }
      });
    }
  };

})(jQuery, Drupal);
