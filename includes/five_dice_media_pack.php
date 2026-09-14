<?php
declare(strict_types=1);

require_once __DIR__ . '/five_dice_identity.php';
require_once __DIR__ . '/ocx_static_media_extractor.php';

/**
 * Installation-private Five Dice presentation pack.
 *
 * The location is outside the public web root and every served file is bound
 * to an allowlisted semantic slot. The pack never owns rules, state, results,
 * randomness, or lifecycle. Missing or invalid files remain independent and
 * the shipped CSS/canvas presentation stays authoritative.
 */

const FIVE_DICE_MEDIA_PACK_CATEGORY = 'five-dice-media-pack';
const FIVE_DICE_MEDIA_PACK_GENERATION_SETTING = 'five_dice_media_pack_active_generation';
const FIVE_DICE_MEDIA_PACK_GENERATIONS_CATEGORY = 'five-dice-media-pack-generations';
const FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY = 'five-dice-media-pack-attempts';
const FIVE_DICE_MEDIA_PACK_OPERATIONS_CATEGORY = 'five-dice-media-pack-operations';
const FIVE_DICE_MEDIA_PACK_MAX_UPLOAD_BYTES = 67108864;
const FIVE_DICE_MEDIA_PACK_UPLOAD_BATCH_SIZE = 10;
const FIVE_DICE_MEDIA_PACK_ATTEMPT_LIFETIME_SECONDS = 1800;

