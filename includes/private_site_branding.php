<?php
declare(strict_types=1);

const PRIVATE_SITE_BRANDING_ID = 'private-site-branding';
const PRIVATE_SITE_BRANDING_REMINDER_DEFAULT = 'Branding changes must continue to follow the original ChatSpace Community Edition license. Review the original license before publishing or deploying changes, and preserve all notices required by that license.';
const PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT = 'Modified by exe';

function private_site_branding_extension_adapter(): array {
    return [
        'id' => PRIVATE_SITE_BRANDING_ID,
        'projection' => 'private_site_branding_projection',
        'pageTitle' => 'private_site_branding_page_title',
        'utilityLinks' => 'private_site_branding_utility_links',
    ];
}

function private_site_branding_setting_defaults(): array {
    return [
        'community_name' => '',
        'community_logo_path' => '',
        'extension.private-site-branding.license_reminder' => PRIVATE_SITE_BRANDING_REMINDER_DEFAULT,
        'extension.private-site-branding.login_name_override' => '',
        'extension.private-site-branding.registration_name_override' => '',
        'extension.private-site-branding.recovery_name_override' => '',
        'extension.private-site-branding.lobby_name_override' => '',
        'extension.private-site-branding.room_name_override' => '',
        'extension.private-site-branding.other_name_override' => '',
        'extension.private-site-branding.room_version_attribution' => PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT,
        'extension.private-site-branding.show_changelog_login' => '1',
    ];
}

function private_site_branding_subsection_order(string $subsectionId): int {
    return [
        'shared-branding' => 10,
        'login-page' => 20,
        'registration-page' => 30,
        'account-recovery-page' => 40,
        'lobby-page' => 50,
        'chat-room' => 60,
        'setup-update-pages' => 70,
        'maintenance-error-pages' => 80,
        'about-legal-page' => 90,
        'other-detected-branding' => 100,
    ][$subsectionId] ?? 999;
}

