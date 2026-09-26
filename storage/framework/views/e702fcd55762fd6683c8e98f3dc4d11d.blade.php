@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using ___SINGLE_BACKTICK___{{ $assist->sailBinaryPath() }} up -d___SINGLE_BACKTICK___ and stop them with ___SINGLE_BACKTICK___{{ $assist->sailBinaryPath() }} stop___SINGLE_BACKTICK___.
- Open the application in the browser by running ___SINGLE_BACKTICK___{{ $assist->sailBinaryPath() }} open___SINGLE_BACKTICK___.
- Always prefix PHP, Artisan, Composer, and Node commands with ___SINGLE_BACKTICK___{{ $assist->sailBinaryPath() }}___SINGLE_BACKTICK___. Examples:
    - Run Artisan Commands: ___SINGLE_BACKTICK___{{ $assist->artisanCommand('migrate') }}___SINGLE_BACKTICK___
    - Install Composer packages: ___SINGLE_BACKTICK___{{ $assist->composerCommand('install') }}___SINGLE_BACKTICK___
    - Execute Node commands: ___SINGLE_BACKTICK___{{ $assist->nodePackageManagerCommand('run dev') }}___SINGLE_BACKTICK___
    - Execute PHP scripts: ___SINGLE_BACKTICK___{{ $assist->sailBinaryPath() }} php [script]___SINGLE_BACKTICK___
- View all available Sail commands by running ___SINGLE_BACKTICK___{{ $assist->sailBinaryPath() }}___SINGLE_BACKTICK___ without arguments.