function five_dice_media_pack_slots(): array
{
    $slots = [
        'classic-board' => [
            'label' => 'Classic game board',
            'installName' => 'classic-board.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 4194304,
            'maximumWidth' => 2048,
            'maximumHeight' => 2048,
            'requiredWidth' => 840,
            'requiredHeight' => 640,
            'requiredForClassic' => true,
        ],
        'roll-control' => [
            'label' => 'Roll control',
            'installName' => 'roll-control.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 104,
            'requiredHeight' => 78,
            'requiredForClassic' => true,
        ],
        'roll-control-hover' => [
            'label' => 'Roll control hover state',
            'installName' => 'roll-control-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 104,
            'requiredHeight' => 78,
            'requiredForClassic' => true,
        ],
        'roll-control-pressed' => [
            'label' => 'Roll control pressed state',
            'installName' => 'roll-control-pressed.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 104,
            'requiredHeight' => 78,
            'requiredForClassic' => true,
        ],
        'roll-control-disabled' => [
            'label' => 'Roll control unavailable state',
            'installName' => 'roll-control-disabled.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 104,
            'requiredHeight' => 78,
            'requiredForClassic' => true,
        ],
        'sound-on' => [
            'label' => 'Sound on control',
            'installName' => 'sound-on.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 70,
            'requiredHeight' => 36,
            'requiredForClassic' => true,
        ],
        'sound-on-hover' => [
            'label' => 'Sound on hover and focus control',
            'installName' => 'sound-on-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 70,
            'requiredHeight' => 36,
            'requiredForClassic' => true,
        ],
        'sound-off' => [
            'label' => 'Sound off control',
            'installName' => 'sound-off.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 70,
            'requiredHeight' => 36,
            'requiredForClassic' => true,
        ],
        'sound-off-hover' => [
            'label' => 'Sound off hover and focus control',
            'installName' => 'sound-off-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 70,
            'requiredHeight' => 36,
            'requiredForClassic' => true,
        ],
        'music-on' => [
            'label' => 'Music on control',
            'installName' => 'music-on.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 130,
            'requiredHeight' => 66,
            'requiredForClassic' => true,
        ],
        'music-on-hover' => [
            'label' => 'Music on hover and focus control',
            'installName' => 'music-on-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 130,
            'requiredHeight' => 66,
            'requiredForClassic' => true,
        ],
        'music-off' => [
            'label' => 'Music off control',
            'installName' => 'music-off.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 130,
            'requiredHeight' => 66,
            'requiredForClassic' => true,
        ],
        'music-off-hover' => [
            'label' => 'Music off hover and focus control',
            'installName' => 'music-off-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 130,
            'requiredHeight' => 66,
            'requiredForClassic' => true,
        ],
        'gfx-on' => [
            'label' => 'Visual effects on control',
            'installName' => 'gfx-on.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 146,
            'requiredHeight' => 110,
            'requiredForClassic' => true,
        ],
        'gfx-on-hover' => [
            'label' => 'Visual effects on hover and focus control',
            'installName' => 'gfx-on-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 146,
            'requiredHeight' => 110,
            'requiredForClassic' => true,
        ],
        'gfx-off' => [
            'label' => 'Visual effects off control',
            'installName' => 'gfx-off.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 146,
            'requiredHeight' => 110,
            'requiredForClassic' => true,
        ],
        'gfx-off-hover' => [
            'label' => 'Visual effects off hover and focus control',
            'installName' => 'gfx-off-hover.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 146,
            'requiredHeight' => 110,
            'requiredForClassic' => true,
        ],
        'rolling-dice-a' => [
            'label' => 'Rolling dice animation A',
            'installName' => 'rolling-dice-a.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 1048576,
            'maximumWidth' => 512,
            'maximumHeight' => 2048,
            'requiredWidth' => 70,
            'requiredHeight' => 840,
            'requiredForClassic' => true,
            'preparation' => [
                'owner' => 'five-dice-temporal-variance-strip-alpha-v1',
                'frameWidth' => 70,
                'frameHeight' => 70,
                'frameCount' => 12,
                'varianceThreshold' => 100,
                'dilation' => 3,
                'outputMime' => 'image/png',
            ],
        ],
        'rolling-dice-b' => [
            'label' => 'Rolling dice animation B',
            'installName' => 'rolling-dice-b.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 1048576,
            'maximumWidth' => 512,
            'maximumHeight' => 2048,
            'requiredWidth' => 70,
            'requiredHeight' => 840,
            'requiredForClassic' => true,
            'preparation' => [
                'owner' => 'five-dice-temporal-variance-strip-alpha-v1',
                'frameWidth' => 70,
                'frameHeight' => 70,
                'frameCount' => 12,
                'varianceThreshold' => 100,
                'dilation' => 3,
                'outputMime' => 'image/png',
            ],
        ],
        'drum-motion' => [
            'label' => 'Music-owned drum animation',
            'installName' => 'drum-motion.png',
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 1048576,
            'maximumWidth' => 512,
            'maximumHeight' => 2048,
            'requiredWidth' => 92,
            'requiredHeight' => 450,
            'requiredForClassic' => true,
            'preparation' => [
                'owner' => 'five-dice-temporal-variance-strip-alpha-v1',
                'frameWidth' => 92,
                'frameHeight' => 90,
                'frameCount' => 5,
                'varianceThreshold' => 100,
                'dilation' => 2,
                'outputMime' => 'image/png',
            ],
        ],
    ];
    for ($face = 0; $face <= 6; $face++) {
        $slots["die-{$face}"] = [
            'label' => $face === 0 ? 'Blank die' : "Die {$face}",
            'installName' => "die-{$face}.png",
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 56,
            'requiredHeight' => 56,
            'requiredForClassic' => true,
        ];
        if ($face === 0) continue;
        $slots["die-{$face}"]['preparation'] = [
            'owner' => 'five-dice-blank-frame-silhouette-alpha-v2',
            'maskSourceSlot' => 'die-0',
            'outputMime' => 'image/png',
        ];
        $slots["held-die-{$face}"] = [
            'label' => "Held die {$face}",
            'installName' => "held-die-{$face}.png",
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 512,
            'maximumHeight' => 512,
            'requiredWidth' => 56,
            'requiredHeight' => 56,
            'requiredForClassic' => true,
            'preparation' => [
                'owner' => 'five-dice-blank-frame-silhouette-alpha-v2',
                'maskSourceSlot' => 'die-0',
                'outputMime' => 'image/png',
            ],
        ];
    }
    $approvedSourceImages = [
        'current-player-lane' => ['GIF_HSTRIP@2x.png', 62, 506, '2A7A8A9017F32FFB2D664AE4D4F30C8FB0648B56C262789E68BE6747FE8E5AF1'],
        'start-new-game' => ['GIF_STARTOVER@2x.png', 168, 42, 'D20D73345566BD22AEEE8B2F338B362034260F4C9702679E7D2D00D75BAFED79'],
        'microphone-wave-strip' => ['GIF_SPEACH@2x.png', 24, 598, '1BDA9E99FB348D3BB6A60E07D479C1D340E1D6FCF9C8C42E26725CA3839A60C3'],
        'player-lane-1' => ['GIF_STRIP01@2x.png', 62, 506, 'AD9D9CFB34558F69C23DA3BD1EE3DF3422049EDD2F806FF395CA095BA39B8225'],
        'on-hold-lane-1' => ['GIF_STRIP01W@2x.png', 62, 506, '06385FB245D5DD3763D41A8309602C2FFE8AA99DE393170BC5734BB4DD682C56'],
        'player-lane-2' => ['GIF_STRIP02@2x.png', 62, 506, 'D0F113F25F8955ADD51289DEB9534455108C806B7ED18D22A8E6B32AB1B06E44'],
        'on-hold-lane-2' => ['GIF_STRIP02W@2x.png', 62, 506, 'B8899791BADE09937099A143B6A42AB6DFFFD627453CF637DDE962C2AD0C670D'],
        'player-lane-3' => ['GIF_STRIP03@2x.png', 62, 506, '9AB8D8A7B7E2FB771977E6BA0B090A38347949822B59F9B17AE5EEFAB48A156C'],
        'on-hold-lane-3' => ['GIF_STRIP03W@2x.png', 62, 506, '3B9B93C52B02BF882621A4ED44AD630989B863953433858B1C60E204F2D1F6AA'],
        'player-lane-4' => ['GIF_STRIP04@2x.png', 62, 506, '497CBD751BFB3EC643878C59AD266131F45B753E44FBFA107D09671955639CDC'],
        'on-hold-lane-4' => ['GIF_STRIP04W@2x.png', 62, 506, '22E6A4E96727FBD3EA6B4AB5DEE0BFEF667978717FAF04856ABE369698302C72'],
    ];
    foreach ($approvedSourceImages as $slot => [$sourceName, $width, $height, $sha256]) {
        $slots[$slot] = [
            'label' => ucwords(str_replace('-', ' ', $slot)),
            'installName' => $slot . '.png',
            'sourceName' => $sourceName,
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => $width,
            'maximumHeight' => $height,
            'requiredWidth' => $width,
            'requiredHeight' => $height,
            'requiredSha256' => $sha256,
            'requiredForClassic' => true,
        ];
    }
    $scoreRowHashes = [
        500 => 'D8F3A266CE582FC425579485F532B960EB8F0615CA512FE8905A7408B012A43E',
        501 => 'B0675A7B2D289F5BA2A07A83290C0CEA588EC050D55C0D10CBC947E96C244B41',
        502 => '41887E79E7219EBCD329772D2A0DB2133B63782408A15B03EED5E11D1CC80D6B',
        503 => 'A36FF0A5AEAC5097C5057486AA4C29229F8BED8316D9A2E631E4110F2100BF30',
        504 => '9EA8634D8E4C534C640EE134CA1570D634A0375D3BA398434E27A51E29FB2C3A',
        505 => 'E834683A71ABC54D9C76F3382E259D3D33D60EF5A70FDD41BD9AA6BD6750BF46',
        506 => '54E6C896E127AA873CD56452A24864ACDBD2321C465209C949FB627B5D1422C8',
        507 => 'E62933DABAB35D8345F4F5E315C0A97B473E315ABC5F91E2256614768E44F748',
        508 => '9BF1F0ED87A3C8A8721A621E03E000E9E4EA50D5292F18E75F7C6F3D0CB83443',
        509 => 'D78D99D5DADE5C0B9E3D5AA63E70D4F5D35F409BB1EEC0FF3720D700D2304E88',
        510 => '7A7E9182E6EDA0ECB8C693109262455EFCFA6C5A713CAB80EA6659D50DF873BB',
        511 => '7FB07BADD1991CE08A943AA6133B4CAF2BDC5F5214B461112988CD26AF8D25DC',
        512 => 'CDCBED1ED080E943FB8653425BED80C0D0121CC442C217F1D9A427E69248B6A1',
        513 => 'E14214E0F5259BE3AB4FEC6FE3913EF44DE1E9E940CCFF4D3A42F1F8EDD0114B',
        514 => '6535D8EE4796941CDE1E4E6DA5A8B981DB059C2F4574A818FF8563EBD4B85E37',
        515 => '040B228B92EC2A9667501D2505D0A37AE9DFEDBB9B8B463D391B888FF2878886',
        516 => 'D475841A132A710E69183A8682C4AB8DEB4BB88CF2B048BA462F8ADE1C859654',
    ];
    foreach ($scoreRowHashes as $resourceId => $sha256) {
        $slots["score-row-{$resourceId}"] = [
            'label' => "Score row source resource {$resourceId}",
            'installName' => "score-row-{$resourceId}.png",
            'sourceName' => "{$resourceId}@2x.png",
            'kind' => 'image',
            'mime' => 'image/png',
            'maximumBytes' => 524288,
            'maximumWidth' => 160,
            'maximumHeight' => 48,
            'requiredWidth' => 160,
            'requiredHeight' => 48,
            'requiredSha256' => $sha256,
            'requiredForClassic' => true,
        ];
    }
    return $slots + [
        'background-music' => [
            'label' => 'Background music',
            'installName' => 'background-music.mp3',
            'kind' => 'mp3',
            'mime' => 'audio/mpeg',
            'maximumBytes' => 5242880,
            'requiredForClassic' => true,
        ],
        'roll-sound' => [
            'label' => 'Roll sound',
            'installName' => 'roll.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
        'hold-sound' => [
            'label' => 'Hold sound',
            'installName' => 'hold.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
        'release-sound' => [
            'label' => 'Release sound',
            'installName' => 'release.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
        'invalid-sound' => [
            'label' => 'Unavailable-action sound',
            'installName' => 'invalid.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
        'celebration-sound' => [
            'label' => 'Score celebration sound',
            'installName' => 'celebration.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
        'ready-sound' => [
            'label' => 'Game ready sound',
            'installName' => 'ready.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
        'win-sound' => [
            'label' => 'Win sound',
            'installName' => 'win.wav',
            'kind' => 'wav',
            'mime' => 'audio/wav',
            'maximumBytes' => 1048576,
            'requiredForClassic' => true,
        ],
    ];
}

function five_dice_media_pack_original_filename_map(): array
{
    $map = [
        '536@2x.png' => 'classic-board',
        'gif_roll_r@2x.png' => 'roll-control',
        'gif_roll_h@2x.png' => 'roll-control-hover',
        'gif_roll_p@2x.png' => 'roll-control-pressed',
        'gif_roll_d@2x.png' => 'roll-control-disabled',
        'gif_sound_dr@2x.png' => 'sound-on',
        'gif_sound_dh@2x.png' => 'sound-on-hover',
        'gif_sound_ur@2x.png' => 'sound-off',
        'gif_sound_uh@2x.png' => 'sound-off-hover',
        'gif_music_dr@2x.png' => 'music-on',
        'gif_music_dh@2x.png' => 'music-on-hover',
        'gif_music_ur@2x.png' => 'music-off',
        'gif_music_uh@2x.png' => 'music-off-hover',
        'gif_gfx_ur@2x.png' => 'gfx-on',
        'gif_gfx_uh@2x.png' => 'gfx-on-hover',
        'gif_gfx_dr@2x.png' => 'gfx-off',
        'gif_gfx_dh@2x.png' => 'gfx-off-hover',
        '517@2x.png' => 'rolling-dice-a',
        '518@2x.png' => 'rolling-dice-b',
        '522@2x.png' => 'drum-motion',
        'gif_dice0@2x.png' => 'die-0',
        'gif_dice1@2x.png' => 'die-1',
        'gif_dice2@2x.png' => 'die-2',
        'gif_dice3@2x.png' => 'die-3',
        'gif_dice4@2x.png' => 'die-4',
        'gif_dice5@2x.png' => 'die-5',
        'gif_dice6@2x.png' => 'die-6',
        'gif_dice1lck@2x.png' => 'held-die-1',
        'gif_dice2lck@2x.png' => 'held-die-2',
        'gif_dice3lck@2x.png' => 'held-die-3',
        'gif_dice4lck@2x.png' => 'held-die-4',
        'gif_dice5lck@2x.png' => 'held-die-5',
        'gif_dice6lck@2x.png' => 'held-die-6',
        'mid_ytz.mp3' => 'background-music',
        'wav_roll.wav' => 'roll-sound',
        'wav_lock.wav' => 'hold-sound',
        'wav_unlock.wav' => 'release-sound',
        'wav_buzz.wav' => 'invalid-sound',
        'wav_cheers.wav' => 'celebration-sound',
        'wav_ready.wav' => 'ready-sound',
        'wav_yourwin.wav' => 'win-sound',
    ];
    foreach (five_dice_media_pack_slots() as $slot => $definition) {
        $sourceName = strtolower(trim((string)($definition['sourceName'] ?? '')));
        if ($sourceName !== '') $map[$sourceName] = (string)$slot;
    }
    return $map;
}

function five_dice_media_pack_accepted_filename_map(): array
{
    $map = five_dice_media_pack_original_filename_map();
    foreach (five_dice_media_pack_slots() as $slot => $definition) {
        $map[strtolower((string)$definition['installName'])] = (string)$slot;
    }
    return $map;
}

function five_dice_media_pack_root_directory(): string
{
    return security_private_storage_directory(FIVE_DICE_MEDIA_PACK_CATEGORY);
}

function five_dice_media_pack_generation_directory(string $generation): ?string
{
    if (!preg_match('/^generation-[a-f0-9-]{36}$/', $generation)) return null;
    $root = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_GENERATIONS_CATEGORY);
    $path = $root . DIRECTORY_SEPARATOR . $generation;
    return is_dir($path) ? $path : null;
}

function five_dice_media_pack_directory(?PDO $pdo = null): string
{
    $pdo ??= db();
    $generation = trim(app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, ''));
    $generationDirectory = $generation === '' ? null : five_dice_media_pack_generation_directory($generation);
    return $generationDirectory ?? five_dice_media_pack_root_directory();
}

function five_dice_wav_signature_valid(string $path): bool
{
    $bytes = (string)file_get_contents($path);
    if (strlen($bytes) < 44 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WAVE') return false;
    $riffLength = unpack('V', substr($bytes, 4, 4))[1] ?? 0;
    if ($riffLength + 8 > strlen($bytes)) return false;
    $offset = 12;
    $hasFormat = false;
    $hasData = false;
    while ($offset + 8 <= strlen($bytes)) {
        $chunk = substr($bytes, $offset, 4);
        $length = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? -1;
        if ($length < 0 || $offset + 8 + $length > strlen($bytes)) return false;
        if ($chunk === 'fmt ' && $length >= 16) $hasFormat = true;
        if ($chunk === 'data' && $length > 0) $hasData = true;
        $offset += 8 + $length + ($length % 2);
    }
    return $hasFormat && $hasData;
}

function five_dice_mp3_frame_length(string $bytes, int $offset): int
{
    if ($offset + 4 > strlen($bytes)) return 0;
    $header = unpack('N', substr($bytes, $offset, 4))[1] ?? 0;
    if (($header & 0xFFE00000) !== 0xFFE00000) return 0;
    $versionBits = ($header >> 19) & 0x3;
    $layerBits = ($header >> 17) & 0x3;
    $bitrateIndex = ($header >> 12) & 0xF;
    $sampleIndex = ($header >> 10) & 0x3;
    if (!in_array($versionBits, [2, 3], true) || $layerBits !== 1
        || in_array($bitrateIndex, [0, 15], true) || $sampleIndex === 3) return 0;
    $bitrates = $versionBits === 3
        ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320]
        : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
    $sampleRates = $versionBits === 3 ? [44100, 48000, 32000] : [22050, 24000, 16000];
    $coefficient = $versionBits === 3 ? 144 : 72;
    $padding = ($header >> 9) & 0x1;
    return (int)floor($coefficient * ($bitrates[$bitrateIndex] * 1000) / $sampleRates[$sampleIndex]) + $padding;
}

function five_dice_mp3_signature_valid(string $path): bool
{
    $bytes = (string)file_get_contents($path);
    $offset = 0;
    if (str_starts_with($bytes, 'ID3') && strlen($bytes) >= 10) {
        $size = 0;
        for ($index = 6; $index <= 9; $index++) {
            $value = ord($bytes[$index]);
            if (($value & 0x80) !== 0) return false;
            $size = ($size << 7) | $value;
        }
        $offset = 10 + $size;
    }
    $first = five_dice_mp3_frame_length($bytes, $offset);
    if ($first < 24 || $offset + $first + 4 > strlen($bytes)) return false;
    $second = five_dice_mp3_frame_length($bytes, $offset + $first);
    return $second >= 24 && $offset + $first + $second <= strlen($bytes);
}

function five_dice_media_pack_validate_slot(string $slot, ?string $directory = null): array
{
    $definition = five_dice_media_pack_slots()[$slot] ?? null;
    if (!is_array($definition)) {
        return ['state' => 'invalid', 'reason' => 'Unknown media slot.'];
    }
    $root = $directory ?? five_dice_media_pack_directory();
    $path = $root . DIRECTORY_SEPARATOR . (string)$definition['installName'];
    if (!is_file($path)) {
        return $definition + ['slot' => $slot, 'state' => 'missing', 'path' => null];
    }
    $resolvedRoot = realpath($root);
    $resolvedPath = realpath($path);
    $bytes = $resolvedPath === false ? false : filesize($resolvedPath);
    if ($resolvedRoot === false || $resolvedPath === false
        || !str_starts_with(
            strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedPath)) . DIRECTORY_SEPARATOR,
            strtolower(rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedRoot), DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR
        )
        || $bytes === false || $bytes < 1 || $bytes > (int)$definition['maximumBytes']) {
        return $definition + ['slot' => $slot, 'state' => 'invalid', 'path' => null, 'reason' => 'The file is unavailable or outside its safe size boundary.'];
    }
    $prefix = (string)file_get_contents($resolvedPath, false, null, 0, 16);
    $sha256 = strtoupper(hash_file('sha256', $resolvedPath));
    $valid = false;
    $dimensions = null;
    if ($definition['kind'] === 'image') {
        $image = @getimagesize($resolvedPath);
        $valid = str_starts_with($prefix, "\x89PNG\r\n\x1a\n")
            && is_array($image)
            && (string)($image['mime'] ?? '') === 'image/png'
            && (int)$image[0] >= 16
            && (int)$image[0] <= (int)($definition['maximumWidth'] ?? 512)
            && (int)$image[1] >= 16
            && (int)$image[1] <= (int)($definition['maximumHeight'] ?? 512)
            && (!isset($definition['requiredWidth']) || (int)$image[0] === (int)$definition['requiredWidth'])
            && (!isset($definition['requiredHeight']) || (int)$image[1] === (int)$definition['requiredHeight'])
            && (!isset($definition['requiredSha256']) || hash_equals(strtoupper((string)$definition['requiredSha256']), $sha256));
        if (is_array($image)) $dimensions = [(int)$image[0], (int)$image[1]];
    } elseif ($definition['kind'] === 'wav') {
        $valid = five_dice_wav_signature_valid($resolvedPath);
    } elseif ($definition['kind'] === 'mp3') {
        $valid = five_dice_mp3_signature_valid($resolvedPath);
    }
    if (!$valid) {
        return $definition + ['slot' => $slot, 'state' => 'invalid', 'path' => null, 'reason' => 'The file signature, format, or image dimensions are not allowed.'];
    }
    return $definition + [
        'slot' => $slot,
        'state' => 'installed',
        'path' => $resolvedPath,
        'bytes' => (int)$bytes,
        'dimensions' => $dimensions,
        'sha256' => $sha256,
    ];
}

function five_dice_media_pack_prepared_alpha_contract(string $path, array $preparation): bool
{
    if (!extension_loaded('gd')) return false;
    $image = @imagecreatefrompng($path);
    if ($image === false) return false;
    $width = imagesx($image);
    $height = imagesy($image);
    $opaque = 0;
    $partial = 0;
    $minimumX = $width;
    $minimumY = $height;
    $maximumX = -1;
    $maximumY = -1;
    $boundaryTransparent = true;
    $owner = (string)($preparation['owner'] ?? '');
    $frameWidth = (int)($preparation['frameWidth'] ?? $width);
    $frameHeight = (int)($preparation['frameHeight'] ?? $height);
    $frameCount = (int)($preparation['frameCount'] ?? 1);
    $opaquePerFrame = array_fill(0, max(1, $frameCount), 0);
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $alpha = (int)imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'];
            $frameY = $frameHeight > 0 ? $y % $frameHeight : $y;
            $frameIndex = $frameHeight > 0 ? intdiv($y, $frameHeight) : 0;
            $isFrameBoundary = $x === 0 || $x === $frameWidth - 1 || $frameY === 0 || $frameY === $frameHeight - 1;
            if ($isFrameBoundary && $alpha !== 127) {
                $boundaryTransparent = false;
            }
            if ($alpha === 0) {
                $opaque++;
                if (isset($opaquePerFrame[$frameIndex])) $opaquePerFrame[$frameIndex]++;
                $minimumX = min($minimumX, $x);
                $minimumY = min($minimumY, $y);
                $maximumX = max($maximumX, $x);
                $maximumY = max($maximumY, $y);
            } elseif ($alpha !== 127) {
                $partial++;
            }
        }
    }
    imagedestroy($image);
    if ($owner === 'five-dice-temporal-variance-strip-alpha-v1') {
        $framePixels = $frameWidth * $frameHeight;
        return $frameWidth > 2 && $frameHeight > 2 && $frameCount > 1
            && $width === $frameWidth && $height === $frameHeight * $frameCount
            && $boundaryTransparent && $partial === 0
            && array_reduce(
                $opaquePerFrame,
                static fn(bool $valid, int $count): bool => $valid && $count >= 16 && $count < (int)floor($framePixels * 0.82),
                true
            );
    }
    return $owner === 'five-dice-blank-frame-silhouette-alpha-v2'
        && $width === 56 && $height === 56
        && $boundaryTransparent
        && $opaque === 1820
        && $partial === 0
        && [$minimumX, $minimumY, $maximumX, $maximumY] === [5, 3, 52, 50];
}

