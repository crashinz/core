<?php
declare(strict_types=1);

/**
 * Build 000048 Part 3 authoritative member-profile and public identity owner.
 */

const MEMBER_PROFILE_USERNAME_MIN = 3;
const MEMBER_PROFILE_USERNAME_MAX = 32;
const MEMBER_PROFILE_DISPLAY_NAME_MAX = 80;
const MEMBER_PROFILE_NAME_MAX = 160;
const MEMBER_PROFILE_LOCATION_MAX = 160;
const MEMBER_PROFILE_ABOUT_MAX = 2000;
const MEMBER_PROFILE_PUBLIC_EMAIL_MAX = 254;
const MEMBER_PROFILE_WEBSITE_MAX = 500;
const MEMBER_PROFILE_INTERESTS_MAX = 1000;
const MEMBER_PROFILE_DISCORD_USERNAME_MIN = 2;
const MEMBER_PROFILE_DISCORD_USERNAME_MAX = 32;
const MEMBER_PROFILE_REQUEST_ID_MAX = 96;

function member_profiles_limit_definitions(): array
{
    return [
        'profile_limit_display_name' => [
            'field' => 'display_name',
            'label' => 'Display name',
            'default' => MEMBER_PROFILE_DISPLAY_NAME_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_DISPLAY_NAME_MAX,
            'table' => 'users',
            'column' => 'display_name',
        ],
        'profile_limit_name' => [
            'field' => 'name',
            'label' => 'Name',
            'default' => MEMBER_PROFILE_NAME_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_NAME_MAX,
            'table' => 'member_profiles',
            'column' => 'profile_name',
        ],
        'profile_limit_location' => [
            'field' => 'location',
            'label' => 'Location',
            'default' => MEMBER_PROFILE_LOCATION_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_LOCATION_MAX,
            'table' => 'member_profiles',
            'column' => 'location',
        ],
        'profile_limit_about_me' => [
            'field' => 'about_me',
            'label' => 'About Me',
            'default' => MEMBER_PROFILE_ABOUT_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_ABOUT_MAX,
            'table' => 'member_profiles',
            'column' => 'about_me',
        ],
        'profile_limit_public_contact_email' => [
            'field' => 'public_contact_email',
            'label' => 'Public profile contact email',
            'default' => MEMBER_PROFILE_PUBLIC_EMAIL_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_PUBLIC_EMAIL_MAX,
            'table' => 'member_profiles',
            'column' => 'public_contact_email',
        ],
        'profile_limit_website' => [
            'field' => 'website',
            'label' => 'Website',
            'default' => MEMBER_PROFILE_WEBSITE_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_WEBSITE_MAX,
            'table' => 'member_profiles',
            'column' => 'website',
        ],
        'profile_limit_interests' => [
            'field' => 'interests',
            'label' => 'Interests',
            'default' => MEMBER_PROFILE_INTERESTS_MAX,
            'minimum' => 1,
            'maximum' => MEMBER_PROFILE_INTERESTS_MAX,
            'table' => 'member_profiles',
            'column' => 'interests',
        ],
    ];
}

function member_profiles_limit_setting_defaults(): array
{
    $defaults = [];
    foreach (member_profiles_limit_definitions() as $settingId => $definition) {
        $defaults[$settingId] = (string)$definition['default'];
    }
    return $defaults;
}

function member_profiles_limit_definition_for_field(string $field): ?array
{
    foreach (member_profiles_limit_definitions() as $settingId => $definition) {
        if ($definition['field'] === $field) return ['settingId' => $settingId] + $definition;
    }
    return null;
}

function member_profiles_effective_limits(PDO $pdo): array
{
    $limits = [];
    foreach (member_profiles_limit_definitions() as $settingId => $definition) {
        $stored = (int)app_setting($pdo, $settingId, (string)$definition['default']);
        $limits[$definition['field']] = max(
            (int)$definition['minimum'],
            min((int)$definition['maximum'], $stored)
        );
    }
    return $limits;
}

function member_profiles_assert_effective_length(
    PDO $pdo,
    string $field,
    string $label,
    string $value
): void {
    if ($value === '') return;
    $limits = member_profiles_effective_limits($pdo);
    $maximum = (int)($limits[$field] ?? 0);
    if ($maximum < 1) {
        throw new MemberProfileException(
            $label . ' limit is unavailable.',
            'MEMBER_PROFILE_LIMIT_UNAVAILABLE',
            503
        );
    }
    if (member_profiles_text_length($value) > $maximum) {
        throw new MemberProfileException(
            $label . ' must be ' . $maximum . ' characters or less.',
            'MEMBER_PROFILE_FIELD_TOO_LONG'
        );
    }
}

function member_profiles_limit_impacts(PDO $pdo, array $proposed): array
{
    $definitions = member_profiles_limit_definitions();
    $lengthFunction = db_driver($pdo) === 'mysql' ? 'CHAR_LENGTH' : 'LENGTH';
    $impacts = [];
    foreach ($proposed as $settingId => $rawValue) {
        if (!isset($definitions[$settingId]) || !is_numeric($rawValue)) continue;
        $definition = $definitions[$settingId];
        $proposedLimit = max(
            (int)$definition['minimum'],
            min((int)$definition['maximum'], (int)$rawValue)
        );
        $currentLimit = (int)(
            member_profiles_effective_limits($pdo)[$definition['field']]
            ?? $definition['default']
        );
        $isLowering = $proposedLimit < $currentLimit;
        $count = 0;
        if ($isLowering) {
            if ($definition['table'] === 'users') {
                $sql = "SELECT COUNT(*) FROM users WHERE display_name <> '' "
                    . "AND LOWER(display_name) <> LOWER(COALESCE(username, '')) "
                    . "AND {$lengthFunction}(display_name) > ?";
            } else {
                $column = (string)$definition['column'];
                $sql = "SELECT COUNT(*) FROM member_profiles WHERE {$column} IS NOT NULL "
                    . "AND {$column} <> '' AND {$lengthFunction}({$column}) > ?";
            }
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $proposedLimit, PDO::PARAM_INT);
            $stmt->execute();
            $count = max(0, (int)$stmt->fetchColumn());
        }
        $impacts[$settingId] = [
            'settingId' => $settingId,
            'field' => $definition['field'],
            'label' => $definition['label'],
            'currentLimit' => $currentLimit,
            'proposedLimit' => $proposedLimit,
            'isLowering' => $isLowering,
            'recordsAboveProposedLimit' => $count,
        ];
    }
    return $impacts;
}

final class MemberProfileException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $httpStatus = 400,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

function member_profiles_text_length(string $value): int
{
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $matched = preg_match_all('/./us', $value, $matches);
    return $matched === false ? strlen($value) : count($matches[0]);
}

function member_profiles_reject_controls(string $value, bool $multiline = false): void
{
    $pattern = $multiline
        ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'
        : '/[\x00-\x1F\x7F]/u';
    if (preg_match($pattern, $value)) {
        throw new MemberProfileException(
            'Profile text contains unsupported control characters.',
            'MEMBER_PROFILE_CONTROL_CHARACTER_INVALID'
        );
    }
}

function member_profiles_normalize_single(string $value): string
{
    $value = trim(str_replace("\0", '', $value));
    member_profiles_reject_controls($value);
    return $value;
}

function member_profiles_normalize_multiline(string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = trim($value);
    member_profiles_reject_controls($value, true);
    return $value;
}

function member_profiles_assert_length(string $field, string $value, int $maximum): void
{
    if (member_profiles_text_length($value) > $maximum) {
        throw new MemberProfileException(
            $field . ' must be ' . $maximum . ' characters or less.',
            'MEMBER_PROFILE_FIELD_TOO_LONG'
        );
    }
}

