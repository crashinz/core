<?php
declare(strict_types=1);

const CHATSPACE_ROOM_VERSION_ATTRIBUTION_ID = 'room_version_attribution';
const CHATSPACE_ROOM_VERSION_ATTRIBUTION_DEFAULT = 'Modified by exe';

function public_room_version_attribution_definition(): array {
    return [
        'id' => CHATSPACE_ROOM_VERSION_ATTRIBUTION_ID,
        'default' => CHATSPACE_ROOM_VERSION_ATTRIBUTION_DEFAULT,
        'destination' => "Chat Room \u{2192} Sidebar version line",
        'required' => true,
        'editable' => true,
        'owner' => 'Private Site Branding',
    ];
}

function public_room_version_attribution(?PDO $pdo = null): string {
    $value = (string)public_room_version_attribution_definition()['default'];
    if ($pdo instanceof PDO) {
        try {
            $value = (string)private_site_branding_projection($pdo, 'room')['room_version_attribution'];
        } catch (Throwable) {
            $value = (string)public_room_version_attribution_definition()['default'];
        }
    }
    $value = trim($value);
    if ($value === '') {
        throw new LogicException('The required public room-version attribution is blank.');
    }
    return $value;
}
