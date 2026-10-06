let waiterTrigger = null;
let waiterQueueFocus = null;
let waiterClearFocus = false;
const afterWaiterRender = (callback) => requestAnimationFrame(() => requestAnimationFrame(callback));

function labelWaiterDialog() {
    const dialog = document.querySelector('[data-waiter-confirmation] dialog');
    dialog?.setAttribute('aria-labelledby', 'waiter-confirmation-heading');
    dialog?.setAttribute('aria-describedby', 'waiter-confirmation-description');
}

labelWaiterDialog();
document.addEventListener('livewire:navigated', () => {
    waiterTrigger = null;
    waiterQueueFocus = null;
    waiterClearFocus = false;
    labelWaiterDialog();
});
document.addEventListener('livewire:init', () => {
    labelWaiterDialog();
    window.Livewire.hook('morphed', ({ el }) => {
        if (!el.matches('[data-waiter-page]')) return;
        afterWaiterRender(() => {
            labelWaiterDialog();
            if (waiterQueueFocus) {
                const button = [...el.querySelectorAll('[data-waiter-queue]')].find(element => element.dataset.waiterQueue === waiterQueueFocus && element.getClientRects().length);
                button?.focus();
                waiterQueueFocus = null;
            }
            if (waiterClearFocus) {
                el.querySelector('input')?.focus();
                waiterClearFocus = false;
            }
            const dialog = el.querySelector('[data-waiter-confirmation] dialog[open]');
            if (dialog && (!dialog.contains(document.activeElement) || document.activeElement?.disabled)) {
                dialog.querySelector('[data-waiter-confirmation-unavailable]')?.focus();
            }
        });
    });
});
document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-waiter-page] [data-waiter-open]');
    if (trigger) waiterTrigger = { key: trigger.dataset.waiterOpen, lane: trigger.dataset.waiterLane };
    const queue = event.target.closest('[data-waiter-queue]');
    if (queue && event.detail === 0) waiterQueueFocus = queue.dataset.waiterQueue;
    if (event.target.closest('[data-waiter-clear]')) waiterClearFocus = true;
});
document.addEventListener('waiter-confirmation-opened', () => afterWaiterRender(() => {
    labelWaiterDialog();
    document.getElementById('waiter-confirmation-heading')?.focus();
}));
document.addEventListener('waiter-confirmation-invalid', () => afterWaiterRender(() => {
    document.querySelector('[data-waiter-confirmation-error]')?.focus();
}));
document.addEventListener('waiter-confirmation-closed', () => afterWaiterRender(() => {
    const root = document.querySelector('[data-waiter-page]');
    if (!root || root.querySelector('dialog[open]')) return;
    const trigger = [...root.querySelectorAll('[data-waiter-open]')].find((element) => element.dataset.waiterOpen === waiterTrigger?.key && element.getClientRects().length);
    const heading = document.getElementById(`waiter-heading-${waiterTrigger?.lane ?? 'ready'}`);
    const visibleHeading = heading?.getClientRects().length ? heading : [...root.querySelectorAll('section h2')].find((element) => element.getClientRects().length);
    (trigger ?? visibleHeading)?.focus();
    waiterTrigger = null;
}));
document.addEventListener('keydown', (event) => {
    const queueButton = event.target.closest('[data-waiter-queues] button');
    if (queueButton && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        const buttons = [...queueButton.parentElement.querySelectorAll('button')];
        const index = buttons.indexOf(queueButton);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
        event.preventDefault();
        if (!buttons[next].disabled) {
            buttons[next].focus();
            buttons[next].click();
        }
        return;
    }
    const dialog = document.querySelector('[data-waiter-confirmation] dialog[open]');
    if (!dialog) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (dialog.querySelector('[data-waiter-confirmation-form][data-loading]')) return;
        if (dialog.querySelector('button[wire\\:click="closeConfirmation"]:disabled')) return;
        window.Livewire.find(dialog.closest('[wire\\:id]').getAttribute('wire:id')).call('closeConfirmation');
        return;
    }
    if (event.key !== 'Tab') return;
    const controls = [...dialog.querySelectorAll('button, input, select, a[href], [tabindex]')]
        .filter((element) => element.tabIndex >= 0 && !element.matches(':disabled') && element.getClientRects().length);
    const first = controls[0];
    const last = controls.at(-1);
    const active = document.activeElement;
    const outside = !controls.includes(active);
    if (first && (event.shiftKey ? active === first || outside : active === last || outside)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    }
}, true);