function private_site_branding_setting_definitions(): array {
    $shared = [
        'extensionId' => PRIVATE_SITE_BRANDING_ID,
        'categoryId' => 'private-site-branding',
        'owner' => 'private_site_branding',
        'setupVisible' => true,
        'adminVisible' => true,
        'controlClass' => 'optional',
        'previewPage' => 'Shared destinations',
        'previewField' => 'Effective community identity',
        'previewPath' => '/login.php',
        'standardFallback' => 'ChatSpace Community Edition',
    ];
    $override = static function (
        string $id,
        string $settingKey,
        string $subsectionId,
        string $subsectionLabel,
        string $label,
        string $previewPage,
        string $previewField,
        string $previewPath,
        int $order
    ) use ($shared): array {
        return array_replace($shared, [
            'id' => $id,
            'settingKey' => $settingKey,
            'subsectionId' => $subsectionId,
            'subsectionLabel' => $subsectionLabel,
            'subsectionOrder' => private_site_branding_subsection_order($subsectionId),
            'label' => $label,
            'description' => 'Optional page-specific brand-name override.',
            'helpText' => 'Leave blank or choose Use shared value to inherit Shared Branding. Rendering remains escaped and falls back to ChatSpace Community Edition.',
            'aliases' => ['branding override', $previewPage, $previewField],
            'type' => 'string',
            'defaultValue' => '',
            'maximum' => 80,
            'order' => $order,
            'optional' => true,
            'allowsOverride' => true,
            'inheritanceSource' => 'community_name',
            'previewPage' => $previewPage,
            'previewField' => $previewField,
            'previewPath' => $previewPath,
        ]);
    };

    return [
        array_replace($shared, [
            'id' => 'extension.private-site-branding.enabled',
            'settingKey' => 'first_party_extension.private-site-branding.enabled',
            'owner' => 'first_party_extensions',
            'subsectionId' => 'shared-branding',
            'subsectionLabel' => 'Shared Branding',
            'subsectionOrder' => private_site_branding_subsection_order('shared-branding'),
            'label' => 'Private Site Branding',
            'description' => 'Enable Private Site Branding for this installation.',
            'helpText' => 'Disabling restores standard ChatSpace branding while preserving saved branding choices.',
            'aliases' => ['extension', 'safe mode', 'branding pilot'],
            'type' => 'boolean',
            'defaultValue' => true,
            'order' => 1,
            'optional' => true,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional', 'preset'],
            'bulkGroup' => 'first-party-extensions',
            'previewField' => 'Extension lifecycle',
            'installedFeature' => [
                'manageLabel' => 'Manage Branding',
                'manageView' => 'private-site-branding',
                'order' => 10,
            ],
        ]),
        array_replace($shared, [
            'id' => 'community_name',
            'settingKey' => 'community_name',
            'subsectionId' => 'shared-branding',
            'subsectionLabel' => 'Shared Branding',
            'subsectionOrder' => private_site_branding_subsection_order('shared-branding'),
            'label' => 'Community name',
            'description' => 'Shared installation name inherited by branding destinations.',
            'helpText' => 'Leave blank to use the standard ChatSpace Community Edition branding.',
            'aliases' => ['installation name', 'site name'],
            'type' => 'string',
            'defaultValue' => '',
            'maximum' => 80,
            'order' => 10,
            'optional' => true,
            'previewPage' => 'Login, lobby, room, and account surfaces',
            'previewField' => 'Shared brand name',
        ]),
        array_replace($shared, [
            'id' => 'community_logo_path',
            'settingKey' => 'community_logo_path',
            'subsectionId' => 'shared-branding',
            'subsectionLabel' => 'Shared Branding',
            'subsectionOrder' => private_site_branding_subsection_order('shared-branding'),
            'label' => 'Community logo',
            'description' => 'Optional validated private installation logo.',
            'helpText' => 'PNG, JPG, GIF, or WEBP under 5 MB and 42-2000 pixels per edge. The registry stores only a validated private upload reference.',
            'aliases' => ['site logo', 'branding image'],
            'type' => 'asset',
            'defaultValue' => '',
            'order' => 20,
            'optional' => true,
            'safeToReset' => false,
            'bulkOperations' => [],
            'previewField' => 'Shared full-size logo',
        ]),
        array_replace($shared, [
            'id' => 'branding_license_reminder',
            'settingKey' => 'extension.private-site-branding.license_reminder',
            'subsectionId' => 'shared-branding',
            'subsectionLabel' => 'Shared Branding',
            'subsectionOrder' => private_site_branding_subsection_order('shared-branding'),
            'label' => 'Branding and License Reminder',
            'description' => 'Required shared reminder shown read-only until deliberate editing.',
            'helpText' => 'Use Edit reminder wording after Unlock settings changes. Save wording, Cancel, and Reset to standard wording return the card to read-only.',
            'aliases' => ['license reminder', 'branding notice'],
            'type' => 'editable-reminder',
            'defaultValue' => PRIVATE_SITE_BRANDING_REMINDER_DEFAULT,
            'maximum' => 600,
            'requiredNonBlank' => true,
            'order' => 30,
            'optional' => false,
            'previewPage' => 'Setup and Admin Branding',
            'previewField' => 'Branding and License Reminder card',
            'standardFallback' => PRIVATE_SITE_BRANDING_REMINDER_DEFAULT,
        ]),
        $override('branding_login_name_override', 'extension.private-site-branding.login_name_override', 'login-page', 'Login Page', 'Login page brand name override', 'Login Page', 'Document title and logo alternative text', '/login.php', 10),
        array_replace($shared, [
            'id' => 'branding_show_changelog_login',
            'settingKey' => 'extension.private-site-branding.show_changelog_login',
            'subsectionId' => 'login-page',
            'subsectionLabel' => 'Login Page',
            'subsectionOrder' => private_site_branding_subsection_order('login-page'),
            'label' => "Show exe's Changelog on login",
            'description' => 'Show the public changelog utility beside the About action.',
            'helpText' => 'Enabled by default. Disabling only this login entry never removes MODIFICATIONS.md, license, source, authorship, or required attribution.',
            'aliases' => ['modifications', 'utility links'],
            'type' => 'boolean',
            'defaultValue' => true,
            'order' => 20,
            'optional' => true,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional', 'preset'],
            'previewPage' => 'Login Page',
            'previewField' => 'Utility Links',
            'previewPath' => '/login.php',
            'standardFallback' => 'Enabled',
        ]),
        $override('branding_registration_name_override', 'extension.private-site-branding.registration_name_override', 'registration-page', 'Registration Page', 'Registration page brand name override', 'Registration Page', 'Document title and logo alternative text', '/register.php', 10),
        $override('branding_recovery_name_override', 'extension.private-site-branding.recovery_name_override', 'account-recovery-page', 'Account Recovery Page', 'Account recovery brand name override', 'Account Recovery Page', 'Document title and logo alternative text', '/recover.php', 10),
        $override('branding_lobby_name_override', 'extension.private-site-branding.lobby_name_override', 'lobby-page', 'Lobby Page', 'Lobby brand name override', 'Lobby Page', 'Document title and header identity', '/lobby.php', 10),
        $override('branding_room_name_override', 'extension.private-site-branding.room_name_override', 'chat-room', 'Chat Room', 'Chat room brand name override', 'Chat Room', 'Document title and header identity', '/chatroom.php', 10),
        array_replace($shared, [
            'id' => 'branding_room_version_attribution',
            'settingKey' => 'extension.private-site-branding.room_version_attribution',
            'subsectionId' => 'chat-room',
            'subsectionLabel' => 'Chat Room',
            'subsectionOrder' => private_site_branding_subsection_order('chat-room'),
            'label' => 'Room version attribution',
            'description' => 'Public modifier attribution shown separately from the authoritative application version.',
            'helpText' => 'The standard exact value is Modified by exe. Blank values are rejected; exe remains the public modifier identity.',
            'aliases' => ['version line', 'modified by exe'],
            'type' => 'string',
            'defaultValue' => PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT,
            'maximum' => 120,
            'requiredNonBlank' => true,
            'resetLabel' => 'Reset to standard wording',
            'order' => 20,
            'optional' => false,
            'previewPage' => 'Chat Room',
            'previewField' => 'Sidebar version line',
            'previewPath' => '/chatroom.php',
            'standardFallback' => PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT,
        ]),
        array_replace($shared, [
            'id' => 'branding_setup_update_protected',
            'settingKey' => null,
            'subsectionId' => 'setup-update-pages',
            'subsectionLabel' => 'Setup and Update Pages',
            'subsectionOrder' => private_site_branding_subsection_order('setup-update-pages'),
            'label' => 'Protected setup and update pages',
            'description' => 'Setup and update pages keep the ChatSpace name and show recovery status accurately.',
            'helpText' => 'Branding cannot hide data-change, backup, update, or recovery information.',
            'type' => 'fixed',
            'defaultValue' => true,
            'order' => 10,
            'mandatory' => true,
            'optional' => false,
            'safeToReset' => false,
            'bulkOperations' => [],
            'controlClass' => 'mandatory-fixed',
            'fixedReason' => 'Required for safe setup, update, and recovery.',
            'previewPage' => 'Setup and Update Pages',
            'previewField' => 'Protected platform and recovery identity',
            'previewPath' => '/database-update.php',
        ]),
        array_replace($shared, [
            'id' => 'branding_maintenance_error_protected',
            'settingKey' => null,
            'subsectionId' => 'maintenance-error-pages',
            'subsectionLabel' => 'Maintenance and Error Pages',
            'subsectionOrder' => private_site_branding_subsection_order('maintenance-error-pages'),
            'label' => 'Protected maintenance and error pages',
            'description' => 'Safety failures, recovery mode, sign-in blocks, and moderation results remain accurate.',
            'helpText' => 'Branding never receives private details, hidden reasons, reports, restrictions, or unrestricted diagnostics.',
            'type' => 'fixed',
            'defaultValue' => true,
            'order' => 10,
            'mandatory' => true,
            'optional' => false,
            'safeToReset' => false,
            'bulkOperations' => [],
            'controlClass' => 'mandatory-fixed',
            'fixedReason' => 'Required for security, recovery, and truthful diagnostics.',
            'previewPage' => 'Maintenance and Error Pages',
            'previewField' => 'Protected core status and fallback',
            'previewPath' => '/recover.php',
        ]),
        array_replace($shared, [
            'id' => 'branding_about_legal_protected',
            'settingKey' => null,
            'subsectionId' => 'about-legal-page',
            'subsectionLabel' => 'About & Legal Page',
            'subsectionOrder' => private_site_branding_subsection_order('about-legal-page'),
            'label' => 'Required license and attribution information',
            'description' => 'Community branding never replaces required product and legal information.',
            'helpText' => 'Original authorship, credits, exe modification notice, Elastic License 2.0, LICENSE, source, AGST attribution, application version, and required notices remain visible.',
            'type' => 'fixed',
            'defaultValue' => true,
            'order' => 10,
            'mandatory' => true,
            'optional' => false,
            'safeToReset' => false,
            'bulkOperations' => [],
            'controlClass' => 'mandatory-fixed',
            'fixedReason' => 'Required legal and attribution information cannot be disabled.',
            'previewPage' => 'About & Legal Page',
            'previewField' => 'Mandatory platform/legal section',
            'previewPath' => '/about.html',
        ]),
        $override('branding_other_name_override', 'extension.private-site-branding.other_name_override', 'other-detected-branding', 'Other Detected Branding', 'Other public page brand name override', 'Account and ejection pages', 'Document title and public header identity', '/account.php', 10),
    ];
}