function member_profiles_validate_username(mixed $raw): string
{
    $username = strtolower(member_profiles_normalize_single((string)$raw));
    $length = member_profiles_text_length($username);
    if ($length < MEMBER_PROFILE_USERNAME_MIN || $length > MEMBER_PROFILE_USERNAME_MAX
        || !preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $username)) {
        throw new MemberProfileException(
            'Username must be 3-32 lowercase letters, numbers, dots, dashes, or underscores.',
            'MEMBER_PROFILE_USERNAME_INVALID'
        );
    }
    return $username;
}

function member_profiles_validate_display_name(mixed $raw): string
{
    $value = member_profiles_normalize_single((string)$raw);
    member_profiles_assert_length('Display name', $value, MEMBER_PROFILE_DISPLAY_NAME_MAX);
    return $value;
}

function member_profiles_validate_discord_username(mixed $raw): string
{
    $value = strtolower(member_profiles_normalize_single((string)$raw));
    if ($value === '') return '';
    $length = member_profiles_text_length($value);
    if ($length < MEMBER_PROFILE_DISCORD_USERNAME_MIN
        || $length > MEMBER_PROFILE_DISCORD_USERNAME_MAX
        || preg_match('/^(?!.*\.\.)[a-z0-9_][a-z0-9_.]*[a-z0-9_]$/', $value) !== 1) {
        throw new MemberProfileException(
            'Discord username must be 2-32 lowercase letters, numbers, underscores, or single interior dots.',
            'MEMBER_PROFILE_DISCORD_USERNAME_INVALID'
        );
    }
    return $value;
}

function member_profiles_validate_public_profile_id(mixed $raw): string
{
    $value = strtolower(member_profiles_normalize_single((string)$raw));
    if (preg_match(
        '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
        $value
    ) !== 1) {
        throw new MemberProfileException(
            'The public profile identity is invalid.',
            'MEMBER_PROFILE_PUBLIC_ID_INVALID',
            409
        );
    }
    return $value;
}

function member_profiles_boolean(mixed $raw, string $field): bool
{
    if (is_bool($raw)) return $raw;
    if ($raw === 0 || $raw === 1 || $raw === '0' || $raw === '1') {
        return (bool)(int)$raw;
    }
    throw new MemberProfileException(
        $field . ' must be an explicit boolean.',
        'MEMBER_PROFILE_BOOLEAN_INVALID'
    );
}

/**
 * Freeze the identity carried by a source-proven schema that predates the
 * separate username column. This is deliberately read-only: callers run it
 * before any migration or restore mutation and later apply the returned plan
 * only after the published baseline has created the username column.
 */
function member_profiles_preflight_legacy_identity_source(
    PDO $pdo,
    array $sourceVariant
): array {
    if (!member_profiles_table_exists($pdo, 'users')) return [];
    $columns = array_fill_keys(member_profiles_table_columns($pdo, 'users'), true);
    if (isset($columns['username'])) return [];
    foreach (['id', 'email', 'password_hash', 'display_name'] as $required) {
        if (!isset($columns[$required])) {
            throw new MemberProfileException(
                'The supported predecessor is missing required identity data.',
                'MEMBER_PROFILE_LEGACY_IDENTITY_INCOMPLETE',
                409
            );
        }
    }
    if (empty($sourceVariant['recognized'])
        || !is_string($sourceVariant['id'] ?? null)
        || (string)$sourceVariant['id'] === '') {
        throw new MemberProfileException(
            'Legacy identity normalization requires a source-proven predecessor.',
            'MEMBER_PROFILE_LEGACY_SOURCE_UNRECOGNIZED',
            409
        );
    }

    $rows = $pdo->query(
        'SELECT id, email, password_hash, display_name FROM users ORDER BY id ASC'
    )->fetchAll();
    $emails = [];
    $usernames = [];
    $identities = [];
    foreach ($rows as $row) {
        $userId = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);
        $email = strtolower(member_profiles_normalize_single((string)($row['email'] ?? '')));
        $sourceDisplayName = member_profiles_normalize_single(
            (string)($row['display_name'] ?? '')
        );
        $passwordHash = (string)($row['password_hash'] ?? '');
        try {
            $username = member_profiles_validate_username($sourceDisplayName);
        } catch (MemberProfileException $error) {
            throw new MemberProfileException(
                'A legacy account does not contain a valid source username.',
                'MEMBER_PROFILE_LEGACY_USERNAME_INVALID',
                409,
                $error
            );
        }
        if ($userId === false
            || (int)$userId < 1
            || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || isset($emails[$email])
            || isset($usernames[$username])
            || password_get_info($passwordHash)['algoName'] === 'unknown') {
            throw new MemberProfileException(
                'Legacy account identity is invalid, ambiguous, or duplicated.',
                'MEMBER_PROFILE_LEGACY_IDENTITY_AMBIGUOUS',
                409
            );
        }
        $emails[$email] = true;
        $usernames[$username] = true;
        $identities[] = [
            'user_id' => (int)$userId,
            'email' => $email,
            'source_display_name' => $sourceDisplayName,
            'username' => $username,
            'password_sha256' => strtoupper(hash('sha256', $passwordHash)),
        ];
    }
    return [
        'source_variant' => (string)$sourceVariant['id'],
        'users' => $identities,
    ];
}

