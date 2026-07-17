<?php
declare(strict_types=1);

require_once __DIR__ . '/base.php';

function room_import_safe_url(string $url): string {
    if (trim($url) === '') throw new RuntimeException('URL required.');
    return (string)security_remote_target($url)['url'];
}

function room_import_fetch_url(string $url, int $maxBytes, string $accept, int $redirects = 3, string $referer = '', bool $allowHostFallback = true): array {
    $url = room_import_safe_url($url);
    try {
        return security_fetch_remote_url($url, $maxBytes, $accept, [
            'redirects' => $redirects,
            'referer' => $referer,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);
    } catch (Throwable $error) {
        if ($allowHostFallback) {
            $fallback = room_import_www_fallback_url($url);
            if ($fallback !== null) {
                return room_import_fetch_url($fallback, $maxBytes, $accept, $redirects, $referer, false);
            }
        }
        throw $error;
    }
}

function room_import_fetch_headers(string $accept, string $referer = ''): array {
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        'Accept: ' . $accept,
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: no-cache',
    ];
    if ($referer !== '') $headers[] = 'Referer: ' . $referer;
    return $headers;
}

function room_import_fetch_url_curl(string $url, int $maxBytes, array $headers): array {
    $accept = '*/*';
    $referer = '';
    foreach ($headers as $header) {
        if (stripos((string)$header, 'Accept:') === 0) $accept = trim(substr((string)$header, 7));
        if (stripos((string)$header, 'Referer:') === 0) $referer = trim(substr((string)$header, 8));
    }
    return security_fetch_remote_url($url, $maxBytes, $accept, ['redirects' => 0, 'referer' => $referer]);
}

function room_import_www_fallback_url(string $url): ?string {
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
    $host = (string)$parts['host'];
    $fallbackHost = str_starts_with(strtolower($host), 'www.') ? substr($host, 4) : 'www.' . $host;
    if ($fallbackHost === $host || !preview_host_is_safe($fallbackHost)) return null;
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    return (string)$parts['scheme'] . '://' . $fallbackHost . $port . $path . $query . $fragment;
}

function room_import_style_value(string $style, string $property): string {
    if (preg_match('~(?:^|;)\s*' . preg_quote($property, '~') . '\s*:\s*([^;]+)~i', $style, $m)) {
        return trim((string)$m[1], " \t\r\n\"'");
    }
    return '';
}

function room_import_css_color(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('~^#[0-9a-f]{3,8}$~i', $value)) return $value;
    if (preg_match('~^(?:rgb|rgba|hsl|hsla)\([0-9.%\s,+-]+\)$~i', $value)) return $value;
    if (preg_match('~^[a-z]{3,24}$~i', $value)) return strtolower($value);
    return '';
}

function room_import_css_size(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    return preg_match('~^[0-9.]+(?:px|pt|em|rem|%)$~i', $value) ? $value : '';
}

function room_import_style_from_node(DOMNode $node, array $parent = []): array {
    $style = $parent;
    if (!$node instanceof DOMElement) return $style;
    $inline = (string)$node->getAttribute('style');
    $color = room_import_css_color(room_import_style_value($inline, 'color') ?: (string)$node->getAttribute('color') ?: (string)$node->getAttribute('text'));
    if ($color !== '') $style['color'] = $color;
    $fontSize = room_import_style_value($inline, 'font-size');
    $fontSize = room_import_css_size($fontSize);
    if ($fontSize !== '') $style['font_size'] = $fontSize;
    $align = strtolower(room_import_style_value($inline, 'text-align') ?: (string)$node->getAttribute('align'));
    if (in_array($align, ['left', 'center', 'right'], true)) $style['text_align'] = $align;
    return $style;
}

function room_import_background_image(string $style): string {
    if (preg_match('~background(?:-image)?\s*:\s*[^;]*url\((["\']?)(.*?)\1\)~i', $style, $m)) {
        return trim((string)$m[2]);
    }
    return '';
}