function five_dice_media_pack_prepared_path_is_valid(string $path, array $definition, array $preparation): bool
{
    if (!is_file($path) || (int)(filesize($path) ?: 0) < 1 || (int)(filesize($path) ?: 0) > (int)$definition['maximumBytes']) return false;
    $image = @getimagesize($path);
    $prefix = (string)@file_get_contents($path, false, null, 0, 8);
    return is_array($image)
        && (string)($image['mime'] ?? '') === (string)$preparation['outputMime']
        && $prefix === "\x89PNG\r\n\x1a\n"
        && (int)$image[0] === (int)$definition['requiredWidth']
        && (int)$image[1] === (int)$definition['requiredHeight']
        && five_dice_media_pack_prepared_alpha_contract($path, $preparation);
}

function five_dice_media_pack_prepare_temporal_variance_strip_alpha(
    string $sourcePath,
    string $targetPath,
    array $preparation
): void {
    if (!extension_loaded('gd')) throw new RuntimeException('The private Five Dice motion preparation owner is unavailable.');
    $source = @imagecreatefrompng($sourcePath);
    if ($source === false) throw new RuntimeException('A private Five Dice motion strip could not be decoded safely.');
    $frameWidth = (int)($preparation['frameWidth'] ?? 0);
    $frameHeight = (int)($preparation['frameHeight'] ?? 0);
    $frameCount = (int)($preparation['frameCount'] ?? 0);
    $threshold = max(1, (int)($preparation['varianceThreshold'] ?? 12));
    $dilation = max(0, min(4, (int)($preparation['dilation'] ?? 2)));
    if ($frameWidth < 3 || $frameHeight < 3 || $frameCount < 2
        || imagesx($source) !== $frameWidth || imagesy($source) !== $frameHeight * $frameCount) {
        imagedestroy($source);
        throw new RuntimeException('The Five Dice motion strip does not match its measured frame geometry.');
    }

    // The numbered source sheets retain a static rectangular matte around a
    // moving foreground. The temporal owner derives one union silhouette only
    // from source-frame variation at the same local pixel; neither the current
    // browser output nor the board/CSS rectangles can influence this mask.
    $seed = [];
    for ($y = 0; $y < $frameHeight; $y++) {
        for ($x = 0; $x < $frameWidth; $x++) {
            $minimum = [255, 255, 255];
            $maximum = [0, 0, 0];
            for ($frame = 0; $frame < $frameCount; $frame++) {
                $color = imagecolorsforindex($source, imagecolorat($source, $x, ($frame * $frameHeight) + $y));
                foreach (['red', 'green', 'blue'] as $index => $channel) {
                    $value = (int)$color[$channel];
                    $minimum[$index] = min($minimum[$index], $value);
                    $maximum[$index] = max($maximum[$index], $value);
                }
            }
            if (max($maximum[0] - $minimum[0], $maximum[1] - $minimum[1], $maximum[2] - $minimum[2]) >= $threshold) {
                $seed[$y * $frameWidth + $x] = true;
            }
        }
    }
    if (count($seed) < 12 || count($seed) >= (int)floor($frameWidth * $frameHeight * 0.8)) {
        imagedestroy($source);
        throw new RuntimeException('The Five Dice motion foreground could not be isolated from its source matte.');
    }
    $mask = [];
    foreach (array_keys($seed) as $key) {
        $x = $key % $frameWidth;
        $y = intdiv($key, $frameWidth);
        for ($offsetY = -$dilation; $offsetY <= $dilation; $offsetY++) {
            for ($offsetX = -$dilation; $offsetX <= $dilation; $offsetX++) {
                $nextX = $x + $offsetX;
                $nextY = $y + $offsetY;
                if ($nextX <= 0 || $nextX >= $frameWidth - 1 || $nextY <= 0 || $nextY >= $frameHeight - 1) continue;
                $mask[$nextY * $frameWidth + $nextX] = true;
            }
        }
    }

    $output = imagecreatetruecolor($frameWidth, $frameHeight * $frameCount);
    if ($output === false) {
        imagedestroy($source);
        throw new RuntimeException('The private Five Dice motion preparation surface is unavailable.');
    }
    imagealphablending($output, false);
    imagesavealpha($output, true);
    $transparent = imagecolorallocatealpha($output, 0, 0, 0, 127);
    imagefill($output, 0, 0, $transparent);
    for ($frame = 0; $frame < $frameCount; $frame++) {
        $sourceY = $frame * $frameHeight;
        foreach (array_keys($mask) as $key) {
            $x = $key % $frameWidth;
            $y = intdiv($key, $frameWidth);
            $sourceColor = imagecolorsforindex($source, imagecolorat($source, $x, $sourceY + $y));
            $color = imagecolorallocatealpha(
                $output,
                (int)$sourceColor['red'],
                (int)$sourceColor['green'],
                (int)$sourceColor['blue'],
                0
            );
            imagesetpixel($output, $x, $sourceY + $y, $color);
        }
    }
    imagedestroy($source);
    if (!imagepng($output, $targetPath, 9)) {
        imagedestroy($output);
        throw new RuntimeException('The prepared private Five Dice motion strip could not be written.');
    }
    imagedestroy($output);
}