function member_profiles_apply_legacy_identity_plan(PDO $pdo, array $plan): void
{
    $users = $plan['users'] ?? [];
    if (!is_array($users) || $users === []) return;
    $columns = array_fill_keys(member_profiles_table_columns($pdo, 'users'), true);
    if (!isset($columns['username'])) {
        throw new MemberProfileException(
            'The database update did not create the stable Username setting.',
            'MEMBER_PROFILE_USERNAME_OWNER_MISSING',
            500
        );
    }

    $transaction = database_transaction_begin($pdo, db_driver($pdo) === 'sqlite');
    try {
        $select = $pdo->prepare(
            'SELECT email, username, password_hash, display_name FROM users WHERE id = ? LIMIT 1'
            . (db_driver($pdo) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $updateUser = $pdo->prepare(
            'UPDATE users SET username = ?, display_name = ? '
            . 'WHERE id = ? AND (username IS NULL OR username = "")'
        );
        $updateParticipants = $pdo->prepare(
            'UPDATE participants SET display_name = ? WHERE user_id = ?'
        );
        foreach ($users as $identity) {
            if (!is_array($identity)) {
                throw new MemberProfileException(
                    'The frozen legacy identity plan is invalid.',
                    'MEMBER_PROFILE_LEGACY_PLAN_INVALID',
                    500
                );
            }
            $userId = (int)($identity['user_id'] ?? 0);
            $select->execute([$userId]);
            $current = $select->fetch();
            if (!$current
                || !hash_equals(
                    (string)($identity['email'] ?? ''),
                    strtolower((string)$current['email'])
                )
                || !hash_equals(
                    (string)($identity['source_display_name'] ?? ''),
                    (string)$current['display_name']
                )
                || !hash_equals(
                    (string)($identity['password_sha256'] ?? ''),
                    strtoupper(hash('sha256', (string)$current['password_hash']))
                )
                || trim((string)$current['username']) !== '') {
                throw new MemberProfileException(
                    'Legacy identity changed after preflight; no identity was adopted.',
                    'MEMBER_PROFILE_LEGACY_PLAN_DRIFT',
                    409
                );
            }
            $username = member_profiles_validate_username($identity['username'] ?? '');
            if (!member_profiles_namespace_available($pdo, $username, $userId)) {
                throw new MemberProfileException(
                    'Legacy Username conflicts with another account identity.',
                    'MEMBER_PROFILE_LEGACY_USERNAME_COLLISION',
                    409
                );
            }
            $updateUser->execute([$username, $username, $userId]);
            if ($updateUser->rowCount() !== 1) {
                throw new MemberProfileException(
                    'Legacy Username could not be adopted exactly once.',
                    'MEMBER_PROFILE_LEGACY_USERNAME_ADOPTION_FAILED',
                    409
                );
            }
            $updateParticipants->execute([$username, $userId]);
        }
        database_transaction_commit($pdo, $transaction);
    } catch (Throwable $error) {
        database_transaction_rollback($pdo, $transaction);
        throw $error;
    }
}

function member_profiles_effective_display_name(string $username, mixed $storedDisplayName): string
{
    $displayName = member_profiles_normalize_single((string)$storedDisplayName);
    return $displayName === '' || strtolower($displayName) === strtolower($username)
        ? $username
        : $displayName;
}

function member_profiles_has_custom_display_name(string $username, mixed $storedDisplayName): bool
{
    $displayName = member_profiles_normalize_single((string)$storedDisplayName);
    return $displayName !== '' && strtolower($displayName) !== strtolower($username);
}

function member_profiles_validate_fields(array $input): array
{
    $name = member_profiles_normalize_single((string)($input['name'] ?? ''));
    if ($name !== '' && (str_contains($name, '<') || str_contains($name, '>'))) {
        throw new MemberProfileException(
            'Name must be plain text without markup.',
            'MEMBER_PROFILE_NAME_MARKUP_INVALID'
        );
    }
    member_profiles_assert_length('Name', $name, MEMBER_PROFILE_NAME_MAX);

    $location = member_profiles_normalize_single((string)($input['location'] ?? ''));
    member_profiles_assert_length('Location', $location, MEMBER_PROFILE_LOCATION_MAX);

    $about = member_profiles_normalize_multiline((string)($input['about_me'] ?? ''));
    member_profiles_assert_length('About Me', $about, MEMBER_PROFILE_ABOUT_MAX);

    $publicEmail = member_profiles_normalize_single(
        (string)($input['public_contact_email'] ?? '')
    );
    member_profiles_assert_length(
        'Public profile contact email',
        $publicEmail,
        MEMBER_PROFILE_PUBLIC_EMAIL_MAX
    );
    if ($publicEmail !== '' && filter_var($publicEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new MemberProfileException(
            'Enter a valid public profile contact email.',
            'MEMBER_PROFILE_PUBLIC_EMAIL_INVALID'
        );
    }

    $website = member_profiles_normalize_single((string)($input['website'] ?? ''));
    member_profiles_assert_length('Website', $website, MEMBER_PROFILE_WEBSITE_MAX);
    if ($website !== '') {
        $parts = parse_url($website);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)
            || filter_var($website, FILTER_VALIDATE_URL) === false) {
            throw new MemberProfileException(
                'Website must be a complete HTTP or HTTPS address.',
                'MEMBER_PROFILE_WEBSITE_INVALID'
            );
        }
    }

    $interests = member_profiles_normalize_multiline((string)($input['interests'] ?? ''));
    member_profiles_assert_length('Interests', $interests, MEMBER_PROFILE_INTERESTS_MAX);

    return [
        'profile_name' => $name === '' ? null : $name,
        'location' => $location === '' ? null : $location,
        'about_me' => $about === '' ? null : $about,
        'public_contact_email' => $publicEmail === '' ? null : $publicEmail,
        'website' => $website === '' ? null : $website,
        'interests' => $interests === '' ? null : $interests,
    ];
}

function member_profiles_request_id(mixed $raw): string
{
    $value = trim((string)$raw);
    if (strlen($value) < 8 || strlen($value) > MEMBER_PROFILE_REQUEST_ID_MAX
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value)) {
        throw new MemberProfileException(
            'A valid profile request identity is required.',
            'MEMBER_PROFILE_REQUEST_ID_INVALID'
        );
    }
    return $value;
}

function member_profiles_table_columns(PDO $pdo, string $table): array
{
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) return [];
    if (db_driver($pdo) === 'mysql') {
        $stmt = $pdo->prepare(
            'SELECT column_name FROM information_schema.columns '
            . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
        );
        $stmt->execute([$table]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_map(
        static fn(array $row): string => (string)$row['name'],
        $pdo->query("PRAGMA table_info({$table})")->fetchAll()
    );
}

function member_profiles_table_exists(PDO $pdo, string $table): bool
{
    return member_profiles_table_columns($pdo, $table) !== [];
}

function member_profiles_public_identity_columns_present(PDO $pdo): bool
{
    $columns = array_fill_keys(member_profiles_table_columns($pdo, 'member_profiles'), true);
    return isset(
        $columns['discord_username'],
        $columns['discord_visible'],
        $columns['public_profile_id']
    );
}

function member_profiles_generate_public_profile_id(PDO $pdo): string
{
    $check = $pdo->prepare(
        'SELECT 1 FROM member_profiles WHERE public_profile_id = ? LIMIT 1'
    );
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = strtolower(uuid_v4());
        $check->execute([$candidate]);
        if ($check->fetchColumn() === false) return $candidate;
    }
    throw new MemberProfileException(
        'A unique public profile identity could not be reserved.',
        'MEMBER_PROFILE_PUBLIC_ID_RESERVATION_FAILED',
        500
    );
}

function member_profiles_ensure_public_profile_id(PDO $pdo, int $userId): string
{
    if ($userId < 1 || !member_profiles_public_identity_columns_present($pdo)) {
        throw new MemberProfileException(
            'The public profile identity owner is unavailable.',
            'MEMBER_PROFILE_PUBLIC_ID_OWNER_UNAVAILABLE',
            503
        );
    }
    $select = $pdo->prepare(
        'SELECT public_profile_id FROM member_profiles WHERE user_id = ? LIMIT 1'
    );
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $select->execute([$userId]);
        $current = $select->fetchColumn();
        if ($current !== false && trim((string)$current) !== '') {
            return member_profiles_validate_public_profile_id($current);
        }
        $candidate = member_profiles_generate_public_profile_id($pdo);
        try {
            $update = $pdo->prepare(
                'UPDATE member_profiles SET public_profile_id = ? '
                . 'WHERE user_id = ? AND (public_profile_id IS NULL OR public_profile_id = "")'
            );
            $update->execute([$candidate, $userId]);
        } catch (PDOException $error) {
            if ($attempt === 19) throw $error;
            continue;
        }
    }
    throw new MemberProfileException(
        'A stable public profile identity could not be established.',
        'MEMBER_PROFILE_PUBLIC_ID_BACKFILL_FAILED',
        500
    );
}

function member_profiles_install_public_identity_schema(PDO $pdo): void
{
    $columns = array_fill_keys(member_profiles_table_columns($pdo, 'member_profiles'), true);
    if (!isset($columns['discord_username'])) {
        $pdo->exec(
            'ALTER TABLE member_profiles ADD COLUMN discord_username '
            . (db_driver($pdo) === 'mysql'
                ? 'VARCHAR(32) DEFAULT NULL'
                : 'TEXT DEFAULT NULL')
        );
    }
    if (!isset($columns['discord_visible'])) {
        $pdo->exec(
            'ALTER TABLE member_profiles ADD COLUMN discord_visible '
            . (db_driver($pdo) === 'mysql'
                ? 'TINYINT(1) NOT NULL DEFAULT 0'
                : 'INTEGER NOT NULL DEFAULT 0')
        );
    }
    if (!isset($columns['public_profile_id'])) {
        $pdo->exec(
            'ALTER TABLE member_profiles ADD COLUMN public_profile_id '
            . (db_driver($pdo) === 'mysql'
                ? 'VARCHAR(36) DEFAULT NULL'
                : 'TEXT DEFAULT NULL')
        );
    }
    $pdo->exec(
        'UPDATE member_profiles SET discord_visible = 0 '
        . 'WHERE discord_username IS NULL OR discord_username = ""'
    );
    $userIds = array_map(
        'intval',
        $pdo->query('SELECT user_id FROM member_profiles ORDER BY user_id ASC')
            ->fetchAll(PDO::FETCH_COLUMN)
    );
    foreach ($userIds as $userId) {
        member_profiles_ensure_public_profile_id($pdo, $userId);
    }
    if (db_driver($pdo) === 'mysql') {
        $index = $pdo->query(
            "SELECT 1 FROM information_schema.statistics "
            . "WHERE table_schema = DATABASE() AND table_name = 'member_profiles' "
            . "AND index_name = 'uq_member_profiles_public_id' LIMIT 1"
        )->fetchColumn();
        if ($index === false) {
            $pdo->exec(
                'CREATE UNIQUE INDEX uq_member_profiles_public_id '
                . 'ON member_profiles(public_profile_id)'
            );
        }
    } else {
        $pdo->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_member_profiles_public_id '
            . 'ON member_profiles(public_profile_id)'
        );
    }
}