function room_import_candidate_media_url(string $value, string $baseUrl): string {
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($value === '' || str_starts_with(strtolower($value), 'javascript:')) return '';
    $absolute = absolutize_preview_url($value, $baseUrl);
    $absolute = preg_replace_callback('~[^:/?#&=%]+~u', fn(array $m): string => str_replace('%2F', '/', rawurlencode($m[0])), $absolute) ?? $absolute;
    if (!preg_match('#^https?://#i', $absolute)) return '';
    $parts = parse_url($absolute);
    $baseParts = parse_url($baseUrl);
    $assetHost = strtolower((string)($parts['host'] ?? ''));
    $baseHost = strtolower((string)($baseParts['host'] ?? ''));
    if (!$parts || $assetHost === '') return '';
    if ($assetHost !== $baseHost && !room_import_allowed_external_link_host($assetHost) && !preview_host_is_safe($assetHost)) return '';
    return $absolute;
}

function room_import_allowed_external_link_host(string $host): bool {
    foreach (['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'spotify.com', 'soundcloud.com'] as $domain) {
        if (host_matches_domain($host, $domain)) return true;
    }
    return false;
}

function room_import_media_label(string $url): string {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    $base = rawurldecode(basename($path));
    $base = preg_replace('~\.[a-z0-9]{2,5}$~i', '', $base) ?: 'Audio';
    $base = trim(str_replace(['_', '-'], ' ', $base));
    return $base !== '' ? ucwords($base) : 'Audio';
}

function room_import_has_audio_extension(string $url): bool {
    return (bool)preg_match('~\.(?:mp3|m4a|aac|ogg|oga|opus|wav|mid|midi|m3u|m3u8|pls|asx|wax|wma)(?:[?#].*)?$~i', $url);
}

function room_import_has_image_extension(string $url): bool {
    return (bool)preg_match('~\.(?:jpe?g|png|webp|gif|bmp)(?:[?#].*)?$~i', $url);
}

function room_import_audio_hint(DOMElement $node, string $attr = ''): bool {
    $tag = strtolower($node->tagName);
    if (in_array($tag, ['audio', 'source', 'embed', 'object', 'bgsound'], true)) return true;
    if ($tag === 'param') {
        $name = strtolower((string)$node->getAttribute('name'));
        return in_array($name, ['filename', 'url', 'src', 'movie', 'autostart', 'uimode'], true);
    }
    $type = strtolower((string)$node->getAttribute('type'));
    $classid = strtolower((string)$node->getAttribute('classid'));
    return str_contains($type, 'audio')
        || str_contains($type, 'mplayer')
        || str_contains($type, 'mediaplayer')
        || str_contains($classid, '6bf52a52')
        || in_array(strtolower($attr), ['dynsrc', 'lowsrc'], true);
}

function room_import_is_youtube_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['host'])) return false;
    $host = strtolower((string)$parts['host']);
    return host_matches_domain($host, 'youtube.com') || host_matches_domain($host, 'youtu.be') || host_matches_domain($host, 'youtube-nocookie.com');
}

function room_import_youtube_embed(string $url): string {
    $parts = parse_url($url);
    return $parts ? (youtube_embed_url($parts) ?: '') : '';
}

function room_import_clean_manifest_value(string $value): string {
    $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $value = trim($value, " \t\r\n\"'");
    if (preg_match('~\[[^\]]+\]\((https?://[^)]+)\)~i', $value, $m)) {
        return trim($m[1]);
    }
    return trim($value);
}

