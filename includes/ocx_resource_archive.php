<?php
declare(strict_types=1);

/** Inert resource bytes only; never an executable OCX or a public media route. */
function ocx_resource_archive_store(array $resources, string $directory): int
{
    if (count($resources) > OCX_STATIC_MEDIA_MAX_RESOURCES) throw new RuntimeException('Too many original resources.');
    $archive = $directory . '/.ocx-resources';
    if (is_link($archive)) throw new RuntimeException('Invalid original resource directory.');
    if (is_file($archive.'/index.json')) throw new RuntimeException('Select only one original OCX for each game installation.');
    if (!is_dir($archive) && !mkdir($archive, 0700)) throw new RuntimeException('Cannot retain original resources.');
    $entries = []; $total = 0;
    foreach ($resources as $resource) {
        $bytes = (string)$resource['bytes']; $size = strlen($bytes);
        if ($size > OCX_STATIC_MEDIA_MAX_RESOURCE_BYTES || ($total += $size) > OCX_STATIC_MEDIA_MAX_CONTAINER_BYTES) {
            throw new RuntimeException('Original resources exceed the private archive limit.');
        }
        $sha = hash('sha256', $bytes); $file = $archive . '/' . $sha . '.bin';
        if (is_link($file)) throw new RuntimeException('Invalid original resource file.');
        if (file_put_contents($file, $bytes, LOCK_EX) !== $size) throw new RuntimeException('Cannot retain original resource bytes.');
        $payload = ocx_static_media_payload($resource);
        $entries[] = ['type'=>$resource['type'], 'name'=>$resource['name'], 'language'=>$resource['language'],
            'bytes'=>$size, 'sha256'=>$sha, 'names'=>$payload ? ocx_static_media_candidate_names($resource, $payload['extension']) : []];
    }
    $index = json_encode(['version'=>1,'resources'=>$entries], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (strlen($index) > 2097152 || is_link($archive.'/index.json')
        || file_put_contents($archive.'/index.json', $index, LOCK_EX) !== strlen($index)) {
        throw new RuntimeException('Cannot retain the original resource index.');
    }
    return count($entries);
}

function ocx_resource_archive_remove(string $directory): void
{
    $path = $directory . '/.ocx-resources';
    if (is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    $root = realpath($directory); $resolved = realpath($path);
    if ($root === false || $resolved === false || dirname($resolved) !== $root) return;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isLink() || !$item->isDir()) unlink($item->getPathname());
        else rmdir($item->getPathname());
    }
    rmdir($resolved);
}

/** Prepare a newly supported missing slot from retained bytes, under its current validator. */
function ocx_resource_archive_restore(string $directory, string $slot, array $definition, array $nameMap, callable $prepare): bool
{
    static $inProgress = [];
    $key = $directory . ':' . $slot;
    $archive = $directory . '/.ocx-resources'; $index = $archive . '/index.json';
    $target = $directory . '/' . $definition['installName'];
    if (isset($inProgress[$key]) || is_file($target) || !is_file($index) || is_link($archive) || is_link($index)
        || filesize($index) > 2097152 || is_link($archive.'/.lock')) return false;
    $lock = @fopen($archive.'/.lock', 'c');
    if ($lock === false) return false;
    $inProgress[$key] = true; $work = null;
    try {
        if (!flock($lock, LOCK_EX)) return false;
        clearstatcache(true, $target);
        if (is_file($target)) return true;
        $manifest = json_decode((string)file_get_contents($index), true, 32, JSON_THROW_ON_ERROR);
        $entries = $manifest['resources'] ?? null;
        if (($manifest['version'] ?? 0) !== 1 || !is_array($entries) || count($entries) > OCX_STATIC_MEDIA_MAX_RESOURCES) return false;
        $matches = array_values(array_filter($entries, static function ($entry) use ($nameMap, $slot): bool {
            if (!is_array($entry) || !is_array($entry['names'] ?? null)) return false;
            foreach ($entry['names'] as $name) if (is_string($name) && ($nameMap[strtolower($name)] ?? null) === $slot) return true;
            return false;
        }));
        if (count($matches) !== 1) return false;
        $entry = $matches[0]; $sha = $entry['sha256'] ?? '';
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) return false;
        $file = $archive . '/' . $sha . '.bin';
        if (is_link($file) || !is_file($file) || filesize($file) !== ($entry['bytes'] ?? -1)
            || filesize($file) > OCX_STATIC_MEDIA_MAX_RESOURCE_BYTES || !hash_equals($sha, hash_file('sha256', $file))) return false;
        $entry['bytes'] = file_get_contents($file);
        $payload = ocx_static_media_payload($entry);
        if ($payload === null) return false;
        // Re-derive candidate names; the archive index cannot invent a slot mapping.
        $mapped = false;
        foreach (ocx_static_media_candidate_names($entry, $payload['extension']) as $name) {
            if (($nameMap[strtolower($name)] ?? null) === $slot) $mapped = true;
        }
        if (!$mapped) return false;
        $work = $archive . '/prepare-' . bin2hex(random_bytes(8));
        if (!mkdir($work, 0700)) return false;
        $prepare($payload, $work);
        $prepared = $work . '/' . $definition['installName'];
        if (!is_file($prepared) || is_link($target)) return false;
        // Optional normalization provenance preserves original-vs-prepared ranking.
        if (is_file($work.'/.source-selection.json')) {
            if (is_link($directory.'/.source-selection.json')) return false;
            $proof = json_decode((string)file_get_contents($work.'/.source-selection.json'), true, 16, JSON_THROW_ON_ERROR);
            $existing = is_file($directory.'/.source-selection.json')
                ? json_decode((string)file_get_contents($directory.'/.source-selection.json'), true, 16, JSON_THROW_ON_ERROR) : [];
            if (!is_array($existing)) return false;
            file_put_contents($work.'/proof.json', json_encode(array_replace($existing, $proof), JSON_THROW_ON_ERROR), LOCK_EX);
            if (!rename($work.'/proof.json', $directory.'/.source-selection.json')) return false;
        }
        return rename($prepared, $target);
    } catch (Throwable) {
        // Missing/corrupt optional resources remain unavailable, never weaken validation.
        return false;
    } finally {
        if ($work !== null && is_dir($work)) {
            foreach (scandir($work) ?: [] as $name) if ($name !== '.' && $name !== '..' && is_file($work.'/'.$name)) unlink($work.'/'.$name);
            @rmdir($work);
        }
        flock($lock, LOCK_UN); fclose($lock); unset($inProgress[$key]);
    }
}
