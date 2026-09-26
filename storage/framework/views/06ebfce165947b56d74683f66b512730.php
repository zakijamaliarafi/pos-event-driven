<?php
use App\Domain\Orders\TransitionOrder;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
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
    <main class="mx-auto max-w-7xl space-y-7 px-5 py-8">
        <div><p class="text-sm font-semibold uppercase tracking-widest text-blue-700">Fulfillment</p><h1 class="mt-1 text-3xl font-bold">Ready to serve</h1></div>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($message): ?><div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-blue-900"><?php echo e($message); ?></div><?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__errorArgs = ['order'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="rounded-xl border border-red-200 bg-red-50 p-4 text-red-700"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        <div wire:poll.2s class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__empty_1 = true; $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h2 class="font-bold"><?php echo e($order->order_number); ?></h2>
                    <p class="mt-1 text-sm text-slate-500"><?php echo e($order->customer_name); ?> · <?php echo e($order->order_type === 'takeaway' ? 'Takeaway' : 'Table '.$order->table_number); ?></p>
                    <ul class="my-5 space-y-2 border-y border-slate-100 py-4 text-sm"><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::openLoop(); ?><?php endif; ?><?php $__currentLoopData = $order->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::startLoopIteration(); ?><?php endif; ?><li class="flex justify-between gap-3"><span><?php echo e($item->product?->name ?? 'Product'); ?></span><strong>× <?php echo e($item->quantity); ?></strong></li><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?></ul>
                    <button wire:click="serve(<?php echo e($order->id); ?>)" class="w-full rounded-lg bg-blue-700 px-4 py-2 font-semibold text-white hover:bg-blue-800">Mark served</button>
                </article>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::endLoop(); ?><?php endif; ?><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::closeLoop(); ?><?php endif; ?>
                <p class="text-slate-500">No orders are ready right now.</p>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </main>
</div><?php /**PATH /var/www/html/storage/framework/views/livewire/views/ab9ea4d7.blade.php ENDPATH**/ ?>