function room_import_css_asset_manifest(string $html, string $sourceUrl): array {
   $manifest = [
    'images' => [],
    'background_image' => '',
    'text_color' => '',
    'text_size' => '',
    'desktop_image_width' => '',
    'desktop_image_max_width' => '',
    'main_image_width' => '',
    'mobile_image_width' => '',
    'audio_player_bg' => '',
    'audio_player_text_buttons' => '',
    'music' => []
];
    if (!preg_match_all('~--([A-Za-z0-9_-]+)\s*:\s*(?:"([^"]*)"|\'([^\']*)\'|([^;]+))\s*;~', $html, $matches, PREG_SET_ORDER)) {
        return $manifest;
    }
    foreach ($matches as $match) {
        $key = strtolower((string)$match[1]);
        $rawValue = (string)($match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : $match[4]));
        $value = room_import_clean_manifest_value($rawValue);
        if ($value === '') continue;
    if ($key === 'main-image') {
    $url = room_import_candidate_media_url($value, $sourceUrl);
    if ($url !== '') {
        $manifest['images'][] = [
            'src'  => $url,
            'role' => 'header'
        ];
    }
}

elseif ($key === 'main-image-2') {
    $url = room_import_candidate_media_url($value, $sourceUrl);
    if ($url !== '') {
        $manifest['images'][] = [
            'src'  => $url,
            'role' => 'header'
        ];
    }
}

elseif ($key === 'poem-image') {
    $url = room_import_candidate_media_url($value, $sourceUrl);
    if ($url !== '') {
        $manifest['images'][] = [
            'src'  => $url,
            'role' => 'poem'
        ];
    }
}

elseif (str_starts_with($key, 'avatar-image')) {
    $url = room_import_candidate_media_url($value, $sourceUrl);

    $role = $key === 'avatar-image-1'
        ? 'avatar-left'
        : ($key === 'avatar-image-2'
            ? 'avatar-right'
            : 'avatar-piece');

    if ($url !== '') {
        $manifest['images'][] = [
            'src'  => $url,
            'role' => $role
        ];
    }
}
elseif ($key === 'background-image') {
    $url = room_import_candidate_media_url($value, $sourceUrl);

    if ($url !== '') {
        $manifest['background_image'] = $url;
    }
}

elseif ($key === 'website-font-color') {
    $color = room_import_css_color($value);

    if ($color !== '') {
        $manifest['text_color'] = $color;
    }
}
elseif ($key === 'text-size') {
    $size = room_import_css_size($value);
    if ($size !== '') $manifest['text_size'] = $size;
}
elseif ($key === 'desktop-image-width') {
    $size = room_import_css_size($value);
    if ($size !== '') $manifest['desktop_image_width'] = $size;
}
elseif ($key === 'desktop-image-max-width') {
    $size = room_import_css_size($value);
    if ($size !== '') $manifest['desktop_image_max_width'] = $size;
}
elseif ($key === 'main-image-width') {
    $size = room_import_css_size($value);
    if ($size !== '') $manifest['main_image_width'] = $size;
}
elseif ($key === 'mobile-image-width') {
    $size = room_import_css_size($value);
    if ($size !== '') $manifest['mobile_image_width'] = $size;
}
elseif ($key === 'audio-player-bg') {
    $color = room_import_css_color($value);
    if ($color !== '') $manifest['audio_player_bg'] = $color;
}
elseif ($key === 'audio-player-text-buttons') {
    $color = room_import_css_color($value);
    if ($color !== '') $manifest['audio_player_text_buttons'] = $color;
}
elseif (in_array($key, ['youtube-link', 'music-link', 'song-url', 'audio-link', 'stream-link'], true)) {
    $url = room_import_candidate_media_url($value, $sourceUrl);
    if ($url !== '') $manifest['music'][] = [
        'label' => room_import_is_youtube_url($url) ? 'YouTube Music' : room_import_media_label($url),
        'url' => $url
    ];
}
    }
    return $manifest;
}

