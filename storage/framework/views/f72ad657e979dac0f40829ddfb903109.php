<?php if (isset($component)) { $__componentOriginal6da4ac82edc751872ca5a34642b398d0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal6da4ac82edc751872ca5a34642b398d0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'f4ac99e09542ff494432bc959d4fee61::auth.simple','data' => ['title' => $title ?? null]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('layouts::auth.simple'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($title ?? null)]); ?>
<?php \Livewire\Features\SupportCompiledWireKeys\SupportCompiledWireKeys::processComponentKey($component); ?>

    <?php echo e($slot); ?>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal6da4ac82edc751872ca5a34642b398d0)): ?>
<?php $attributes = $__attributesOriginal6da4ac82edc751872ca5a34642b398d0; ?>
<?php unset($__attributesOriginal6da4ac82edc751872ca5a34642b398d0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal6da4ac82edc751872ca5a34642b398d0)): ?>
<?php $component = $__componentOriginal6da4ac82edc751872ca5a34642b398d0; ?>
<?php unset($__componentOriginal6da4ac82edc751872ca5a34642b398d0); ?>
<?php endif; ?>
<?php /**PATH /var/www/html/resources/views/layouts/auth.blade.php ENDPATH**/ ?>