function member_profiles_validate_public_identity_schema(PDO $pdo): bool
{
    if (!member_profiles_public_identity_columns_present($pdo)) return false;
    if ((int)$pdo->query(
        'SELECT COUNT(*) FROM member_profiles '
        . 'WHERE public_profile_id IS NULL OR public_profile_id = "" '
        . 'OR discord_visible NOT IN (0, 1) '
        . 'OR ((discord_username IS NULL OR discord_username = "") AND discord_visible <> 0)'
    )->fetchColumn() !== 0) {
        return false;
    }
    $rows = $pdo->query(
        'SELECT public_profile_id, discord_username FROM member_profiles ORDER BY user_id ASC'
    )->fetchAll();
    $seen = [];
    foreach ($rows as $row) {
        try {
            $publicId = member_profiles_validate_public_profile_id(
                $row['public_profile_id'] ?? ''
            );
            member_profiles_validate_discord_username($row['discord_username'] ?? '');
        } catch (MemberProfileException) {
            return false;
        }
        if (isset($seen[$publicId])) return false;
        $seen[$publicId] = true;
    }
    return true;
}

function member_profiles_install_game_message_identity_snapshots(PDO $pdo): void
{
    if (!member_profiles_table_exists($pdo, 'game_chat_messages')) return;
    $columns = array_fill_keys(
        member_profiles_table_columns($pdo, 'game_chat_messages'),
        true
    );
    if (!isset($columns['user_id'])) {
        $pdo->exec(
            'ALTER TABLE game_chat_messages ADD COLUMN user_id '
            . (db_driver($pdo) === 'mysql' ? 'INT DEFAULT NULL' : 'INTEGER DEFAULT NULL')
        );
    }
    if (!isset($columns['display_name'])) {
        $pdo->exec(
            'ALTER TABLE game_chat_messages ADD COLUMN display_name '
            . (db_driver($pdo) === 'mysql' ? 'VARCHAR(191) DEFAULT NULL' : 'TEXT DEFAULT NULL')
        );
    }
    $pdo->exec(
        'UPDATE game_chat_messages SET user_id = ('
        . 'SELECT p.user_id FROM participants p '
        . 'WHERE p.id = game_chat_messages.participant_id'
        . ') WHERE user_id IS NULL'
    );
    $pdo->exec(
        'UPDATE game_chat_messages SET display_name = ('
        . 'SELECT p.display_name FROM participants p '
        . 'WHERE p.id = game_chat_messages.participant_id'
        . ') WHERE display_name IS NULL OR display_name = ""'
    );
}

