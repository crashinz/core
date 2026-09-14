<?php
require_once __DIR__ . '/../includes/base.php';

$pdo = db();
$sessionId = resolve_session_id($pdo, $_GET['session_id'] ?? '');
auth_participant($pdo, $sessionId, $_GET['join_token'] ?? '');

$query = trim((string)($_GET['q'] ?? ''));
if ($query === '') json_out(['results' => []]);
$query = function_exists('mb_substr') ? mb_substr($query, 0, 80, 'UTF-8') : substr($query, 0, 80);
$cursor = $_GET['cursor'] ?? '';
if (!is_string($cursor) || strlen($cursor) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $cursor)) {
    json_out(['error' => 'Invalid GIF search cursor'], 400);
}

$requestedProvider = (string)($_GET['provider'] ?? app_setting($pdo, 'gif_default_provider', 'giphy'));
$providers = [];
$giphyKey = trim(app_setting($pdo, 'gif_giphy_api_key'));
$tenorKey = trim(app_setting($pdo, 'gif_tenor_api_key'));
$klipyKey = trim(app_setting($pdo, 'gif_klipy_api_key'));
if ($requestedProvider === 'giphy' && $giphyKey !== '') $providers[] = 'giphy';
if ($requestedProvider === 'klipy' && $klipyKey !== '') $providers[] = 'klipy';
if ($requestedProvider === 'tenor' && $tenorKey !== '') $providers[] = 'tenor';
if ($cursor !== '' && !$providers) json_out(['error' => 'GIF provider changed. Please search again.'], 400);
if (!$providers) {
    if ($giphyKey !== '') $providers[] = 'giphy';
    if ($klipyKey !== '') $providers[] = 'klipy';
    if ($tenorKey !== '') $providers[] = 'tenor';
}
if (!$providers) json_out(['error' => 'GIF search is not configured'], 400);

function gif_fetch_json(string $url): ?array {
    $raw = false;
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8,
                'header' => "Accept: application/json\r\nUser-Agent: ChatSpace-CE/1.0\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
    }
    if (($raw === false || $raw === '') && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ChatSpace-CE/1.0'],
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
    }
    if ($raw === false || $raw === '') return null;
    $json = json_decode($raw, true);
    return is_array($json) ? $json : null;
}

$results = [];
$nextCursor = null;
foreach ($providers as $provider) {
    if ($provider === 'giphy') {
        if ($cursor !== '' && (!ctype_digit($cursor) || (float)$cursor > 4999)) {
            json_out(['error' => 'Invalid GIF search offset'], 400);
        }
        $offset = (int)$cursor;
        $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $giphyKey,
            'q' => $query,
            'limit' => 24,
            'offset' => $offset,
            'rating' => 'pg-13',
            'lang' => 'en',
        ]);
        $data = gif_fetch_json($url);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data']) || (int)($data['meta']['status'] ?? 200) !== 200) {
            json_out(['error' => 'GIF provider could not load this batch. Please retry.'], 502);
        }
        $count = count($data['data']);
        $nextOffset = $offset + $count;
        if ($count > 0 && $nextOffset <= 4999 && $nextOffset < (int)($data['pagination']['total_count'] ?? ($count === 24 ? $nextOffset + 1 : $nextOffset))) {
            $nextCursor = (string)$nextOffset;
        }
        foreach (($data['data'] ?? []) as $item) {
            $original = $item['images']['original']['url'] ?? '';
            $preview = $item['images']['fixed_width_small']['url'] ?? $original;
            if ($original === '') continue;
            $results[] = [
                'provider' => 'giphy',
                'id' => (string)($item['id'] ?? $original),
                'title' => $item['title'] ?? 'GIF',
                'url' => $original,
                'preview' => $preview,
            ];
        }
        break;
    }
    if ($provider === 'tenor') {
        $url = 'https://tenor.googleapis.com/v2/search?' . http_build_query([
            'key' => $tenorKey,
            'q' => $query,
            'limit' => 24,
            'media_filter' => 'gif,tinygif',
            'pos' => $cursor,
            'contentfilter' => 'medium',
        ]);
        $data = gif_fetch_json($url);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results']) || isset($data['error'])) {
            json_out(['error' => 'GIF provider could not load this batch. Please retry.'], 502);
        }
        $next = is_scalar($data['next'] ?? null) ? (string)$data['next'] : '';
        if ($next !== '' && $next !== '0' && $next !== $cursor && strlen($next) <= 2048) $nextCursor = $next;
        foreach (($data['results'] ?? []) as $item) {
            $original = $item['media_formats']['gif']['url'] ?? '';
            $preview = $item['media_formats']['tinygif']['url'] ?? $original;
            if ($original === '') continue;
            $results[] = [
                'provider' => 'tenor',
                'id' => (string)($item['id'] ?? $original),
                'title' => $item['content_description'] ?? 'GIF',
                'url' => $original,
                'preview' => $preview,
            ];
        }
        break;
    }
    if ($provider === 'klipy') {
        $url = 'https://api.klipy.com/v2/search?' . http_build_query([
            'key' => $klipyKey,
            'q' => $query,
            'limit' => 24,
            'media_filter' => 'gif,tinygif',
            'pos' => $cursor,
            'contentfilter' => 'medium',
        ]);
        $data = gif_fetch_json($url);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results']) || isset($data['error'])) {
            json_out(['error' => 'GIF provider could not load this batch. Please retry.'], 502);
        }
        $next = is_scalar($data['next'] ?? null) ? (string)$data['next'] : '';
        if ($next !== '' && $next !== '0' && $next !== $cursor && strlen($next) <= 2048) $nextCursor = $next;
        foreach (($data['results'] ?? []) as $item) {
            $original = $item['media_formats']['gif']['url'] ?? '';
            $preview = $item['media_formats']['tinygif']['url'] ?? $original;
            if ($original === '') continue;
            $results[] = [
                'provider' => 'klipy',
                'id' => (string)($item['id'] ?? $original),
                'title' => $item['content_description'] ?? 'GIF',
                'url' => $original,
                'preview' => $preview,
            ];
        }
        break;
    }
}

json_out(['results' => $results, 'provider' => $provider, 'next_cursor' => $nextCursor]);
