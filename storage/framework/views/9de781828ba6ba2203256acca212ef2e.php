<?php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
?>
# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using ___SINGLE_BACKTICK___<?php echo e($assist->sailBinaryPath()); ?> up -d___SINGLE_BACKTICK___ and stop them with ___SINGLE_BACKTICK___<?php echo e($assist->sailBinaryPath()); ?> stop___SINGLE_BACKTICK___.
- Open the application in the browser by running ___SINGLE_BACKTICK___<?php echo e($assist->sailBinaryPath()); ?> open___SINGLE_BACKTICK___.
- Always prefix PHP, Artisan, Composer, and Node commands with ___SINGLE_BACKTICK___<?php echo e($assist->sailBinaryPath()); ?>___SINGLE_BACKTICK___. Examples:
    - Run Artisan Commands: ___SINGLE_BACKTICK___<?php echo e($assist->artisanCommand('migrate')); ?>___SINGLE_BACKTICK___
    - Install Composer packages: ___SINGLE_BACKTICK___<?php echo e($assist->composerCommand('install')); ?>___SINGLE_BACKTICK___
    - Execute Node commands: ___SINGLE_BACKTICK___<?php echo e($assist->nodePackageManagerCommand('run dev')); ?>___SINGLE_BACKTICK___
    - Execute PHP scripts: ___SINGLE_BACKTICK___<?php echo e($assist->sailBinaryPath()); ?> php [script]___SINGLE_BACKTICK___
- View all available Sail commands by running ___SINGLE_BACKTICK___<?php echo e($assist->sailBinaryPath()); ?>___SINGLE_BACKTICK___ without arguments.
<?php /**PATH /var/www/html/storage/framework/views/e702fcd55762fd6683c8e98f3dc4d11d.blade.php ENDPATH**/ ?>