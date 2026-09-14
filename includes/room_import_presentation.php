<?php
declare(strict_types=1);

// Transfer only safe presentation values, never source scripts or arbitrary CSS.
function room_import_css_variables(string $css): array {
    $variables = [];
    preg_match_all('~(--[a-zA-Z0-9_-]+)\s*:\s*([^;{}]+);~', $css, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) $variables[$match[1]] = trim($match[2]);
    return $variables;
}

function room_import_resolve_css_variables(string $value, array $variables): string {
    for ($depth = 0; $depth < 12 && str_contains($value, 'var('); $depth++) {
        $next = preg_replace_callback('~var\(\s*(--[a-zA-Z0-9_-]+)\s*(?:,\s*([^()]*))?\)~',
            static fn(array $match): string => $variables[$match[1]] ?? ($match[2] ?? $match[0]), $value) ?? $value;
        if ($next === $value) break;
        $value = $next;
    }
    return trim(preg_replace('~\s*!important\s*$~i', '', $value) ?? $value);
}

function room_import_source_presentation(DOMDocument $dom): array {
    $css = '';
    foreach ($dom->getElementsByTagName('style') as $element) $css .= "\n" . $element->textContent;
    $css = preg_replace('~/\*.*?\*/~s', '', $css) ?? $css;
    $variables = room_import_css_variables($css);
    $playerStyle = [];
    foreach ($variables as $name => $value) {
        if (!preg_match('~^--(?:audio-player-[a-z-]+|player-(?:width|height|accent|overlay-size|symbol-size|replay-size|tooltip-(?:bg|border|text))|(?:play|pause)-icon-(?:width|height))$~', $name)) continue;
        $value = room_import_resolve_css_variables($value, $variables);
        if (room_import_css_color($value) !== '' || room_import_css_size($value) !== '') $playerStyle[$name] = $value;
    }

    // Bounded flat rules with simple tag/class/id descendant selectors. Complex
    // selectors and responsive blocks are not a general stylesheet renderer.
    $flatCss = '';
    $depth = 0;
    $blockStart = 0;
    $selectorStart = 0;
    $nested = false;
    for ($i = 0, $length = strlen($css); $i < $length; $i++) {
        if ($css[$i] === '{') {
            if ($depth === 0) { $blockStart = $i; $nested = false; }
            else $nested = true;
            $depth++;
        } elseif ($css[$i] === '}' && $depth > 0) {
            $depth--;
            if ($depth === 0) {
                if (!$nested) $flatCss .= substr($css, $selectorStart, $i - $selectorStart + 1) . "\n";
                $selectorStart = $i + 1;
            }
        }
    }
    preg_match_all('~([^{}]+)\{([^{}]*)\}~', $flatCss, $blocks, PREG_SET_ORDER);
    $rules = [];
    $hideAudioFrame = false;
    foreach (array_slice($blocks, 0, 400) as $block) {
        foreach (explode(',', $block[1]) as $selector) {
            $selector = trim($selector);
            if (str_contains($selector, '.mejs__mediaelement') && preg_match('~\biframe\b~', $selector)
                && room_import_style_value($block[2], 'opacity') === '0') $hideAudioFrame = true;
            if (!preg_match('~^(?:[a-zA-Z][a-zA-Z0-9_-]*)?(?:[.#][a-zA-Z_][a-zA-Z0-9_-]*)*(?:\s+(?:[a-zA-Z][a-zA-Z0-9_-]*)?(?:[.#][a-zA-Z_][a-zA-Z0-9_-]*)*)*$~D', $selector) || $selector === '') continue;
            $parts = preg_split('/\s+/', $selector) ?: [];
            $path = '';
            $weight = 0;
            foreach ($parts as $part) {
                preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*/', $part, $tag);
                $path .= '//' . ($tag[0] ?? '*');
                if ($tag) $weight++;
                preg_match_all('/([.#])([a-zA-Z_][a-zA-Z0-9_-]*)/', $part, $tokens, PREG_SET_ORDER);
                foreach ($tokens as $token) {
                    $path .= $token[1] === '#'
                        ? '[@id="' . $token[2] . '"]'
                        : '[contains(concat(" ",normalize-space(@class)," ")," ' . $token[2] . ' ")]';
                    $weight += $token[1] === '#' ? 100 : 10;
                }
            }
            $rules[] = ['path' => $path, 'weight' => $weight, 'order' => count($rules), 'css' => $block[2]];
        }
    }
    usort($rules, static fn(array $a, array $b): int => [$a['weight'], $a['order']] <=> [$b['weight'], $b['order']]);
    $xpath = new DOMXPath($dom);
    $styles = [];
    $imageStyles = [];
    foreach ($rules as $rule) {
        $declarations = '';
        foreach (['color', 'font-size', 'text-align'] as $property) {
            $value = room_import_resolve_css_variables(room_import_style_value($rule['css'], $property), $variables);
            $safe = $property === 'color' ? room_import_css_color($value)
                : ($property === 'font-size' ? room_import_css_size($value) : (in_array($value, ['left', 'center', 'right'], true) ? $value : ''));
            if ($safe !== '') $declarations .= $property . ':' . $safe . ';';
        }
        $imageStyle = room_import_image_presentation($rule['css'], $variables);
        if ($declarations === '' && $imageStyle === []) continue;
        foreach ($xpath->query($rule['path']) as $node) {
            // Prepend higher-specificity declarations because style_value reads
            // the first occurrence. Inline declarations are prepended later.
            $path = $node->getNodePath();
            $styles[$path] = $declarations . ($styles[$path] ?? '');
            if ($imageStyle !== [] && strtolower($node->nodeName) === 'img') {
                $imageStyles[$path] = array_replace($imageStyles[$path] ?? [], $imageStyle);
            }
        }
    }
    room_import_attach_detail_layers($dom, $xpath, $rules, $variables, $imageStyles);
    return ['variables' => $variables, 'styles' => $styles, 'image_styles' => $imageStyles, 'player_style' => $playerStyle, 'hide_audio_iframe' => $hideAudioFrame];
}