function room_import_parse(string $html, string $sourceUrl): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $encoded = '<?xml encoding="UTF-8">' . $html;
    $dom->loadHTML($encoded, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $title = trim((string)($xpath->evaluate('string(//title)') ?: ''));
    $body = $dom->getElementsByTagName('body')->item(0);
    $bodyStyle = $body instanceof DOMElement ? (string)$body->getAttribute('style') : '';
    $bodyBg = $body instanceof DOMElement ? (string)$body->getAttribute('background') : '';
    $cssManifest = room_import_css_asset_manifest($html, $sourceUrl);
    $backgroundImage = $bodyBg !== '' ? room_import_candidate_media_url($bodyBg, $sourceUrl) : room_import_candidate_media_url(room_import_background_image($bodyStyle), $sourceUrl);
    if ($backgroundImage === '' && !empty($cssManifest['background_image'])) {
        $backgroundImage = (string)$cssManifest['background_image'];
    }
    $backgroundColor = '#000000';
    if ($body instanceof DOMElement) {
        $bgColor = room_import_css_color((string)$body->getAttribute('bgcolor') ?: room_import_style_value($bodyStyle, 'background-color'));
        if ($bgColor !== '') $backgroundColor = $bgColor;
    }
    $textColor = room_import_css_color((string)($cssManifest['text_color'] ?? ''));
	$textSize = room_import_css_size((string)($cssManifest['text_size'] ?? ''));
    $mainImageWidthSource = (string)($cssManifest['desktop_image_width'] ?? '');
    if ($mainImageWidthSource === '') $mainImageWidthSource = (string)($cssManifest['main_image_width'] ?? '');
    if ($mainImageWidthSource === '') $mainImageWidthSource = '45%';
	$mainImageWidth = room_import_css_size($mainImageWidthSource);
    $mainImageMaxWidthSource = (string)($cssManifest['desktop_image_max_width'] ?? '');
    if ($mainImageMaxWidthSource === '') $mainImageMaxWidthSource = '1200px';
	$mainImageMaxWidth = room_import_css_size($mainImageMaxWidthSource);
	$mobileImageWidth = room_import_css_size((string)(
        $cssManifest['mobile_image_width']
        ?? ''
    ));
	$audioPlayerBg = room_import_css_color((string)($cssManifest['audio_player_bg'] ?? ''));
	$audioPlayerTextButtons = room_import_css_color((string)($cssManifest['audio_player_text_buttons'] ?? ''));
    if ($textColor === '' && $body instanceof DOMElement) {
        $textColor = room_import_css_color((string)$body->getAttribute('text') ?: room_import_style_value($bodyStyle, 'color'));
    }

    $sections = [];
    $audio = [];
    $seenAudio = [];
    $seenImages = [];
    $textBuffer = '';
    $textStyle = [];
    $textBudget = 1800;

    $flushText = function () use (&$sections, &$textBuffer, &$textStyle, &$textBudget): void {
        $text = trim(preg_replace('~[ \t\r\n]+~u', ' ', $textBuffer) ?? '');
        $textBuffer = '';
        if ($text === '' || $textBudget <= 0) return;
        if (function_exists('mb_substr')) $text = mb_substr($text, 0, $textBudget, 'UTF-8');
        else $text = substr($text, 0, $textBudget);
        $textBudget -= strlen($text);
        $sections[] = ['type' => 'text', 'text' => $text, 'style' => $textStyle];
    };

    $rememberAudio = function (string $url, bool $force = false) use (&$audio, &$seenAudio): void {
        if ($url === '' || isset($seenAudio[$url]) || count($audio) >= 12) return;
        if (!room_import_is_youtube_url($url) && !room_import_has_audio_extension($url)) {
            if (!$force || room_import_has_image_extension($url)) return;
            $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
            if (preg_match('~\.(?:html?|php|asp|aspx|cgi|css|js|txt|xml)(?:[?#].*)?$~i', $path)) return;
        }
        $seenAudio[$url] = true;
        $audio[] = ['label' => room_import_is_youtube_url($url) ? 'YouTube Music' : room_import_media_label($url), 'url' => $url];
    };

    $rememberImage = function (string $url, string $role = '') use (&$sections, &$seenImages): void {
        if ($url === '' || isset($seenImages[$url]) || count($sections) >= 24) return;
        $seenImages[$url] = true;
        $section = [
            'type' => 'image',
            'src' => $url,
            'alt' => '',
            'width' => null,
            'height' => null,
        ];
        if ($role !== '') $section['role'] = $role;
        $sections[] = $section;
    };

    foreach (($cssManifest['images'] ?? []) as $image) {
        if (is_array($image)) $rememberImage((string)($image['src'] ?? ''), (string)($image['role'] ?? ''));
    }
    foreach (($cssManifest['music'] ?? []) as $track) {
        if (is_array($track)) $rememberAudio((string)($track['url'] ?? ''), true);
    }


    $walk = function (DOMNode $node, array $style = []) use (&$walk, &$sections, &$textBuffer, &$textStyle, $sourceUrl, $flushText, $rememberAudio, $rememberImage): void {
        if (count($sections) >= 24) return;
        if ($node instanceof DOMText) {
            $text = trim($node->wholeText);
            if ($text !== '') {
                if ($textBuffer === '') $textStyle = $style;
                $textBuffer .= ' ' . $text;
            }
            return;
        }
        if (!$node instanceof DOMElement) {
            foreach ($node->childNodes as $child) $walk($child, $style);
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'noscript', 'iframe'], true)) return;

        foreach (['src', 'href', 'data', 'url', 'filename', 'FileName', 'dynsrc', 'lowsrc'] as $attr) {
            if ($node->hasAttribute($attr)) {
                $rememberAudio(
                    room_import_candidate_media_url((string)$node->getAttribute($attr), $sourceUrl),
                    room_import_audio_hint($node, $attr)
                );
            }
        }
        if ($tag === 'param') {
            $rememberAudio(room_import_candidate_media_url((string)$node->getAttribute('value'), $sourceUrl), room_import_audio_hint($node, 'value'));
        }
        if ($tag !== 'body') {
            $elementBackground = '';
            if ($node->hasAttribute('background')) {
                $elementBackground = room_import_candidate_media_url((string)$node->getAttribute('background'), $sourceUrl);
            }
            if ($elementBackground === '') {
                $elementBackground = room_import_candidate_media_url(room_import_background_image((string)$node->getAttribute('style')), $sourceUrl);
            }
            if ($elementBackground !== '') {
                $flushText();
                $rememberImage($elementBackground, 'background-piece');
            }
        }
        if ($tag === 'img' || ($tag === 'input' && strtolower((string)$node->getAttribute('type')) === 'image')) {
            $flushText();
            $src = '';
            foreach (['src', 'data-src', 'data-original', 'lowsrc', 'dynsrc'] as $imgAttr) {
                if (!$node->hasAttribute($imgAttr)) continue;
                $src = room_import_candidate_media_url((string)$node->getAttribute($imgAttr), $sourceUrl);
                if ($src !== '') break;
            }
            if ($src !== '') {
                $rememberImage($src);
            }
            return;
        }
        $childStyle = room_import_style_from_node($node, $style);
        $isBlock = in_array($tag, ['address', 'article', 'aside', 'blockquote', 'center', 'div', 'footer', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'p', 'pre', 'section', 'table', 'tbody', 'td', 'th', 'tr', 'ul', 'ol', 'br'], true);
        if ($isBlock) $flushText();
        foreach ($node->childNodes as $child) $walk($child, $childStyle);
        if ($isBlock) $flushText();
    };

    if ($body) {
        $rootStyle = room_import_style_from_node($body);
        if ($textColor !== '') $rootStyle['color'] = $textColor;
        $walk($body, $rootStyle);
    }
    $flushText();