function five_dice_media_pack_prepare_blank_frame_silhouette_alpha(
    string $sourcePath,
    string $maskSourcePath,
    string $targetPath
): void {
    if (!extension_loaded('gd')) throw new RuntimeException('The private Five Dice media preparation owner is unavailable.');
    $source = @imagecreatefrompng($sourcePath);
    $maskSource = @imagecreatefrompng($maskSourcePath);
    if ($source === false || $maskSource === false) {
        if ($source !== false) imagedestroy($source);
        if ($maskSource !== false) imagedestroy($maskSource);
        throw new RuntimeException('A private Five Dice source frame could not be decoded safely.');
    }
    $width = imagesx($source);
    $height = imagesy($source);
    if ($width !== imagesx($maskSource) || $height !== imagesy($maskSource)) {
        imagedestroy($source);
        imagedestroy($maskSource);
        throw new RuntimeException('The Five Dice blank-frame silhouette owner does not match the source-frame geometry.');
    }
    if ($width !== 56 || $height !== 56) {
        imagedestroy($source);
        imagedestroy($maskSource);
        throw new RuntimeException('The Five Dice source frame does not match the measured silhouette geometry.');
    }

    // The ignored private measurement owner independently registered the
    // owner captures to the intact board.  The blank source frame owns only
    // the die silhouette: largest 8-connected luminance >= 140 component,
    // row-interior fill, then one-pixel Chebyshev dilation.  Runtime/CSS
    // rectangles and the current rendered output are never mask inputs.
    $seed = [];
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorsforindex($maskSource, imagecolorat($maskSource, $x, $y));
            $luminance = ((299 * (int)$color['red']) + (587 * (int)$color['green']) + (114 * (int)$color['blue'])) / 1000;
            if ($luminance >= 140) $seed[$y * $width + $x] = true;
        }
    }
    $visited = [];
    $largest = [];
    foreach (array_keys($seed) as $start) {
        if (isset($visited[$start])) continue;
        $component = [];
        $queue = new SplQueue();
        $queue->enqueue($start);
        $visited[$start] = true;
        while (!$queue->isEmpty()) {
            $key = (int)$queue->dequeue();
            $component[] = $key;
            $x = $key % $width;
            $y = intdiv($key, $width);
            for ($offsetY = -1; $offsetY <= 1; $offsetY++) {
                for ($offsetX = -1; $offsetX <= 1; $offsetX++) {
                    if ($offsetX === 0 && $offsetY === 0) continue;
                    $nextX = $x + $offsetX;
                    $nextY = $y + $offsetY;
                    if ($nextX < 0 || $nextX >= $width || $nextY < 0 || $nextY >= $height) continue;
                    $next = $nextY * $width + $nextX;
                    if (!isset($seed[$next]) || isset($visited[$next])) continue;
                    $visited[$next] = true;
                    $queue->enqueue($next);
                }
            }
        }
        if (count($component) > count($largest)) $largest = $component;
    }
    if (count($largest) !== 1620) {
        imagedestroy($source);
        imagedestroy($maskSource);
        throw new RuntimeException('The Five Dice blank-frame source silhouette changed.');
    }
    $componentMask = array_fill_keys($largest, true);
    $filled = [];
    for ($y = 0; $y < $height; $y++) {
        $row = [];
        for ($x = 0; $x < $width; $x++) {
            if (isset($componentMask[$y * $width + $x])) $row[] = $x;
        }
        if ($row === []) continue;
        for ($x = min($row); $x <= max($row); $x++) $filled[$y * $width + $x] = true;
    }
    $silhouette = [];
    foreach (array_keys($filled) as $key) {
        $x = $key % $width;
        $y = intdiv($key, $width);
        for ($offsetY = -1; $offsetY <= 1; $offsetY++) {
            for ($offsetX = -1; $offsetX <= 1; $offsetX++) {
                $nextX = $x + $offsetX;
                $nextY = $y + $offsetY;
                if ($nextX < 0 || $nextX >= $width || $nextY < 0 || $nextY >= $height) continue;
                $silhouette[$nextY * $width + $nextX] = true;
            }
        }
    }
    if (count($silhouette) !== 1820) {
        imagedestroy($source);
        imagedestroy($maskSource);
        throw new RuntimeException('The prepared Five Dice silhouette geometry changed.');
    }

    $image = imagecreatetruecolor($width, $height);
    if ($image === false) {
        imagedestroy($source);
        imagedestroy($maskSource);
        throw new RuntimeException('The private Five Dice preparation surface is unavailable.');
    }
    imagealphablending($image, false);
    imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);
    imagecopy($image, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);
    imagedestroy($maskSource);
    for ($key = 0; $key < $width * $height; $key++) {
        if (!isset($silhouette[$key])) imagesetpixel($image, $key % $width, intdiv($key, $width), $transparent);
    }
    if (!imagepng($image, $targetPath, 9)) {
        imagedestroy($image);
        throw new RuntimeException('The prepared private Five Dice frame could not be written.');
    }
    imagedestroy($image);
}

