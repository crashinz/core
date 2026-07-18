<?php

function media_signal_is_assoc_array(mixed $value): bool {
    if (!is_array($value) || $value === []) return false;
    return array_keys($value) !== range(0, count($value) - 1);
}

function media_signal_is_sdp_type(string $type): bool {
    return in_array($type, ['offer', 'answer'], true);
}

function media_signal_normalize_line_endings(string $sdp): string {
    return str_replace(["\r\n", "\r"], "\n", $sdp);
}

function media_signal_sdp_diagnostics(mixed $sdp): array {
    $isString = is_string($sdp);
    $text = $isString ? $sdp : '';
    $normalized = $isString ? media_signal_normalize_line_endings($text) : '';

    return [
        'sdp_type' => get_debug_type($sdp),
        'sdp_length' => $isString ? strlen($text) : null,
        'first_line' => $isString ? strtok($normalized, "\n") : null,
        'starts_with_v0' => $isString ? str_starts_with($normalized, 'v=0') : false,
        'has_real_newline' => $isString ? str_contains($text, "\n") : false,
        'has_literal_backslash_newline' => $isString ? (str_contains($text, '\\n') || str_contains($text, '\\r\\n')) : false,
        'looks_json_wrapped' => $isString ? str_starts_with(ltrim($text), '{') : false,
        'looks_object_string' => $isString ? trim($text) === '[object Object]' : false,
        'ends_with_newline' => $isString ? preg_match("/(?:\r\n|\n|\r)$/", $text) === 1 : false,
    ];
}

function media_signal_validate_sdp_description(string $expectedType, mixed $description): array {
    if (!media_signal_is_sdp_type($expectedType)) {
        return [
            'ok' => false,
            'error' => 'Unsupported SDP signal type.',
            'diagnostics' => ['expected_type' => $expectedType],
        ];
    }

    if (!media_signal_is_assoc_array($description)) {
        return [
            'ok' => false,
            'error' => 'SDP description must be an object.',
            'diagnostics' => ['description_type' => get_debug_type($description)],
        ];
    }

    $type = (string)($description['type'] ?? '');
    $sdp = $description['sdp'] ?? null;
    $diagnostics = media_signal_sdp_diagnostics($sdp) + [
        'description_type' => get_debug_type($description),
        'declared_type' => $type,
        'expected_type' => $expectedType,
    ];

    if ($type !== $expectedType) {
        return [
            'ok' => false,
            'error' => 'SDP description type does not match signal type.',
            'diagnostics' => $diagnostics,
        ];
    }

    if (!is_string($sdp) || $sdp === '') {
        return [
            'ok' => false,
            'error' => 'SDP description is missing a string sdp field.',
            'diagnostics' => $diagnostics,
        ];
    }

    $normalized = media_signal_normalize_line_endings($sdp);

    if (!str_starts_with($normalized, 'v=0')) {
        return [
            'ok' => false,
            'error' => 'SDP description does not begin with v=0.',
            'diagnostics' => $diagnostics,
        ];
    }

    if (trim($sdp) === '[object Object]' || str_starts_with(ltrim($sdp), '{')) {
        return [
            'ok' => false,
            'error' => 'SDP description appears to be stringified JSON or object text.',
            'diagnostics' => $diagnostics,
        ];
    }

    if (!str_contains($sdp, "\n") && (str_contains($sdp, '\\n') || str_contains($sdp, '\\r\\n'))) {
        return [
            'ok' => false,
            'error' => 'SDP description contains escaped newline text instead of real newlines.',
            'diagnostics' => $diagnostics,
        ];
    }

    return [
        'ok' => true,
        'description' => [
            'type' => $expectedType,
            'sdp' => $sdp,
        ],
        'diagnostics' => $diagnostics,
    ];
}

function media_signal_normalize_payload(string $signalType, mixed $data): array {
    if (media_signal_is_sdp_type($signalType)) {
        if (!media_signal_is_assoc_array($data)) {
            return [
                'ok' => false,
                'error' => 'SDP signal payload must be an object.',
                'diagnostics' => ['payload_type' => get_debug_type($data)],
            ];
        }

        $description = $data['description'] ?? $data;
        $validated = media_signal_validate_sdp_description($signalType, $description);

        if (!$validated['ok']) {
            $validated['diagnostics']['payload_keys'] = array_keys($data);
            $validated['diagnostics']['canonical_kind'] = $data['kind'] ?? null;
            return $validated;
        }

        $payload = [
            'kind' => $signalType,
            'description' => $validated['description'],
        ];

        foreach ([
            'negotiation_id',
            'generation',
            'peer_instance_id',
            'target_peer_instance_id',
            'media_reason',
            'webcam_operation',
        ] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $payload[$key] = (string)$data[$key];
            }
        }

        return [
            'ok' => true,
            'data' => $payload,
            'diagnostics' => $validated['diagnostics'] + [
                'payload_type' => 'object',
                'payload_keys' => array_keys($data),
            ],
        ];
    }

    if ($signalType === 'ice') {
        if (!media_signal_is_assoc_array($data)) {
            return [
                'ok' => false,
                'error' => 'ICE signal payload must be an object.',
                'diagnostics' => ['payload_type' => get_debug_type($data)],
            ];
        }

        $candidate = media_signal_is_assoc_array($data['candidate'] ?? null)
            ? $data['candidate']
            : $data;

        if (!isset($candidate['candidate']) || !is_string($candidate['candidate']) || $candidate['candidate'] === '') {
            return [
                'ok' => false,
                'error' => 'ICE signal is missing a string candidate field.',
                'diagnostics' => [
                    'payload_keys' => array_keys($data),
                    'candidate_type' => get_debug_type($candidate['candidate'] ?? null),
                ],
            ];
        }

        $payload = [
            'kind' => 'ice',
            'candidate' => [
                'candidate' => $candidate['candidate'],
                'sdpMid' => $candidate['sdpMid'] ?? null,
                'sdpMLineIndex' => $candidate['sdpMLineIndex'] ?? null,
                'usernameFragment' => $candidate['usernameFragment'] ?? null,
            ],
        ];

        foreach (['generation', 'peer_instance_id', 'target_peer_instance_id'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $payload[$key] = (string)$data[$key];
            }
        }

        return [
            'ok' => true,
            'data' => $payload,
            'diagnostics' => [
                'payload_type' => 'object',
                'payload_keys' => array_keys($data),
            ],
        ];
    }

    return [
        'ok' => true,
        'data' => $data,
        'diagnostics' => ['payload_type' => get_debug_type($data)],
    ];
}
