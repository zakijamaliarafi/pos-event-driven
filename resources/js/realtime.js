import './echo';

window.Echo.private('staff.orders')
    .listen('.order.changed', () => window.Livewire?.dispatch('order-view-changed'));