function five_dice_media_pack_prepared_file(array $validatedSource, string $directory): array
{
    $preparation = $validatedSource['preparation'] ?? null;
    if (!is_array($preparation)) return $validatedSource;
    $preparationOwner = (string)($preparation['owner'] ?? '');
    if (!in_array($preparationOwner, [
        'five-dice-blank-frame-silhouette-alpha-v2',
        'five-dice-temporal-variance-strip-alpha-v1',
    ], true)) {
        throw new RuntimeException('The private Five Dice preparation owner is invalid.');
    }
    $root = realpath($directory);
    if ($root === false) throw new RuntimeException('The private Five Dice generation is unavailable.');
    $maskSource = null;
    $maskSourceSha = 'SELF_TEMPORAL_VARIANCE';
    if ($preparationOwner === 'five-dice-blank-frame-silhouette-alpha-v2') {
        $maskSlot = (string)($preparation['maskSourceSlot'] ?? '');
        $maskSource = five_dice_media_pack_validate_slot($maskSlot, $root);
        if (($maskSource['state'] ?? '') !== 'installed' || !is_string($maskSource['path'] ?? null)) {
            throw new RuntimeException('The source-backed Five Dice matte frame is unavailable.');
        }
        $maskSourceSha = strtoupper((string)$maskSource['sha256']);
    }
    $preparedDirectory = $root . DIRECTORY_SEPARATOR . '.prepared';
    if (!is_dir($preparedDirectory) && !mkdir($preparedDirectory, 0700, true) && !is_dir($preparedDirectory)) {
        throw new RuntimeException('The private Five Dice prepared-media directory is unavailable.');
    }
    $slot = (string)$validatedSource['slot'];
    $target = $preparedDirectory . DIRECTORY_SEPARATOR . $slot . '.png';
    $metadataPath = $preparedDirectory . DIRECTORY_SEPARATOR . $slot . '.json';
    $sourceSha = strtoupper((string)$validatedSource['sha256']);
    $preparationFingerprint = strtoupper(hash('sha256', json_encode($preparation, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    $metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath), true) : null;
    if (is_array($metadata)
        && hash_equals($sourceSha, strtoupper((string)($metadata['sourceSha256'] ?? '')))
        && hash_equals($maskSourceSha, strtoupper((string)($metadata['maskSourceSha256'] ?? '')))
        && (string)($metadata['preparationOwner'] ?? '') === $preparationOwner
        && hash_equals($preparationFingerprint, strtoupper((string)($metadata['preparationFingerprint'] ?? '')))
        && five_dice_media_pack_prepared_path_is_valid($target, $validatedSource, $preparation)) {
        return array_replace($validatedSource, [
            'path' => realpath($target), 'mime' => (string)$preparation['outputMime'],
            'bytes' => (int)filesize($target), 'sha256' => strtoupper(hash_file('sha256', $target)),
            'preparedFromSha256' => $sourceSha, 'maskSourceSha256' => $maskSourceSha,
            'preparationOwner' => $preparationOwner,
        ]);
    }
    $temporary = $preparedDirectory . DIRECTORY_SEPARATOR . '.attempt-' . uuid_v4() . '.png';
    try {
        if ($preparationOwner === 'five-dice-blank-frame-silhouette-alpha-v2') {
            five_dice_media_pack_prepare_blank_frame_silhouette_alpha(
                (string)$validatedSource['path'],
                (string)$maskSource['path'],
                $temporary
            );
        } else {
            five_dice_media_pack_prepare_temporal_variance_strip_alpha(
                (string)$validatedSource['path'],
                $temporary,
                $preparation
            );
        }
        if (!five_dice_media_pack_prepared_path_is_valid($temporary, $validatedSource, $preparation)) {
            throw new RuntimeException('The prepared private Five Dice frame failed its alpha-output contract.');
        }
        if (is_file($target) && !unlink($target)) throw new RuntimeException('The stale prepared private Five Dice frame could not be replaced.');
        if (!rename($temporary, $target)) throw new RuntimeException('The prepared private Five Dice frame could not be activated.');
        $metadataJson = json_encode([
            'sourceSha256' => $sourceSha,
            'maskSourceSha256' => $maskSourceSha,
            'preparedSha256' => strtoupper(hash_file('sha256', $target)),
            'preparationOwner' => $preparationOwner,
            'preparationFingerprint' => $preparationFingerprint,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $metadataTemporary = $metadataPath . '.attempt-' . uuid_v4();
        if (file_put_contents($metadataTemporary, $metadataJson . "\n", LOCK_EX) === false || !rename($metadataTemporary, $metadataPath)) {
            @unlink($metadataTemporary);
            throw new RuntimeException('The prepared private Five Dice provenance could not be recorded.');
        }
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
    return array_replace($validatedSource, [
        'path' => realpath($target), 'mime' => (string)$preparation['outputMime'],
        'bytes' => (int)filesize($target), 'sha256' => strtoupper(hash_file('sha256', $target)),
        'preparedFromSha256' => $sourceSha, 'maskSourceSha256' => $maskSourceSha,
        'preparationOwner' => $preparationOwner,
    ]);
}

function five_dice_media_pack_status(?PDO $pdo = null): array
{
    $pdo ??= db();
    $directory = five_dice_media_pack_directory($pdo);
    $installed = [];
    $missing = [];
    $invalid = [];
    foreach (array_keys(five_dice_media_pack_slots()) as $slot) {
        $result = five_dice_media_pack_validate_slot($slot, $directory);
        if (($result['state'] ?? '') === 'installed' && is_array($result['preparation'] ?? null)) {
            try {
                five_dice_media_pack_prepared_file($result, $directory);
            } catch (Throwable) {
                $result['state'] = 'invalid';
                $result['reason'] = 'The source-backed private die-frame preparation failed.';
            }
        }
        $public = [
            'slot' => $slot,
            'label' => (string)$result['label'],
            'installName' => (string)$result['installName'],
            'state' => (string)$result['state'],
        ];
        if ($result['state'] === 'installed') $installed[] = $public;
        elseif ($result['state'] === 'missing') $missing[] = $public;
        else $invalid[] = $public + ['guidance' => 'Replace this file with a supported file that matches the listed slot.'];
    }
    $knownNames = array_fill_keys(array_map(
        static fn(array $slot): string => (string)$slot['installName'],
        five_dice_media_pack_slots()
    ), true);
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..' || isset($knownNames[$name])) continue;
        if (!is_file($directory . DIRECTORY_SEPARATOR . $name)) continue;
        $invalid[] = [
            'slot' => 'unrecognized',
            'label' => 'Unrecognized file',
            'installName' => $name,
            'state' => 'invalid',
            'guidance' => 'Remove this file. Only the listed media-pack filenames and formats are used.',
        ];
    }
    $requiredSlots = array_keys(array_filter(
        five_dice_media_pack_slots(),
        static fn(array $definition): bool => !empty($definition['requiredForClassic'])
    ));
    $installedSlots = array_fill_keys(array_column($installed, 'slot'), true);
    $classicComplete = $invalid === [];
    foreach ($requiredSlots as $requiredSlot) {
        if (!isset($installedSlots[$requiredSlot])) {
            $classicComplete = false;
            break;
        }
    }
    return [
        'gameKey' => FIVE_DICE_GAME_KEY,
        'presentationOnly' => true,
        'installedCount' => count($installed),
        'missingCount' => count($missing),
        'invalidCount' => count($invalid),
        'requiredCount' => count($requiredSlots),
        'classicComplete' => $classicComplete,
        'classicAvailable' => $classicComplete,
        'installed' => $installed,
        'missing' => $missing,
        'invalid' => $invalid,
        'guidance' => 'Use the protected Installation Owner action to select the original source folder or a prepared pack. Classic becomes available only after all ' . count(five_dice_media_pack_slots()) . ' slots pass validation; otherwise the complete Built-in appearance is used.',
        'fallbackComplete' => true,
        'midiRequired' => false,
        'acceptedOriginalNames' => array_keys(five_dice_media_pack_original_filename_map()),
        'acceptedPreparedNames' => array_values(array_map(
            static fn(array $definition): string => (string)$definition['installName'],
            five_dice_media_pack_slots()
        )),
        'acceptedFilenameSlots' => five_dice_media_pack_accepted_filename_map(),
    ];
}

function five_dice_presentation_status(
    PDO $pdo,
    ?string $requestedPack = null,
    int $viewerUserId = 0,
    array $definition = []
): array
{
    $pack = five_dice_media_pack_status($pdo);
    $requested = $requestedPack ?? app_setting($pdo, FIVE_DICE_APPEARANCE_SETTING, 'classic');
    if (!in_array($requested, ['classic', 'built-in'], true)) $requested = 'classic';
    $effective = $requested === 'classic' && !empty($pack['classicAvailable'])
        ? 'classic'
        : 'built-in';
    return [
        'selectionOwner' => 'viewer',
        'requestedPack' => $requested,
        'effectivePack' => $effective,
        'classicAvailable' => !empty($pack['classicAvailable']),
        'fallbackApplied' => $requested === 'classic' && $effective !== 'classic',
        'scoreRecordsSurface' => [
            'label' => 'Score & Records',
            'separate' => true,
            'presentationOnly' => true,
        ],
        'presentationOnly' => true,
    ];
}

function five_dice_media_pack_authorized_file(PDO $pdo, string $publicId, int $userId, string $slot): array
{
    $session = multiplayer_game_require_member($pdo, $publicId, $userId);
    if ((string)$session['game_key'] !== FIVE_DICE_GAME_KEY) {
        throw new MultiplayerGameException('This media slot is unavailable.', 'FIVE_DICE_MEDIA_GAME_MISMATCH', 404);
    }
    first_party_extension_assert_capability($pdo, FIVE_DICE_EXTENSION_ID, 'game.presentation.private-media');
    $presentation = five_dice_presentation_status($pdo);
    if (($presentation['effectivePack'] ?? '') !== 'classic') {
        throw new MultiplayerGameException('The Classic appearance is unavailable.', 'FIVE_DICE_CLASSIC_UNAVAILABLE', 404);
    }
    $result = five_dice_media_pack_validate_slot($slot, five_dice_media_pack_directory($pdo));
    if (($result['state'] ?? '') !== 'installed' || !is_string($result['path'] ?? null)) {
        throw new MultiplayerGameException('This optional media slot is unavailable.', 'FIVE_DICE_MEDIA_UNAVAILABLE', 404);
    }
    return is_array($result['preparation'] ?? null)
        ? five_dice_media_pack_prepared_file($result, five_dice_media_pack_directory($pdo))
        : $result;
}

function five_dice_media_pack_delete_prepared_directory(string $directory): void
{
    $root = realpath($directory);
    $prepared = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . '.prepared');
    if ($root === false || $prepared === false || dirname($prepared) !== $root || basename($prepared) !== '.prepared') return;
    foreach (scandir($prepared) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $prepared . DIRECTORY_SEPARATOR . $name;
        if (is_file($child)) @unlink($child);
    }
    @rmdir($prepared);
}

function five_dice_media_pack_delete_attempt_directory(string $path, string $boundedRoot): void
{
    $resolvedRoot = realpath($boundedRoot);
    $resolvedPath = realpath($path);
    if ($resolvedRoot === false || $resolvedPath === false || dirname($resolvedPath) !== $resolvedRoot
        || !str_starts_with(basename($resolvedPath), 'attempt-')) return;
    five_dice_media_pack_delete_prepared_directory($resolvedPath);
    foreach (scandir($resolvedPath) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $resolvedPath . DIRECTORY_SEPARATOR . $name;
        if (is_file($child)) @unlink($child);
    }
    @rmdir($resolvedPath);
}

function five_dice_media_pack_delete_generation_directory(string $generation): void
{
    $path = five_dice_media_pack_generation_directory($generation);
    if ($path === null) return;
    $root = realpath(security_private_storage_directory(FIVE_DICE_MEDIA_PACK_GENERATIONS_CATEGORY));
    $resolved = realpath($path);
    if ($root === false || $resolved === false || dirname($resolved) !== $root) return;
    five_dice_media_pack_delete_prepared_directory($resolved);
    foreach (scandir($resolved) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $child = $resolved . DIRECTORY_SEPARATOR . $name;
        if (is_file($child)) @unlink($child);
    }
    @rmdir($resolved);
}

function five_dice_media_pack_reconcile_attempt_residue(PDO $pdo): void
{
    $activeGeneration = app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, '');
    $attemptRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY);
    foreach (scandir($attemptRoot) ?: [] as $name) {
        if (!str_starts_with($name, 'attempt-')) continue;
        five_dice_media_pack_delete_attempt_directory($attemptRoot . DIRECTORY_SEPARATOR . $name, $attemptRoot);
    }
    $generationRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_GENERATIONS_CATEGORY);
    foreach (scandir($generationRoot) ?: [] as $name) {
        if (!str_starts_with($name, 'generation-') || $name === $activeGeneration) continue;
        five_dice_media_pack_delete_generation_directory($name);
    }
}

