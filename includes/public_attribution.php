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
        'editable' => false,
        'future_editing_checkpoint' => 'Build 000050 Private Site Branding Extension',
    ];
}

function public_room_version_attribution(): string {
    $value = trim((string)public_room_version_attribution_definition()['default']);
    if ($value === '') {
        throw new LogicException('The required public room-version attribution is blank.');
    }
    return $value;
}
