let customerTrigger = null;

const afterRender = (callback) => requestAnimationFrame(() => requestAnimationFrame(callback));

function labelCustomerDialogs() {
    document.querySelector('[data-customer-cart] dialog')?.setAttribute('aria-labelledby', 'mobile-cart-heading');
    document.querySelector('[data-customer-tracking] dialog')?.setAttribute('aria-labelledby', 'customer-tracking-heading');
}

let headerObserver = null;

function fitCustomerHeader() {
    headerObserver?.disconnect();
    const root = document.querySelector('[data-customer-order]');
    const header = root?.querySelector('header');
    if (!header) {
        return;
    }
    headerObserver = new ResizeObserver(() => {
        root.style.setProperty('--customer-header-height', header.getBoundingClientRect().height + 'px');
    });
    headerObserver.observe(header);
}

labelCustomerDialogs();
fitCustomerHeader();
document.addEventListener('livewire:init', labelCustomerDialogs);
document.addEventListener('livewire:navigated', labelCustomerDialogs);
document.addEventListener('livewire:init', fitCustomerHeader);
document.addEventListener('livewire:navigated', fitCustomerHeader);

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-customer-open]');
    if (trigger) {
        customerTrigger = trigger;
    }
});

document.addEventListener('customer-checkout-invalid', () => {
    afterRender(() => {
        const checkout = [...document.querySelectorAll('[data-customer-checkout]')]
            .find((element) => element.getClientRects().length);
        const invalid = checkout?.querySelector('[data-customer-invalid], [aria-invalid="true"]');
        invalid?.focus();
        (invalid?.closest('[data-flux-field]') ?? invalid)?.scrollIntoView({ block: 'center' });
    });
});

document.addEventListener('customer-order-submitted', () => {
    afterRender(() => {
        customerTrigger = document.querySelector('[data-customer-open="tracking"]');
        document.getElementById('customer-tracking-heading')?.focus();
    });
});

document.addEventListener('close', (event) => {
    if (!event.target.matches('[data-customer-cart] dialog, [data-customer-tracking] dialog')) {
        return;
    }
    afterRender(() => {
        if (document.querySelector('[data-customer-order] dialog[open]')) {
            return;
        }
        const type = customerTrigger?.dataset.customerOpen;
        const trigger = customerTrigger?.isConnected
            ? customerTrigger
            : document.querySelector('[data-customer-open="' + type + '"]');
        trigger?.focus();
    });
}, true);

document.addEventListener('keydown', (event) => {
    const dialog = document.querySelector('[data-customer-cart] dialog[open], [data-customer-tracking] dialog[open]');
    if (!dialog) {
        return;
    }
    if (event.key === 'Escape' && dialog.querySelector('[data-customer-cart-fields][disabled]')) {
        event.preventDefault();
        event.stopImmediatePropagation();
        return;
    }
    if (event.key !== 'Tab') {
        return;
    }
    const controls = [...dialog.querySelectorAll('button, input, a[href], summary, [tabindex]')]
        .filter((element) => element.tabIndex >= 0 && !element.matches(':disabled') && element.getClientRects().length);
    const first = controls[0];
    const last = controls.at(-1);
    const active = document.activeElement;
    const outside = !dialog.contains(active);
    const heading = active.id === dialog.getAttribute('aria-labelledby');
    if (first && (event.shiftKey ? active === first || outside || heading : active === last || outside || heading)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
    }
}, true);
