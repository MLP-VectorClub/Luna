<?php

namespace App\Exceptions;

use App\Http\Controllers\ConflictException;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $e
     * @return void
     */
    public function report(\Throwable $e)
    {
        // A number the database cannot hold in a path is a visitor's typo, not an error of ours
        if ($e instanceof QueryException && self::isMalformedValue($e) && request()->isMethodSafe()) {
            return;
        }

        parent::report($e);
    }

    /**
     * The database refused a value it was handed: text where it wanted a number (SQLSTATE 22P02), a number it cannot hold (22003). For a read the value can
     * only come out of the address, so the thing it names does not exist
     */
    private static function isMalformedValue(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? $e->getCode()), ['22P02', '22003'], true);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, \Throwable $exception)
    {
        // The API contract wants every failure as `{message}` with the proper status code
        if ($exception instanceof NotFoundHttpException || $exception instanceof ModelNotFoundException) {
            // abort(404, 'reason') keeps its reason, a plain missing route or model does not say what was looked for
            $reason = $exception instanceof NotFoundHttpException && !str_starts_with($exception->getMessage(), 'The route ') ? $exception->getMessage() : '';

            return response()->json(['message' => $reason !== '' ? $reason : 'Not found'], 404);
        }

        if ($exception instanceof QueryException && self::isMalformedValue($exception) && $request->isMethodSafe()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($exception instanceof ConflictException) {
            return response()->json(['message' => $exception->getMessage()] + $exception->extra, 409);
        }

        if ($exception instanceof AuthorizationException) {
            return response()->json(['message' => $exception->getMessage() ?: 'You do not have permission to do this'], 403);
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 403) {
            return response()->json(['message' => $exception->getMessage() ?: 'You do not have permission to do this'], 403);
        }

        return parent::render($request, $exception);
    }
}
