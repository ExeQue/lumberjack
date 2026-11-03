<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

/**
 * Stack traces as the SDK sends them: the app's frames on top, then the framework's
 * for the kind of work the error happened in.
 */
final class ShowcaseStack
{
    private const array HTTP = [
        ['vendor/laravel/framework/src/Illuminate/Routing/ControllerDispatcher.php', 46, 'dispatch', 'Illuminate\\Routing\\ControllerDispatcher'],
        ['vendor/laravel/framework/src/Illuminate/Routing/Route.php', 276, 'runController', 'Illuminate\\Routing\\Route'],
        ['vendor/laravel/framework/src/Illuminate/Routing/Route.php', 216, 'run', 'Illuminate\\Routing\\Route'],
        ['vendor/laravel/framework/src/Illuminate/Routing/Router.php', 835, '{closure:Illuminate\\Routing\\Router::runRouteWithinStack():834}', 'Illuminate\\Routing\\Router'],
        ['vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 180, '{closure:Illuminate\\Pipeline\\Pipeline::prepareDestination():178}', 'Illuminate\\Pipeline\\Pipeline'],
        ['vendor/laravel/framework/src/Illuminate/Routing/Middleware/SubstituteBindings.php', 52, 'handle', 'Illuminate\\Routing\\Middleware\\SubstituteBindings'],
        ['vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 219, '{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}', 'Illuminate\\Pipeline\\Pipeline'],
        ['vendor/laravel/framework/src/Illuminate/Session/Middleware/StartSession.php', 120, 'handleStatefulRequest', 'Illuminate\\Session\\Middleware\\StartSession'],
        ['vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 137, 'then', 'Illuminate\\Pipeline\\Pipeline'],
        ['vendor/laravel/framework/src/Illuminate/Routing/Router.php', 813, 'runRoute', 'Illuminate\\Routing\\Router'],
        ['vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php', 200, '{closure:Illuminate\\Foundation\\Http\\Kernel::dispatchToRouter():197}', 'Illuminate\\Foundation\\Http\\Kernel'],
        ['vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php', 175, 'sendRequestThroughRouter', 'Illuminate\\Foundation\\Http\\Kernel'],
        ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php', 1228, 'handleRequest', 'Illuminate\\Foundation\\Application'],
        ['public/index.php', 20, null, null],
    ];

    private const array QUEUE = [
        ['vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php', 36, '{closure:Illuminate\\Container\\BoundMethod::call():35}', 'Illuminate\\Container\\BoundMethod'],
        ['vendor/laravel/framework/src/Illuminate/Container/Container.php', 799, 'call', 'Illuminate\\Container\\Container'],
        ['vendor/laravel/framework/src/Illuminate/Bus/Dispatcher.php', 129, '{closure:Illuminate\\Bus\\Dispatcher::dispatchNow():126}', 'Illuminate\\Bus\\Dispatcher'],
        ['vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php', 137, 'then', 'Illuminate\\Pipeline\\Pipeline'],
        ['vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php', 134, 'dispatchThroughMiddleware', 'Illuminate\\Queue\\CallQueuedHandler'],
        ['vendor/laravel/framework/src/Illuminate/Queue/CallQueuedHandler.php', 70, 'call', 'Illuminate\\Queue\\CallQueuedHandler'],
        ['vendor/laravel/framework/src/Illuminate/Queue/Jobs/Job.php', 102, 'fire', 'Illuminate\\Queue\\Jobs\\Job'],
        ['vendor/laravel/framework/src/Illuminate/Queue/Worker.php', 487, 'process', 'Illuminate\\Queue\\Worker'],
        ['vendor/laravel/framework/src/Illuminate/Queue/Worker.php', 213, 'daemon', 'Illuminate\\Queue\\Worker'],
        ['vendor/laravel/framework/src/Illuminate/Queue/Console/WorkCommand.php', 148, 'runWorker', 'Illuminate\\Queue\\Console\\WorkCommand'],
        ['artisan', 16, null, null],
    ];

    /**
     * @param  list<array<int, mixed>>  $frames  the app's: file, line, function, class, and optionally the code around the line
     * @return list<array<string, mixed>>
     */
    public static function trace(array $frames, string $kind): array
    {
        $browser = $kind === 'browser';
        $app = array_map(fn (array $frame): array => self::frame($frame, true, $browser), $frames);
        $framework = match ($kind) {
            'http' => self::HTTP,
            'queue' => self::QUEUE,
            default => [],
        };

        return [...$app, ...array_map(fn (array $frame): array => self::frame($frame, false, false), $framework)];
    }

    /**
     * @param  array<int, mixed>  $frame
     * @return array<string, mixed>
     */
    private static function frame(array $frame, bool $inApp, bool $browser): array
    {
        $entry = array_filter([
            'file' => $frame[0],
            'line' => $frame[1],
            'column' => $browser ? 1_000 + 37 * $frame[1] : null,
            'function' => $frame[2],
            'class' => $frame[3],
        ], fn ($value): bool => $value !== null);
        $entry['in_app'] = $inApp && ! str_starts_with($frame[0], 'vendor/');

        if (isset($frame[4])) {
            // The line itself is the third of the five shown.
            $entry['code'] = ['start' => $frame[1] - 2, 'lines' => $frame[4]];
        }

        return $entry;
    }
}
