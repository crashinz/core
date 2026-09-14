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

function api_exception_request_id(): string
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Throwable) {
        return hash('sha256', uniqid('corechat-api-', true));
    }
}

function api_exception_class_name(Throwable $error): string
{
    $class = get_class($error);
    $separator = strrpos($class, '\\');
    if ($separator !== false) $class = substr($class, $separator + 1);
    $class = preg_replace('/[^A-Za-z0-9_]/', '', $class) ?: 'Throwable';
    return substr($class, 0, 80);
}

function api_exception_category(Throwable $error): string
{
    if ($error instanceof PDOException) {
        return api_exception_is_recoverable($error) ? 'database-transient' : 'database';
    }
    if ($error instanceof TypeError) return 'type-contract';
    if ($error instanceof ValueError || $error instanceof InvalidArgumentException
        || $error instanceof DomainException || $error instanceof UnexpectedValueException) {
        return 'value-contract';
    }
    if ($error instanceof JsonException) return 'serialization';
    if ($error instanceof RuntimeException) return 'domain-runtime';
    if ($error instanceof Error) return 'engine';
    return 'unexpected';
}

function api_exception_game_hash(string $gameKey): ?string
{
    $gameKey = trim($gameKey);
    if ($gameKey === '') return null;
    return hash('sha256', "corechat-game-v1\0" . $gameKey);
}

function api_exception_game_session_hash(int $roomSessionId, string $publicId): ?string
{
    $publicId = trim($publicId);
    if ($roomSessionId <= 0 || $publicId === '') return null;
    return hash('sha256', "corechat-game-session-v1\0" . $roomSessionId . "\0" . $publicId);
}

function api_exception_safe_context(array $context): array
{
    $safe = [];
    $routes = ['api', 'game-framework'];
    $actions = [
        'accept', 'action', 'cancel', 'cleanup', 'close', 'create', 'decline', 'exit',
        'forfeit', 'join', 'leave', 'list', 'options', 'pause', 'ready', 'records',
        'request-seat', 'resolve-seat', 'resume', 'resume-game', 'session', 'settings',
        'start', 'uncaught', 'update-settings', 'unknown',
    ];
    $stages = [
        'action-dispatch', 'dispatch', 'session-envelope-validation', 'session-projection',
        'request', 'authentication', 'policy', 'offer-poll', 'signal-poll', 'mutation',
        'uncaught', 'unknown',
    ];
    $fields = [
        'session', 'session.publicId', 'session.status', 'session.settingsSha256',
        'session.stateVersion', 'session.members', 'session.members[]', 'session.state',
        'session.state._framework', 'session.state._framework.players',
        'session.state._framework.players[]',
    ];
    $expectedTypes = ['array-of-objects', 'non-empty-string', 'non-negative-integer', 'object', 'string'];
    $actualTypes = [
        'array', 'boolean', 'empty-string', 'integer', 'missing', 'negative-integer',
        'non-integer-string', 'null', 'number', 'object', 'resource', 'string', 'unknown',
    ];

    $route = (string)($context['route'] ?? 'api');
    $safe['route'] = in_array($route, $routes, true) ? $route : 'api';
    $action = (string)($context['action'] ?? 'unknown');
    $safe['action'] = in_array($action, $actions, true) ? $action : 'unknown';
    $stage = (string)($context['stage'] ?? 'unknown');
    $safe['stage'] = in_array($stage, $stages, true) ? $stage : 'unknown';

    $method = strtoupper((string)($context['requestMethod'] ?? ''));
    if (in_array($method, ['DELETE', 'GET', 'OPTIONS', 'PATCH', 'POST', 'PUT'], true)) {
        $safe['requestMethod'] = $method;
    }
    foreach (['gameIdentityHash', 'gameSessionIdentityHash'] as $hashField) {
        $hash = strtolower((string)($context[$hashField] ?? ''));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) === 1) $safe[$hashField] = $hash;
    }
    $field = (string)($context['offendingField'] ?? '');
    if (in_array($field, $fields, true)) $safe['offendingField'] = $field;
    $expectedType = (string)($context['expectedType'] ?? '');
    if (in_array($expectedType, $expectedTypes, true)) $safe['expectedType'] = $expectedType;
    $actualType = (string)($context['actualType'] ?? '');
    if (in_array($actualType, $actualTypes, true)) $safe['actualType'] = $actualType;
    return $safe;
}