function five_dice_media_pack_with_operation_lock(callable $operation): mixed
{
    $lockPath = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_OPERATIONS_CATEGORY)
        . DIRECTORY_SEPARATOR . 'owner.lock';
    $handle = fopen($lockPath, 'c+b');
    if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Another Classic artwork and sound operation is still active.');
    }
    try {
        return $operation();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function five_dice_media_pack_uploaded_files(): array
{
    $upload = $_FILES['files'] ?? null;
    if (!is_array($upload) || !is_array($upload['name'] ?? null)) return [];
    $files = [];
    foreach ($upload['name'] as $index => $name) {
        $files[] = [
            'name' => (string)$name,
            'fullPath' => (string)(is_array($upload['full_path'] ?? null) ? ($upload['full_path'][$index] ?? $name) : $name),
            'tmpName' => (string)($upload['tmp_name'][$index] ?? ''),
            'error' => (int)($upload['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'bytes' => (int)($upload['size'][$index] ?? 0),
        ];
    }
    return $files;
}

function five_dice_media_pack_safe_upload_name(string $name): string
{
    $name = str_replace('\\', '/', trim($name));
    if ($name === '' || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)
        || preg_match('/[\x00-\x1F\x7F]/', $name)) {
        throw new RuntimeException('A selected file has an unsafe path.');
    }
    $parts = explode('/', $name);
    if (array_filter($parts, static fn(string $part): bool => $part === '' || $part === '.' || $part === '..')) {
        throw new RuntimeException('A selected file has an unsafe path.');
    }
    return strtolower((string)end($parts));
}

function five_dice_media_pack_attempt_directory(string $attemptId): ?string
{
    if (!preg_match('/^attempt-[a-f0-9-]{36}$/', $attemptId)) return null;
    $root = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY);
    $path = $root . DIRECTORY_SEPARATOR . $attemptId;
    return is_dir($path) ? $path : null;
}

function five_dice_media_pack_attempt_metadata(string $attempt, int $actorUserId): array
{
    $metadataPath = $attempt . DIRECTORY_SEPARATOR . '.attempt.json';
    $metadata = is_file($metadataPath)
        ? json_decode((string)file_get_contents($metadataPath), true)
        : null;
    if (!is_array($metadata) || (int)($metadata['ownerUserId'] ?? 0) !== $actorUserId) {
        throw new RuntimeException('The private pack attempt is unavailable or no longer authorized.');
    }
    $createdAt = (int)($metadata['createdAt'] ?? 0);
    if ($createdAt < 1 || time() - $createdAt > FIVE_DICE_MEDIA_PACK_ATTEMPT_LIFETIME_SECONDS) {
        five_dice_media_pack_delete_attempt_directory(
            $attempt,
            security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY)
        );
        throw new RuntimeException('The private pack attempt expired. Select the files again.');
    }
    return $metadata;
}

