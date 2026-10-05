let discountTrigger = null;

function labelDiscountDialogs() {
    for (const name of ['editor', 'delete']) {
        const dialog = document.querySelector(`[data-discount-${name}] dialog`);
        dialog?.setAttribute('aria-labelledby', `discount-${name}-heading`);
        dialog?.setAttribute('aria-describedby', `discount-${name}-description`);
    }
}

function afterDiscountRender(callback) {
    requestAnimationFrame(() => requestAnimationFrame(callback));
}

labelDiscountDialogs();
document.addEventListener('livewire:navigated', labelDiscountDialogs);
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-discount-open]');
    if (trigger) {
        discountTrigger = trigger;
    }
});
document.addEventListener('discount-confirmation-requested', () => {
    afterDiscountRender(() => document.querySelector('[data-discount-confirmation] button')?.focus());
});
document.addEventListener('discount-editing-resumed', () => {
    afterDiscountRender(() => document.querySelector('[data-discount-editor] input')?.focus());
});
document.addEventListener('discount-validation-failed', () => {
    afterDiscountRender(() => {
        const dialog = document.querySelector('[data-discount-editor] dialog[open]');
        (dialog?.querySelector('[aria-invalid="true"]') ?? dialog?.querySelector('input'))?.focus();
    });
});
document.addEventListener('keydown', (event) => {
    const dialog = document.querySelector('[data-discount-editor] dialog[open], [data-discount-delete] dialog[open]');
    if (!dialog) {
        return;
    }
    if (event.key === 'Tab') {
        const controls = [...dialog.querySelectorAll('button, input, select, textarea, a[href], [tabindex]')]
            .filter((element) => element.tabIndex >= 0 && !element.matches(':disabled') && !element.hasAttribute('disabled') && !element.closest('fieldset[disabled]') && element.getClientRects().length);
        const first = controls[0];
        const last = controls[controls.length - 1];
        const outside = !dialog.contains(document.activeElement);
        if (first && (event.shiftKey ? document.activeElement === first || outside : document.activeElement === last || outside)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();
        }
        return;
    }
    if (event.key !== 'Escape' || event.repeat) {
        return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    if (dialog.querySelector('[data-loading]')) {
        return;
    }
    const component = dialog.closest('[wire\\:id]');
    const action = dialog.closest('[data-discount-editor]') ? 'closeDiscountModal' : 'closeDiscountDeleteModal';
    window.Livewire.find(component.getAttribute('wire:id')).call(action);
}, true);
document.addEventListener('discount-dialog-closed', () => {
    afterDiscountRender(() => {
        const triggerKey = discountTrigger?.dataset.discountOpen;
        const trigger = discountTrigger?.isConnected && discountTrigger.getClientRects().length
            ? discountTrigger
            : [...document.querySelectorAll('[data-discount-open]')].find((element) => element.dataset.discountOpen === triggerKey && element.getClientRects().length);
        (trigger ?? document.getElementById('discounts-heading'))?.focus();
        discountTrigger = null;
    });
});
