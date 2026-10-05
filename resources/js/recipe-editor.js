let recipeTrigger = null;

function labelRecipeDialog() {
    const dialog = document.querySelector('[data-recipe-editor] dialog');
    if (dialog) {
        dialog.setAttribute('aria-labelledby', 'recipe-editor-heading');
        dialog.setAttribute('aria-describedby', 'recipe-editor-description');
    }
}

labelRecipeDialog();
document.addEventListener('livewire:navigated', labelRecipeDialog);

function afterRecipeRender(callback) {
    requestAnimationFrame(() => requestAnimationFrame(callback));
}

document.addEventListener('recipe-confirmation-requested', () => {
    afterRecipeRender(() => document.querySelector('[data-recipe-confirmation] button')?.focus());
});

document.addEventListener('recipe-row-added', (event) => {
    afterRecipeRender(() => {
        const row = [...document.querySelectorAll('[data-recipe-row]')]
            .find((element) => element.dataset.recipeRow === event.detail.key);
        row?.querySelector('input')?.focus();
    });
});

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-recipe-open]');
    if (trigger) {
        recipeTrigger = trigger;
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' || event.repeat) {
        return;
    }

    const dialog = document.querySelector('[data-recipe-editor] dialog[open]');
    if (!dialog || dialog.querySelector('button[type="submit"][data-loading]')) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    const component = dialog.closest('[wire\\:id]');
    window.Livewire.find(component.getAttribute('wire:id')).call('requestRecipeClose');
}, true);

document.addEventListener('recipe-editor-closed', (event) => {
    afterRecipeRender(() => {
        const triggers = document.querySelectorAll(`[data-recipe-open="${Number(event.detail.productId)}"]`);
        const trigger = recipeTrigger?.isConnected && recipeTrigger.getClientRects().length
            ? recipeTrigger
            : [...triggers].find((element) => element.getClientRects().length);
        (trigger ?? document.getElementById('recipes-heading'))?.focus();
        recipeTrigger = null;
    });
});
