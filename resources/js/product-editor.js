let productTrigger = null;

function labelProductDialogs() {
    for (const name of ['editor', 'delete']) {
        const dialog = document.querySelector(`[data-product-${name}] dialog`);
        dialog?.setAttribute('aria-labelledby', `product-${name}-heading`);
        dialog?.setAttribute('aria-describedby', `product-${name}-description`);
    }
}

function afterProductRender(callback) {
    requestAnimationFrame(() => requestAnimationFrame(callback));
}

labelProductDialogs();
document.addEventListener('livewire:navigated', labelProductDialogs);

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-product-open]');
    if (trigger) {
        productTrigger = trigger;
    }
});

document.addEventListener('product-confirmation-requested', () => {
    afterProductRender(() => document.querySelector('[data-product-confirmation] button')?.focus());
});

document.addEventListener('product-editing-resumed', () => {
    afterProductRender(() => document.querySelector('[data-product-editor] input')?.focus());
});

document.addEventListener('product-validation-failed', () => {
    afterProductRender(() => document.querySelector('[data-product-editor] [aria-invalid="true"]')?.focus());
});

document.addEventListener('keydown', (event) => {
    const dialog = document.querySelector('[data-product-editor] dialog[open], [data-product-delete] dialog[open]');
    if (!dialog) {
        return;
    }
    if (event.key === 'Tab') {
        const controls = [...dialog.querySelectorAll('button, input, select, textarea, a[href], [tabindex]')]
            .filter((element) => element.tabIndex >= 0 && !element.matches(':disabled') && element.getClientRects().length);
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
    if (dialog.querySelector('[data-loading]') || dialog.querySelector('[data-product-upload-status]')?.getClientRects().length) {
        return;
    }
    const component = dialog.closest('[wire\\:id]');
    const action = dialog.closest('[data-product-editor]') ? 'requestProductClose' : 'closeProductDeleteModal';
    window.Livewire.find(component.getAttribute('wire:id')).call(action);
}, true);

document.addEventListener('product-dialog-closed', () => {
    afterProductRender(() => {
        const triggerKey = productTrigger?.dataset.productOpen;
        const triggers = [...document.querySelectorAll('[data-product-open]')];
        const trigger = productTrigger?.isConnected && productTrigger.getClientRects().length
            ? productTrigger
            : triggers.find((element) => element.dataset.productOpen === triggerKey && element.getClientRects().length);
        (trigger ?? document.getElementById('products-heading'))?.focus();
        productTrigger = null;
    });
});
