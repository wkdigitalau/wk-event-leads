/* WK Event Leads — form.js
   Async form submission. No dependencies. ES2017+.
*/
(function () {
    'use strict';

    const config = window.wkelForm || {};

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('wkel-capture-form');
        if (!form) return;
        form.addEventListener('submit', handleSubmit);
    });

    async function handleSubmit(e) {
        e.preventDefault();

        const form      = e.target;
        const wrap      = document.getElementById('wkel-form-wrap');
        const submitBtn = form.querySelector('.wkel-submit');
        const label     = form.querySelector('.wkel-submit-label');
        const spinner   = form.querySelector('.wkel-spinner');
        const generalError = form.querySelector('.wkel-general-error');

        clearErrors(form);

        if (!clientValidate(form)) return;

        setSubmitting(true, submitBtn, label, spinner);

        const payload = buildPayload(form);

        try {
            const response = await fetch(config.restUrl, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   config.nonce,
                },
                body: JSON.stringify(payload),
            });

            const data = await response.json();

            if (response.ok && data.success) {
                onSuccess(form, wrap, data);
            } else if (response.status === 422 && data.errors) {
                onValidationErrors(form, data.errors);
                setSubmitting(false, submitBtn, label, spinner);
            } else {
                showGeneralError(generalError, data.message || 'Something went wrong. Please try again.');
                setSubmitting(false, submitBtn, label, spinner);
            }
        } catch (err) {
            showGeneralError(generalError, 'Network error — please check your connection and try again.');
            setSubmitting(false, submitBtn, label, spinner);
        }
    }

    function buildPayload(form) {
        const data    = new FormData(form);
        const payload = {
            event:        form.dataset.event || 'general',
            wkel_privacy: form.querySelector('[name="wkel_privacy"]')?.checked ? '1' : '0',
            wkel_hp:      form.querySelector('[name="wkel_hp"]')?.value || '',
        };

        for (const [key, value] of data.entries()) {
            if (key === 'wkel_privacy' || key === 'wkel_hp') continue;
            // Handle checkbox arrays
            if (key.endsWith('[]')) {
                const cleanKey = key.slice(0, -2);
                if (!payload[cleanKey]) payload[cleanKey] = [];
                payload[cleanKey].push(value);
            } else {
                payload[key] = value;
            }
        }

        return payload;
    }

    function clientValidate(form) {
        let valid = true;

        form.querySelectorAll('[required]').forEach(function (input) {
            const value = input.type === 'checkbox' ? input.checked : input.value.trim();
            if (!value) {
                showFieldError(input, 'This field is required.');
                valid = false;
            } else if (input.type === 'email' && !isValidEmail(input.value)) {
                showFieldError(input, 'Please enter a valid email address.');
                valid = false;
            }
        });

        return valid;
    }

    function isValidEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function showFieldError(input, message) {
        const field = input.closest('.wkel-field');
        if (!field) return;
        const errorEl = field.querySelector('.wkel-field-error');
        if (errorEl) errorEl.textContent = message;
        input.setAttribute('aria-invalid', 'true');
    }

    function onValidationErrors(form, errors) {
        Object.entries(errors).forEach(function ([fieldId, message]) {
            const input = form.querySelector('[name="' + fieldId + '"]');
            if (input) showFieldError(input, message);
        });
    }

    function showGeneralError(el, message) {
        if (!el) return;
        el.textContent = message;
        el.style.display = 'block';
    }

    function clearErrors(form) {
        form.querySelectorAll('.wkel-field-error').forEach(function (el) {
            el.textContent = '';
        });
        form.querySelectorAll('[aria-invalid]').forEach(function (el) {
            el.removeAttribute('aria-invalid');
        });
        const general = form.querySelector('.wkel-general-error');
        if (general) {
            general.textContent = '';
            general.style.display = 'none';
        }
    }

    function setSubmitting(submitting, btn, label, spinner) {
        btn.disabled         = submitting;
        label.style.display  = submitting ? 'none' : '';
        spinner.style.display = submitting ? 'inline-block' : 'none';
    }

    function onSuccess(form, wrap, data) {
        // Show success message, hide form
        form.style.display = 'none';
        const successEl = wrap.querySelector('.wkel-success-message');
        if (successEl) successEl.style.display = 'block';

        const redirect = form.dataset.redirect;
        if (redirect) {
            setTimeout(function () {
                window.location.href = redirect;
            }, 1500);
        }
    }
}());
