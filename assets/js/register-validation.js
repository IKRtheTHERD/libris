(() => {
    const form = document.querySelector('[data-register-form]');
    if (!form) return;

    const summary = document.querySelector('[data-register-error-summary]');
    const fields = [...form.querySelectorAll('input:not([type="hidden"])')];
    const password = form.querySelector('[data-password-field]');
    const confirmation = form.querySelector('[data-password-confirmation]');
    const messages = {
        required: form.dataset.requiredMessage || '',
        email: form.dataset.emailMessage || '',
        password: form.dataset.passwordMessage || '',
        mismatch: form.dataset.passwordMismatchMessage || '',
    };

    const errorNode = (field) => {
        const id = `${field.name.replaceAll('-', '_')}-error`;
        let node = document.getElementById(id);
        if (!node) {
            node = document.createElement('p');
            node.id = id;
            node.className = 'form-error';
            node.dataset.fieldError = field.name;
            node.setAttribute('aria-live', 'polite');
            field.closest('.form-field')?.append(node);
        }
        return node;
    };

    const validationMessage = (field) => {
        if (field.validity.valueMissing) return messages.required;
        if (field === confirmation && password.value !== confirmation.value) return messages.mismatch;
        if (field.type === 'email' && field.validity.typeMismatch) return messages.email;
        if (field.validity.tooShort || field.validity.tooLong) return messages.password;
        return '';
    };

    const updateField = (field) => {
        field.setCustomValidity('');
        const message = validationMessage(field);
        field.setCustomValidity(message);
        const error = errorNode(field);
        error.textContent = message;
        error.hidden = message === '';
        if (message) {
            field.setAttribute('aria-invalid', 'true');
            const descriptions = [field.dataset.hintId, error.id].filter(Boolean);
            field.setAttribute('aria-describedby', descriptions.join(' '));
        } else {
            field.removeAttribute('aria-invalid');
            if (field.dataset.hintId) {
                field.setAttribute('aria-describedby', field.dataset.hintId);
            } else {
                field.removeAttribute('aria-describedby');
            }
        }
        return message;
    };

    const updateSummary = () => {
        if (!summary || summary.dataset.dismissible !== 'true') return;
        const visibleErrors = [...form.querySelectorAll('[data-field-error]')]
            .filter((node) => !node.hidden);
        summary.textContent = visibleErrors[0]?.textContent || '';
        summary.hidden = visibleErrors.length === 0;
    };

    fields.forEach((field) => {
        field.addEventListener('input', () => {
            updateField(field);
            if (field === password && confirmation.value !== '') updateField(confirmation);
            updateSummary();
        });
        field.addEventListener('blur', () => {
            updateField(field);
            updateSummary();
        });
        field.addEventListener('invalid', (event) => {
            event.preventDefault();
            const message = updateField(field);
            if (summary && !summary.textContent) {
                summary.textContent = message;
                summary.hidden = false;
            }
            if (!form.dataset.validationFocused) {
                form.dataset.validationFocused = 'true';
                field.focus();
            }
        }, true);
    });

    form.addEventListener('submit', () => {
        form.dataset.validationFocused = '';
        fields.forEach(updateField);
    });

    if (summary) summary.focus();
})();