function private_site_branding_page_setting_key(string $pageKey): string {
    return match ($pageKey) {
        'login' => 'extension.private-site-branding.login_name_override',
        'registration' => 'extension.private-site-branding.registration_name_override',
        'recovery' => 'extension.private-site-branding.recovery_name_override',
        'lobby' => 'extension.private-site-branding.lobby_name_override',
        'room' => 'extension.private-site-branding.room_name_override',
        default => 'extension.private-site-branding.other_name_override',
    };
}

function private_site_branding_projection(PDO $pdo, string $pageKey = 'shared'): array {
    $defaults = [
        'extension_id' => PRIVATE_SITE_BRANDING_ID,
        'enabled' => false,
        'community_name' => '',
        'effective_name' => 'ChatSpace Community Edition',
        'logo_path' => '/assets/images/logos/chatspace-ce-full-logo.png',
        'compact_logo_path' => '/assets/images/chatspace-ce-logo.png',
        'powered_logo_path' => '/assets/images/logos/chatspace-ce-full-logo.png',
        'has_custom_logo' => false,
        'license_reminder' => PRIVATE_SITE_BRANDING_REMINDER_DEFAULT,
        'room_version_attribution' => PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT,
        'show_changelog_login' => true,
    ];
    try {
        $status = first_party_extension_status($pdo, PRIVATE_SITE_BRANDING_ID);
        if (($status['state'] ?? '') !== 'enabled') return $defaults;
        $sharedName = trim(app_setting($pdo, 'community_name', ''));
        $override = $pageKey === 'shared'
            ? ''
            : trim(app_setting($pdo, private_site_branding_page_setting_key($pageKey), ''));
        $logo = trim(app_setting($pdo, 'community_logo_path', ''));
        if ($logo !== '' && !private_site_branding_valid_asset_reference($logo)) $logo = '';
        $reminder = trim(app_setting(
            $pdo,
            'extension.private-site-branding.license_reminder',
            PRIVATE_SITE_BRANDING_REMINDER_DEFAULT
        ));
        $attribution = trim(app_setting(
            $pdo,
            'extension.private-site-branding.room_version_attribution',
            PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT
        ));
        return [
            'extension_id' => PRIVATE_SITE_BRANDING_ID,
            'enabled' => true,
            'community_name' => $sharedName,
            'effective_name' => $override !== '' ? $override : ($sharedName !== '' ? $sharedName : 'ChatSpace Community Edition'),
            'logo_path' => $logo !== '' ? $logo : $defaults['logo_path'],
            'compact_logo_path' => $logo !== '' ? $logo : $defaults['compact_logo_path'],
            'powered_logo_path' => $defaults['powered_logo_path'],
            'has_custom_logo' => $logo !== '',
            'license_reminder' => $reminder !== '' ? $reminder : PRIVATE_SITE_BRANDING_REMINDER_DEFAULT,
            'room_version_attribution' => $attribution !== '' ? $attribution : PRIVATE_SITE_BRANDING_ROOM_ATTRIBUTION_DEFAULT,
            'show_changelog_login' => app_setting(
                $pdo,
                'extension.private-site-branding.show_changelog_login',
                '1'
            ) === '1',
        ];
    } catch (Throwable) {
        return $defaults;
    }
}