$roleSet = false;

foreach ($sections as &$section) {
    if (
        ($section['type'] ?? '') === 'image'
        && empty($section['role'])
        && !$roleSet
    ) {
        $section['role'] = 'header';
        $roleSet = true;
    }
}
unset($section);

    if (preg_match_all('~https?://[^\s"\'<>]+\.(?:mp3|m4a|aac|ogg|oga|opus|wav|m3u|m3u8|pls|asx|wma)(?:[^\s"\'<>]*)~i', $html, $matches)) {
        foreach ($matches[0] as $url) $rememberAudio(room_import_candidate_media_url($url, $sourceUrl));
    }
    if (preg_match_all('~["\']([^"\']+\.(?:mp3|m4a|aac|ogg|oga|opus|wav|mid|midi|m3u|m3u8|pls|asx|wax|wma)(?:[?#][^"\']*)?)["\']~i', $html, $matches)) {
        foreach ($matches[1] as $url) $rememberAudio(room_import_candidate_media_url($url, $sourceUrl));
    }
    if (preg_match_all('~https?://(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)[A-Za-z0-9_\-=&?%./]+~i', $html, $matches)) {
        foreach ($matches[0] as $url) $rememberAudio(room_import_candidate_media_url($url, $sourceUrl), true);
    }

$header = [];
$avatars = [];
$other = [];

