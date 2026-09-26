import './echo';

const subscribedOrders = new Set();

function subscribeToOrder(orderId) {
    const id = Number(orderId);

    if (!Number.isSafeInteger(id) || id < 1 || subscribedOrders.has(id)) {
        return;
    }

    subscribedOrders.add(id);
    window.Echo.private(`customer.order.${id}`)
        .listen('.order.changed', () => window.Livewire?.dispatch('customer-order-view-changed'));
}

function subscribeToVisibleOrders() {
    document.querySelectorAll('[data-pos-order-id]').forEach((element) => {
        subscribeToOrder(element.dataset.posOrderId);
    });
}

document.addEventListener('livewire:init', subscribeToVisibleOrders);
document.addEventListener('livewire:navigated', subscribeToVisibleOrders);
window.addEventListener('customer-order-submitted', (event) => subscribeToOrder(event.detail.orderId));