function private_site_branding_page_title(PDO $pdo, string $page, string $pageKey): string {
    $projection = private_site_branding_projection($pdo, $pageKey);
    $effective = trim((string)$projection['effective_name']);
    $prefix = $effective !== '' && $effective !== 'ChatSpace Community Edition'
        ? $effective . ' - '
        : '';
    return $prefix . $page . ' - ChatSpace CE';
}

function private_site_branding_utility_links(PDO $pdo): array {
    $projection = private_site_branding_projection($pdo, 'login');
    $links = [[
        'id' => 'original-license',
        'label' => 'Original License',
        'path' => '/license.php',
        'primary' => false,
    ]];
    if (!empty($projection['show_changelog_login'])) {
        $links[] = [
            'id' => 'exe-changelog',
            'label' => "exe's Changelog",
            'path' => '/changelog.php',
            'primary' => false,
        ];
    }
    return $links;
}

function private_site_branding_valid_asset_reference(string $path): bool {
    if (!preg_match('#^/assets/uploads/branding/[a-f0-9]{24}\.(?:png|jpe?g|gif|webp)$#i', $path)) {
        return false;
    }
    $root = realpath(dirname(__DIR__) . '/assets/uploads/branding');
    $candidate = realpath(dirname(__DIR__) . $path);
    return $root !== false
        && $candidate !== false
        && str_starts_with(str_replace('\\', '/', $candidate), str_replace('\\', '/', $root) . '/')
        && is_file($candidate);
}

