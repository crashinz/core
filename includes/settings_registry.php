<?php
declare(strict_types=1);

const SETTINGS_REGISTRY_REVISION_SETTING = 'settings_registry_revision';

function settings_registry_setting_defaults(): array {
    return [SETTINGS_REGISTRY_REVISION_SETTING => '1'];
}

function settings_registry_categories(): array {
    return [
        ['id' => 'general-appearance', 'label' => 'General & Appearance', 'order' => 10],
        ['id' => 'avatars-presence', 'label' => 'Avatars & Presence', 'order' => 20],
        ['id' => 'avatar-interactions', 'label' => 'Avatar Interactions', 'order' => 30],
        ['id' => 'chat-messaging', 'label' => 'Chat & Messaging', 'order' => 40],
        ['id' => 'voice-media-players', 'label' => 'Voice, Media & Players', 'order' => 50],
        ['id' => 'rooms-games', 'label' => 'Rooms & Games', 'order' => 60],
        ['id' => 'moderation-privacy-security', 'label' => 'Moderation, Privacy & Security', 'order' => 70],
        ['id' => 'errors-diagnostics', 'label' => 'Errors & Diagnostics', 'order' => 80],
        ['id' => 'advanced-compatibility', 'label' => 'Advanced & Compatibility', 'order' => 90],
    ];
}

function settings_registry_entry(array $entry): array {
    return array_replace([
        'id' => '',
        'settingKey' => null,
        'owner' => 'app_settings',
        'categoryId' => 'advanced-compatibility',
        'subsectionId' => 'unresolved',
        'subsectionLabel' => 'Unresolved',
        'label' => '',
        'description' => '',
        'helpText' => '',
        'aliases' => [],
        'type' => 'string',
        'defaultValue' => '',
        'allowedValues' => null,
        'minimum' => null,
        'maximum' => null,
        'step' => null,
        'order' => 100,
        'controlClass' => 'configurable-required',
        'optional' => false,
        'mandatory' => false,
        'safeToReset' => true,
        'bulkOperations' => ['setting', 'subsection', 'category'],
        'dependencies' => [],
        'incompatibilities' => [],
        'originalRelevant' => false,
        'originalValueAvailable' => false,
        'originalValue' => null,
        'disablingMovesTowardOriginal' => false,
        'differsFromOriginalByDefault' => false,
        'setupVisible' => false,
        'adminVisible' => true,
        'visibilityReason' => '',
        'authorization' => 'administrator-and-recent-authentication',
        'staleWriteOwner' => SETTINGS_REGISTRY_REVISION_SETTING,
        'toolLogBehavior' => 'bounded-registry-operation',
        'secret' => false,
        'fixedReason' => '',
        'bulkGroup' => null,
    ], $entry);
}

