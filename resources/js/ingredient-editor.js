let ingredientTrigger = null;

function labelIngredientDialogs() {
    for (const name of ['editor', 'delete']) {
        const dialog = document.querySelector(`[data-ingredient-${name}] dialog`);
        dialog?.setAttribute('aria-labelledby', `ingredient-${name}-heading`);
        dialog?.setAttribute('aria-describedby', `ingredient-${name}-description`);
    }
}

function afterIngredientRender(callback) {
    requestAnimationFrame(() => requestAnimationFrame(callback));
}

labelIngredientDialogs();
document.addEventListener('livewire:navigated', labelIngredientDialogs);

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-ingredient-open]');
    if (trigger) {
        ingredientTrigger = trigger;
    }
});

document.addEventListener('ingredient-confirmation-requested', () => {
    afterIngredientRender(() => document.querySelector('[data-ingredient-confirmation] button')?.focus());
});

document.addEventListener('ingredient-editing-resumed', () => {
    afterIngredientRender(() => document.querySelector('[data-ingredient-editor] input')?.focus());
});

document.addEventListener('ingredient-validation-failed', () => {
    afterIngredientRender(() => document.querySelector('[data-ingredient-editor] [aria-invalid="true"]')?.focus());
});

document.addEventListener('keydown', (event) => {
    const dialog = document.querySelector('[data-ingredient-editor] dialog[open], [data-ingredient-delete] dialog[open]');
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
    const action = dialog.closest('[data-ingredient-editor]') ? 'requestIngredientClose' : 'closeIngredientDeleteModal';
    window.Livewire.find(component.getAttribute('wire:id')).call(action);
}, true);

document.addEventListener('ingredient-dialog-closed', () => {
    afterIngredientRender(() => {
        const triggerKey = ingredientTrigger?.dataset.ingredientOpen;
        const triggers = [...document.querySelectorAll('[data-ingredient-open]')];
        const trigger = ingredientTrigger?.isConnected && ingredientTrigger.getClientRects().length
            ? ingredientTrigger
            : triggers.find((element) => element.dataset.ingredientOpen === triggerKey && element.getClientRects().length);
        (trigger ?? document.getElementById('ingredients-heading'))?.focus();
        ingredientTrigger = null;
    });
});