function five_dice_media_pack_attempt_progress(string $attempt): array
{
    $installed = 0;
    $bytes = 0;
    foreach (five_dice_media_pack_slots() as $definition) {
        $path = $attempt . DIRECTORY_SEPARATOR . (string)$definition['installName'];
        if (!is_file($path)) continue;
        $installed++;
        $bytes += (int)(filesize($path) ?: 0);
    }
    return [
        'stagedCount' => $installed,
        'requiredCount' => count(five_dice_media_pack_slots()),
        'stagedBytes' => $bytes,
    ];
}

function five_dice_media_pack_begin_attempt(PDO $pdo, int $actorUserId): array
{
    return five_dice_media_pack_with_operation_lock(static function () use ($pdo, $actorUserId): array {
        five_dice_media_pack_reconcile_attempt_residue($pdo);
        $attemptRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY);
        $attemptId = 'attempt-' . uuid_v4();
        $attempt = $attemptRoot . DIRECTORY_SEPARATOR . $attemptId;
        if (!mkdir($attempt, 0770) && !is_dir($attempt)) {
            throw new RuntimeException('The private validation attempt could not be created.');
        }
        $metadata = [
            'ownerUserId' => $actorUserId,
            'createdAt' => time(),
            'hadActivePack' => (int)five_dice_media_pack_status($pdo)['installedCount'] > 0,
        ];
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($attempt . DIRECTORY_SEPARATOR . '.attempt.json', $encoded, LOCK_EX) === false) {
            five_dice_media_pack_delete_attempt_directory($attempt, $attemptRoot);
            throw new RuntimeException('The private validation attempt could not be recorded.');
        }
        return ['operation' => 'begun', 'attemptId' => $attemptId, 'progress' => five_dice_media_pack_attempt_progress($attempt)];
    });
}

function five_dice_media_pack_stage_attempt(PDO $pdo, int $actorUserId, string $attemptId): array
{
    return five_dice_media_pack_with_operation_lock(static function () use ($pdo, $actorUserId, $attemptId): array {
        $attempt = five_dice_media_pack_attempt_directory($attemptId);
        if ($attempt === null) throw new RuntimeException('The private pack attempt is unavailable.');
        five_dice_media_pack_attempt_metadata($attempt, $actorUserId);
        $attemptRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY);
        try {
            $files = five_dice_media_pack_uploaded_files();
            if ($files === [] || count($files) > FIVE_DICE_MEDIA_PACK_UPLOAD_BATCH_SIZE) {
                throw new RuntimeException('Each private staging request must contain between 1 and 10 files.');
            }
            $slots = five_dice_media_pack_slots();
            $nameMap = five_dice_media_pack_accepted_filename_map();
            $progress = five_dice_media_pack_attempt_progress($attempt);
            $staticOcx = [];
            $incomingBytes = array_sum(array_column($files, 'bytes'));
            if ($incomingBytes < 1 || (int)$progress['stagedBytes'] + $incomingBytes > FIVE_DICE_MEDIA_PACK_MAX_UPLOAD_BYTES) {
                throw new RuntimeException('The selected pack is outside the allowed total size.');
            }
            foreach ($files as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK || $file['bytes'] < 1 || !is_file($file['tmpName'])) {
                    throw new RuntimeException('Every selected pack file must upload completely.');
                }
                $safeName = five_dice_media_pack_safe_upload_name($file['fullPath'] !== '' ? $file['fullPath'] : $file['name']);
                if (str_ends_with($safeName, '.ocx')) {
                    $staticOcx[] = ocx_static_media_stage(
                        $file['tmpName'],
                        $attempt,
                        $nameMap,
                        $slots,
                        static fn(string $slot, string $directory): array => five_dice_media_pack_validate_slot($slot, $directory)
                    );
                    continue;
                }
                $slot = $nameMap[$safeName] ?? null;
                if (!is_string($slot) || !isset($slots[$slot])) {
                    throw new RuntimeException('The selected pack contains an unknown file. Choose only a recognized OCX, original media, or prepared media.');
                }
                $target = $attempt . DIRECTORY_SEPARATOR . (string)$slots[$slot]['installName'];
                if (is_file($target)) throw new RuntimeException('The selected pack contains duplicate files for one media slot.');
                if ($file['bytes'] > (int)$slots[$slot]['maximumBytes']) {
                    throw new RuntimeException('A selected pack file exceeds its safe size limit.');
                }
                $moved = PHP_SAPI === 'cli' ? copy($file['tmpName'], $target) : move_uploaded_file($file['tmpName'], $target);
                if (!$moved || !is_file($target)) throw new RuntimeException('A selected pack file could not be staged privately.');
                $validation = five_dice_media_pack_validate_slot($slot, $attempt);
                if (($validation['state'] ?? '') !== 'installed') {
                    throw new RuntimeException((string)($validation['reason'] ?? 'A selected pack file did not pass validation.'));
                }
            }
            $next = five_dice_media_pack_attempt_progress($attempt);
            if ($staticOcx !== []) $next['staticOcx'] = $staticOcx;
            return ['operation' => 'staged', 'attemptId' => $attemptId, 'progress' => $next];
        } catch (Throwable $error) {
            five_dice_media_pack_delete_attempt_directory($attempt, $attemptRoot);
            throw $error;
        }
    });
}

