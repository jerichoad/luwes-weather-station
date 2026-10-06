<?php

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\AssignRequestId;
use App\Support\ApiException;
use App\Support\ApiResponse;
use App\Support\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            Route::middleware('api')->get('/healthz', HealthController::class);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'healthz') || $request->expectsJson());

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*', 'healthz')) {
                return null;
            }

            return match (true) {
                $e instanceof ApiException => ApiResponse::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers),
                $e instanceof ValidationException => ApiResponse::error(422, ErrorCode::VALIDATION_FAILED, 'Payload tidak valid.', ErrorCode::detailsFromValidator($e->validator)),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(404, ErrorCode::NOT_FOUND, 'Resource tidak ditemukan.'),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error(405, ErrorCode::METHOD_NOT_ALLOWED, 'Method tidak diizinkan.'),
                $e instanceof AuthenticationException => ApiResponse::error(401, 'UNAUTHENTICATED', 'Autentikasi diperlukan.'),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => ApiResponse::error(403, ErrorCode::FORBIDDEN, 'Akses ditolak.'),
                $e instanceof ThrottleRequestsException => ApiResponse::error(
                    429, ErrorCode::RATE_LIMITED,
                    'Terlalu banyak request, coba lagi dalam '.($e->getHeaders()['Retry-After'] ?? 60).' detik.',
                    [], $e->getHeaders(),
                ),
                $e instanceof PostTooLargeException => ApiResponse::error(413, ErrorCode::PAYLOAD_TOO_LARGE, 'Ukuran payload melebihi batas.'),
                $e instanceof QueryException && str_starts_with((string) ($e->errorInfo[0] ?? ''), '08'),
                $e instanceof PDOException,
                $e instanceof RedisException => (function () use ($e) {
                    Log::error('Service unavailable', ['exception' => $e->getMessage()]);

                    return ApiResponse::error(503, ErrorCode::SERVICE_UNAVAILABLE, 'Layanan sementara tidak tersedia.', [], ['Retry-After' => '30']);
                })(),
                $e instanceof HttpExceptionInterface => ApiResponse::error($e->getStatusCode(), 'HTTP_'.$e->getStatusCode(), $e->getMessage() ?: 'Error.'),
                default => (function () use ($e) {
                    Log::error('Unhandled exception', ['exception' => $e]);

                    return ApiResponse::error(500, ErrorCode::INTERNAL_ERROR, 'Terjadi kesalahan internal.');
                })(),
            };
        });
    })->create();
