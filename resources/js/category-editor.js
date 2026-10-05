let categoryTrigger = null;

function labelCategoryDialogs() {
    for (const name of ['editor', 'delete']) {
        const dialog = document.querySelector(`[data-category-${name}] dialog`);
        dialog?.setAttribute('aria-labelledby', `category-${name}-heading`);
        dialog?.setAttribute('aria-describedby', `category-${name}-description`);
    }
}

function afterCategoryRender(callback) {
    requestAnimationFrame(() => requestAnimationFrame(callback));
}

labelCategoryDialogs();
document.addEventListener('livewire:navigated', labelCategoryDialogs);

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-category-open]');
    if (trigger) {
        categoryTrigger = trigger;
    }
});

document.addEventListener('category-confirmation-requested', () => {
    afterCategoryRender(() => document.querySelector('[data-category-confirmation] button')?.focus());
});

document.addEventListener('category-editing-resumed', () => {
    afterCategoryRender(() => document.querySelector('[data-category-editor] input')?.focus());
});

document.addEventListener('category-validation-failed', () => {
    afterCategoryRender(() => document.querySelector('[data-category-editor] [aria-invalid="true"]')?.focus());
});

document.addEventListener('keydown', (event) => {
    const dialog = document.querySelector('[data-category-editor] dialog[open], [data-category-delete] dialog[open]');
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
    if (dialog.querySelector('[data-loading]')) {
        return;
    }
    const component = dialog.closest('[wire\\:id]');
    const action = dialog.closest('[data-category-editor]') ? 'requestCategoryClose' : 'closeCategoryDeleteModal';
    window.Livewire.find(component.getAttribute('wire:id')).call(action);
}, true);

document.addEventListener('category-dialog-closed', () => {
    afterCategoryRender(() => {
        const triggerKey = categoryTrigger?.dataset.categoryOpen;
        const triggers = [...document.querySelectorAll('[data-category-open]')];
        const trigger = categoryTrigger?.isConnected && categoryTrigger.getClientRects().length
            ? categoryTrigger
            : triggers.find((element) => element.dataset.categoryOpen === triggerKey && element.getClientRects().length);
        (trigger ?? document.getElementById('categorys-heading'))?.focus();
        categoryTrigger = null;
    });
});
