<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoStore;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active' => EnsureUserIsActive::class,
            'two_factor' => RequireTwoFactor::class,
        ]);
        $middleware->web(append: [NoStore::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('activities:daily')->dailyAt('06:00')->withoutOverlapping();
        $schedule->command('activities:weekly-summary')->weeklyOn(1, '06:30');
        $schedule->command('backup:clean')->dailyAt('01:00');
        $schedule->command('backup:run')->dailyAt('01:30');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
