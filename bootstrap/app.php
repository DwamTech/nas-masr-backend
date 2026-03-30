<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\CanMonitorChat;
use App\Http\Middleware\DashboardAccessMiddleware;
use App\Http\Middleware\DashboardPageMiddleware;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases
        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'dashboard.access' => DashboardAccessMiddleware::class,
            'dashboard.page' => DashboardPageMiddleware::class,
            'can.monitor.chat' => CanMonitorChat::class,
            'chat.user' => \App\Http\Middleware\ResolveChatUser::class,
        ]);

        // Apply security headers to all API routes
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->withCommands([
        \App\Console\Commands\ExpirePackageAdsCommand::class,
    ])->create();