function settings_registry_definitions(): array {
    static $definitions = null;
    if ($definitions !== null) return $definitions;

    $definitions = [
        settings_registry_entry([
            'id' => 'community_name', 'settingKey' => 'community_name',
            'categoryId' => 'general-appearance', 'subsectionId' => 'branding',
            'subsectionLabel' => 'Community Branding', 'label' => 'Community name',
            'description' => 'Optional installation name shown in shared community branding.',
            'helpText' => 'Leave blank to use the standard ChatSpace Community Edition branding.',
            'aliases' => ['installation name', 'site name'], 'type' => 'string',
            'defaultValue' => '', 'maximum' => 80, 'order' => 10,
            'controlClass' => 'optional', 'optional' => true,
            'setupVisible' => true, 'adminVisible' => true,
        ]),
        settings_registry_entry([
            'id' => 'community_logo_path', 'settingKey' => 'community_logo_path',
            'categoryId' => 'general-appearance', 'subsectionId' => 'branding',
            'subsectionLabel' => 'Community Branding', 'label' => 'Community logo',
            'description' => 'Optional uploaded logo used by community branding.',
            'helpText' => 'The setup upload owner validates and stores the image; the registry stores no image bytes.',
            'aliases' => ['site logo', 'branding image'], 'type' => 'asset',
            'defaultValue' => '', 'order' => 20, 'controlClass' => 'optional',
            'optional' => true, 'safeToReset' => false, 'bulkOperations' => [],
            'setupVisible' => true, 'adminVisible' => false,
            'visibilityReason' => 'The validated logo upload is available only during first-install setup.',
            'authorization' => 'first-install-setup', 'toolLogBehavior' => 'setup-registry-operation',
        ]),
        settings_registry_entry([
            'id' => 'role_colors_mode', 'settingKey' => 'role_colors_mode',
            'owner' => 'role_color_policy', 'categoryId' => 'general-appearance',
            'subsectionId' => 'role-colors', 'subsectionLabel' => 'Username Role Colors',
            'label' => 'Username role colors',
            'description' => 'Choose the framework palette, turn role colors off, or use a custom accessible palette.',
            'helpText' => 'Color presentation never grants or changes a role.',
            'aliases' => ['name colors', 'role palette'], 'type' => 'select',
            'defaultValue' => 'enabled', 'allowedValues' => ['enabled', 'disabled', 'custom'],
            'order' => 10, 'controlClass' => 'optional', 'optional' => true,
            'setupVisible' => true,
            'originalRelevant' => true, 'originalValueAvailable' => true,
            'originalValue' => 'disabled', 'disablingMovesTowardOriginal' => true,
            'differsFromOriginalByDefault' => true,
        ]),
    ];

    $roleLabels = ['admin' => 'Administrator', 'developer' => 'Developer', 'guide' => 'Guide', 'owner' => 'Room Owner', 'user' => 'Standard User'];
    $roleDefaults = role_color_default_palette();
    $roleOrder = 20;
    foreach ($roleLabels as $role => $label) {
        foreach (['bg' => 'background', 'text' => 'text'] as $suffix => $part) {
            $definitions[] = settings_registry_entry([
                'id' => "role_color_{$role}_{$suffix}", 'settingKey' => "role_color_{$role}_{$suffix}",
                'owner' => 'role_color_policy', 'categoryId' => 'general-appearance',
                'subsectionId' => 'role-colors', 'subsectionLabel' => 'Username Role Colors',
                'label' => "{$label} " . ucfirst($part) . ' color',
                'description' => "Accessible {$part} color for {$label} names when role colors are enabled.",
                'helpText' => 'Custom colors must retain at least 4.5:1 contrast.',
                'aliases' => [$role . ' color', 'role palette'], 'type' => 'color',
                'defaultValue' => $roleDefaults[$role][$suffix === 'bg' ? 'background' : 'text'],
                'order' => $roleOrder++, 'dependencies' => ['role_colors_mode'],
                'bulkGroup' => 'role-colors',
            ]);
        }
    }

    $numeric = [
        ['chat_posts_per_second', 'chat-messaging', 'rate-history', 'Rate & History', 'Chat posts per second', 'Maximum accepted chat-post rate per account.', 3, 0.2, 30, 10, ['message rate', 'chat rate']],
        ['room_chat_history_limit', 'chat-messaging', 'rate-history', 'Rate & History', 'Room chat history posts', 'Maximum room history posts returned by the shared history owner.', 100, 1, 1000, 20, ['history limit']],
        ['avatar_movements_per_second', 'avatars-presence', 'presence', 'Presence & Movement', 'Avatar movements per second', 'Maximum accepted avatar position-update rate.', 12, 1, 60, 10, ['movement rate']],
        ['participant_idle_timeout_minutes', 'avatars-presence', 'presence', 'Presence & Movement', 'Idle removal minutes', 'Minutes before stale participant presence is eligible for cleanup.', 2, 0.5, 120, 20, ['idle timeout']],
        ['avatar_max_size_mb', 'avatars-presence', 'avatar-upload', 'Avatar Upload', 'Avatar upload max MB', 'Maximum avatar upload byte size, independent of source dimensions.', 5, 0.5, 50, 10, ['avatar bytes', 'avatar size']],
        ['avatar_upload_max_width_px', 'avatars-presence', 'avatar-upload', 'Avatar Upload', 'Avatar upload max width px', 'Maximum authoritative source width accepted for avatar uploads.', 250, 42, 4096, 20, ['avatar source width']],
        ['avatar_upload_max_height_px', 'avatars-presence', 'avatar-upload', 'Avatar Upload', 'Avatar upload max height px', 'Maximum authoritative source height accepted for avatar uploads.', 250, 42, 4096, 30, ['avatar source height']],
        ['avatar_display_max_px', 'avatars-presence', 'display-limits', 'Display Limits', 'Avatar display max edge px', 'Installation maximum for rendered avatar display geometry.', 200, 42, 1000, 10, ['avatar display size']],
        ['webcam_display_max_width_px', 'voice-media-players', 'webcam-display', 'Webcam Display', 'Webcam display max width px', 'Installation maximum webcam presentation width.', 200, 42, 2048, 10, ['webcam width']],
        ['webcam_display_max_height_px', 'voice-media-players', 'webcam-display', 'Webcam Display', 'Webcam display max height px', 'Installation maximum webcam presentation height.', 200, 42, 2048, 20, ['webcam height']],
        ['gesture_upload_limit', 'chat-messaging', 'gesture-limits', 'Gesture Limits', 'Gestures per account', 'Maximum stored gestures per account under the current catalog owner.', 50, 0, 1000, 10, ['gesture limit']],
        ['room_image_max_size_mb', 'rooms-games', 'room-media', 'Room Media', 'Room image max MB', 'Maximum uploaded room-image byte size.', 10, 1, 100, 10, ['room image size']],
        ['room_video_max_size_mb', 'rooms-games', 'room-media', 'Room Media', 'Room video max MB', 'Maximum uploaded room-video byte size.', 200, 5, 1000, 20, ['room video size']],
        ['auth_login_max_attempts', 'moderation-privacy-security', 'authentication', 'Authentication', 'Login attempts', 'Maximum account login attempts within the configured window.', 5, 1, 50, 10, ['login rate limit']],
        ['auth_recovery_max_attempts', 'moderation-privacy-security', 'authentication', 'Authentication', 'Recovery attempts', 'Maximum account-recovery attempts within the configured window.', 5, 1, 50, 20, ['recovery rate limit']],
        ['auth_ip_max_attempts', 'moderation-privacy-security', 'authentication', 'Authentication', 'Attempts per IP', 'Maximum authentication attempts attributed to one network source.', 30, 5, 500, 30, ['IP attempts']],
        ['auth_attempt_window_minutes', 'moderation-privacy-security', 'authentication', 'Authentication', 'Attempt window minutes', 'Rolling authentication attempt window.', 15, 1, 1440, 40, ['rate window']],
        ['auth_lockout_minutes', 'moderation-privacy-security', 'authentication', 'Authentication', 'Lockout minutes', 'Authentication lockout duration after the bounded limit is reached.', 15, 1, 1440, 50, ['lockout']],
        ['age_gate_min_age', 'moderation-privacy-security', 'age-gate', 'Age Gate', 'Age gate minimum age', 'Minimum age confirmed when the optional age gate is enabled.', 13, 1, 120, 20, ['minimum age']],
    ];
    foreach ($numeric as [$id, $category, $subsection, $subsectionLabel, $label, $description, $default, $minimum, $maximum, $order, $aliases]) {
        $definitions[] = settings_registry_entry([
            'id' => $id, 'settingKey' => $id,
            'owner' => in_array($id, array_keys(avatar_size_policy_setting_map()), true) ? 'avatar_size_policy' : 'app_settings',
            'categoryId' => $category, 'subsectionId' => $subsection,
            'subsectionLabel' => $subsectionLabel, 'label' => $label,
            'description' => $description, 'helpText' => "Allowed range: {$minimum}-{$maximum}.",
            'aliases' => $aliases, 'type' => 'number', 'defaultValue' => $default,
            'minimum' => $minimum, 'maximum' => $maximum,
            'step' => match ($id) {
                'chat_posts_per_second' => 0.1,
                'participant_idle_timeout_minutes', 'avatar_max_size_mb' => 0.5,
                'room_video_max_size_mb' => 5,
                default => 1,
            },
            'order' => $order,
        ]);
    }

    $definitions[] = settings_registry_entry([
        'id' => 'avatar_relationship_max_regular_links',
        'settingKey' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_SETTING,
        'owner' => 'avatar_relationship_capacity_policy',
        'categoryId' => 'avatar-interactions', 'subsectionId' => 'relationships',
        'subsectionLabel' => 'Avatar Relationships',
        'label' => 'Maximum regular avatar links in one relationship',
        'description' => 'Controls regular members in one relationship; left and right lap occupants do not count.',
        'helpText' => 'Existing relationships above a lowered limit remain valid but cannot accept new regular members until below it. Multi-member relationship architecture has no truthful exact original-author value and is preserved.',
        'aliases' => ['relationship limit', 'maximum links'], 'type' => 'number',
        'defaultValue' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_DEFAULT,
        'minimum' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MIN,
        'maximum' => AVATAR_RELATIONSHIP_REGULAR_LINK_LIMIT_MAX, 'step' => 1,
        'order' => 10, 'setupVisible' => true,
        'originalRelevant' => true, 'originalValueAvailable' => false,
    ]);

    foreach (avatar_dance_capability_registry() as $dance) {
        $definitions[] = settings_registry_entry([
            'id' => 'avatar_dance.' . (string)$dance['id'],
            'settingKey' => AVATAR_DANCE_CAPABILITY_SETTING,
            'owner' => 'avatar_dance_capability_policy',
            'categoryId' => 'avatar-interactions', 'subsectionId' => 'dances',
            'subsectionLabel' => 'Dances', 'label' => (string)$dance['label'],
            'description' => (string)$dance['description'],
            'helpText' => 'Disabling an active mode safely restores its exact baseline. Re-enabling never restarts it.',
            'aliases' => [(string)$dance['id'], 'avatar animation'], 'type' => 'boolean',
            'defaultValue' => true, 'order' => (int)$dance['order'],
            'controlClass' => 'optional', 'optional' => true,
            'originalRelevant' => true, 'originalValueAvailable' => true,
            'originalValue' => false, 'disablingMovesTowardOriginal' => true,
            'differsFromOriginalByDefault' => true, 'setupVisible' => true,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional', 'preset'],
            'bulkGroup' => 'dances',
        ]);
    }

    foreach (gesture_capability_registry() as $capability) {
        $id = (string)$capability['id'];
        $master = $id === 'allow_gestures';
        $definitions[] = settings_registry_entry([
            'id' => $id,
            'settingKey' => $id,
            'owner' => 'gesture_capability_policy',
            'categoryId' => 'chat-messaging',
            'subsectionId' => 'gesture-capabilities',
            'subsectionLabel' => 'Gesture Capabilities',
            'label' => (string)$capability['label'],
            'description' => (string)$capability['description'],
            'helpText' => $master
                ? 'This is the server-authoritative parent gate. Turning it off preserves subordinate stored values and historical canonical text.'
                : 'Effective only while Allow gestures is enabled. The stored value is preserved when the parent is off.',
            'aliases' => ['gesture capability', 'gesture permission', $master ? 'master gestures' : 'gesture subordinate'],
            'type' => 'boolean',
            'defaultValue' => true,
            'order' => (int)$capability['order'],
            'controlClass' => 'optional',
            'optional' => true,
            'setupVisible' => true,
            'adminVisible' => true,
            'originalRelevant' => true,
            'originalValueAvailable' => true,
            'originalValue' => true,
            'dependencies' => $master ? [] : ['allow_gestures'],
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional', 'preset'],
            'bulkGroup' => 'gesture-capability',
        ]);
    }

    $gesturePart3Features = [
        ['gesture_part3_enhanced_picker', 'Enhanced gesture picker', 'Use the Part 3 catalog picker presentation instead of the earlier combined gesture list.', 10],
        ['gesture_part3_gifs_tab', 'GIFs tab', 'Show the existing GIF search as a dedicated picker tab.', 20],
        ['gesture_part3_server_tab', 'Server Gestures tab', 'Show public gestures from other accounts in a dedicated picker tab.', 30],
        ['gesture_part3_personal_tab', 'Personal Gestures tab', 'Show the signed-in account’s public and private gestures in a dedicated picker tab.', 40],
        ['gesture_part3_emojis_tab', 'Emojis tab', 'Show the existing emoji picker as a dedicated tab.', 50],
        ['gesture_part3_search', 'Gesture catalog search', 'Allow separate bounded server-side search in Server and Personal Gestures.', 60],
        ['gesture_part3_sorting', 'Gesture catalog sorting', 'Allow Last uploaded, File name A–Z, and persistent Custom order sorting.', 70],
        ['gesture_part3_pagination', 'Gesture catalog pagination presentation', 'Show accessible 20-item page navigation for user gesture catalogs.', 80],
        ['gesture_part3_custom_order', 'Gesture custom ordering', 'Allow persistent stable-ID insertion ordering and move actions.', 90],
        ['gesture_part3_hide_unhide', 'Server Gesture hide and show', 'Allow each account to hide Server Gestures from its own catalog presentation.', 100],
        ['gesture_part3_context_menus', 'Gesture action menus', 'Replace native image actions with accessible ChatSpace gesture actions.', 110],
        ['gesture_part3_message_hide_unhide', 'Gesture-message hide and show', 'Allow a viewer to hide or show the stable gesture used by a visible message.', 120],
        ['gesture_part3_admin_catalog', 'Admin read-only gesture catalog', 'Show the bounded text-only Server Gesture catalog in the canonical Admin menu.', 130],
    ];
    foreach ($gesturePart3Features as [$id, $label, $description, $order]) {
        $definitions[] = settings_registry_entry([
            'id' => $id, 'settingKey' => $id, 'owner' => 'gesture_part3_presentation_policy',
            'categoryId' => 'chat-messaging', 'subsectionId' => 'gesture-part3',
            'subsectionLabel' => 'Gesture Presentation & Catalog', 'label' => $label,
            'description' => $description,
            'helpText' => 'This is a Part 3 presentation capability, not the Part 5 server-authoritative Allow gestures boundary.',
            'aliases' => ['gesture picker', 'gesture presentation', 'gesture catalog'],
            'type' => 'boolean', 'defaultValue' => true, 'order' => $order,
            'controlClass' => 'optional', 'optional' => true,
            'setupVisible' => true, 'adminVisible' => true,
            'originalRelevant' => true, 'originalValueAvailable' => true,
            'originalValue' => false, 'disablingMovesTowardOriginal' => true,
            'differsFromOriginalByDefault' => true,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional', 'preset'],
            'bulkGroup' => 'gesture-part-3',
        ]);
    }

    $gesturePart4Features = [
        ['gesture_part4_editor', 'Gesture Maker and Editor', 'Allow account owners to create and edit validated Personal Gestures through the dedicated room-preserving editor.', 10],
        ['gesture_part4_user_package_import', 'User AGST package import', 'Allow validated AGST packages to be used as a source in the Gesture Maker and legacy picker adapter.', 20],
        ['gesture_part4_user_package_download', 'User gesture-package download', 'Allow protected package export for owners and policy-authorized Server Gestures.', 30],
        ['gesture_part4_animation_media', 'Gesture animation media', 'Allow validated GIF animation media in new or edited gesture packages.', 40],
        ['gesture_part4_audio_media', 'Gesture audio media', 'Allow validated MP3 sound media with explicit playback and Part 3 preference suppression.', 50],
        ['gesture_part4_legacy_agst', 'Legacy AGST compatibility', 'Allow source-backed toc.json and meta.json packages to be validated and normalized by the canonical package owner.', 60],
        ['gesture_part4_admin_package_inspection', 'Admin gesture-package inspection', 'Allow authorized Admin users to inspect bounded package, provenance, and media-role summaries.', 70],
        ['gesture_part4_admin_media_replacement', 'Admin gesture media replacement', 'Allow authorized Admin users to open the shared editor for validated Server Gesture replacement.', 80],
    ];
    foreach ($gesturePart4Features as [$id, $label, $description, $order]) {
        $definitions[] = settings_registry_entry([
            'id' => $id, 'settingKey' => $id, 'owner' => 'gesture_part4_package_policy',
            'categoryId' => 'chat-messaging', 'subsectionId' => 'gesture-part4',
            'subsectionLabel' => 'Gesture Maker, Packages & Media', 'label' => $label,
            'description' => $description,
            'helpText' => 'This optional Part 4 capability never disables mandatory archive safety, authorization, privacy, validation, atomicity, or the future Part 5 master Allow gestures boundary.',
            'aliases' => ['gesture maker', 'gesture editor', 'AGST', 'gesture package', 'gesture media'],
            'type' => 'boolean', 'defaultValue' => true, 'order' => $order,
            'controlClass' => 'optional', 'optional' => true,
            'setupVisible' => true, 'adminVisible' => true,
            'originalRelevant' => true, 'originalValueAvailable' => true,
            'originalValue' => false, 'disablingMovesTowardOriginal' => true,
            'differsFromOriginalByDefault' => true,
            'bulkOperations' => ['setting', 'subsection', 'category', 'all-optional', 'preset'],
            'bulkGroup' => 'gesture-part-4',
        ]);
    }

    $definitions = array_merge($definitions, [
        settings_registry_entry([
            'id' => 'allow_webcam_use', 'settingKey' => 'allow_webcam_use',
            'owner' => 'webcam_policy', 'categoryId' => 'voice-media-players',
            'subsectionId' => 'webcam-capability', 'subsectionLabel' => 'Webcam Capability',
            'label' => 'Allow webcam use',
            'description' => 'Allow participants to share webcam video while voice remains independent.',
            'helpText' => 'Disabling stops active webcam sharing and restores avatar presentation without changing voice.',
            'aliases' => ['webcam enabled', 'camera sharing'], 'type' => 'boolean',
            'defaultValue' => true, 'order' => 10, 'controlClass' => 'optional',
            'optional' => true, 'setupVisible' => true,
            'originalRelevant' => true, 'originalValueAvailable' => true,
            'originalValue' => true,
        ]),
        settings_registry_entry([
            'id' => 'gif_default_provider', 'settingKey' => 'gif_default_provider',
            'categoryId' => 'chat-messaging', 'subsectionId' => 'gif-providers',
            'subsectionLabel' => 'GIF Providers', 'label' => 'Default GIF provider',
            'description' => 'Provider selected first in the GIF search surface.',
            'helpText' => 'A corresponding provider key may be required.',
            'aliases' => ['GIPHY', 'Klipy', 'Tenor'], 'type' => 'select',
            'defaultValue' => 'giphy', 'allowedValues' => ['giphy', 'klipy', 'tenor'], 'order' => 10,
        ]),
        settings_registry_entry([
            'id' => 'gif_giphy_api_key', 'settingKey' => 'gif_giphy_api_key',
            'categoryId' => 'chat-messaging', 'subsectionId' => 'gif-providers',
            'subsectionLabel' => 'GIF Providers', 'label' => 'GIPHY key',
            'description' => 'Private provider credential used for GIPHY search.',
            'helpText' => 'Stored values are never returned by the settings registry.',
            'aliases' => ['GIPHY API key'], 'type' => 'secret', 'defaultValue' => '',
            'order' => 20, 'safeToReset' => false, 'bulkOperations' => [], 'secret' => true,
            'toolLogBehavior' => 'never-log-value',
        ]),
        settings_registry_entry([
            'id' => 'gif_klipy_api_key', 'settingKey' => 'gif_klipy_api_key',
            'categoryId' => 'chat-messaging', 'subsectionId' => 'gif-providers',
            'subsectionLabel' => 'GIF Providers', 'label' => 'Klipy key',
            'description' => 'Private provider credential used for Klipy search.',
            'helpText' => 'Stored values are never returned by the settings registry.',
            'aliases' => ['Klipy API key'], 'type' => 'secret', 'defaultValue' => '',
            'order' => 30, 'safeToReset' => false, 'bulkOperations' => [], 'secret' => true,
            'toolLogBehavior' => 'never-log-value',
        ]),
        settings_registry_entry([
            'id' => 'gif_tenor_api_key', 'settingKey' => 'gif_tenor_api_key',
            'categoryId' => 'chat-messaging', 'subsectionId' => 'gif-providers',
            'subsectionLabel' => 'GIF Providers', 'label' => 'Tenor key',
            'description' => 'Private provider credential used for Tenor search.',
            'helpText' => 'Stored values are never returned by the settings registry.',
            'aliases' => ['Tenor API key'], 'type' => 'secret', 'defaultValue' => '',
            'order' => 40, 'safeToReset' => false, 'bulkOperations' => [], 'secret' => true,
            'toolLogBehavior' => 'never-log-value',
        ]),
        settings_registry_entry([
            'id' => 'age_gate_enabled', 'settingKey' => 'age_gate_enabled',
            'categoryId' => 'moderation-privacy-security', 'subsectionId' => 'age-gate',
            'subsectionLabel' => 'Age Gate', 'label' => 'Enable age gate',
            'description' => 'Require users to confirm they meet the configured minimum age.',
            'helpText' => 'This is an installation policy and does not replace applicable legal review.',
            'aliases' => ['minimum age confirmation'], 'type' => 'boolean',
            'defaultValue' => false, 'order' => 10, 'controlClass' => 'optional', 'optional' => true,
            'setupVisible' => true,
        ]),
        settings_registry_entry([
            'id' => 'diagnostic_screenshots_enabled', 'settingKey' => 'diagnostic_screenshots_enabled',
            'categoryId' => 'errors-diagnostics', 'subsectionId' => 'diagnostic-screenshots',
            'subsectionLabel' => 'Diagnostic Screenshots', 'label' => 'Diagnostic screenshots',
            'description' => 'Create locally censored schematic screenshots for unresolved runtime issues.',
            'helpText' => 'This is separate from the future Client diagnostic collection selector.',
            'aliases' => ['censored screenshots', 'issue screenshots'], 'type' => 'boolean',
            'defaultValue' => false, 'order' => 10, 'controlClass' => 'optional', 'optional' => true,
            'setupVisible' => true,
            'dependencies' => ['diagnostic_screenshot_retention_days'],
            'originalRelevant' => true, 'originalValueAvailable' => true, 'originalValue' => false,
            'disablingMovesTowardOriginal' => true,
        ]),
        settings_registry_entry([
            'id' => 'diagnostic_screenshot_retention_days', 'settingKey' => 'diagnostic_screenshot_retention_days',
            'categoryId' => 'errors-diagnostics', 'subsectionId' => 'diagnostic-screenshots',
            'subsectionLabel' => 'Diagnostic Screenshots', 'label' => 'Unresolved retention days',
            'description' => 'Retention period for unresolved locally censored diagnostic screenshots.',
            'helpText' => 'Enabled screenshots require 1-365 days; disabled screenshots allow 0-365.',
            'aliases' => ['screenshot retention'], 'type' => 'number',
            'defaultValue' => 0, 'minimum' => 0, 'maximum' => 365, 'step' => 1, 'order' => 20,
            'setupVisible' => true,
            'originalRelevant' => true, 'originalValueAvailable' => true, 'originalValue' => 0,
        ]),
        settings_registry_entry([
            'id' => 'mandatory.security-safeguards', 'settingKey' => null,
            'owner' => 'security_policy', 'categoryId' => 'moderation-privacy-security',
            'subsectionId' => 'mandatory-safeguards', 'subsectionLabel' => 'Mandatory Safeguards',
            'label' => 'Core security and data-integrity safeguards',
            'description' => 'Authentication, authorization, validation, privacy, compatibility, concurrency, moderation, and safety invariants remain enforced.',
            'helpText' => 'These safeguards are mandatory and cannot be disabled by a preset or reset.',
            'aliases' => ['mandatory security', 'authorization'], 'type' => 'fixed',
            'defaultValue' => true, 'order' => 100, 'controlClass' => 'mandatory-fixed',
            'mandatory' => true, 'safeToReset' => false, 'bulkOperations' => [],
            'fixedReason' => 'Required for security, privacy, authorization, validation, data integrity, and safety.',
            'authorization' => 'not-mutable', 'staleWriteOwner' => null, 'toolLogBehavior' => 'not-mutable',
        ]),
        settings_registry_entry([
            'id' => 'mandatory.compatibility-concurrency-integrity', 'settingKey' => null,
            'owner' => 'database_policy', 'categoryId' => 'advanced-compatibility',
            'subsectionId' => 'mandatory-runtime-invariants', 'subsectionLabel' => 'Mandatory Runtime Invariants',
            'label' => 'Compatibility, concurrency, and data integrity',
            'description' => 'Database portability, stale-version rejection, atomic updates, and authoritative data-integrity rules remain enforced.',
            'helpText' => 'These runtime invariants are mandatory and cannot be disabled by a preset or reset.',
            'aliases' => ['database compatibility', 'concurrency', 'stale writes'], 'type' => 'fixed',
            'defaultValue' => true, 'order' => 10, 'controlClass' => 'mandatory-fixed',
            'mandatory' => true, 'safeToReset' => false, 'bulkOperations' => [],
            'fixedReason' => 'Required for cross-database compatibility, stale-write safety, and data integrity.',
            'authorization' => 'not-mutable', 'staleWriteOwner' => null, 'toolLogBehavior' => 'not-mutable',
        ]),
    ]);

    return $definitions;
}