foreach ($sections as $section) {
    if (($section['type'] ?? '') === 'image') {
        $role = (string)($section['role'] ?? '');

        if ($role === 'header') {
            $header[] = $section;
            continue;
        }

        if (
    $role === 'avatar-left' ||
    $role === 'avatar-right' ||
    $role === 'avatar-piece'
) {
    $avatars[] = $section;
    continue;
}
    }

    $other[] = $section;
}

/*
if ($header && $avatars && count($other) >= 1) {
    $sections = array_merge(
        [$header[0]],
        [$other[0]],
        $avatars,
        array_slice($other, 1)
    );
}
*/

if ($header && $avatars && count($other) >= 2) {
    $sections = array_merge(
        $header,
        array_slice($other, 0, 2),
        $avatars,
        array_slice($other, 2)
    );
}

    return [
		'source_url' => $sourceUrl,
		'title' => $title,
		'background_color' => $backgroundColor,
		'text_color' => $textColor,
		'text_size' => $textSize,
		'main_image_width' => $mainImageWidth,
		'main_image_max_width' => $mainImageMaxWidth,
		'mobile_image_width' => $mobileImageWidth,
		'audio_player_bg' => $audioPlayerBg,
		'audio_player_text_buttons' => $audioPlayerTextButtons,
        'background_image' => $backgroundImage,
        'sections' => array_slice($sections, 0, 24),
        'music' => $audio,
    ];
}

