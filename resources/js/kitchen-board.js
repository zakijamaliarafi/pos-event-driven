document.addEventListener('livewire:init', () => {
    const states = new WeakMap();
    const updateAges = (root) => root.querySelectorAll('[data-kitchen-age]').forEach((label) => {
        const minutes = Math.max(0, Math.floor((Date.now() / 1000 - Number(label.dataset.kitchenAge)) / 60));
        label.textContent = minutes < 1 ? 'Baru masuk' : `${minutes} menit lalu`;
    });

    window.Livewire.hook('component.init', ({ component, cleanup }) => {
        const root = component.el;
        if (!root.matches('[data-kitchen-board]')) return;
        const state = { scroll: {}, focus: null };
        states.set(root, state);
        const click = (event) => {
            const button = event.target.closest('[data-kitchen-action]');
            if (!button) return;
            state.focus = event.detail === 0 ? {
                id: button.closest('[data-kitchen-order]').dataset.kitchenOrder,
                destination: button.dataset.kitchenDestination,
                button,
            } : null;
        };
        root.addEventListener('click', click);
        updateAges(root);
        const timer = setInterval(() => updateAges(root), 60000);
        cleanup(() => {
            clearInterval(timer);
            root.removeEventListener('click', click);
            states.delete(root);
        });
    });

    window.Livewire.hook('morph', ({ el }) => {
        const state = states.get(el);
        if (!state) return;
        el.querySelectorAll('[data-kitchen-scroll]').forEach((list) => {
            state.scroll[list.dataset.kitchenScroll] = list.scrollTop;
        });
    });

    window.Livewire.hook('morphed', ({ el }) => {
        const state = states.get(el);
        if (!state) return;
        el.querySelectorAll('[data-kitchen-scroll]').forEach((list) => {
            list.scrollTop = state.scroll[list.dataset.kitchenScroll] ?? 0;
        });
        updateAges(el);
        if (state.focus) {
            if (!state.focus.button.isConnected && document.activeElement === document.body) {
                const { id, destination } = state.focus;
                const lane = el.querySelector(`[data-kitchen-lane="${destination}"]`);
                const target = lane?.querySelector(`[data-kitchen-order="${id}"]`) ?? lane?.querySelector('h2');
                target?.focus({ preventScroll: true });
                target?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
            state.focus = null;
        }
    });
});