function api_exception_descriptor(Throwable $error, array $context, string $requestId): array
{
    $requestId = preg_match('/^[a-f0-9]{16,64}$/', $requestId) === 1
        ? $requestId
        : api_exception_request_id();
    $class = api_exception_class_name($error);
    $category = api_exception_category($error);
    $fingerprint = hash('sha256', implode("\0", [
        'corechat-api-cause-v1',
        $class,
        $category,
        basename($error->getFile()),
        (string)$error->getLine(),
    ]));
    return [
        'requestId' => $requestId,
        'exceptionClass' => $class,
        'exceptionCategory' => $category,
        'causeFingerprint' => $fingerprint,
        'recoverable' => api_exception_is_recoverable($error),
    ] + api_exception_safe_context($context);
}

function api_exception_record(
    Throwable $error,
    string $component,
    string $code,
    string $publicMessage,
    array $context = [],
    ?string $requestId = null,
    ?PDO $pdo = null,
    ?int $reporterUserId = null
): array {
    $requestId = $requestId ?? api_exception_request_id();
    $descriptor = api_exception_descriptor($error, $context, $requestId);
    $requestId = (string)$descriptor['requestId'];
    $component = preg_replace('/[^a-z0-9._-]/i', '-', $component) ?: 'api';
    $component = substr($component, 0, 64);
    $code = preg_replace('/[^A-Z0-9_]/', '_', strtoupper($code)) ?: 'API_SERVER_ERROR';
    $code = substr($code, 0, 96);

    $logRecord = ['event' => 'api_failure', 'component' => $component, 'code' => $code] + $descriptor;
    $logLine = json_encode($logRecord, JSON_UNESCAPED_SLASHES);
    error_log(is_string($logLine) ? $logLine : '{"event":"api_failure","encoding":"failed"}');

    if ($reporterUserId === null) $reporterUserId = (int)($_SESSION['user_id'] ?? 0);
    $input = [
        'category' => 'server',
        'component' => $component,
        'error_code' => $code,
        'message' => $publicMessage,
        'severity' => 'critical',
        'request_correlation' => $requestId,
        'evidence' => $descriptor,
    ];
    // Do not re-enter an exhausted database retry just to record its failure.
    if (api_exception_is_recoverable($error)) {
        try {
            if ($reporterUserId > 0 && function_exists('runtime_issue_queue_deferred')) {
                runtime_issue_queue_deferred($reporterUserId, $input);
            }
        } catch (Throwable) {
            error_log('{"event":"api_failure_diagnostic_defer_failed","request_id":"' . $requestId . '"}');
        }
        return $descriptor;
    }
    try {
        if ($pdo === null && $reporterUserId > 0 && function_exists('db')) $pdo = db();
        if ($pdo instanceof PDO && $reporterUserId > 0 && function_exists('runtime_issue_submit')) {
            runtime_issue_submit($pdo, $reporterUserId, $input);
        }
    } catch (Throwable) {
        try {
            if ($reporterUserId > 0 && function_exists('runtime_issue_queue_deferred')) {
                runtime_issue_queue_deferred($reporterUserId, $input);
            }
        } catch (Throwable) {
            error_log('{"event":"api_failure_diagnostic_defer_failed","request_id":"' . $requestId . '"}');
        }
        error_log('{"event":"api_failure_diagnostic_persist_failed","request_id":"' . $requestId . '"}');
    }
    return $descriptor;
}

function api_exception_game_public_response(
    string $publicMessage,
    string $code,
    string $requestId,
    bool $retryable,
    ?int $retryAfterMs
): array {
    return [
        'error' => $publicMessage,
        'code' => $code,
        'retryable' => $retryable,
        'retryAfterMs' => $retryAfterMs,
        'request_id' => $requestId,
    ];
}

function api_install_exception_handler(
    string $component,
    string $code,
    string $publicMessage,
    array $context = []
): string {
    $requestId = api_exception_request_id();
    if (ob_get_level() === 0) ob_start();
    set_exception_handler(static function (Throwable $error) use ($component, $code, $publicMessage, $context, $requestId): never {
        while (ob_get_level() > 0) ob_end_clean();
        $descriptor = ['recoverable' => api_exception_is_recoverable($error)];
        try {
            $descriptor = api_exception_record(
                $error,
                $component,
                $code,
                $publicMessage,
                $context + [
                    'route' => 'api',
                    'action' => 'uncaught',
                    'stage' => 'uncaught',
                    'requestMethod' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
                ],
                $requestId
            );
        } catch (Throwable) {
            // Failure reporting must never replace the original safe response.
            error_log('{"event":"api_failure_diagnostic_record_failed","request_id":"' . $requestId . '"}');
        }
        $httpStatus = api_exception_category($error) === 'database-transient' ? 503 : 500;
        http_response_code($httpStatus);
        if ($httpStatus === 503) header('Retry-After: 1');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('X-Request-ID: ' . $requestId);
        echo json_encode([
            'error' => $publicMessage,
            'code' => $code,
            'request_id' => $requestId,
            'recoverable' => (bool)$descriptor['recoverable'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    });
    return $requestId;
}
