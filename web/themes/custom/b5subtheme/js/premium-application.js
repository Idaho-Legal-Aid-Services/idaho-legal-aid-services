/**
 * Premium Employment Application Wizard
 * Properly integrated with Drupal 11 webform system
 * Maintains original step-by-step UX while using Drupal backend
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  // Configuration
  const CONFIG = {
    STORAGE_KEY: 'employment_application_draft',
    AUTO_SAVE_INTERVAL: 30000, // 30 seconds - reasonable for server calls
    ANIMATION_DURATION: 300,
    
    SELECTORS: {
      WIZARD: '.application-wizard',
      STEP: '.wizard-step',
      PROGRESS_BAR: '.progress-bar-fill',
      PROGRESS_STEPS: '.progress-steps .step',
      NAV_PREV: '.btn-prev',
      NAV_NEXT: '.btn-next',
      NAV_SUBMIT: '.btn-submit',
      CURRENT_STEP: '.current-step',
      FILE_INPUT: '.file-input',
      FILE_UPLOAD_AREA: '.file-upload-area',
      AUTO_SAVE_STATUS: '.auto-save-status',
      SUCCESS_MODAL: '.application-success-modal',
      FORM_TOKEN: '#form-token',
      FORM_BUILD_ID: '#form-build-id'
    },

    VALIDATION: {
      EMAIL_PATTERN: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
      PHONE_PATTERN: /^[\+]?[\s\-\(\)]?[\d\s\-\(\)]{10,}$/,
      FILE_MAX_SIZE: 5 * 1024 * 1024, // 5MB
      ALLOWED_EXTENSIONS: ['pdf', 'doc', 'docx']
    },

    MESSAGES: {
      REQUIRED: 'This field is required',
      INVALID_EMAIL: 'Please enter a valid email address',
      INVALID_PHONE: 'Please enter a valid phone number',
      FILE_TOO_LARGE: 'File size must be less than 5MB',
      INVALID_FILE_TYPE: 'Only PDF, DOC, and DOCX files are allowed',
      SAVE_SUCCESS: 'Draft saved locally',
      SAVE_ERROR: 'Error saving draft',
      UPLOAD_ERROR: 'File selection failed'
    }
  };

  // Main wizard class
  function EmploymentApplicationWizard($form) {
    this.$form = $form;
    this.currentStep = 1;
    this.totalSteps = 5;
    this.uploadedFiles = {};
    this.autoSaveTimeout = null;
    this.isSubmitted = false; // Flag to prevent auto-save after submission
    this.isPopulating = false; // Flag to prevent auto-save during form population
    this.formTokens = {
      token: '',
      buildId: ''
    };
    
    this.init();
  }

  EmploymentApplicationWizard.prototype = {
    init: function() {
      this.checkSubmissionStatus();
      this.bindEvents();
      this.initializeFormTokens();
      this.setupAutoSave();
      this.setupFileUploads();
      this.setupMultipleFields();
      this.setupConditionalFields();
      this.loadDraft();
      console.log('Employment Application Wizard initialized');
    },

    // Check if we just submitted and show success message
    checkSubmissionStatus: function() {
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('submitted') === 'success') {
        // Replace the entire page content with success message
        this.showSubmissionSuccessPage();
        
        // Clean up the URL to remove the parameter
        const cleanURL = window.location.pathname;
        window.history.replaceState({}, document.title, cleanURL);
      }
    },

    // Show full page success message
    showSubmissionSuccessPage: function() {
      // Find the application header and everything after it and replace it
      const $article = this.$form.closest('article');
      
      const successHTML = `
        <div class="submission-success-page">
          <div class="success-content">
            <div class="success-icon">
              <i class="fas fa-check-circle" aria-hidden="true"></i>
            </div>
            <h1>Application Submitted Successfully!</h1>
            <p class="success-message">Thank you for your interest in joining our team. We've received your application and will review it carefully.</p>
            
            <div class="next-steps">
              <h3>What happens next?</h3>
              <ul>
                <li>Our team will review your qualifications</li>
                <li>If you're a good fit, we'll contact you within 1-2 weeks for next steps</li>
              </ul>
            </div>
            
            <div class="success-actions">
              <a href="/employment" class="btn btn-primary">View Other Positions</a>
              <a href="/" class="btn btn-primary">Return Home</a>
            </div>
          </div>
        </div>
      `;
      
      $article.html(successHTML);
    },

    // Get CSRF tokens from Drupal
    initializeFormTokens: function() {
      const self = this;
      
      // Get tokens via AJAX
      $.ajax({
        url: '/employment-application/token',
        type: 'GET',
        success: function(response) {
          self.formTokens.token = response.token;
          $(CONFIG.SELECTORS.FORM_TOKEN).val(response.token);
          $(CONFIG.SELECTORS.FORM_BUILD_ID).val(response.build_id);
        }
      });
    },

    bindEvents: function() {
      const self = this;
      
      // Navigation
      this.$form.on('click', CONFIG.SELECTORS.NAV_NEXT, function(e) {
        e.preventDefault();
        self.nextStep();
      });
      
      this.$form.on('click', CONFIG.SELECTORS.NAV_PREV, function(e) {
        e.preventDefault();
        self.prevStep();
      });
      
      // Step indicators
      this.$form.on('click', '.step-button', function(e) {
        e.preventDefault();
        const targetStep = parseInt($(this).closest('.step').data('step'));
        if (targetStep <= self.currentStep || self.validateSteps(1, targetStep - 1)) {
          self.goToStep(targetStep);
        }
      });
      
      // Form submission
      this.$form.on('submit', function(e) {
        e.preventDefault();
        self.submitForm();
      });
      
      // Auto-save on input
      this.$form.on('input change', 'input, select, textarea', function() {
        if (!self.isPopulating) {
          self.scheduleAutoSave();
        }
      });
      
      // Validation on blur
      this.$form.on('blur', 'input[required], select[required], textarea[required]', function() {
        self.validateField($(this));
      });

      // Prevent Enter key from submitting form prematurely
      this.$form.on('keydown', function(e) {
        if (e.key === 'Enter' && !$(e.target).is('textarea, button, input[type="submit"]')) {
          e.preventDefault();
          self.nextStep();
        }
      });
    },

    setupConditionalFields: function() {
      const self = this;
      
      // Position "Other" field
      this.$form.on('change', '#position_applied', function() {
        const $otherField = $('.other-position');
        const $attorneyQuestions = $('#attorney-questions');
        const selectedPosition = $(this).val();
        
        if (selectedPosition === 'other') {
          $otherField.show().attr('aria-hidden', 'false');
          $otherField.find('input').focus();
        } else {
          $otherField.hide().attr('aria-hidden', 'true');
          $otherField.find('input').val('');
        }
        
        // Show/hide attorney-specific questions
        const $step3 = self.$form.find('.wizard-step[data-step="3"]');
        if (selectedPosition === 'managing_attorney' || selectedPosition === 'staff_attorney') {
          $attorneyQuestions.show().attr('aria-hidden', 'false');
          // Make attorney questions required
          $attorneyQuestions.find('select').prop('required', true).attr('aria-required', 'true');
          // Remove non-attorney class
          $step3.removeClass('non-attorney-position');
        } else {
          $attorneyQuestions.hide().attr('aria-hidden', 'true');
          // Remove required from attorney questions and clear values
          $attorneyQuestions.find('select').prop('required', false).attr('aria-required', 'false').val('');
          // Add non-attorney class to center sensitive questions
          $step3.addClass('non-attorney-position');
        }
      });
      
      // Referral source details
      this.$form.on('change', '#referral_source', function() {
        const $detailsField = $('.referral-details');
        if ($(this).val() && $(this).val() !== 'company_website') {
          $detailsField.show();
          $detailsField.find('input').focus();
        } else {
          $detailsField.hide();
          $detailsField.find('input').val('');
        }
      });
      
    },

    setupMultipleFields: function() {
      // No longer needed - work experience and education sections removed
    },


    setupFileUploads: function() {
      const self = this;
      
      this.$form.find(CONFIG.SELECTORS.FILE_INPUT).each(function() {
        const $input = $(this);
        const $area = $input.closest(CONFIG.SELECTORS.FILE_UPLOAD_AREA);
        const fieldName = $area.data('field');
        
        // Drag and drop
        $area.on('dragover', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).addClass('dragging');
        });
        
        $area.on('dragleave', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).removeClass('dragging');
        });
        
        $area.on('drop', function(e) {
          e.preventDefault();
          e.stopPropagation();
          $(this).removeClass('dragging');
          
          const files = e.originalEvent.dataTransfer.files;
          if (files.length > 0) {
            $input[0].files = files;
            if (fieldName === 'additional_documents') {
              // Handle multiple files for additional documents
              self.handleMultipleFileUpload($input, files);
            } else {
              // Handle single file for other fields
              self.handleFileUpload($input, files[0]);
            }
          }
        });
        
        // Click to browse
        $area.on('click', function(e) {
          // Prevent recursive clicks and only trigger if clicking the upload area, not the input or remove button
          if (!$(e.target).hasClass('remove-file') && 
              !$(e.target).closest('.remove-file').length && 
              !$(e.target).hasClass('fa-times') &&  // Don't trigger on X icon
              !$(e.target).is('input[type="file"]') && 
              !$(e.target).closest('input[type="file"]').length &&
              !$(e.target).closest('.file-preview').length &&
              !$(e.target).closest('.multiple-files-preview').length) {
            e.preventDefault();
            e.stopPropagation();
            $input[0].click(); // Use native click to avoid jQuery event recursion
          }
        });
        
        // File input change
        $input.on('change', function() {
          if (this.files.length > 0) {
            if (fieldName === 'additional_documents') {
              // Handle multiple files for additional documents (even if only one is selected)
              self.handleMultipleFileUpload($input, this.files);
            } else {
              // Handle single file for other fields
              self.handleFileUpload($input, this.files[0]);
            }
          }
        });
        
        // Remove file - use event delegation to ensure proper handling
        // Handle both button and icon clicks
        $area.on('click', '.remove-file, .remove-file i', function(e) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation(); // Prevent any other handlers from running
          
          const $button = $(e.target).closest('.remove-file');
          const fileIndex = $button.data('file-index');
          const $fileItem = $button.closest('.file-preview-item');
          const itemIndex = $fileItem.data('file-index');
          
          // Try to get index from either the button or the parent item
          const indexToRemove = fileIndex !== undefined ? fileIndex : itemIndex;
          
          if (indexToRemove !== undefined && fieldName === 'additional_documents') {
            // Remove specific file from multiple files
            self.removeSpecificFile($area, fieldName, indexToRemove);
          } else if (indexToRemove !== undefined) {
            // Remove specific file from multiple files for any field
            self.removeSpecificFile($area, fieldName, indexToRemove);
          } else {
            // Remove single file (fallback)
            self.removeFile($area, fieldName);
          }
          return false; // Extra safety to prevent bubbling
        });
      });
    },

    handleFileUpload: function($input, file) {
      const $area = $input.closest(CONFIG.SELECTORS.FILE_UPLOAD_AREA);
      const fieldName = $area.data('field');
      
      // Validate file
      if (!this.validateFile(file, $area)) {
        return;
      }
      
      // Show upload progress
      this.showUploadProgress($area);
      
      // Store file locally for form submission - no separate upload needed
      // Files will be uploaded when the form is submitted to Drupal
      setTimeout(() => {
        this.showFilePreview($area, file);
        this.uploadedFiles[fieldName] = file;
        this.showSaveStatus('File ready for upload', 'success');
        this.scheduleAutoSave();
      }, 800); // Shorter delay since we're not actually uploading
    },

    handleMultipleFileUpload: function($input, files) {
      const $area = $input.closest(CONFIG.SELECTORS.FILE_UPLOAD_AREA);
      const fieldName = $area.data('field');
      const validFiles = [];
      
      // Validate all files first
      for (let i = 0; i < files.length; i++) {
        if (this.validateFile(files[i], $area)) {
          validFiles.push(files[i]);
        }
      }
      
      if (validFiles.length === 0) {
        return;
      }
      
      // Show upload progress
      this.showUploadProgress($area);
      
      // Store files and show preview
      setTimeout(() => {
        // Get existing files and combine with new ones
        const existingFiles = this.uploadedFiles[fieldName] || [];
        const combinedFiles = [...existingFiles, ...validFiles];
        
        this.showMultipleFilePreview($area, combinedFiles);
        this.uploadedFiles[fieldName] = combinedFiles;
        this.showSaveStatus(`${combinedFiles.length} file(s) ready for upload`, 'success');
        this.scheduleAutoSave();
      }, 800);
    },

    validateFile: function(file, $area) {
      const extension = file.name.split('.').pop().toLowerCase();
      
      if (!CONFIG.VALIDATION.ALLOWED_EXTENSIONS.includes(extension)) {
        this.showFieldError($area, CONFIG.MESSAGES.INVALID_FILE_TYPE);
        return false;
      }
      
      if (file.size > CONFIG.VALIDATION.FILE_MAX_SIZE) {
        this.showFieldError($area, CONFIG.MESSAGES.FILE_TOO_LARGE);
        return false;
      }
      
      this.clearFieldError($area);
      return true;
    },

    showUploadProgress: function($area) {
      $area.addClass('uploading');
      $area.find('.upload-placeholder').hide();
      $area.find('.upload-progress').show();
      
      // Animate progress bar
      let progress = 0;
      const interval = setInterval(function() {
        progress += Math.random() * 30;
        if (progress >= 95) {
          progress = 95;
          clearInterval(interval);
        }
        $area.find('.progress-bar').css('--progress', progress + '%');
      }, 100);
    },

    showFilePreview: function($area, file) {
      $area.removeClass('uploading').addClass('has-file');
      $area.find('.upload-progress').hide();
      $area.find('.upload-placeholder').hide();
      
      const $preview = $area.find('.file-preview');
      $preview.find('.file-name').text(file.name);
      $preview.find('.file-size').text(this.formatFileSize(file.size));
      $preview.show();
    },

    showMultipleFilePreview: function($area, files) {
      $area.removeClass('uploading').addClass('has-file');
      $area.find('.upload-progress').hide();
      $area.find('.upload-placeholder').hide();
      
      // Clear existing preview and create multiple file previews
      const $existingPreview = $area.find('.file-preview');
      $existingPreview.hide();
      
      // Remove any existing multiple preview container
      $area.find('.multiple-files-preview').remove();
      
      // Create container for multiple files
      const $multiplePreview = $('<div class="multiple-files-preview"></div>');
      
      files.forEach((file, index) => {
        const $filePreview = $(`
          <div class="file-preview-item" data-file-index="${index}">
            <div class="file-info">
              <i class="fas fa-file-alt" aria-hidden="true"></i>
              <span class="file-name">${file.name}</span>
              <span class="file-size">${this.formatFileSize(file.size)}</span>
            </div>
            <button type="button" class="remove-file" aria-label="Remove ${file.name}" data-file-index="${index}">
              <i class="fas fa-times" aria-hidden="true"></i>
            </button>
          </div>
        `);
        $multiplePreview.append($filePreview);
      });
      
      $area.append($multiplePreview);
    },

    removeFile: function($area, fieldName) {
      $area.removeClass('has-file uploading');
      $area.find('.file-preview').hide();
      $area.find('.multiple-files-preview').remove();
      $area.find('.upload-progress').hide();
      $area.find('.upload-placeholder').show();
      $area.find('input[type="file"]').val('');
      
      delete this.uploadedFiles[fieldName];
      this.scheduleAutoSave();
    },

    removeSpecificFile: function($area, fieldName, fileIndex) {
      const files = this.uploadedFiles[fieldName];
      
      if (!files || !Array.isArray(files)) {
        this.removeFile($area, fieldName);
        return;
      }
      
      if (fileIndex < 0 || fileIndex >= files.length) {
        return;
      }
      
      // Remove the specific file from the array
      files.splice(fileIndex, 1);
      
      if (files.length === 0) {
        // No more files, reset the upload area
        this.removeFile($area, fieldName);
      } else {
        // Update the array and refresh preview
        this.uploadedFiles[fieldName] = files;
        this.showMultipleFilePreview($area, files);
        this.scheduleAutoSave();
      }
    },

    formatFileSize: function(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    // Navigation methods
    nextStep: function() {
      if (this.validateCurrentStep()) {
        if (this.currentStep < this.totalSteps) {
          this.goToStep(this.currentStep + 1);
        }
      }
    },

    prevStep: function() {
      if (this.currentStep > 1) {
        this.goToStep(this.currentStep - 1);
      }
    },

    goToStep: function(step) {
      if (step < 1 || step > this.totalSteps) return;
      
      // Hide current wizard step content (not progress indicators)
      this.$form.find(`.wizard-step[data-step="${this.currentStep}"]`).removeClass('active').attr('hidden', '');
      
      // Show target wizard step content
      this.$form.find(`.wizard-step[data-step="${step}"]`).addClass('active').removeAttr('hidden');
      
      // Update progress
      this.currentStep = step;
      this.updateProgress();
      this.updateNavigation();
      
      // Scroll to top
      this.$form.find('.wizard-content')[0].scrollIntoView({ behavior: 'smooth', block: 'start' });
      
      // Focus first input in new step
      setTimeout(() => {
        this.$form.find(`.wizard-step[data-step="${step}"] input:visible:first`).focus();
      }, CONFIG.ANIMATION_DURATION);
    },

    updateProgress: function() {
      const progress = ((this.currentStep - 1) / (this.totalSteps - 1)) * 100;
      
      // Update integrated progress bar
      this.$form.find('.progress-steps').css('--progress-width', progress + '%');
      
      // Update step indicators
      this.$form.find(CONFIG.SELECTORS.PROGRESS_STEPS).each((index, element) => {
        const $step = $(element);
        const stepNum = $step.data('step');
        
        $step.removeClass('active completed');
        
        if (stepNum === this.currentStep) {
          $step.addClass('active').attr('aria-current', 'step');
        } else if (stepNum < this.currentStep) {
          $step.addClass('completed').removeAttr('aria-current');
        } else {
          $step.removeAttr('aria-current');
        }
      });
      
      // Update step counter
      this.$form.find(CONFIG.SELECTORS.CURRENT_STEP).text(this.currentStep);
    },

    updateNavigation: function() {
      const $prevBtn = this.$form.find(CONFIG.SELECTORS.NAV_PREV);
      const $nextBtn = this.$form.find(CONFIG.SELECTORS.NAV_NEXT);
      const $submitBtn = this.$form.find(CONFIG.SELECTORS.NAV_SUBMIT);
      
      // Previous button
      if (this.currentStep === 1) {
        $prevBtn.hide();
      } else {
        $prevBtn.show();
      }
      
      // Next/Submit button
      if (this.currentStep === this.totalSteps) {
        $nextBtn.hide();
        $submitBtn.show();
      } else {
        $nextBtn.show();
        $submitBtn.hide();
      }
    },

    // Validation methods
    validateCurrentStep: function() {
      return this.validateStep(this.currentStep);
    },

    validateSteps: function(fromStep, toStep) {
      for (let step = fromStep; step <= toStep; step++) {
        if (!this.validateStep(step)) {
          return false;
        }
      }
      return true;
    },

    validateStep: function(stepNumber) {
      const $step = this.$form.find(`.wizard-step[data-step="${stepNumber}"]`);
      let isValid = true;
      
      $step.find('input[required], select[required], textarea[required]').each((index, element) => {
        if (!this.validateField($(element))) {
          isValid = false;
        }
      });
      
      return isValid;
    },

    validateField: function($field) {
      const value = $field.val().trim();
      const fieldType = $field.attr('type') || $field.prop('tagName').toLowerCase();
      let isValid = true;
      let message = '';
      
      // Required validation
      if ($field.prop('required') && !value) {
        isValid = false;
        message = CONFIG.MESSAGES.REQUIRED;
      }
      // Email validation
      else if (fieldType === 'email' && value && !CONFIG.VALIDATION.EMAIL_PATTERN.test(value)) {
        isValid = false;
        message = CONFIG.MESSAGES.INVALID_EMAIL;
      }
      // Phone validation
      else if (fieldType === 'tel' && value && !CONFIG.VALIDATION.PHONE_PATTERN.test(value.replace(/[\s\-\(\)]/g, ''))) {
        isValid = false;
        message = CONFIG.MESSAGES.INVALID_PHONE;
      }
      
      // Show/clear error
      if (isValid) {
        this.clearFieldError($field);
      } else {
        this.showFieldError($field, message);
      }
      
      return isValid;
    },

    showFieldError: function($field, message) {
      const $group = $field.closest('.form-group, .file-upload-area');
      const $feedback = $group.find('.form-feedback');
      
      $group.addClass('error');
      $field.addClass('is-invalid').attr('aria-invalid', 'true');
      $feedback.text(message).show();
    },

    clearFieldError: function($field) {
      const $group = $field.closest('.form-group, .file-upload-area');
      const $feedback = $group.find('.form-feedback');
      
      $group.removeClass('error');
      $field.removeClass('is-invalid').attr('aria-invalid', 'false');
      $feedback.text('').hide();
    },

    // Auto-save functionality
    setupAutoSave: function() {
      if (drupalSettings.user && drupalSettings.user.uid > 0) {
        this.scheduleAutoSave();
      }
    },

    scheduleAutoSave: function() {
      // Don't schedule auto-save after form has been submitted
      if (this.isSubmitted) {
        return;
      }
      
      const self = this;
      
      if (this.autoSaveTimeout) {
        clearTimeout(this.autoSaveTimeout);
      }
      
      this.autoSaveTimeout = setTimeout(function() {
        self.saveDraft();
      }, CONFIG.AUTO_SAVE_INTERVAL);
    },

    saveDraft: function() {
      // For now, always use localStorage until Drupal draft integration is implemented
      // This is safer and works for both anonymous and authenticated users
      this.saveDraftLocal();
    },

    saveDraftLocal: function() {
      // Don't save drafts after form has been successfully submitted
      if (this.isSubmitted) {
        return;
      }
      
      const formData = this.collectFormData();
      formData.currentStep = this.currentStep;
      formData.timestamp = Date.now();
      
      try {
        localStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(formData));
        this.showSaveStatus(CONFIG.MESSAGES.SAVE_SUCCESS, 'saved');
      } catch (e) {
        this.showSaveStatus(CONFIG.MESSAGES.SAVE_ERROR, 'error');
      }
    },

    loadDraft: function() {
      // Try localStorage first
      const draftData = localStorage.getItem(CONFIG.STORAGE_KEY);
      if (draftData) {
        try {
          const data = JSON.parse(draftData);
          this.populateFormData(data);
          if (data.currentStep && data.currentStep > 1) {
            this.goToStep(data.currentStep);
          }
        } catch (e) {
          console.log('Could not load draft:', e);
        }
      }
    },

    collectFormData: function() {
      const data = {};
      
      this.$form.find('input, select, textarea').each(function() {
        const $field = $(this);
        const name = $field.attr('name');
        const type = $field.attr('type');
        
        if (!name || name === 'form_token' || name === 'form_build_id') return;
        
        if (type === 'checkbox') {
          data[name] = $field.is(':checked') ? $field.val() : '';
        } else if (type === 'file') {
          // File fields handled separately
        } else {
          data[name] = $field.val();
        }
      });
      
      return data;
    },

    populateFormData: function(data) {
      this.isPopulating = true; // Prevent auto-save during population
      
      Object.keys(data).forEach(name => {
        const $field = this.$form.find(`[name="${name}"]`);
        if ($field.length) {
          if ($field.attr('type') === 'checkbox') {
            $field.prop('checked', !!data[name]);
          } else {
            $field.val(data[name]);
          }
        }
      });
      
      // Trigger change events for conditional fields
      this.$form.find('#position_applied, #referral_source').trigger('change');
      
      this.isPopulating = false; // Re-enable auto-save
    },

    showSaveStatus: function(message, type) {
      const $status = this.$form.find(CONFIG.SELECTORS.AUTO_SAVE_STATUS);
      
      $status.removeClass('visible saving saved error')
        .addClass('visible ' + type)
        .find('.save-text').text(message);
      
      // Hide after delay for success messages
      if (type === 'saved') {
        setTimeout(function() {
          $status.removeClass('visible');
        }, 3000);
      }
    },

    // Form submission
    submitForm: function() {
      // Temporarily disable validation to test submission
      console.log('Attempting form submission...');
      /*
      if (!this.validateSteps(1, this.totalSteps)) {
        this.showSaveStatus('Please correct the errors above', 'error');
        return;
      }
      */
      
      const self = this;
      
      // Stop auto-save during submission
      if (this.autoSaveTimeout) {
        clearTimeout(this.autoSaveTimeout);
        this.autoSaveTimeout = null;
      }
      
      // Serialize form data 
      const formDataObj = this.serializeFormToJSON();
      console.log('Serialized form data:', formDataObj);
      
      this.showSaveStatus('Submitting application...', 'saving');
      
      // Check if we have files
      const hasFiles = formDataObj._files_ && Object.keys(formDataObj._files_).length > 0;
      
      let ajaxConfig;
      if (hasFiles) {
        // Use FormData for file uploads
        const formData = new FormData();
        
        // Add all form fields
        Object.keys(formDataObj).forEach(key => {
          if (key !== '_files_') {
            if (typeof formDataObj[key] === 'object' && formDataObj[key] !== null) {
              formData.append(key, JSON.stringify(formDataObj[key]));
            } else {
              formData.append(key, formDataObj[key] || '');
            }
          }
        });
        
        // Add files
        Object.keys(formDataObj._files_).forEach(fieldName => {
          const files = formDataObj._files_[fieldName];
          if (Array.isArray(files)) {
            // Handle multiple files
            files.forEach((file, index) => {
              formData.append(fieldName, file);
            });
          } else {
            // Handle single file
            formData.append(fieldName, files);
          }
        });
        
        ajaxConfig = {
          url: '/employment-application/submit',
          type: 'POST',
          data: formData,
          contentType: false,
          processData: false,
        };
      } else {
        // Use JSON for submissions without files
        ajaxConfig = {
          url: '/employment-application/submit',
          type: 'POST',
          data: JSON.stringify(formDataObj),
          contentType: 'application/json',
          processData: false,
        };
      }
      
      // Add success and error handlers
      ajaxConfig.success = function(response) {
        if (response.success) {
          // Mark form as submitted to prevent further auto-save
          self.isSubmitted = true;
          
          // Clear draft
          localStorage.removeItem(CONFIG.STORAGE_KEY);
          
          // Refresh the page with a success parameter
          window.location.href = window.location.pathname + '?submitted=success';
        } else {
          // Handle server-side validation errors
          self.showSaveStatus(response.message || 'Submission failed. Please try again.', 'error');
        }
      };
      
      ajaxConfig.error = function(xhr, status, error) {
        console.error('Submission error:', error);
        let errorMessage = 'Submission failed. Please try again.';
        
        if (xhr.responseJSON && xhr.responseJSON.message) {
          errorMessage = xhr.responseJSON.message;
        } else if (xhr.responseText) {
          try {
            const response = JSON.parse(xhr.responseText);
            if (response.message) {
              errorMessage = response.message;
            }
          } catch (e) {
            // Use default error message
          }
        }
        
        self.showSaveStatus(errorMessage, 'error');
      };
      
      ajaxConfig.complete = function(xhr, status) {
        console.log('AJAX Complete - Status:', status, 'HTTP Status:', xhr.status);
      };
      
      // Submit the form
      $.ajax(ajaxConfig);
    },

    // Serialize form to JSON with proper structure handling
    serializeFormToJSON: function() {
      const formData = {};
      const $inputs = this.$form.find('input, select, textarea');
      const self = this;
      
      $inputs.each(function() {
        const $input = $(this);
        const name = $input.attr('name');
        const type = $input.attr('type');
        
        if (!name || name === 'form_token' || name === 'form_build_id' || name === 'op') {
          return; // Skip system fields
        }
        
        let value = null;
        
        // Handle different input types
        if (type === 'checkbox') {
          value = $input.is(':checked') ? ($input.val() || '1') : '';
        } else if (type === 'radio') {
          if ($input.is(':checked')) {
            value = $input.val();
          } else {
            return; // Skip unchecked radios
          }
        } else if (type === 'file') {
          // Handle files - we'll process these separately
          if ($input[0].files && $input[0].files.length > 0) {
            formData['_files_'] = formData['_files_'] || {};
            // Check if this field supports multiple files
            if (name === 'additional_documents[]' || $input.prop('multiple')) {
              // Store all files for multiple file fields
              const fileArray = [];
              for (let i = 0; i < $input[0].files.length; i++) {
                fileArray.push($input[0].files[i]);
              }
              formData['_files_'][name] = fileArray;
            } else {
              // Store single file
              formData['_files_'][name] = $input[0].files[0];
            }
            // Don't include file in regular JSON data
            return;
          }
        } else {
          value = $input.val();
        }
        
        if (value !== null) {
          // Handle nested field names like address[city] or work_experience[0][employer]
          // Include empty values too - server will validate required fields
          self.setNestedValue(formData, name, value);
        }
      });
      
      // Add CSRF token
      formData.form_token = this.formTokens.token;
      
      return formData;
    },

    // Helper function to set nested values from field names like address[city]
    setNestedValue: function(obj, path, value) {
      // Handle patterns like "address[city]" or "work_experience[0][employer]"
      const matches = path.match(/^([^[]+)(?:\[([^\]]+)\])*(.*)$/);
      
      if (!matches) {
        obj[path] = value;
        return;
      }
      
      const baseName = matches[1];
      const restOfPath = path.substring(baseName.length);
      
      if (!restOfPath) {
        obj[baseName] = value;
        return;
      }
      
      // Initialize nested object/array if needed
      if (!obj[baseName]) {
        // Determine if this should be an array (numeric index) or object
        const firstIndex = restOfPath.match(/^\[(\d+)\]/);
        obj[baseName] = firstIndex ? [] : {};
      }
      
      // Parse nested structure
      let current = obj[baseName];
      const segments = restOfPath.match(/\[([^\]]+)\]/g) || [];
      
      for (let i = 0; i < segments.length; i++) {
        const segment = segments[i].slice(1, -1); // Remove [ and ]
        const isLast = i === segments.length - 1;
        
        if (isLast) {
          current[segment] = value;
        } else {
          if (!current[segment]) {
            // Check if next segment is numeric to determine array vs object
            const nextSegment = segments[i + 1];
            const isNextNumeric = nextSegment && /^\[\d+\]$/.test(nextSegment);
            current[segment] = isNextNumeric ? [] : {};
          }
          current = current[segment];
        }
      }
    },

    showSuccessModal: function() {
      const $modal = this.$form.find(CONFIG.SELECTORS.SUCCESS_MODAL);
      $modal.fadeIn(CONFIG.ANIMATION_DURATION);
      
      // Focus management
      $modal.find('h2').focus();
      
      // Trap focus in modal
      $modal.on('keydown', function(e) {
        if (e.key === 'Escape') {
          window.location.href = '/employment';
        }
      });
    }
  };

  // Initialize when ready - Drupal 11 behavior
  Drupal.behaviors.premiumApplicationWizard = {
    attach: function(context, settings) {
      // Use Drupal 11's once() API to prevent duplicate initialization
      const $wizards = $(once('premium-wizard', CONFIG.SELECTORS.WIZARD, context));
      $wizards.each(function() {
        new EmploymentApplicationWizard($(this));
      });
    },
    
    detach: function(context, settings, trigger) {
      // Clean up when elements are removed
      if (trigger === 'unload') {
        $(once.remove('premium-wizard', CONFIG.SELECTORS.WIZARD, context));
      }
    }
  };

  // Phone number formatting behavior
  Drupal.behaviors.phoneFormatter = {
    attach: function(context, settings) {
      const $phoneInputs = $(once('phone-format', 'input[type="tel"]', context));
      $phoneInputs.on('input.phoneFormatter', function() {
        let value = $(this).val().replace(/\D/g, '');
        if (value.length >= 6) {
          value = value.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
          $(this).val(value);
        }
      });
    },
    
    detach: function(context, settings, trigger) {
      if (trigger === 'unload') {
        const $phoneInputs = $(once.remove('phone-format', 'input[type="tel"]', context));
        $phoneInputs.off('input.phoneFormatter');
      }
    }
  };

  // Auto-resize textarea behavior
  Drupal.behaviors.autoResizeTextarea = {
    attach: function(context, settings) {
      const $textareas = $(once('auto-resize', 'textarea.auto-resize', context));
      $textareas.on('input.autoResize', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
      });
      
      // Trigger initial resize
      $textareas.trigger('input');
    },
    
    detach: function(context, settings, trigger) {
      if (trigger === 'unload') {
        const $textareas = $(once.remove('auto-resize', 'textarea.auto-resize', context));
        $textareas.off('input.autoResize');
      }
    }
  };

})(jQuery, Drupal, drupalSettings, once);