function member_profiles_install_schema(PDO $pdo): void
{
    if (db_driver($pdo) === 'mysql') {
        $statements = [
            "CREATE TABLE IF NOT EXISTS member_identity_names (
                canonical_name VARCHAR(191) PRIMARY KEY,
                user_id INT NOT NULL,
                name_kind VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_member_identity_kind (user_id, name_kind),
                CONSTRAINT fk_member_identity_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS member_profiles (
                user_id INT PRIMARY KEY,
                profile_name VARCHAR(160) DEFAULT NULL,
                location VARCHAR(160) DEFAULT NULL,
                about_me TEXT DEFAULT NULL,
                public_contact_email VARCHAR(254) DEFAULT NULL,
                website VARCHAR(500) DEFAULT NULL,
                interests TEXT DEFAULT NULL,
                profile_version INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_member_profiles_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS member_display_name_history (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                former_display_name VARCHAR(80) NOT NULL,
                change_request_id VARCHAR(96) NOT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_member_display_history_request (user_id, change_request_id),
                INDEX idx_member_display_history_order (user_id, id),
                CONSTRAINT fk_member_display_history_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS member_profile_requests (
                user_id INT NOT NULL,
                request_id VARCHAR(96) NOT NULL,
                request_sha256 VARCHAR(64) NOT NULL,
                result_json TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, request_id),
                CONSTRAINT fk_member_profile_request_user FOREIGN KEY (user_id)
                    REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS member_deleted_username_uses (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                username_normalized VARCHAR(32) NOT NULL,
                deleted_identity_key VARCHAR(64) NOT NULL,
                recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_member_deleted_username_identity
                    (username_normalized, deleted_identity_key),
                INDEX idx_member_deleted_username_count (username_normalized)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    } else {
        $statements = [
            "CREATE TABLE IF NOT EXISTS member_identity_names (
                canonical_name TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                name_kind TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, name_kind),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS member_profiles (
                user_id INTEGER PRIMARY KEY,
                profile_name TEXT DEFAULT NULL,
                location TEXT DEFAULT NULL,
                about_me TEXT DEFAULT NULL,
                public_contact_email TEXT DEFAULT NULL,
                website TEXT DEFAULT NULL,
                interests TEXT DEFAULT NULL,
                profile_version INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS member_display_name_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                former_display_name TEXT NOT NULL,
                change_request_id TEXT NOT NULL,
                changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(user_id, change_request_id),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE INDEX IF NOT EXISTS idx_member_display_history_order
                ON member_display_name_history(user_id, id)",
            "CREATE TABLE IF NOT EXISTS member_profile_requests (
                user_id INTEGER NOT NULL,
                request_id TEXT NOT NULL,
                request_sha256 TEXT NOT NULL,
                result_json TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(user_id, request_id),
                FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
            )",
            "CREATE TABLE IF NOT EXISTS member_deleted_username_uses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username_normalized TEXT NOT NULL,
                deleted_identity_key TEXT NOT NULL,
                recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(username_normalized, deleted_identity_key)
            )",
            "CREATE INDEX IF NOT EXISTS idx_member_deleted_username_count
                ON member_deleted_username_uses(username_normalized)",
        ];
    }
    foreach ($statements as $statement) $pdo->exec($statement);
    member_profiles_install_game_message_identity_snapshots($pdo);
    member_profiles_backfill($pdo);
}

function member_profiles_namespace_available(
    PDO $pdo,
    string $value,
    ?int $excludeUserId = null
): bool {
    if (member_profiles_table_exists($pdo, 'member_identity_names')) {
        $sql = 'SELECT user_id FROM member_identity_names WHERE canonical_name = LOWER(?)';
        $params = [$value];
        if ($excludeUserId !== null) {
            $sql .= ' AND user_id <> ?';
            $params[] = $excludeUserId;
        }
        $sql .= ' LIMIT 1';
        $mapped = $pdo->prepare($sql);
        $mapped->execute($params);
        if ($mapped->fetchColumn() !== false) return false;
    }
    $sql = 'SELECT id FROM users WHERE '
        . '(LOWER(COALESCE(username, "")) = LOWER(?) OR LOWER(display_name) = LOWER(?))';
    $params = [$value, $value];
    if ($excludeUserId !== null) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return !$stmt->fetchColumn();
}

function member_profiles_validate_identity(
    PDO $pdo,
    mixed $usernameRaw,
    mixed $displayNameRaw,
    ?int $excludeUserId = null
): array {
    $username = member_profiles_validate_username($usernameRaw);
    $requestedDisplayName = member_profiles_validate_display_name($displayNameRaw);
    $displayName = $requestedDisplayName === '' ? $username : $requestedDisplayName;
    if ($requestedDisplayName !== '') {
        if (strtolower($username) === strtolower($displayName)) {
            throw new MemberProfileException(
                'A custom Display name must be different from the Username.',
                'MEMBER_PROFILE_IDENTITY_NAMES_DISTINCT',
                409
            );
        }
        member_profiles_assert_effective_length(
            $pdo,
            'display_name',
            'Display name',
            $displayName
        );
    }
    $names = [$username => 'Username'];
    if ($requestedDisplayName !== '') $names[$displayName] = 'Display name';
    foreach ($names as $value => $label) {
        if (!member_profiles_namespace_available($pdo, $value, $excludeUserId)) {
            throw new MemberProfileException(
                $label . ' is already in use as a Username or Display name.',
                'MEMBER_PROFILE_IDENTITY_NAME_TAKEN',
                409
            );
        }
    }
    return ['username' => $username, 'display_name' => $displayName];
}

function member_profiles_backfill_username(PDO $pdo, int $userId): string
{
    $display = $pdo->prepare('SELECT display_name FROM users WHERE id = ? LIMIT 1');
    $display->execute([$userId]);
    $displayName = strtolower((string)$display->fetchColumn());
    $candidate = 'user' . $userId;
    $suffix = 0;
    while (strtolower($candidate) === $displayName
        || !member_profiles_namespace_available($pdo, $candidate, $userId)) {
        $suffix++;
        $candidate = 'user' . $userId . '-' . $suffix;
    }
    $pdo->prepare('UPDATE users SET username = ? WHERE id = ? AND (username IS NULL OR username = "")')
        ->execute([$candidate, $userId]);
    return $candidate;
}

function member_profiles_sync_identity_names(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT username, display_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new MemberProfileException(
            'The member account is unavailable.',
            'MEMBER_PROFILE_ACCOUNT_NOT_FOUND',
            404
        );
    }
    $username = member_profiles_validate_username($row['username']);
    $displayName = member_profiles_validate_display_name($row['display_name']);
    $hasCustomDisplayName = member_profiles_has_custom_display_name($username, $displayName);
    if ($displayName === '') {
        $displayName = $username;
        $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')
            ->execute([$displayName, $userId]);
        $pdo->prepare('UPDATE participants SET display_name = ? WHERE user_id = ?')
            ->execute([$displayName, $userId]);
    }
    $pdo->prepare('DELETE FROM member_identity_names WHERE user_id = ?')->execute([$userId]);
    $insert = $pdo->prepare(
        'INSERT INTO member_identity_names (canonical_name, user_id, name_kind) VALUES (?,?,?)'
    );
    $insert->execute([strtolower($username), $userId, 'username']);
    if ($hasCustomDisplayName) {
        $insert->execute([strtolower($displayName), $userId, 'display_name']);
    }
}

function member_profiles_initialize_user(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $username = $stmt->fetchColumn();
    if ($username === false) {
        throw new MemberProfileException(
            'The member account is unavailable.',
            'MEMBER_PROFILE_ACCOUNT_NOT_FOUND',
            404
        );
    }
    if (trim((string)$username) === '') member_profiles_backfill_username($pdo, $userId);
    $sql = db_driver($pdo) === 'mysql'
        ? 'INSERT IGNORE INTO member_profiles (user_id) VALUES (?)'
        : 'INSERT OR IGNORE INTO member_profiles (user_id) VALUES (?)';
    $pdo->prepare($sql)->execute([$userId]);
    if (member_profiles_public_identity_columns_present($pdo)) {
        member_profiles_ensure_public_profile_id($pdo, $userId);
    }
    member_profiles_sync_identity_names($pdo, $userId);
}

function member_profiles_emit_identity_update(
    PDO $pdo,
    int $userId,
    string $username,
    string $displayName
): void {
    $participants = $pdo->prepare(
        'SELECT id, session_id FROM participants WHERE user_id = ? ORDER BY id ASC'
    );
    $participants->execute([$userId]);
    foreach ($participants->fetchAll() as $participant) {
        emit_event($pdo, (int)$participant['session_id'], 'participant_identity', [
            'participant_id' => (int)$participant['id'],
            'user_id' => $userId,
            'display_name' => member_profiles_effective_display_name(
                $username,
                $displayName
            ),
        ]);
    }
}

function member_profiles_import_identity(
    PDO $pdo,
    int $userId,
    mixed $usernameRaw,
    mixed $displayNameRaw
): void {
    member_profiles_initialize_user($pdo, $userId);
    $current = member_profiles_row($pdo, $userId, true);
    if (!$current) {
        throw new MemberProfileException(
            'The member account is unavailable.',
            'MEMBER_PROFILE_ACCOUNT_NOT_FOUND',
            404
        );
    }
    $currentUsername = member_profiles_validate_username($current['username']);
    $requestedUsername = trim((string)$usernameRaw) === ''
        ? $currentUsername
        : member_profiles_validate_username($usernameRaw);
    if (!hash_equals($currentUsername, $requestedUsername)) {
        throw new MemberProfileException(
            'Portable import cannot change an existing stable Username.',
            'MEMBER_PROFILE_USERNAME_IMMUTABLE',
            409
        );
    }
    $requestedDisplayName = member_profiles_validate_display_name($displayNameRaw);
    $displayName = $requestedDisplayName === '' ? $currentUsername : $requestedDisplayName;
    if ($requestedDisplayName !== '') {
        member_profiles_assert_effective_length(
            $pdo,
            'display_name',
            'Display name',
            $displayName
        );
    }
    if ($requestedDisplayName !== ''
        && !member_profiles_namespace_available($pdo, $displayName, $userId)) {
        throw new MemberProfileException(
            'Display name is already in use as a Username or Display name.',
            'MEMBER_PROFILE_IDENTITY_NAME_TAKEN',
            409
        );
    }
    if (hash_equals((string)$current['display_name'], $displayName)) {
        member_profiles_sync_identity_names($pdo, $userId);
        return;
    }
    $requestId = 'portable-import-' . bin2hex(random_bytes(24));
    if (member_profiles_has_custom_display_name(
        $currentUsername,
        (string)$current['display_name']
    )) {
        $pdo->prepare(
            'INSERT INTO member_display_name_history '
            . '(user_id, former_display_name, change_request_id) VALUES (?,?,?)'
        )->execute([$userId, (string)$current['display_name'], $requestId]);
    }
    $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')
        ->execute([$displayName, $userId]);
    $pdo->prepare('UPDATE participants SET display_name = ? WHERE user_id = ?')
        ->execute([$displayName, $userId]);
    member_profiles_emit_identity_update(
        $pdo,
        $userId,
        $currentUsername,
        $displayName
    );
    $pdo->prepare(
        'UPDATE member_profiles SET profile_version = profile_version + 1, '
        . 'updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
    )->execute([$userId]);
    member_profiles_sync_identity_names($pdo, $userId);
}

function member_profiles_import_public_identity(
    PDO $pdo,
    int $userId,
    mixed $publicProfileIdRaw,
    mixed $discordUsernameRaw,
    mixed $discordVisibleRaw,
    bool $newAccount,
    int $portableVersion
): void {
    member_profiles_initialize_user($pdo, $userId);
    if ($portableVersion < 3) {
        if ($newAccount) {
            $pdo->prepare(
                'UPDATE member_profiles SET discord_username = NULL, discord_visible = 0 '
                . 'WHERE user_id = ?'
            )->execute([$userId]);
        }
        return;
    }
    $publicProfileId = member_profiles_validate_public_profile_id($publicProfileIdRaw);
    $discordUsername = member_profiles_validate_discord_username($discordUsernameRaw);
    $discordVisible = member_profiles_boolean(
        $discordVisibleRaw,
        'Discord visibility'
    ) && $discordUsername !== '';
    $collision = $pdo->prepare(
        'SELECT user_id FROM member_profiles '
        . 'WHERE public_profile_id = ? AND user_id <> ? LIMIT 1'
    );
    $collision->execute([$publicProfileId, $userId]);
    if ($collision->fetchColumn() !== false) {
        throw new MemberProfileException(
            'Portable import contains a public profile identity collision.',
            'MEMBER_PROFILE_PUBLIC_ID_COLLISION',
            409
        );
    }
    $current = $pdo->prepare(
        'SELECT public_profile_id, discord_visible '
        . 'FROM member_profiles WHERE user_id = ? LIMIT 1'
    );
    $current->execute([$userId]);
    $currentProfile = $current->fetch() ?: [];
    $currentPublicId = (string)($currentProfile['public_profile_id'] ?? '');
    if (!$newAccount
        && !hash_equals(
            member_profiles_validate_public_profile_id($currentPublicId),
            $publicProfileId
        )) {
        throw new MemberProfileException(
            'Portable import cannot change an existing public profile identity.',
            'MEMBER_PROFILE_PUBLIC_ID_IMMUTABLE',
            409
        );
    }
    if (!$newAccount && empty($currentProfile['discord_visible'])) {
        $discordVisible = false;
    }
    $pdo->prepare(
        'UPDATE member_profiles SET public_profile_id = ?, discord_username = ?, '
        . 'discord_visible = ?, updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
    )->execute([
        $publicProfileId,
        $discordUsername === '' ? null : $discordUsername,
        $discordVisible ? 1 : 0,
        $userId,
    ]);
}

function member_profiles_backfill(PDO $pdo): void
{
    $columns = array_fill_keys(member_profiles_table_columns($pdo, 'users'), true);
    $select = ['id', 'username'];
    foreach (['profile_location', 'profile_about'] as $legacy) {
        if (isset($columns[$legacy])) $select[] = $legacy;
    }
    $rows = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM users ORDER BY id ASC')
        ->fetchAll();
    foreach ($rows as $row) {
        $userId = (int)$row['id'];
        if (trim((string)($row['username'] ?? '')) === '') {
            member_profiles_backfill_username($pdo, $userId);
        }
        $location = trim((string)($row['profile_location'] ?? ''));
        $about = trim((string)($row['profile_about'] ?? ''));
        $sql = db_driver($pdo) === 'mysql'
            ? 'INSERT IGNORE INTO member_profiles (user_id, location, about_me) VALUES (?,?,?)'
            : 'INSERT OR IGNORE INTO member_profiles (user_id, location, about_me) VALUES (?,?,?)';
        $pdo->prepare($sql)->execute([
            $userId,
            $location === '' ? null : $location,
            $about === '' ? null : $about,
        ]);
        member_profiles_sync_identity_names($pdo, $userId);
    }
}

function member_profiles_validate_schema(PDO $pdo): bool
{
    $required = [
        'member_identity_names' => [
            'canonical_name', 'user_id', 'name_kind', 'created_at',
        ],
        'member_profiles' => [
            'user_id', 'profile_name', 'location', 'about_me',
            'public_contact_email', 'website', 'interests', 'profile_version',
            'created_at', 'updated_at',
        ],
        'member_display_name_history' => [
            'id', 'user_id', 'former_display_name', 'change_request_id', 'changed_at',
        ],
        'member_profile_requests' => [
            'user_id', 'request_id', 'request_sha256', 'result_json', 'created_at',
        ],
        'member_deleted_username_uses' => [
            'id', 'username_normalized', 'deleted_identity_key', 'recorded_at',
        ],
        'game_chat_messages' => [
            'id', 'lobby_code', 'participant_id', 'user_id', 'display_name',
            'content', 'sent_at',
        ],
    ];
    foreach ($required as $table => $columns) {
        $present = array_fill_keys(member_profiles_table_columns($pdo, $table), true);
        foreach ($columns as $column) {
            if (!isset($present[$column])) return false;
        }
    }
    if ((int)$pdo->query(
        'SELECT COUNT(*) FROM users u LEFT JOIN member_profiles p ON p.user_id = u.id '
        . 'WHERE p.user_id IS NULL OR u.username IS NULL OR u.username = ""'
    )->fetchColumn() !== 0) return false;
    $expectedIdentityNames = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()
        + (int)$pdo->query(
            'SELECT COUNT(*) FROM users WHERE display_name <> "" '
            . 'AND LOWER(display_name) <> LOWER(username)'
        )->fetchColumn();
    if ((int)$pdo->query(
        'SELECT COUNT(*) FROM member_identity_names'
    )->fetchColumn() !== $expectedIdentityNames) {
        return false;
    }
    $collision = $pdo->query(
        'SELECT canonical_name FROM ('
        . 'SELECT LOWER(username) AS canonical_name, id AS user_id FROM users '
        . 'UNION ALL SELECT LOWER(display_name) AS canonical_name, id AS user_id FROM users'
        . ') names GROUP BY canonical_name HAVING COUNT(DISTINCT user_id) > 1 LIMIT 1'
    )->fetchColumn();
    return $collision === false;
}

function member_profiles_history(PDO $pdo, int $userId, string $currentDisplayName): array
{
    $stmt = $pdo->prepare(
        'SELECT former_display_name, changed_at FROM member_display_name_history '
        . 'WHERE user_id = ? ORDER BY id ASC'
    );
    $stmt->execute([$userId]);
    $seen = [];
    $history = [];
    foreach ($stmt->fetchAll() as $row) {
        $value = (string)$row['former_display_name'];
        $key = strtolower($value);
        if ($key === strtolower($currentDisplayName) || isset($seen[$key])) continue;
        $seen[$key] = true;
        $history[] = [
            'displayName' => $value,
            'changedAt' => (string)$row['changed_at'],
        ];
    }
    return $history;
}

function member_profiles_deleted_username_count(PDO $pdo, string $username): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT deleted_identity_key) '
        . 'FROM member_deleted_username_uses WHERE username_normalized = ?'
    );
    $stmt->execute([strtolower($username)]);
    return max(0, (int)$stmt->fetchColumn());
}