function settings_registry_definition_map(): array {
    $map = [];
    foreach (settings_registry_definitions() as $definition) $map[(string)$definition['id']] = $definition;
    return $map;
}

function settings_registry_revision(PDO $pdo): int {
    return max(1, (int)app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, '1'));
}

function settings_registry_current_value(PDO $pdo, array $definition, array $context): mixed {
    $id = (string)$definition['id'];
    if ($definition['type'] === 'fixed') return true;
    if (str_starts_with($id, 'avatar_dance.')) {
        $danceId = substr($id, strlen('avatar_dance.'));
        return !empty($context['dance']['enabled'][$danceId]);
    }
    if ($definition['owner'] === 'avatar_relationship_capacity_policy') {
        return (int)$context['capacity']['maximumRegularAvatarLinks'];
    }
    if ($definition['owner'] === 'avatar_size_policy') {
        $publicKey = avatar_size_policy_setting_map()[(string)$definition['settingKey']];
        return (int)$context['size'][$publicKey];
    }
    if ($id === 'allow_webcam_use') return !empty($context['webcam']['allowWebcamUse']);
    if ($definition['owner'] === 'gesture_capability_policy') {
        return !empty($context['gestureCapabilities']['stored'][$id]);
    }
    if ($id === 'role_colors_mode') return (string)$context['roleColors']['mode'];
    if (str_starts_with($id, 'role_color_')) {
        if (!preg_match('/^role_color_(admin|developer|guide|owner|user)_(bg|text)$/', $id, $match)) return '';
        return (string)$context['roleColors']['palette'][$match[1]][$match[2] === 'bg' ? 'background' : 'text'];
    }
    $raw = app_setting($pdo, (string)$definition['settingKey'], (string)$definition['defaultValue']);
    return match ($definition['type']) {
        'boolean' => $raw === '1',
        'number' => is_float($definition['defaultValue']) ? (float)$raw : (int)$raw,
        'secret' => '',
        default => $raw,
    };
}