// Preserve image compositing without importing arbitrary CSS or URL masks.
function room_import_image_presentation(string $css, array $variables = []): array {
    $result = [];
    $blend = strtolower(room_import_resolve_css_variables(room_import_style_value($css, 'mix-blend-mode'), $variables));
    if (in_array($blend, ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten', 'color-dodge', 'color-burn', 'hard-light', 'soft-light', 'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'], true)) $result['blend_mode'] = $blend;
    $opacity = room_import_resolve_css_variables(room_import_style_value($css, 'opacity'), $variables);
    if (preg_match('~^(?:0(?:\.[0-9]+)?|1(?:\.0+)?|\.[0-9]+)$~D', $opacity)) $result['opacity'] = $opacity;
    $mask = room_import_resolve_css_variables(room_import_style_value($css, 'mask-image'), $variables);
    if ($mask === '') $mask = room_import_resolve_css_variables(room_import_style_value($css, '-webkit-mask-image'), $variables);
    $stop = '(?:transparent|black|white|\#000(?:000)?|\#fff(?:fff)?)(?:\s+(?:100|[0-9]{1,2})(?:\.[0-9]+)?%)?';
    $gradient = 'linear-gradient\(\s*to\s+(?:left|right|top|bottom)\s*,\s*' . $stop . '(?:\s*,\s*' . $stop . '){1,7}\s*\)';
    if ($mask === 'none' || (strlen($mask) <= 800 && preg_match('~^' . $gradient . '(?:\s*,\s*' . $gradient . '){0,3}$~iD', $mask))) $result['mask_image'] = $mask;
    $composite = strtolower(room_import_style_value($css, 'mask-composite'));
    if (in_array($composite, ['add', 'subtract', 'intersect', 'exclude'], true)) $result['mask_composite'] = $composite;
    return $result;
}

// A detail layer may repeat the same local image through a bounded radial mask.
// Never execute source JavaScript or import arbitrary positioned source content.
function room_import_detail_mask(string $value): string {
    $value = trim($value);
    if (strlen($value) > 800) return '';
    $percent = '(?:100|[0-9]{1,2})(?:\.[0-9]+)?%';
    $stop = '(?:transparent|black|white|\#000(?:000)?|\#fff(?:fff)?)(?:\s+' . $percent . ')?';
    $gradient = 'radial-gradient\(\s*ellipse\s+' . $percent . '\s+' . $percent
        . '\s+at\s+' . $percent . '\s+' . $percent . '\s*,\s*'
        . $stop . '(?:\s*,\s*' . $stop . '){1,7}\s*\)';
    return preg_match('~^' . $gradient . '(?:\s*,\s*' . $gradient . '){0,3}$~iD', $value) ? $value : '';
}

function room_import_attach_detail_layers(DOMDocument $dom, DOMXPath $xpath, array $rules, array $variables, array &$imageStyles): void {
    // Recognize the declarative same-image binding used by the site's player/page
    // template. Parsing this narrow assignment does not evaluate the source script.
    $bindingPattern = <<<'REGEX'
~document\.getElementById\(\s*['"](?<id>[a-zA-Z_][a-zA-Z0-9_-]*)['"]\s*\)\.style\.backgroundImage\s*=\s*['"]url\(['"]\s*\+\s*JSON\.stringify\(setting\(['"](?<variable>--[a-zA-Z0-9_-]+)['"]\)\)\s*\+\s*['"]\)['"]\s*;~
REGEX;
    $bindings = [];
    foreach ($dom->getElementsByTagName('script') as $script) {
        preg_match_all($bindingPattern, substr($script->textContent, 0, 131072), $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $bindings[$match['id']] = trim((string)($variables[$match['variable']] ?? ''), " \t\r\n\"'");
            if (count($bindings) >= 32) break 2;
        }
    }
    foreach ($bindings as $id => $source) {
        if ($source === '') continue;
        $layer = $xpath->query('//*[@id="' . $id . '"]')->item(0);
        if (!$layer instanceof DOMElement || strtolower($layer->tagName) !== 'div'
            || trim($layer->textContent) !== '' || $layer->getElementsByTagName('*')->length > 0) continue;
        $image = $layer->previousSibling;
        while ($image && !$image instanceof DOMElement) $image = $image->previousSibling;
        if (!$image instanceof DOMElement || strtolower($image->tagName) !== 'img'
            || $image->getAttribute('src') !== $source) continue;
        $css = $layer->getAttribute('style') . ';';
        foreach (array_reverse($rules) as $rule) {
            foreach ($xpath->query($rule['path']) as $node) {
                if ($node->isSameNode($layer)) { $css .= $rule['css'] . ';'; break; }
            }
        }
        if (room_import_style_value($css, 'position') !== 'absolute'
            || !in_array(room_import_style_value($css, 'inset'), ['0', '0px'], true)
            || room_import_style_value($css, 'pointer-events') !== 'none'
            || room_import_style_value($css, 'background-size') !== '100% 100%'
            || room_import_style_value($css, 'background-repeat') !== 'no-repeat') continue;
        $mask = room_import_style_value($css, 'mask-image');
        if ($mask === '') $mask = room_import_style_value($css, '-webkit-mask-image');
        $mask = room_import_detail_mask(room_import_resolve_css_variables($mask, $variables));
        if ($mask !== '') $imageStyles[$image->getNodePath()]['detail_mask_image'] = $mask;
    }
}