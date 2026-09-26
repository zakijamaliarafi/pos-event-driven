<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
    <head>
        <?php echo $__env->make('partials.head', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
    </head>
    <body class="min-h-screen bg-slate-50 antialiased">
        <div class="bg-background flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-2">
                <a href="<?php echo e(route('home')); ?>" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-24 w-24 mb-1 items-center justify-center rounded-md">
                        <img src="<?php echo e(asset('pos-logo.svg')); ?>" alt="POS" class="h-20 w-20">
                    </span>
                    <span class="sr-only"><?php echo e(config('app.name', 'POS')); ?></span>
                </a>
                <div class="flex flex-col gap-6">
                    <?php echo e($slot); ?>

                </div>
            </div>
        </div>
        <?php app('livewire')->forceAssetInjection(); ?>
<?php echo app('flux')->scripts(); ?>

    </body>
</html>
<?php /**PATH /var/www/html/resources/views/layouts/auth/simple.blade.php ENDPATH**/ ?>