function settings_registry_values_equal(mixed $left, mixed $right, string $type): bool {
    return match ($type) {
        'boolean', 'fixed' => (bool)$left === (bool)$right,
        'number' => (float)$left === (float)$right,
        default => (string)$left === (string)$right,
    };
}

function settings_registry_snapshot(PDO $pdo, string $surface = 'admin'): array {
    $surface = $surface === 'setup' ? 'setup' : 'admin';
    $context = [
        'dance' => avatar_dance_capability_policy($pdo),
        'capacity' => avatar_relationship_capacity_policy($pdo),
        'size' => avatar_size_policy($pdo),
        'webcam' => webcam_capability($pdo),
        'roleColors' => role_color_settings($pdo),
        'gestureCapabilities' => gesture_capability_policy($pdo),
    ];
    $entries = [];
    foreach (settings_registry_definitions() as $definition) {
        $current = settings_registry_current_value($pdo, $definition, $context);
        $visible = $surface === 'setup' ? !empty($definition['setupVisible']) : !empty($definition['adminVisible']);
        $entry = $definition;
        $entry['currentValue'] = $current;
        $entry['hasStoredValue'] = $definition['secret']
            ? app_setting($pdo, (string)$definition['settingKey'], '') !== ''
            : null;
        $entry['changedFromDefault'] = $definition['secret']
            ? (bool)$entry['hasStoredValue']
            : !settings_registry_values_equal($current, $definition['defaultValue'], (string)$definition['type']);
        $entry['enabled'] = $definition['optional'] ? (
            $definition['type'] === 'boolean'
                ? (bool)$current
                : ($definition['id'] === 'role_colors_mode' ? $current !== 'disabled' : null)
        ) : null;
        $entry['differsFromOriginal'] = $definition['originalRelevant'] && $definition['originalValueAvailable']
            ? !settings_registry_values_equal($current, $definition['originalValue'], (string)$definition['type'])
            : false;
        $entry['visibleOnSurface'] = $visible;
        $entries[] = $entry;
    }
    $currentValues = [];
    foreach ($entries as $entry) $currentValues[(string)$entry['id']] = $entry['currentValue'];
    foreach ($entries as &$entry) {
        $unmet = array_values(array_filter(
            (array)$entry['dependencies'],
            static fn(string $dependency): bool => array_key_exists($dependency, $currentValues)
                && $currentValues[$dependency] === false
        ));
        $entry['unmetDependencies'] = $unmet;
        $entry['effectiveValue'] = $entry['type'] === 'boolean' && $unmet
            ? false
            : $entry['currentValue'];
    }
    unset($entry);
    usort($entries, static function (array $a, array $b): int {
        $categoryOrder = array_column(settings_registry_categories(), 'order', 'id');
        return [$categoryOrder[$a['categoryId']] ?? 999, $a['subsectionId'], (int)$a['order'], $a['id']]
            <=> [$categoryOrder[$b['categoryId']] ?? 999, $b['subsectionId'], (int)$b['order'], $b['id']];
    });

    $visibleEntries = array_values(array_filter($entries, static fn(array $entry): bool => !empty($entry['visibleOnSurface'])));
    $originalEntries = array_values(array_filter($entries, static fn(array $entry): bool => !empty($entry['originalRelevant']) && !empty($entry['originalValueAvailable'])));
    $originalCompatible = !array_filter($originalEntries, static fn(array $entry): bool => !empty($entry['differsFromOriginal']));
    $frameworkDefault = !array_filter($entries, static fn(array $entry): bool => !empty($entry['changedFromDefault']));
    $compatibilityState = $originalCompatible ? 'original-compatible' : ($frameworkDefault ? 'framework-default' : 'custom');
    $changed = count(array_filter($visibleEntries, static fn(array $entry): bool => !empty($entry['changedFromDefault'])));
    $optional = array_values(array_filter($visibleEntries, static fn(array $entry): bool => !empty($entry['optional'])));
    $enabledOptional = count(array_filter($optional, static fn(array $entry): bool => $entry['enabled'] === true));
    $danceEntries = array_values(array_filter($visibleEntries, static fn(array $entry): bool => $entry['bulkGroup'] === 'dances'));
    $enabledDances = count(array_filter($danceEntries, static fn(array $entry): bool => $entry['enabled'] === true));
    $gesturePart3Entries = array_values(array_filter($visibleEntries, static fn(array $entry): bool => $entry['bulkGroup'] === 'gesture-part-3'));
    $enabledGesturePart3 = count(array_filter($gesturePart3Entries, static fn(array $entry): bool => $entry['enabled'] === true));
    $gesturePart4Entries = array_values(array_filter($visibleEntries, static fn(array $entry): bool => $entry['bulkGroup'] === 'gesture-part-4'));
    $enabledGesturePart4 = count(array_filter($gesturePart4Entries, static fn(array $entry): bool => $entry['enabled'] === true));
    $gestureCapabilityEntries = array_values(array_filter($visibleEntries, static fn(array $entry): bool => $entry['bulkGroup'] === 'gesture-capability'));
    $enabledGestureCapabilities = count(array_filter($gestureCapabilityEntries, static fn(array $entry): bool => $entry['enabled'] === true));
    $effectiveGestureCapabilities = count(array_filter($gestureCapabilityEntries, static fn(array $entry): bool => $entry['effectiveValue'] === true));

    return [
        'schemaId' => 'chatspace.settings-registry',
        'schemaVersion' => 1,
        'revision' => settings_registry_revision($pdo),
        'surface' => $surface,
        'categories' => settings_registry_categories(),
        'entries' => $entries,
        'visibleEntries' => $visibleEntries,
        'summaries' => [
            'visibleSettingCount' => count($visibleEntries),
            'changedFromDefaultCount' => $changed,
            'optionalSettingCount' => count($optional),
            'enabledOptionalCount' => $enabledOptional,
            'danceEnabledCount' => $enabledDances,
            'danceTotalCount' => count($danceEntries),
            'gesturePart3EnabledCount' => $enabledGesturePart3,
            'gesturePart3TotalCount' => count($gesturePart3Entries),
            'gesturePart4EnabledCount' => $enabledGesturePart4,
            'gesturePart4TotalCount' => count($gesturePart4Entries),
            'gestureCapabilityEnabledCount' => $enabledGestureCapabilities,
            'gestureCapabilityEffectiveCount' => $effectiveGestureCapabilities,
            'gestureCapabilityTotalCount' => count($gestureCapabilityEntries),
            'compatibilityState' => $compatibilityState,
        ],
        'compatibility' => [
            'state' => $compatibilityState,
            'labels' => [
                'original-compatible' => 'Original-author compatible',
                'framework-default' => 'Framework default',
                'custom' => 'Custom',
            ],
            'unavoidableDifferences' => [
                'Mandatory security, privacy, authorization, validation, moderation, compatibility, concurrency, and data-integrity safeguards remain enforced.',
                'The certified multi-member relationship architecture is preserved because the original source has no safe equivalent configuration value.',
            ],
            'originalEvidence' => 'Source comparison against upstream/main at 1b1b9b750c2b508a75e0fb88c8cb57c3bd349e25.',
            'adminAccessPathPolicy' => 'Both intentional Admin access paths are preserved. Original-author mode does not hide or disable the later in-room entry point without a separate owner decision.',
        ],
        'presentationAuthority' => [
            'registryOwner' => 'chatspace.settings-registry',
            'canonicalAdminMenu' => 'lobby-admin-modal',
            'adminEntryPoints' => [
                ['id' => 'lobby-admin-entry', 'label' => 'Original lobby Admin entry point', 'historicalRole' => 'original-admin-access', 'menuOwner' => 'lobby-admin-modal'],
                ['id' => 'in-room-admin-entry', 'label' => 'In-room Admin entry point', 'historicalRole' => 'later-convenience-access', 'menuOwner' => 'lobby-admin-modal'],
            ],
            'relationship' => 'One canonical lobby-owned Admin menu with two intentional launch locations. The in-room launch is non-destructive and does not remove the administrator from the active room.',
        ],
    ];
}

