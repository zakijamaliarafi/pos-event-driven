let cashierTrigger = null;
let cashierHeaderObserver = null;
const afterCashierRender = (callback) => requestAnimationFrame(() => requestAnimationFrame(callback));

function labelCashierDialogs() {
    document.querySelector('[data-cashier-cart] dialog')?.setAttribute('aria-labelledby', 'cashier-mobile-cart-heading');
    const confirmation = document.querySelector('[data-cashier-confirmation] dialog');
    confirmation?.setAttribute('aria-labelledby', 'cashier-confirmation-heading');
    confirmation?.setAttribute('aria-describedby', 'cashier-confirmation-description');
}

function fitCashierCart() {
    const cart = document.querySelector('[data-cashier-page] .cashier-desktop-cart');
    if (cart) {
        const top = Math.max(16, cart.getBoundingClientRect().top);
        cart.style.setProperty('--cashier-cart-height', `${Math.max(240, window.innerHeight - top - 16)}px`);
    }
    if (window.matchMedia('(min-width: 1024px)').matches) {
        document.querySelector('[data-cashier-cart] dialog[open]')?.close();
    }
}

function initializeCashier() {
    labelCashierDialogs();
    const cashierRoot = document.querySelector('[data-cashier-page]');
    cashierHeaderObserver?.disconnect();
    if (!cashierRoot) return;
    cashierHeaderObserver = new ResizeObserver(fitCashierCart);
    cashierHeaderObserver.observe(cashierRoot.querySelector('header'));
    fitCashierCart();
}

initializeCashier();
document.addEventListener('livewire:init', () => {
    initializeCashier();
    window.Livewire.hook('morphed', ({ el }) => {
        if (el.matches('[data-cashier-page]')) afterCashierRender(fitCashierCart);
    });
});
document.addEventListener('livewire:navigated', initializeCashier);
window.addEventListener('resize', fitCashierCart);
window.addEventListener('scroll', fitCashierCart, { passive: true });

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-cashier-open]');
    if (trigger) cashierTrigger = trigger;
    if (event.target.closest('[data-cashier-tabs]')) afterCashierRender(fitCashierCart);
});

document.addEventListener('cashier-checkout-invalid', () => afterCashierRender(() => {
    const checkout = [...document.querySelectorAll('[data-cashier-checkout]')].find((element) => element.getClientRects().length);
    const invalid = checkout?.querySelector('[data-cashier-invalid], [aria-invalid="true"]');
    invalid?.focus();
    invalid?.scrollIntoView({ block: 'center' });
}));

document.addEventListener('cashier-sale-submitted', () => afterCashierRender(() => {
    document.getElementById('cashier-orders-heading')?.focus();
}));

document.addEventListener('cashier-confirmation-opened', () => afterCashierRender(() => {
    labelCashierDialogs();
    document.getElementById('cashier-confirmation-heading')?.focus();
}));

document.addEventListener('cashier-confirmation-invalid', () => afterCashierRender(() => {
    document.querySelector('[data-cashier-confirmation-error]')?.focus();
}));

function restoreCashierFocus() {
    afterCashierRender(() => {
        if (document.querySelector('[data-cashier-page] dialog[open]')) return;
        const key = cashierTrigger?.dataset.cashierOpen;
        const trigger = cashierTrigger?.isConnected && cashierTrigger.getClientRects().length
            ? cashierTrigger
            : [...document.querySelectorAll('[data-cashier-open]')].find((element) => element.dataset.cashierOpen === key && element.getClientRects().length);
        (trigger ?? document.getElementById('cashier-orders-heading'))?.focus();
        cashierTrigger = null;
    });
}
document.addEventListener('cashier-dialog-closed', restoreCashierFocus);
document.addEventListener('close', (event) => {
    if (event.target.matches('[data-cashier-cart] dialog')) restoreCashierFocus();
}, true);

document.addEventListener('keydown', (event) => {
    const tab = event.target.closest('[data-cashier-tabs] [role="tab"]');
    if (tab && ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        const tabs = [...tab.parentElement.querySelectorAll('[role="tab"]')];
        const index = tabs.indexOf(tab);
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        event.preventDefault();
        if (!tabs[next].disabled) {
            tabs[next].focus();
            tabs[next].click();
        }
        return;
    }
    const dialog = document.querySelector('[data-cashier-cart] dialog[open], [data-cashier-confirmation] dialog[open]');
    if (!dialog) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopImmediatePropagation();
        if (dialog.querySelector('[data-cashier-cart-fields][disabled], [data-loading]')) return;
        const component = window.Livewire.find(dialog.closest('[wire\\:id]').getAttribute('wire:id'));
        if (dialog.closest('[data-cashier-confirmation]')) component.call('closeConfirmation');
        else component.set('showCart', false);
        return;
    }
    if (event.key !== 'Tab') return;
    const controls = [...dialog.querySelectorAll('button, input, select, a[href], [tabindex]')]
        .filter((element) => element.tabIndex >= 0 && !element.matches(':disabled') && element.getClientRects().length);
    const first = controls[0];
    const last = controls.at(-1);
    const active = document.activeElement;
    const outside = !dialog.contains(active) || active.id === dialog.getAttribute('aria-labelledby');
    if (first && (event.shiftKey ? active === first || outside : active === last || outside)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    }
}, true);
