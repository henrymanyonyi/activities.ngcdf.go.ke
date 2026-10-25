<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireTwoFactor;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\SmsLeopardSmsGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Permission\Middleware\PermissionMiddleware;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // SMS Leopard for every SMS. The log driver is honoured only in tests, so a
        // stale ACTIVITIES_SMS_DRIVER=log on a server can never swallow real messages.
        $this->app->bind(SmsGateway::class, fn () => config('activities.sms.driver') === 'log' && $this->app->runningUnitTests()
            ? new LogSmsGateway
            : new SmsLeopardSmsGateway(config('activities.sms.smsleopard')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Re-apply these on every Livewire update request, not only the first render.
        Livewire::addPersistentMiddleware([EnsureUserIsActive::class, RequireTwoFactor::class, PermissionMiddleware::class]);

    }
}