function settings_registry_validate_value(array $definition, mixed $value, string $source): array {
    $type = (string)$definition['type'];
    if ($type === 'fixed') return ['ok' => false, 'code' => 'SETTING_MANDATORY', 'error' => 'Mandatory safeguards cannot be changed.', 'http_status' => 400];
    if ($type === 'asset') {
        if ($source !== 'setup') return ['ok' => false, 'code' => 'SETTING_ASSET_OWNER_REQUIRED', 'error' => 'Use the validated setup asset owner.', 'http_status' => 400];
        $path = trim((string)$value);
        if ($path !== '' && !str_starts_with($path, '/assets/uploads/branding/')) {
            return ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => 'The community logo path is invalid.', 'http_status' => 400];
        }
        return ['ok' => true, 'value' => $path];
    }
    if ($type === 'boolean') {
        $normalized = avatar_dance_capability_validate_boolean($value);
        return $normalized === null
            ? ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => $definition['label'] . ' must be enabled or disabled.', 'http_status' => 400]
            : ['ok' => true, 'value' => $normalized];
    }
    if ($type === 'number') {
        if (!is_numeric($value)) return ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => $definition['label'] . ' must be a number.', 'http_status' => 400];
        $numeric = str_contains((string)$value, '.') || is_float($definition['defaultValue']) ? (float)$value : (int)$value;
        if (($definition['minimum'] !== null && $numeric < $definition['minimum']) || ($definition['maximum'] !== null && $numeric > $definition['maximum'])) {
            return ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => $definition['label'] . ' must be from ' . $definition['minimum'] . ' to ' . $definition['maximum'] . '.', 'http_status' => 400];
        }
        if ((float)($definition['step'] ?? 1) >= 1 && (float)$numeric !== (float)(int)$numeric) {
            return ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => $definition['label'] . ' must be a whole number.', 'http_status' => 400];
        }
        return ['ok' => true, 'value' => $numeric];
    }
    if ($type === 'select') {
        $value = (string)$value;
        if (!in_array($value, $definition['allowedValues'] ?? [], true)) return ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => 'Choose an allowed value for ' . $definition['label'] . '.', 'http_status' => 400];
        return ['ok' => true, 'value' => $value];
    }
    $value = trim((string)$value);
    if ($definition['maximum'] !== null && (function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value)) > (int)$definition['maximum']) {
        return ['ok' => false, 'code' => 'SETTING_VALUE_INVALID', 'error' => $definition['label'] . ' is too long.', 'http_status' => 400];
    }
    return ['ok' => true, 'value' => $value];
}