function member_profiles_record_deleted_username_use(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $username = $stmt->fetchColumn();
    if ($username === false) {
        throw new MemberProfileException(
            'The member account is unavailable.',
            'MEMBER_PROFILE_ACCOUNT_NOT_FOUND',
            404
        );
    }
    $normalized = member_profiles_validate_username($username);
    $identityKey = strtoupper(hash(
        'sha256',
        'deleted-member:' . $userId . ':' . $normalized
    ));
    $sql = db_driver($pdo) === 'mysql'
        ? 'INSERT IGNORE INTO member_deleted_username_uses '
            . '(username_normalized, deleted_identity_key) VALUES (?,?)'
        : 'INSERT OR IGNORE INTO member_deleted_username_uses '
            . '(username_normalized, deleted_identity_key) VALUES (?,?)';
    $pdo->prepare($sql)->execute([$normalized, $identityKey]);
}

function member_profiles_user_id_for_public_profile_id(
    PDO $pdo,
    mixed $publicProfileIdRaw
): int {
    $publicProfileId = member_profiles_validate_public_profile_id($publicProfileIdRaw);
    $stmt = $pdo->prepare(
        'SELECT user_id FROM member_profiles WHERE public_profile_id = ? LIMIT 1'
    );
    $stmt->execute([$publicProfileId]);
    $userId = (int)($stmt->fetchColumn() ?: 0);
    if ($userId < 1) {
        throw new MemberProfileException(
            'That member profile is unavailable.',
            'MEMBER_PROFILE_NOT_FOUND',
            404
        );
    }
    return $userId;
}

