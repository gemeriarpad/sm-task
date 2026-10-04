<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Outside the web middleware group: monitor pings shouldn't start sessions.
        then: fn () => Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health'),
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The API's per-IP rate limit (see AppServiceProvider) must run before
        // authentication, so it is global: Laravel sorts route middleware and always
        // runs `auth` before any `throttle`. Its limiter ignores requests outside api/*.
        $middleware->append(ThrottleRequests::using('api-ip'));

        // API token abilities: `abilities:a,b` needs all of them, `ability:a,b` any one.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API always answers in JSON ({message, errors?}), even without an
        // `Accept: application/json` header: no HTML error pages or login redirects.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Laravel's message for a missing model names the class ("No query results
        // for model [App\Models\Target] 5"); say "Target not found." instead.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $previous = $e->getPrevious();
            $model = $previous instanceof ModelNotFoundException ? class_basename($previous->getModel()) : null;

            return response()->json(['message' => $model ? "{$model} not found." : 'Not found.'], 404);
        });
    })->create();
