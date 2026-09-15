<?php
declare(strict_types=1);

/** One-time distribution seed. Afterwards the ordinary catalog owns rename/delete. */
function custom_emoji_install_bundled(PDO $pdo): void {
    $key = 'custom_emoji.bundled.monkey.v1';
    if (app_setting($pdo, $key, '') !== '') return;
    $source = dirname(__DIR__) . '/assets/images/custom-emojis/monkey.gif';
    $hash = '7f0a2799a62dc1c0eaffa561ca5290064eacd3663b0798af09e2073ed48f008a';
    // Incomplete uploads of a new release must not break the existing picker.
    if (!is_file($source) || !hash_equals($hash, (string)hash_file('sha256', $source))) return;
    $transaction = database_transaction_begin($pdo, true);
    if (empty($transaction['owned'])) throw new LogicException('Bundled emoji installation must own its transaction.');
    $createdPath = null;
    try {
        $pdo->prepare(db_uses_mysql_syntax($pdo)
            ? 'INSERT IGNORE INTO app_settings (setting_key,value) VALUES (?,?)'
            : 'INSERT OR IGNORE INTO app_settings (setting_key,value) VALUES (?,?)')->execute([CUSTOM_EMOJI_INDEX_KEY, '[]']);
        $ids = custom_emoji_index($pdo, true);
        if (app_setting($pdo, $key, '') !== '') { database_transaction_commit($pdo, $transaction); return; }
        // Reuse an existing copy, even if an administrator already removed it.
        $query = $pdo->prepare('SELECT value FROM app_settings WHERE setting_key LIKE ?');
        $query->execute([CUSTOM_EMOJI_RECORD_PREFIX . '%']);
        while (($raw = $query->fetchColumn()) !== false) {
            $record = json_decode((string)$raw, true);
            if (is_array($record) && ($record['sha256'] ?? '') === $hash) {
                set_app_setting($pdo, $key, 'existing');
                database_transaction_commit($pdo, $transaction);
                return;
            }
        }
        if (count($ids) >= CUSTOM_EMOJI_MAX_ENTRIES) { database_transaction_commit($pdo, $transaction); return; }
        $name = 'monkey';
        for ($suffix = 2; app_setting($pdo, CUSTOM_EMOJI_NAME_PREFIX . $name, '') !== ''; $suffix++) {
            if ($suffix > CUSTOM_EMOJI_MAX_ENTRIES + 1) throw new CustomEmojiException('A bundled emoji name could not be allocated.', 409);
            $name = 'monkey_' . $suffix;
        }
        $id = bin2hex(random_bytes(16));
        $filename = bin2hex(random_bytes(16)) . '.gif';
        security_assert_storage_destination('custom_emoji_upload', '/assets/uploads/emojis/' . $filename);
        $directory = custom_emoji_storage_directory(true);
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        $out = @fopen($destination, 'xb');
        if ($out === false) throw new CustomEmojiException('Bundled emoji storage is unavailable.', 500);
        $createdPath = $destination;
        $input = @fopen($source, 'rb');
        try {
            if ($input === false || stream_copy_to_stream($input, $out) !== 183730) throw new CustomEmojiException('The bundled emoji could not be stored.', 500);
        } finally {
            if (is_resource($input)) fclose($input);
            fclose($out);
        }
        if (!hash_equals($hash, (string)hash_file('sha256', $destination))) throw new CustomEmojiException('The bundled emoji could not be verified.', 500);
        $record = ['id'=>$id, 'name'=>$name, 'file'=>$filename, 'mime'=>'image/gif', 'width'=>120, 'height'=>120,
            'bytes'=>183730, 'sha256'=>$hash, 'createdBy'=>0, 'source'=>'bundled', 'createdAt'=>gmdate('Y-m-d\TH:i:s\Z')];
        $insert = $pdo->prepare('INSERT INTO app_settings (setting_key,value) VALUES (?,?)');
        $insert->execute([CUSTOM_EMOJI_RECORD_PREFIX . $id, json_encode($record, JSON_THROW_ON_ERROR)]);
        $insert->execute([CUSTOM_EMOJI_NAME_PREFIX . $name, $id]);
        $ids[] = $id;
        set_app_setting($pdo, CUSTOM_EMOJI_INDEX_KEY, json_encode($ids, JSON_THROW_ON_ERROR));
        set_app_setting($pdo, $key, 'installed');
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        if ($createdPath !== null) @unlink($createdPath);
        throw $error;
    }
}