function five_dice_media_pack_activate_attempt(PDO $pdo, int $actorUserId, string $attemptId): array
{
    return five_dice_media_pack_with_operation_lock(static function () use ($pdo, $actorUserId, $attemptId): array {
        $attempt = five_dice_media_pack_attempt_directory($attemptId);
        if ($attempt === null) throw new RuntimeException('The private pack attempt is unavailable.');
        $metadata = five_dice_media_pack_attempt_metadata($attempt, $actorUserId);
        $attemptRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY);
        try {
            $progress = five_dice_media_pack_attempt_progress($attempt);
            if ((int)$progress['stagedCount'] !== count(five_dice_media_pack_slots())) {
                throw new RuntimeException('The selected pack does not cover all ' . count(five_dice_media_pack_slots()) . ' required media slots.');
            }
            foreach (array_keys(five_dice_media_pack_slots()) as $slot) {
                $validation = five_dice_media_pack_validate_slot($slot, $attempt);
                if (($validation['state'] ?? '') !== 'installed') {
                    throw new RuntimeException((string)($validation['reason'] ?? 'A selected pack file did not pass validation.'));
                }
                if (is_array($validation['preparation'] ?? null)) {
                    five_dice_media_pack_prepared_file($validation, $attempt);
                }
            }
            @unlink($attempt . DIRECTORY_SEPARATOR . '.attempt.json');
            $generation = 'generation-' . uuid_v4();
            $generationRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_GENERATIONS_CATEGORY);
            $generationPath = $generationRoot . DIRECTORY_SEPARATOR . $generation;
            if (!rename($attempt, $generationPath)) throw new RuntimeException('The validated pack could not be prepared for activation.');
            $previous = app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, '');
            $transaction = !$pdo->inTransaction();
            try {
                if ($transaction) $pdo->beginTransaction();
                set_app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, $generation);
                set_app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, (string)(settings_registry_revision($pdo) + 1));
                $hadActivePack = !empty($metadata['hadActivePack']);
                $slotCount = count(five_dice_media_pack_slots());
                log_tool($pdo, $actorUserId, $hadActivePack ? 'five_dice_classic_pack_replace' : 'five_dice_classic_pack_install', null, null, "Validated {$slotCount}/{$slotCount} installation-private media slots.");
                if ($transaction) $pdo->commit();
            } catch (Throwable $error) {
                if ($transaction && $pdo->inTransaction()) $pdo->rollBack();
                five_dice_media_pack_delete_generation_directory($generation);
                throw $error;
            }
            $status = five_dice_media_pack_status($pdo);
            if (empty($status['classicComplete'])) throw new RuntimeException('The activated pack could not be reverified.');
            if ($previous !== '' && $previous !== $generation) five_dice_media_pack_delete_generation_directory($previous);
            return ['status' => $status, 'operation' => !empty($metadata['hadActivePack']) ? 'replaced' : 'installed'];
        } catch (Throwable $error) {
            if (is_dir($attempt)) five_dice_media_pack_delete_attempt_directory($attempt, $attemptRoot);
            throw $error;
        }
    });
}

function five_dice_media_pack_abort_attempt(int $actorUserId, string $attemptId): array
{
    return five_dice_media_pack_with_operation_lock(static function () use ($actorUserId, $attemptId): array {
        $attempt = five_dice_media_pack_attempt_directory($attemptId);
        if ($attempt !== null) {
            five_dice_media_pack_attempt_metadata($attempt, $actorUserId);
            five_dice_media_pack_delete_attempt_directory(
                $attempt,
                security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY)
            );
        }
        return ['operation' => 'aborted'];
    });
}

function five_dice_media_pack_install(PDO $pdo, int $actorUserId): array
{
    return five_dice_media_pack_with_operation_lock(static function () use ($pdo, $actorUserId): array {
        five_dice_media_pack_reconcile_attempt_residue($pdo);
        $priorStatus = five_dice_media_pack_status($pdo);
        $hadActivePack = (int)$priorStatus['installedCount'] > 0;
        $files = five_dice_media_pack_uploaded_files();
        $slots = five_dice_media_pack_slots();
        if (count($files) !== count($slots)) {
            throw new RuntimeException('Choose the complete 41-file Classic artwork and sound pack.');
        }
        $total = array_sum(array_column($files, 'bytes'));
        if ($total < 1 || $total > FIVE_DICE_MEDIA_PACK_MAX_UPLOAD_BYTES) {
            throw new RuntimeException('The selected pack is outside the allowed total size.');
        }
        $attemptRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_ATTEMPTS_CATEGORY);
        $attemptId = 'attempt-' . uuid_v4();
        $attempt = $attemptRoot . DIRECTORY_SEPARATOR . $attemptId;
        if (!mkdir($attempt, 0770) && !is_dir($attempt)) {
            throw new RuntimeException('The private validation attempt could not be created.');
        }
        $seen = [];
        try {
            $nameMap = five_dice_media_pack_accepted_filename_map();
            foreach ($files as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK || $file['bytes'] < 1 || !is_file($file['tmpName'])) {
                    throw new RuntimeException('Every selected pack file must upload completely.');
                }
                $safeName = five_dice_media_pack_safe_upload_name($file['fullPath'] !== '' ? $file['fullPath'] : $file['name']);
                $slot = $nameMap[$safeName] ?? null;
                if (!is_string($slot) || !isset($slots[$slot])) {
                    throw new RuntimeException('The selected pack contains an unknown file. Choose only the recognized original or prepared pack files.');
                }
                if (isset($seen[$slot])) throw new RuntimeException('The selected pack contains duplicate files for one media slot.');
                $seen[$slot] = true;
                if ($file['bytes'] > (int)$slots[$slot]['maximumBytes']) {
                    throw new RuntimeException('A selected pack file exceeds its safe size limit.');
                }
                $target = $attempt . DIRECTORY_SEPARATOR . (string)$slots[$slot]['installName'];
                $moved = PHP_SAPI === 'cli'
                    ? copy($file['tmpName'], $target)
                    : move_uploaded_file($file['tmpName'], $target);
                if (!$moved || !is_file($target)) throw new RuntimeException('A selected pack file could not be staged privately.');
            }
            if (count($seen) !== count($slots)) throw new RuntimeException('The selected pack does not cover all ' . count($slots) . ' required media slots.');
            foreach (array_keys($slots) as $slot) {
                $validation = five_dice_media_pack_validate_slot($slot, $attempt);
                if (($validation['state'] ?? '') !== 'installed') {
                    throw new RuntimeException((string)($validation['reason'] ?? 'A selected pack file did not pass validation.'));
                }
                if (is_array($validation['preparation'] ?? null)) {
                    five_dice_media_pack_prepared_file($validation, $attempt);
                }
            }

            $generation = 'generation-' . uuid_v4();
            $generationRoot = security_private_storage_directory(FIVE_DICE_MEDIA_PACK_GENERATIONS_CATEGORY);
            $generationPath = $generationRoot . DIRECTORY_SEPARATOR . $generation;
            if (!rename($attempt, $generationPath)) throw new RuntimeException('The validated pack could not be prepared for activation.');
            $previous = app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, '');
            $transaction = !$pdo->inTransaction();
            try {
                if ($transaction) $pdo->beginTransaction();
                set_app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, $generation);
                set_app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, (string)(settings_registry_revision($pdo) + 1));
                $slotCount = count($slots);
                log_tool($pdo, $actorUserId, $previous === '' ? 'five_dice_classic_pack_install' : 'five_dice_classic_pack_replace', null, null, "Validated {$slotCount}/{$slotCount} installation-private media slots.");
                if ($transaction) $pdo->commit();
            } catch (Throwable $error) {
                if ($transaction && $pdo->inTransaction()) $pdo->rollBack();
                five_dice_media_pack_delete_generation_directory($generation);
                throw $error;
            }
            $status = five_dice_media_pack_status($pdo);
            if (empty($status['classicComplete'])) throw new RuntimeException('The activated pack could not be reverified.');
            if ($previous !== '' && $previous !== $generation) five_dice_media_pack_delete_generation_directory($previous);
            return ['status' => $status, 'operation' => $hadActivePack ? 'replaced' : 'installed'];
        } finally {
            five_dice_media_pack_delete_attempt_directory($attempt, $attemptRoot);
        }
    });
}

function five_dice_media_pack_remove(PDO $pdo, int $actorUserId): array
{
    return five_dice_media_pack_with_operation_lock(static function () use ($pdo, $actorUserId): array {
        five_dice_media_pack_reconcile_attempt_residue($pdo);
        $activeGeneration = app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, '');
        $transaction = !$pdo->inTransaction();
        try {
            if ($transaction) $pdo->beginTransaction();
            set_app_setting($pdo, FIVE_DICE_MEDIA_PACK_GENERATION_SETTING, '');
            set_app_setting($pdo, SETTINGS_REGISTRY_REVISION_SETTING, (string)(settings_registry_revision($pdo) + 1));
            log_tool($pdo, $actorUserId, 'five_dice_classic_pack_remove', null, null, 'Removed the active installation-private pack; Built-in fallback remains available.');
            if ($transaction) $pdo->commit();
        } catch (Throwable $error) {
            if ($transaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        if ($activeGeneration === '') {
            $legacyRoot = five_dice_media_pack_root_directory();
            foreach (five_dice_media_pack_slots() as $definition) {
                $path = $legacyRoot . DIRECTORY_SEPARATOR . (string)$definition['installName'];
                if (is_file($path)) @unlink($path);
            }
        } else {
            five_dice_media_pack_delete_generation_directory($activeGeneration);
        }
        return ['status' => five_dice_media_pack_status($pdo), 'operation' => 'removed'];
    });
}
