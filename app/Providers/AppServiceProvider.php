<?php

namespace App\Providers;

use App\Models\PushSubscription;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Minishlink\WebPush\WebPush;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WebPush::class, fn (): WebPush => new WebPush([
            'VAPID' => [
                'subject' => config('webpush.subject'),
                'publicKey' => config('webpush.public_key'),
                'privateKey' => config('webpush.private_key'),
            ],
        ], [], new Client(['timeout' => 20, 'connect_timeout' => 5, 'allow_redirects' => false])));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(Logout::class, function (Logout $event): void {
            if (request()->hasSession() && filled(config('webpush.public_key'))) {
                PushSubscription::where('user_id', $event->user->getAuthIdentifier())
                    ->where('session_hash', hash('sha256', request()->session()->getId()))->delete();
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