function private_site_branding_remove_managed_asset(string $path): bool {
    if (!private_site_branding_valid_asset_reference($path)) return false;
    $candidate = realpath(dirname(__DIR__) . $path);
    if ($candidate === false || !is_file($candidate)) return false;
    return @unlink($candidate);
}

function private_site_branding_store_logo_upload(
    array $upload,
    string $storageOperation = 'setup_branding'
): string {
    $tmp = (string)($upload['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Choose a branding image to upload.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];
    $size = (int)($upload['size'] ?? 0);
    $dims = @getimagesize($tmp);
    if (!isset($allowed[$mime])
        || !security_valid_image_file($tmp, $mime)
        || $size < 1
        || $size > 5 * 1024 * 1024
        || !$dims
        || $dims[0] < 42
        || $dims[1] < 42
        || $dims[0] > 2000
        || $dims[1] > 2000) {
        throw new RuntimeException('Use a PNG, JPG, GIF, or WEBP logo under 5 MB and between 42x42 and 2000x2000.');
    }
    $directory = dirname(__DIR__) . '/assets/uploads/branding';
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not prepare private branding storage.');
    }
    $filename = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $destination = $directory . '/' . $filename;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new RuntimeException('Could not save community logo.');
    }
    $publicPath = '/assets/uploads/branding/' . $filename;
    security_assert_storage_destination($storageOperation, $publicPath);
    return $publicPath;
}

function private_site_branding_inline_markdown(string $text): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)]+)\)/',
        static function (array $match): string {
            $target = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $browserRoutes = [
                'LICENSE.md' => '/license.php',
                'MODIFICATIONS.md' => '/changelog.php',
            ];
            $allowedLocal = ['README.md', 'AUTHORS.md'];
            if (isset($browserRoutes[$target])) {
                $href = app_url($browserRoutes[$target]);
            } elseif (in_array($target, $allowedLocal, true)) {
                $href = app_url('/' . $target);
            } elseif (filter_var($target, FILTER_VALIDATE_URL)
                && in_array(parse_url($target, PHP_URL_SCHEME), ['https'], true)) {
                $href = $target;
            } else {
                return $match[1];
            }
            return '<a href="' . e($href) . '">' . $match[1] . '</a>';
        },
        $escaped
    ) ?? $escaped;
    return $escaped;
}

function private_site_branding_render_modifications(string $markdown): string {
    $html = [];
    $paragraph = [];
    $inList = false;
    $flushParagraph = static function () use (&$paragraph, &$html): void {
        if (!$paragraph) return;
        $html[] = '<p>' . private_site_branding_inline_markdown(implode(' ', $paragraph)) . '</p>';
        $paragraph = [];
    };
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushParagraph();
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            continue;
        }
        if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $match)) {
            $flushParagraph();
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            $level = strlen($match[1]) + 1;
            $html[] = "<h{$level}>" . private_site_branding_inline_markdown($match[2]) . "</h{$level}>";
            continue;
        }
        if ($trimmed === '<details>') {
            $flushParagraph();
            $html[] = '<details class="changelog-details">';
            continue;
        }
        if ($trimmed === '</details>') {
            $flushParagraph();
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            $html[] = '</details>';
            continue;
        }
        if (preg_match('#^<summary>(.+)</summary>$#', $trimmed, $match)) {
            $flushParagraph();
            $html[] = '<summary>' . private_site_branding_inline_markdown($match[1]) . '</summary>';
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $match)) {
            $flushParagraph();
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . private_site_branding_inline_markdown($match[1]) . '</li>';
            continue;
        }
        $paragraph[] = $trimmed;
    }
    $flushParagraph();
    if ($inList) $html[] = '</ul>';
    return implode("\n", $html);
}
