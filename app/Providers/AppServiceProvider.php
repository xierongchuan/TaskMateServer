<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\FileValidatorInterface;
use App\Listeners\PublishTaskEventSubscriber;
use App\Models\AutoDealership;
use App\Models\TaskResponse;
use App\Policies\DealershipPolicy;
use App\Policies\TaskResponsePolicy;
use App\Services\AmqpChannelManager;
use App\Services\FileValidation\FileValidationConfig;
use App\Services\FileValidation\FileValidator;
use App\Services\FileValidation\MimeTypeResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Регистрация FileValidationConfig как singleton
        $this->app->singleton(FileValidationConfig::class, function ($app) {
            return new FileValidationConfig($app['config']);
        });

        // Регистрация MimeTypeResolver
        $this->app->singleton(MimeTypeResolver::class, function ($app) {
            return new MimeTypeResolver($app->make(FileValidationConfig::class));
        });

        // Регистрация FileValidator и привязка к интерфейсу
        $this->app->singleton(FileValidatorInterface::class, function ($app) {
            return new FileValidator(
                $app->make(FileValidationConfig::class),
                $app->make(MimeTypeResolver::class)
            );
        });

        // Alias для конкретного класса
        $this->app->alias(FileValidatorInterface::class, FileValidator::class);

        // Регистрация AMQP-менеджера каналов как singleton
        // Одно физическое соединение на весь жизненный цикл процесса.
        // Конфигурация берётся из config/queue.php → connections.rabbitmq.hosts.0
        $this->app->singleton(AmqpChannelManager::class, function ($app) {
            $config = $app['config']->get('queue.connections.rabbitmq.hosts.0', []);

            return new AmqpChannelManager(
                host: $config['host'] ?? 'rabbitmq',
                port: (int) ($config['port'] ?? 5672),
                user: $config['user'] ?? 'guest',
                password: $config['password'] ?? 'guest',
                vhost: $config['vhost'] ?? '/',
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->registerPolicies();

        Event::subscribe(PublishTaskEventSubscriber::class);
    }

    /**
     * Register model policies that don't follow default naming convention.
     */
    protected function registerPolicies(): void
    {
        Gate::policy(AutoDealership::class, DealershipPolicy::class);
        Gate::policy(TaskResponse::class, TaskResponsePolicy::class);
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Login rate limiting - 5 attempts per minute per IP
        RateLimiter::for('login', function (Request $request) {
            if ($this->isTrustedIp($request)) {
                return Limit::none();
            }

            return Limit::perMinute(5)->by($request->ip());
        });

        // General API rate limiting - 180 requests per minute per user/IP
        RateLimiter::for('api', function (Request $request) {
            if ($this->isTrustedIp($request)) {
                return Limit::none();
            }

            return $request->user()
                ? Limit::perMinute(180)->by($request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });

        // Downloads rate limiting - higher limit for file downloads (signed URLs)
        RateLimiter::for('downloads', function (Request $request) {
            if ($this->isTrustedIp($request)) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->ip());
        });
    }

    private function isTrustedIp(Request $request): bool
    {
        $ip = $request->ip();
        $trustedIps = config('ratelimit.trusted_ips', []);

        foreach ($trustedIps as $trusted) {
            if (str_contains($trusted, '/')) {
                // CIDR-нотация (например, 172.18.0.0/16)
                if ($this->ipInCidr($ip, $trusted)) {
                    return true;
                }
            } elseif ($ip === $trusted) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