function member_profiles_row(PDO $pdo, int $userId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT u.id, u.username, u.display_name, u.avatar_path, '
        . 'u.avatar_source_width_px, u.avatar_source_height_px, '
        . 'u.avatar_orientation, u.avatar_display_size_px, u.avatar_size_version, '
        . 'u.created_at, '
        . 'p.profile_name, p.location, p.about_me, p.public_contact_email, '
        . 'p.website, p.interests, p.discord_username, p.discord_visible, '
        . 'p.public_profile_id, p.profile_version, p.updated_at '
        . 'FROM users u JOIN member_profiles p ON p.user_id = u.id WHERE u.id = ? LIMIT 1';
    if ($forUpdate && db_driver($pdo) === 'mysql') $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function member_profiles_projection(PDO $pdo, int $viewerUserId, int $targetUserId): array
{
    if (function_exists('account_deletion_is_deleted')
        && account_deletion_is_deleted($pdo, $targetUserId)) {
        throw new MemberProfileException(
            'That member profile is unavailable.',
            'MEMBER_PROFILE_NOT_FOUND',
            404
        );
    }
    $row = member_profiles_row($pdo, $targetUserId);
    if (!$row) {
        throw new MemberProfileException(
            'That member profile is unavailable.',
            'MEMBER_PROFILE_NOT_FOUND',
            404
        );
    }
    $username = (string)$row['username'];
    $storedDisplayName = (string)$row['display_name'];
    $hasCustomDisplayName = member_profiles_has_custom_display_name(
        $username,
        $storedDisplayName
    );
    $displayName = $hasCustomDisplayName ? $storedDisplayName : null;
    $effectiveDisplayName = member_profiles_effective_display_name(
        $username,
        $storedDisplayName
    );
    $avatar = avatar_visibility_project_payload($pdo, $viewerUserId, [
        'user_id' => $targetUserId,
        'avatar_path' => (string)($row['avatar_path'] ?? 'preset:Default'),
        'avatar_url' => resolve_avatar((string)($row['avatar_path'] ?? 'preset:Default')),
    ]);
    $avatarHidden = !empty($avatar['avatar_hidden']);
    $avatarSize = avatar_size_preferences_public($pdo, $row);
    $avatarPolicy = avatar_size_policy($pdo);
    $sourceWidth = isset($row['avatar_source_width_px'])
        ? max(1, (int)$row['avatar_source_width_px'])
        : null;
    $sourceHeight = isset($row['avatar_source_height_px'])
        ? max(1, (int)$row['avatar_source_height_px'])
        : null;
    $count = member_profiles_deleted_username_count($pdo, $username);
    $publicProfileId = member_profiles_validate_public_profile_id(
        $row['public_profile_id'] ?? ''
    );
    $discordUsername = !empty($row['discord_visible'])
        ? member_profiles_validate_discord_username($row['discord_username'] ?? '')
        : '';
    $profile = [
        'publicProfileId' => $publicProfileId,
        'profileUrl' => app_url('/profile.php?id=' . rawurlencode($publicProfileId)),
        'displayName' => $displayName,
        'effectiveDisplayName' => $effectiveDisplayName,
        'location' => $row['location'],
        'aboutMe' => $row['about_me'],
        'publicContactEmail' => $row['public_contact_email'],
        'website' => $row['website'],
        'interests' => $row['interests'],
        'registeredAt' => (string)$row['created_at'],
        'previousDisplayNames' => member_profiles_history(
            $pdo,
            $targetUserId,
            $effectiveDisplayName
        ),
        'priorUsernameUseCount' => $count,
        'priorUsernameUseWarning' => $count === 0
            ? null
            : ($count === 1
                ? 'This Username was previously used by 1 deleted account. This member may not be the original holder.'
                : 'This Username was previously used by ' . $count . ' deleted accounts. This member may not be the original holder.'),
        'avatarUrl' => $avatar['avatar_url'] ?? null,
        'avatarHidden' => $avatarHidden,
        'avatarHiddenNotice' => $avatar['avatar_hidden_notice'] ?? null,
        'avatarMissing' => trim((string)($row['avatar_path'] ?? '')) === '',
        'avatarSourceWidthPx' => $avatarHidden ? null : $sourceWidth,
        'avatarSourceHeightPx' => $avatarHidden ? null : $sourceHeight,
        'avatarDisplayMaxEdgePx' => $avatarHidden
            ? (int)$avatarPolicy['avatarDisplayMaxPx']
            : (int)$avatarSize['effectiveAvatarDisplayMaxPx'],
        'avatarOrientation' => $avatarHidden
            ? 'original'
            : avatar_orientation_normalize($row['avatar_orientation'] ?? null),
        'isSelf' => $viewerUserId === $targetUserId,
    ];
    if ($discordUsername !== '') $profile['discordUsername'] = $discordUsername;
    if ($row['profile_name'] !== null && trim((string)$row['profile_name']) !== '') {
        $profile['name'] = (string)$row['profile_name'];
    }
    return $profile;
}

function member_profiles_editor_projection(PDO $pdo, int $userId): array
{
    $profile = member_profiles_projection($pdo, $userId, $userId);
    $row = member_profiles_row($pdo, $userId);
    if (!$row) {
        throw new MemberProfileException(
            'The member profile is unavailable.',
            'MEMBER_PROFILE_NOT_FOUND',
            404
        );
    }
    $profile['username'] = (string)$row['username'];
    $profile['discordUsername'] = (string)($row['discord_username'] ?? '');
    $profile['discordVisible'] = !empty($row['discord_visible'])
        && trim((string)($row['discord_username'] ?? '')) !== '';
    $profile['profileVersion'] = max(1, (int)$row['profile_version']);
    $profile['fieldLimits'] = member_profiles_effective_limits($pdo);
    $profile['fieldLimits']['discord_username'] = MEMBER_PROFILE_DISCORD_USERNAME_MAX;
    return $profile;
}

function member_profiles_begin_write(PDO $pdo): bool
{
    if ($pdo->inTransaction()) return false;
    if (db_driver($pdo) === 'mysql') $pdo->beginTransaction();
    else $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
    return true;
}

function member_profiles_lock_account_for_update(PDO $pdo, int $userId): void
{
    if (db_driver($pdo) !== 'mysql') return;
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$userId]);
    if ($stmt->fetchColumn() === false) {
        throw new MemberProfileException(
            'The member account is unavailable.',
            'MEMBER_PROFILE_ACCOUNT_NOT_FOUND',
            404
        );
    }
}