function settings_registry_target_ids(array $request, array $snapshot): array {
    $operation = (string)($request['operation'] ?? '');
    if ($operation === 'set' || $operation === 'set_many') return array_values(array_unique(array_map('strval', array_keys((array)($request['values'] ?? [])))));
    if ($operation === 'reset_setting') return [(string)($request['setting_id'] ?? '')];
    if ($operation === 'reset_subsection') {
        $category = (string)($request['category_id'] ?? '');
        $subsection = (string)($request['subsection_id'] ?? '');
        return array_values(array_map(static fn(array $entry): string => (string)$entry['id'], array_filter($snapshot['entries'], static fn(array $entry): bool => $entry['categoryId'] === $category && $entry['subsectionId'] === $subsection && !empty($entry['safeToReset']))));
    }
    if ($operation === 'reset_category') {
        $category = (string)($request['category_id'] ?? '');
        return array_values(array_map(static fn(array $entry): string => (string)$entry['id'], array_filter($snapshot['entries'], static fn(array $entry): bool => $entry['categoryId'] === $category && !empty($entry['safeToReset']))));
    }
    if ($operation === 'reset_all_optional') return array_values(array_map(static fn(array $entry): string => (string)$entry['id'], array_filter($snapshot['entries'], static fn(array $entry): bool => !empty($entry['optional']) && !empty($entry['safeToReset']))));
    if ($operation === 'apply_preset') {
        $preset = (string)($request['preset'] ?? '');
        if (!in_array($preset, ['original-compatible', 'framework-default'], true)) return [];
        return array_values(array_map(static fn(array $entry): string => (string)$entry['id'], array_filter($snapshot['entries'], static fn(array $entry): bool => !empty($entry['originalRelevant']) && !empty($entry['originalValueAvailable']) && !empty($entry['safeToReset']))));
    }
    return [];
}

