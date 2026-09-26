<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-5 px-5 py-4">
        <a href="<?php echo e(route('home')); ?>" class="flex items-center gap-3 text-lg font-bold text-slate-900">
            <img src="<?php echo e(asset('pos-logo.svg')); ?>" class="h-9 w-9" alt="POS">
            POS
        </a>
        <nav class="flex flex-1 flex-wrap gap-4 text-sm font-medium text-slate-600">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->hasRole('cashier')): ?> <a class="hover:text-blue-700" href="<?php echo e(route('order.create')); ?>">Cashier</a> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->hasRole('manager')): ?>
                    <a class="hover:text-blue-700" href="<?php echo e(route('menu.menu')); ?>">Catalog</a>
                    <a class="hover:text-blue-700" href="<?php echo e(route('menu.inventory')); ?>">Inventory</a>
                    <a class="hover:text-blue-700" href="<?php echo e(route('menu.recipes')); ?>">Recipes</a>
                    <a class="hover:text-blue-700" href="<?php echo e(route('dashboard.revenue')); ?>">Dashboard</a>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->hasRole('kitchen')): ?> <a class="hover:text-blue-700" href="<?php echo e(route('kitchen.view')); ?>">Kitchen</a> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->hasRole('waiter')): ?> <a class="hover:text-blue-700" href="<?php echo e(route('waiter.view')); ?>">Waiter</a> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->user()->hasRole('owner')): ?> <a class="hover:text-blue-700" href="<?php echo e(route('dashboard.revenue')); ?>">Dashboard</a> <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <a class="hover:text-blue-700" href="<?php echo e(route('customer.order')); ?>">Customer order</a>
        </nav>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->guard()->check()): ?>
            <details class="relative text-sm text-slate-700">
                <summary class="cursor-pointer rounded-lg border border-slate-300 px-3 py-2">Alerts (<?php echo e(auth()->user()->unreadNotifications()->count()); ?>)</summary>
                <div class="absolute right-0 z-20 mt-2 w-72 space-y-2 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = auth()->user()->unreadNotifications()->latest()->limit(5)->get(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $alert): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                        <p class="border-b border-slate-100 pb-2 text-xs"><strong class="block text-blue-700"><?php echo e($alert->data['title']); ?></strong><?php echo e($alert->data['message']); ?></p>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                        <p class="text-xs text-slate-500">No new alerts.</p>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </details>
            <form method="POST" action="<?php echo e(route('logout')); ?>">
                <?php echo csrf_field(); ?>
                <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Sign out</button>
            </form>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
</header>
<?php /**PATH /var/www/html/resources/views/components/pos-nav.blade.php ENDPATH**/ ?>