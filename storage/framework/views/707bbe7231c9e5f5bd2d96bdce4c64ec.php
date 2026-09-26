<?php

use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $range = 'today';

    #[On('order-view-changed')]
    public function refreshMetrics(): void {}

    public function with(): array
    {
        $from = match ($this->range) {
            'week' => today()->startOfWeek(),
            'month' => today()->startOfMonth(),
            default => today(),
        };
        $to = now();
        $paid = DB::table('order_read_models')->whereNotNull('paid_at')->whereBetween('paid_at', [$from, $to]);
        $topProducts = DB::table('order_items')
            ->join('order_read_models', 'order_items.order_id', '=', 'order_read_models.order_id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_read_models.paid_at', [$from, $to])
            ->select('products.name', DB::raw('SUM(order_items.quantity) as units'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('units')->limit(5)->get();

        return [
            'revenue' => (float) (clone $paid)->sum('total_amount'),
            'orderCount' => (clone $paid)->count(),
            'readyCount' => DB::table('order_read_models')->where('status', 'ready')->count(),
            'topProducts' => $topProducts,
        ];
    }
};
?>

<div class="min-h-screen bg-slate-50 text-slate-900">
    <?php if (isset($component)) { $__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.pos-nav','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('pos-nav'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b)): ?>
<?php $attributes = $__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b; ?>
<?php unset($__attributesOriginal8b48e0c2a9b3e1472da7ff63186d471b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b)): ?>
<?php $component = $__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b; ?>
<?php unset($__componentOriginal8b48e0c2a9b3e1472da7ff63186d471b); ?>
<?php endif; ?>
    <main class="mx-auto max-w-7xl space-y-8 px-5 py-8">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div><p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Reporting</p><h1 class="mt-1 text-3xl font-bold">Sales dashboard</h1></div>
            <label class="text-sm font-medium text-slate-600">Period<select wire:model.live="range" class="ml-2 rounded-lg border border-slate-300 px-3 py-2 text-slate-900"><option value="today">Today</option><option value="week">This week</option><option value="month">This month</option></select></label>
        </div>
        <div wire:poll.10s class="grid gap-5 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm text-slate-500">Cash revenue</p><p class="mt-3 text-3xl font-bold text-blue-700"><?php echo e(config('pos.currency')); ?> <?php echo e(number_format($revenue, 0)); ?></p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm text-slate-500">Paid orders</p><p class="mt-3 text-3xl font-bold"><?php echo e(number_format($orderCount)); ?></p></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm text-slate-500">Ready to serve</p><p class="mt-3 text-3xl font-bold"><?php echo e(number_format($readyCount)); ?></p></div>
        </div>
        <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold">Top products</h2>
            <div class="mt-5 space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $topProducts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                    <div class="flex justify-between border-b border-slate-100 pb-3 text-sm"><span><?php echo e($product->name); ?></span><strong><?php echo e($product->units); ?> sold</strong></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?><p class="text-sm text-slate-500">No paid sales in this period.</p><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php /**PATH /var/www/html/resources/views/pages/dashboard/revenue.blade.php ENDPATH**/ ?>