function member_profiles_update(
    PDO $pdo,
    int $actorUserId,
    int $targetUserId,
    array $input,
    mixed $expectedVersionRaw,
    mixed $requestIdRaw
): array {
    if ($actorUserId < 1 || $targetUserId < 1 || $actorUserId !== $targetUserId) {
        throw new MemberProfileException(
            'You may edit only your own public profile.',
            'MEMBER_PROFILE_EDIT_FORBIDDEN',
            403
        );
    }
    $userId = $targetUserId;
    $requestId = member_profiles_request_id($requestIdRaw);
    $expectedVersion = filter_var($expectedVersionRaw, FILTER_VALIDATE_INT);
    if ($expectedVersion === false || (int)$expectedVersion < 1) {
        throw new MemberProfileException(
            'Profile version is required. Refresh and try again.',
            'MEMBER_PROFILE_VERSION_REQUIRED',
            409
        );
    }
    $allowed = [
        'display_name', 'name', 'location', 'about_me',
        'public_contact_email', 'website', 'interests',
        'discord_username', 'discord_visible',
    ];
    $unknown = array_diff(array_keys($input), $allowed);
    if ($unknown !== []) {
        throw new MemberProfileException(
            'The profile request contains unsupported fields.',
            'MEMBER_PROFILE_FIELD_NOT_ALLOWED',
            400
        );
    }
    foreach ($allowed as $field) {
        if (!array_key_exists($field, $input)) {
            throw new MemberProfileException(
                'The complete editable profile field set is required.',
                'MEMBER_PROFILE_FIELD_SET_INCOMPLETE',
                400
            );
        }
    }
    $requestedDisplayName = member_profiles_validate_display_name($input['display_name']);
    $fields = member_profiles_validate_fields($input);
    $discordUsername = member_profiles_validate_discord_username(
        $input['discord_username']
    );
    $discordVisible = member_profiles_boolean(
        $input['discord_visible'],
        'Discord visibility'
    ) && $discordUsername !== '';
    $fields['discord_username'] = $discordUsername === '' ? null : $discordUsername;
    $fields['discord_visible'] = $discordVisible ? 1 : 0;
    $fingerprintInput = ['expected_version' => (int)$expectedVersion, 'fields' => $input];
    ksort($fingerprintInput['fields'], SORT_STRING);
    $requestSha = strtoupper(hash(
        'sha256',
        (string)json_encode($fingerprintInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    ));

    $ownsTransaction = member_profiles_begin_write($pdo);
    try {
        member_profiles_lock_account_for_update($pdo, $userId);
        member_profiles_initialize_user($pdo, $userId);
        $current = member_profiles_row($pdo, $userId, true);
        if (!$current) {
            throw new MemberProfileException(
                'The member profile is unavailable.',
                'MEMBER_PROFILE_NOT_FOUND',
                404
            );
        }
        $priorRequest = $pdo->prepare(
            'SELECT request_sha256, result_json FROM member_profile_requests '
            . 'WHERE user_id = ? AND request_id = ? LIMIT 1'
            . (db_driver($pdo) === 'mysql' ? ' FOR UPDATE' : '')
        );
        $priorRequest->execute([$userId, $requestId]);
        $replay = $priorRequest->fetch();
        if ($replay) {
            if (!hash_equals((string)$replay['request_sha256'], $requestSha)) {
                throw new MemberProfileException(
                    'That request identity was already used for different profile changes.',
                    'MEMBER_PROFILE_REQUEST_REUSED',
                    409
                );
            }
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
            $result = json_decode((string)$replay['result_json'], true);
            return [
                'ok' => true,
                'idempotentReplay' => true,
                'changedFields' => is_array($result['changed_fields'] ?? null)
                    ? $result['changed_fields']
                    : [],
                'profile' => member_profiles_editor_projection($pdo, $userId),
            ];
        }
        $currentVersion = max(1, (int)$current['profile_version']);
        if ((int)$expectedVersion !== $currentVersion) {
            throw new MemberProfileException(
                'Your public profile changed in another window. Refresh and review before saving.',
                'MEMBER_PROFILE_STALE_WRITE',
                409
            );
        }
        $username = member_profiles_validate_username($current['username']);
        $displayName = $requestedDisplayName === '' ? $username : $requestedDisplayName;
        if ($requestedDisplayName !== ''
            && !member_profiles_namespace_available($pdo, $displayName, $userId)) {
            throw new MemberProfileException(
                'Display name is already in use as a Username or Display name.',
                'MEMBER_PROFILE_IDENTITY_NAME_TAKEN',
                409
            );
        }
        $changed = [];
        $columnMap = [
            'name' => 'profile_name',
            'location' => 'location',
            'about_me' => 'about_me',
            'public_contact_email' => 'public_contact_email',
            'website' => 'website',
            'interests' => 'interests',
            'discord_username' => 'discord_username',
            'discord_visible' => 'discord_visible',
        ];
        foreach ($columnMap as $publicField => $column) {
            if ($column === 'discord_visible') {
                if ((int)($current[$column] ?? 0) !== (int)$fields[$column]) {
                    $changed[] = $publicField;
                }
            } elseif (($current[$column] ?? null) !== $fields[$column]) {
                $changed[] = $publicField;
            }
        }
        $displayChanged = !hash_equals(
            (string)$current['display_name'],
            $displayName
        );
        if ($displayChanged) $changed[] = 'display_name';
        sort($changed, SORT_STRING);
        $effectiveValues = [
            'display_name' => $requestedDisplayName,
            'name' => (string)($fields['profile_name'] ?? ''),
            'location' => (string)($fields['location'] ?? ''),
            'about_me' => (string)($fields['about_me'] ?? ''),
            'public_contact_email' => (string)($fields['public_contact_email'] ?? ''),
            'website' => (string)($fields['website'] ?? ''),
            'interests' => (string)($fields['interests'] ?? ''),
            'discord_username' => (string)($fields['discord_username'] ?? ''),
            'discord_visible' => $fields['discord_visible'] ? 'visible' : 'hidden',
        ];
        $labels = [
            'display_name' => 'Display name',
            'name' => 'Name',
            'location' => 'Location',
            'about_me' => 'About Me',
            'public_contact_email' => 'Public profile contact email',
            'website' => 'Website',
            'interests' => 'Interests',
            'discord_username' => 'Discord username',
            'discord_visible' => 'Discord visibility',
        ];
        foreach ($changed as $changedField) {
            if ($changedField === 'discord_username') {
                member_profiles_assert_length(
                    $labels[$changedField],
                    $effectiveValues[$changedField],
                    MEMBER_PROFILE_DISCORD_USERNAME_MAX
                );
            } elseif ($changedField !== 'discord_visible') {
                member_profiles_assert_effective_length(
                    $pdo,
                    $changedField,
                    $labels[$changedField],
                    $effectiveValues[$changedField]
                );
            }
        }
        $nextVersion = $changed === [] ? $currentVersion : $currentVersion + 1;
        if ($changed !== []) {
            if ($displayChanged) {
                if (member_profiles_has_custom_display_name(
                    $username,
                    (string)$current['display_name']
                )) {
                    $pdo->prepare(
                        'INSERT INTO member_display_name_history '
                        . '(user_id, former_display_name, change_request_id) VALUES (?,?,?)'
                    )->execute([$userId, (string)$current['display_name'], $requestId]);
                }
                $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                    ->execute([$displayName, $userId]);
                member_profiles_sync_identity_names($pdo, $userId);
                $pdo->prepare('UPDATE participants SET display_name = ? WHERE user_id = ?')
                    ->execute([$displayName, $userId]);
                member_profiles_emit_identity_update(
                    $pdo,
                    $userId,
                    $username,
                    $displayName
                );
            }
            $pdo->prepare(
                'UPDATE member_profiles SET profile_name = ?, location = ?, about_me = ?, '
                . 'public_contact_email = ?, website = ?, interests = ?, '
                . 'discord_username = ?, discord_visible = ?, profile_version = ?, '
                . 'updated_at = CURRENT_TIMESTAMP WHERE user_id = ?'
            )->execute([
                $fields['profile_name'],
                $fields['location'],
                $fields['about_me'],
                $fields['public_contact_email'],
                $fields['website'],
                $fields['interests'],
                $fields['discord_username'],
                $fields['discord_visible'],
                $nextVersion,
                $userId,
            ]);
            log_tool(
                $pdo,
                $userId,
                'member_profile_update',
                $userId,
                null,
                (string)json_encode([
                    'changed_fields' => $changed,
                    'profile_version' => $nextVersion,
                    'request_id' => $requestId,
                ], JSON_UNESCAPED_SLASHES)
            );
        }
        $resultRecord = [
            'changed_fields' => $changed,
            'profile_version' => $nextVersion,
            'no_op' => $changed === [],
        ];
        $pdo->prepare(
            'INSERT INTO member_profile_requests '
            . '(user_id, request_id, request_sha256, result_json) VALUES (?,?,?,?)'
        )->execute([
            $userId,
            $requestId,
            $requestSha,
            (string)json_encode($resultRecord, JSON_UNESCAPED_SLASHES),
        ]);
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        return [
            'ok' => true,
            'idempotentReplay' => false,
            'noOp' => $changed === [],
            'changedFields' => $changed,
            'profile' => member_profiles_editor_projection($pdo, $userId),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        if ($error instanceof MemberProfileException) throw $error;
        throw new MemberProfileException(
            'The public profile could not be saved.',
            'MEMBER_PROFILE_UPDATE_FAILED',
            500,
            $error
        );
    }
}
