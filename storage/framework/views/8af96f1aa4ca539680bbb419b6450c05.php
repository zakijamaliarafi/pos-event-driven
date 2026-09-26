<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
        <link rel="icon" href="/pos-logo.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <title><?php echo e($title ?? config('app.name')); ?></title>

        <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(auth()->check() && request()->routeIs('order.create', 'kitchen.view', 'waiter.view', 'dashboard.revenue')): ?>
            <?php echo app('Illuminate\Foundation\Vite')('resources/js/realtime.js'); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('customer.order')): ?>
            <?php echo app('Illuminate\Foundation\Vite')('resources/js/customer-realtime.js'); ?>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::styles(); ?>

    </head>
    <body>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(request()->routeIs('profile.edit', 'security.edit', 'appearance.edit')): ?>
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
                <main class="mx-auto max-w-5xl rounded-2xl px-5 py-8"><?php echo e($slot); ?></main>
            </div>
        <?php else: ?>
            <?php echo e($slot); ?>

        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

        <?php echo \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(); ?>

        <?php app('livewire')->forceAssetInjection(); ?>
<?php echo app('flux')->scripts(); ?>

    </body>
</html>
<?php /**PATH /var/www/html/resources/views/layouts/app.blade.php ENDPATH**/ ?>