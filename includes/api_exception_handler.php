<?php
declare(strict_types=1);

function api_exception_is_recoverable(Throwable $error): bool
{
    if (!$error instanceof PDOException) return false;
    $message = strtolower($error->getMessage());
    return str_contains($message, 'database is locked')
        || str_contains($message, 'database table is locked')
        || str_contains($message, 'deadlock')
        || str_contains($message, 'lock wait timeout');
}

function api_install_exception_handler(string $component, string $code, string $publicMessage): string
{
    $requestId = bin2hex(random_bytes(8));
    if (ob_get_level() === 0) ob_start();
    set_exception_handler(static function (Throwable $error) use ($component, $code, $publicMessage, $requestId): never {
        while (ob_get_level() > 0) ob_end_clean();
        $recoverable = api_exception_is_recoverable($error);
        error_log(sprintf('%s failure [%s] %s: %s', $component, $requestId, get_class($error), $error->getMessage()));
        if (function_exists('runtime_issue_submit') && function_exists('db')) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            if ($userId > 0) {
                try {
                    runtime_issue_submit(db(), $userId, [
                        'category' => 'server',
                        'component' => $component,
                        'error_code' => $code,
                        'message' => $error->getMessage(),
                        'severity' => 'critical',
                        'request_correlation' => $requestId,
                        'evidence' => [
                            'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                            'recoverable' => $recoverable,
                        ],
                    ]);
                } catch (Throwable) {
                    // The private server log remains authoritative if issue persistence is unavailable.
                }
            }
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Request-ID: ' . $requestId);
        echo json_encode([
            'error' => $publicMessage,
            'code' => $code,
            'request_id' => $requestId,
            'recoverable' => $recoverable,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    });
    return $requestId;
}