function room_import_download_asset(string $url, string $kind, string $referer = ''): ?string {
    $max = $kind === 'audio' ? 24 * 1024 * 1024 : 12 * 1024 * 1024;
    try {
        $fetched = room_import_fetch_url($url, $max, $kind === 'audio' ? 'audio/*,*/*;q=0.6' : 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8', 3, $referer);
    } catch (Throwable $e) {
        return null;
    }
    $body = (string)$fetched['body'];
    if ($body === '') return null;
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($body) ?: '';
    $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $audioTypes = ['audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/ogg' => 'ogg', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/aac' => 'aac', 'audio/mp4' => 'm4a'];
    $types = $kind === 'audio' ? $audioTypes : $imageTypes;
    if (!isset($types[$mime])) return null;
    $dir = __DIR__ . '/../assets/uploads/imported-rooms';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $file = bin2hex(random_bytes(12)) . '.' . $types[$mime];
    $dest = $dir . '/' . $file;
    $publicPath = '/assets/uploads/imported-rooms/' . $file;
    security_assert_storage_destination('room_import_create', $publicPath);
    if (file_put_contents($dest, $body, LOCK_EX) === false) return null;
    if (!security_valid_uploaded_file_signature($dest, $mime, $types[$mime])) {
        @unlink($dest);
        return null;
    }

$rootDir = dirname(__DIR__);

$docRoot = rtrim(realpath($_SERVER['DOCUMENT_ROOT']) ?: '', DIRECTORY_SEPARATOR);
$rootReal = rtrim(realpath($rootDir) ?: $rootDir, DIRECTORY_SEPARATOR);

$baseUrl = str_replace('\\', '/', substr($rootReal, strlen($docRoot)));

if ($baseUrl === '/') {
    $baseUrl = '';
}

return $baseUrl . $publicPath;

}

function room_import_localize(array $preview): array {
    $layout = [
    'source_url' => $preview['source_url'] ?? '',
    'background_color' => $preview['background_color'] ?? '#000000',
    'text_color' => $preview['text_color'] ?? '',
    'text_size' => $preview['text_size'] ?? '',
    'main_image_width' => $preview['main_image_width'] ?? '',
    'main_image_max_width' => $preview['main_image_max_width'] ?? '',
    'mobile_image_width' => $preview['mobile_image_width'] ?? '',
    'audio_player_bg' => $preview['audio_player_bg'] ?? '',
    'audio_player_text_buttons' => $preview['audio_player_text_buttons'] ?? '',
    'sections' => [],
];
    $sourceUrl = (string)($preview['source_url'] ?? '');
    $backgroundPath = '';
    if (!empty($preview['background_image'])) {
        $backgroundPath = room_import_download_asset((string)$preview['background_image'], 'image', $sourceUrl) ?: '';
    }
    foreach (($preview['sections'] ?? []) as $section) {
        if (!is_array($section)) continue;
        if (($section['type'] ?? '') === 'image') {
            $path = room_import_download_asset((string)($section['src'] ?? ''), 'image', $sourceUrl);
            if (!$path) continue;
            $layout['sections'][] = [
                'type' => 'image',
                'path' => $path,
                'alt' => (string)($section['alt'] ?? ''),
                'role' => (string)($section['role'] ?? ''),
            ];
        } elseif (($section['type'] ?? '') === 'text') {
            $text = trim((string)($section['text'] ?? ''));
            if ($text === '') continue;
            $layout['sections'][] = [
                'type' => 'text',
                'text' => $text,
                'style' => is_array($section['style'] ?? null) ? $section['style'] : [],
            ];
        }
    }
    $music = [];
    foreach (($preview['music'] ?? []) as $track) {
        if (!is_array($track)) continue;
        $url = (string)($track['url'] ?? '');
        if ($url === '') continue;
        $isYouTube = room_import_is_youtube_url($url);
        $local = preg_match('~\.(?:mp3|m4a|aac|ogg|oga|opus|wav)(?:[?#].*)?$~i', $url)
            ? room_import_download_asset($url, 'audio', $sourceUrl)
            : null;
        $music[] = [
            'label' => (string)($track['label'] ?? ($isYouTube ? 'YouTube Music' : room_import_media_label($url))),
            'url' => $local ?: $url,
            'local' => (bool)$local,
            'type' => $isYouTube ? 'youtube' : 'audio',
            'provider' => $isYouTube ? 'YouTube' : '',
            'embed_url' => $isYouTube ? room_import_youtube_embed($url) : '',
        ];
    }
    return ['layout' => $layout, 'music' => $music, 'background_path' => $backgroundPath];
}

function room_import_preview_from_url(string $url): array {
    $fetched = room_import_fetch_url($url, 3 * 1024 * 1024, 'text/html,application/xhtml+xml,*/*;q=0.4', 3);
    $preview = room_import_parse((string)$fetched['body'], (string)$fetched['url']);
    if (empty($preview['sections']) && empty($preview['music']) && empty($preview['background_image'])) {
        throw new RuntimeException('That page did not look like an importable VP-style room.');
    }
    return $preview;
}

function room_import_tile_image_from_layout(?string $layoutJson): string {
    if (!$layoutJson) return '';
    $layout = json_decode($layoutJson, true);
    if (!is_array($layout)) return '';
    foreach (($layout['sections'] ?? []) as $section) {
        if (is_array($section) && ($section['type'] ?? '') === 'image' && !empty($section['path'])) {
            return (string)$section['path'];
        }
    }
    return '';
}

function room_import_file_paths(?string $layoutJson, ?string $musicJson): array {
    $paths = [];
    foreach ([$layoutJson, $musicJson] as $json) {
        if (!$json) continue;
        $decoded = json_decode($json, true);
        $stack = is_array($decoded) ? [$decoded] : [];
        while ($stack) {
            $item = array_pop($stack);
            foreach ($item as $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                } elseif (
    is_string($value) &&
    (
        str_starts_with($value, '/assets/uploads/imported-rooms/')
        || str_contains($value, '/assets/uploads/imported-rooms/')
    )
) {
    $paths[] = $value;
}
            }
        }
    }
    return array_values(array_unique($paths));
}