function settings_registry_update(PDO $pdo, array $request, mixed $expectedRevision, int $actorUserId, string $source = 'admin'): array {
    $parsedRevision = filter_var($expectedRevision, FILTER_VALIDATE_INT);
    if ($parsedRevision === false || (int)$parsedRevision < 1) return ['ok' => false, 'code' => 'SETTINGS_REGISTRY_REVISION_REQUIRED', 'error' => 'A current settings revision is required.', 'http_status' => 400];
    $operation = (string)($request['operation'] ?? '');
    $broad = in_array($operation, ['reset_subsection', 'reset_category', 'reset_all_optional', 'apply_preset'], true);
    if ($broad && empty($request['confirmed'])) return ['ok' => false, 'code' => 'SETTINGS_REGISTRY_CONFIRMATION_REQUIRED', 'error' => 'Review and confirm this broad settings operation.', 'http_status' => 409];

    $ownsTransaction = !$pdo->inTransaction();
    try {
        if ($ownsTransaction) {
            if (db_uses_mysql_syntax($pdo)) {
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                $pdo->beginTransaction();
            } else {
                $pdo->exec('BEGIN IMMEDIATE TRANSACTION');
            }
        }
        $lockSql = 'SELECT value FROM app_settings WHERE setting_key = ? LIMIT 1';
        if (db_uses_mysql_syntax($pdo)) $lockSql .= ' FOR UPDATE';
        $lock = $pdo->prepare($lockSql);
        $lock->execute([SETTINGS_REGISTRY_REVISION_SETTING]);
        $actualRevision = max(1, (int)($lock->fetchColumn() ?: 1));
        $snapshot = settings_registry_snapshot($pdo, $source === 'setup' ? 'setup' : 'admin');
        $definitionMap = settings_registry_definition_map();
        $entryMap = [];
        foreach ($snapshot['entries'] as $entry) $entryMap[$entry['id']] = $entry;
        $ids = settings_registry_target_ids($request, $snapshot);
        if (!$ids || array_diff($ids, array_keys($definitionMap))) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'code' => 'SETTINGS_REGISTRY_OPERATION_INVALID', 'error' => 'Choose a registered settings operation.', 'http_status' => 400];
        }

        foreach ($ids as $id) {
            $visible = $source === 'setup' ? !empty($definitionMap[$id]['setupVisible']) : !empty($definitionMap[$id]['adminVisible']);
            if (!$visible) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'code' => 'SETTING_SURFACE_FORBIDDEN', 'error' => 'That setting is not available on this settings surface.', 'http_status' => 403];
            }
            if ($operation === 'reset_setting' && empty($definitionMap[$id]['safeToReset'])) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'code' => 'SETTING_RESET_FORBIDDEN', 'error' => 'That setting cannot be reset through this operation.', 'http_status' => 400];
            }
        }

        $target = [];
        $provided = (array)($request['values'] ?? []);
        foreach ($ids as $id) {
            $definition = $definitionMap[$id];
            if ($operation === 'set' || $operation === 'set_many') {
                if (!array_key_exists($id, $provided)) {
                    if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                    return ['ok' => false, 'code' => 'SETTING_VALUE_REQUIRED', 'error' => 'A value is required for ' . $definition['label'] . '.', 'http_status' => 400];
                }
                $candidate = $provided[$id];
            } elseif ($operation === 'apply_preset' && (string)$request['preset'] === 'original-compatible') {
                $candidate = $definition['originalValue'];
            } else {
                $candidate = $definition['defaultValue'];
            }
            $validation = settings_registry_validate_value($definition, $candidate, $source);
            if (empty($validation['ok'])) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return $validation;
            }
            $target[$id] = $validation['value'];
        }
        $changedIds = [];
        foreach ($target as $id => $value) {
            $comparisonValue = !empty($definitionMap[$id]['secret'])
                ? app_setting($pdo, (string)$definitionMap[$id]['settingKey'], '')
                : $entryMap[$id]['currentValue'];
            if (!settings_registry_values_equal($value, $comparisonValue, (string)$definitionMap[$id]['type'])) $changedIds[] = $id;
        }
        if ($actualRevision !== (int)$parsedRevision && $changedIds) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'code' => 'SETTINGS_REGISTRY_STALE', 'error' => 'Settings changed. Refresh and try again.', 'revision' => $actualRevision, 'http_status' => 409];
        }
        if (!$changedIds) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
            return ['ok' => true, 'idempotent' => true, 'revision' => $actualRevision, 'changedSettingCount' => 0, 'stoppedActiveCapabilityCount' => 0, 'registry' => settings_registry_snapshot($pdo, $source === 'setup' ? 'setup' : 'admin')];
        }

        $effective = [];
        foreach ($entryMap as $id => $entry) $effective[$id] = $entry['currentValue'];
        foreach ($target as $id => $value) $effective[$id] = $value;
        if (!empty($effective['diagnostic_screenshots_enabled'])) {
            $days = (int)$effective['diagnostic_screenshot_retention_days'];
            if ($days < 1 || $days > 365) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return ['ok' => false, 'code' => 'DIAGNOSTIC_SCREENSHOT_RETENTION_REQUIRED', 'error' => 'Enabled screenshots require a retention period from 1 to 365 days.', 'http_status' => 400];
            }
        }
        $roleInput = [];
        foreach (role_color_setting_defaults() as $key => $default) $roleInput[$key] = $effective[$key] ?? $default;
        $roleValidation = role_color_validate_settings($roleInput);
        if (empty($roleValidation['ok'])) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            return $roleValidation;
        }

        $stopped = 0;
        $changedMap = array_fill_keys($changedIds, true);
        $gestureCapabilityChanged = array_values(array_filter(
            $changedIds,
            static fn(string $id): bool => gesture_capability_definition($id) !== null
        ));
        $gestureCapabilityProjection = null;
        if ($gestureCapabilityChanged) {
            $capabilityValues = gesture_capability_policy($pdo)['stored'];
            foreach ($target as $id => $value) {
                if (gesture_capability_definition($id) !== null) $capabilityValues[$id] = (bool)$value;
            }
            $gestureCapabilityResult = gesture_capability_update_locked($pdo, $capabilityValues);
            $gestureCapabilityProjection = $gestureCapabilityResult['capability'];
        }
        $danceChanged = array_filter($changedIds, static fn(string $id): bool => str_starts_with($id, 'avatar_dance.'));
        if ($danceChanged) {
            $danceValues = avatar_dance_capability_normalize_values(avatar_dance_capability_policy($pdo)['enabled']);
            foreach ($target as $id => $value) if (str_starts_with($id, 'avatar_dance.')) $danceValues[substr($id, strlen('avatar_dance.'))] = (bool)$value;
            $dancePolicy = avatar_dance_capability_policy($pdo);
            $danceResult = avatar_dance_capability_update($pdo, ['operation' => 'replace', 'enabled' => $danceValues], $dancePolicy['revision'], $actorUserId, 'settings-registry');
            if (empty($danceResult['ok'])) throw new RuntimeException((string)($danceResult['error'] ?? 'Dance policy update failed.'));
            $stopped += (int)($danceResult['stoppedStateCount'] ?? 0);
        }
        if (isset($changedMap['avatar_relationship_max_regular_links'])) {
            $capacity = avatar_relationship_capacity_policy($pdo);
            $capacityConfirmed = $source === 'setup' || !empty($request['capacity_confirmed']);
            $capacityResult = avatar_relationship_capacity_update($pdo, $target['avatar_relationship_max_regular_links'], $capacity['revision'], $capacityConfirmed, $actorUserId, 'settings-registry');
            if (empty($capacityResult['ok'])) {
                if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
                return $capacityResult;
            }
        }
        $sizeChanged = array_filter($changedIds, static fn(string $id): bool => isset(avatar_size_policy_setting_map()[$id]));
        if ($sizeChanged) {
            $sizeInput = [];
            foreach (avatar_size_policy_setting_map() as $key => $publicKey) $sizeInput[$key] = $effective[$key];
            $sizeResult = avatar_size_policy_update($pdo, $sizeInput);
            if (empty($sizeResult['ok'])) throw new RuntimeException((string)($sizeResult['error'] ?? 'Display policy update failed.'));
        }
        if (isset($changedMap['allow_webcam_use'])) {
            $webcamResult = webcam_capability_update($pdo, (bool)$target['allow_webcam_use']);
            $stopped += (int)($webcamResult['stoppedParticipantCount'] ?? 0);
        }
        $roleChanged = array_filter($changedIds, static fn(string $id): bool => $id === 'role_colors_mode' || str_starts_with($id, 'role_color_'));
        if ($roleChanged) {
            set_app_setting($pdo, 'role_colors_mode', $roleValidation['mode']);
            foreach ($roleValidation['palette'] as $role => $colors) {
                set_app_setting($pdo, "role_color_{$role}_bg", $colors['background']);
                set_app_setting($pdo, "role_color_{$role}_text", $colors['text']);
            }
            $colors = role_color_settings($pdo);
            foreach ($pdo->query('SELECT id FROM room_sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $sessionId) emit_event($pdo, (int)$sessionId, 'role_colors_update', $colors);
        }

        $special = array_fill_keys(array_merge(
            array_keys(avatar_size_policy_setting_map()),
            ['avatar_relationship_max_regular_links', 'allow_webcam_use', 'role_colors_mode'],
            array_keys(role_color_setting_defaults()),
            array_map(static fn(array $capability): string => (string)$capability['id'], gesture_capability_registry()),
            array_map(static fn(array $dance): string => 'avatar_dance.' . $dance['id'], avatar_dance_capability_registry())
        ), true);
        foreach ($changedIds as $id) {
            if (isset($special[$id])) continue;
            $definition = $definitionMap[$id];
            $value = $target[$id];
            $stored = match ($definition['type']) {
                'boolean' => $value ? '1' : '0',
                'number' => (string)$value,
                default => (string)$value,
            };
            set_app_setting($pdo, (string)$definition['settingKey'], $stored);
        }

        $nextRevision = $actualRevision + 1;
        set_app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, (string)$nextRevision);
        $scope = match ($operation) {
            'reset_setting' => 'setting',
            'reset_subsection' => 'subsection',
            'reset_category' => 'category',
            'reset_all_optional' => 'all-optional',
            'apply_preset' => 'preset-' . (string)$request['preset'],
            default => count($changedIds) === 1 ? 'setting' : 'selection',
        };
        $listed = array_slice($changedIds, 0, 12);
        $detail = 'Settings registry ' . $scope . '; revision ' . $actualRevision . ' to ' . $nextRevision
            . '; changed ' . count($changedIds) . '; ids ' . implode(',', $listed)
            . (count($changedIds) > count($listed) ? ',+' . (count($changedIds) - count($listed)) . ' more' : '')
            . '; stopped ' . $stopped . ' active optional state(s).';
        log_tool($pdo, $actorUserId > 0 ? $actorUserId : null, $source === 'setup' ? 'setup_settings_registry_update' : 'admin_settings_registry_update', null, null, $detail);
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->commit();
        if ($gestureCapabilityChanged) {
            gesture_capability_emit($pdo, gesture_capability_policy($pdo));
        }
        return [
            'ok' => true, 'idempotent' => false, 'revision' => $nextRevision,
            'changedSettingCount' => count($changedIds), 'changedSettingIds' => $changedIds,
            'stoppedActiveCapabilityCount' => $stopped,
            'registry' => settings_registry_snapshot($pdo, $source === 'setup' ? 'setup' : 'admin'),
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
