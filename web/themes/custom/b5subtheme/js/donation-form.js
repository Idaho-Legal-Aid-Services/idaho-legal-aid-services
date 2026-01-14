/**
 * @file
 * Custom donation form functionality
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.donationForm = {
    attach: function (context, settings) {
      // Try without once first to see if that's the issue
      const $forms = $('.custom-donation-form', context);

      // Check if once method exists
      if (typeof $forms.once === 'function') {
        $forms.once('donation-form').each(function() {
          initializeDonationForm($(this));
        });
      } else {
        $forms.each(function() {
          const $form = $(this);
          if (!$form.data('donation-form-initialized')) {
            $form.data('donation-form-initialized', true);
            initializeDonationForm($form);
          }
        });
      }

      function initializeDonationForm($form) {
        const $amountBtns = $form.find('.amount-btn');
        const $frequencyBtns = $form.find('.frequency-btn');
        const $customAmount = $form.find('#custom-amount-input');
        const $customAmountError = $form.find('#custom-amount-error');
        const $donateBtn = $form.find('.donate-button');
        const $donationSummary = $form.find('.donation-summary');

        let selectedAmount = null;
        let selectedFrequency = 'one-time';

        // Amount button selection with aria-pressed
        $amountBtns.on('click', function(e) {
          e.preventDefault();
          $amountBtns.removeClass('active').attr('aria-pressed', 'false');
          $(this).addClass('active').attr('aria-pressed', 'true');
          selectedAmount = $(this).data('amount');
          $customAmount.val('').removeClass('is-invalid').removeAttr('aria-invalid');
          $customAmountError.text('');
          updateDonateButton();
        });

        // Custom amount input
        $customAmount.on('input', function() {
          const val = $(this).val().replace(/[^0-9.]/g, ''); // Strip non-numeric except decimal
          $(this).val(val); // Sanitize input

          const amount = parseFloat(val);
          if (amount > 0) {
            $amountBtns.removeClass('active').attr('aria-pressed', 'false');
            selectedAmount = amount;
            // Clear any previous error while typing
            $(this).removeClass('is-invalid').removeAttr('aria-invalid');
            $customAmountError.text('');
          } else {
            selectedAmount = null;
          }
          updateDonateButton();
        });

        // Custom amount validation on blur
        $customAmount.on('blur', function() {
          const val = parseFloat($(this).val());
          if ($(this).val() !== '' && (isNaN(val) || val < 1)) {
            $(this).addClass('is-invalid').attr('aria-invalid', 'true');
            $customAmountError.text('Please enter an amount of $1 or more');
          } else {
            $(this).removeClass('is-invalid').removeAttr('aria-invalid');
            $customAmountError.text('');
          }
        });

        // Frequency button selection with aria-pressed
        $frequencyBtns.on('click', function(e) {
          e.preventDefault();
          $frequencyBtns.removeClass('active').attr('aria-pressed', 'false');
          $(this).addClass('active').attr('aria-pressed', 'true');
          selectedFrequency = $(this).data('frequency');
          updateDonateButton();
        });

        // Update donate button with selected amount and frequency
        function updateDonateButton() {
          let baseUrl = 'https://donorbox.org/ilas';
          let ariaLabel = 'Donate Now';
          let summaryText = '';

          if (selectedAmount) {
            baseUrl += '?default_interval=' + selectedFrequency + '&amount=' + selectedAmount;

            const formattedAmount = '$' + selectedAmount.toLocaleString();
            if (selectedFrequency === 'monthly') {
              ariaLabel = 'Donate ' + formattedAmount + ' per month';
              summaryText = formattedAmount + '/month';
            } else if (selectedFrequency === 'quarterly') {
              ariaLabel = 'Donate ' + formattedAmount + ' per quarter';
              summaryText = formattedAmount + '/quarter';
            } else {
              ariaLabel = 'Donate ' + formattedAmount + ' one time';
              summaryText = formattedAmount + ' one-time';
            }
          }

          // Update href and aria-label, keep button text static
          $donateBtn.attr('href', baseUrl).attr('aria-label', ariaLabel);

          // Update the summary text below the button
          if ($donationSummary.length) {
            $donationSummary.text(summaryText);
          }
        }

        // Initialize with default state
        updateDonateButton();
      }
    }
  };

})(jQuery, Drupal);
