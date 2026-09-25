<?php
declare(strict_types=1);

require_once __DIR__ . '/five_dice_identity.php';
require_once __DIR__ . '/ocx_static_media_extractor.php';
require_once __DIR__ . '/five_dice_media_sources.php';

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
    // RGBA fingerprints preserve approved artwork across lossless PNG encoders.
    $pixelHashes = [
        'die-0' => 'dcf96163ac2485478af474ccd3281f22e2f8989fe0e9399087f2afe8026622e0',
        'die-1' => 'c7b09346abd8745576f9240612725b37cb9b7cb08eb8f21751772ab9f60317f9',
        'held-die-1' => 'eeed1c6ff0b775b59d1bce0739e1f12466a61aa0e848a1cebcc1901d9ca7ca37',
        'die-2' => 'd65c85952d4bf4feb67751dc5cb54124bae85d9c62530ee8cab32ddc1f7be654',
        'held-die-2' => 'b00414e4a79386aa54e16e211eec9a317c56be82f67f7e2a74972ed5f7c97cc6',
        'die-3' => '5406f07d0544b88154218e8ef47ffe61a8d3348d5103f869088fd021cfef8c6a',
        'held-die-3' => 'f956f4dbcd17cdac26c6e56cefc5917c03bb6f2974ceb3232790022616104764',
        'die-4' => '70906405ea0aae6049737dbed0dcb81a03d5fc1b24ca86d07eab103c06c6ebc3',
        'held-die-4' => '3155653e3433bb4c9714c6a3d7a69723ea4c2e1f56226ade2d0a49517313db6f',
        'die-5' => '01d8981fbd45de8c039c86c79a2e0632990eb4710765f1173ce6f8d95a41793a',
        'held-die-5' => '25b4dd451abd478f0f013288b1a78f2e06775bfdae106f8bd95e012daa51072e',
        'die-6' => '7c7beb2a08639d0215bba710c048372196804453984fd6cb1b9a9030f47d92a3',
        'held-die-6' => '7becab87bc175bca9dd45b06d4be228dfe01f040e6323132d40ffff5fbe2849c',
        'gfx-off-hover' => '7ae79dbb85316562ed2d7187bd050956f8dc18bf7ec1a0c703c6f1b7350d9082',
        'gfx-off' => '73a60d506910fbe1560d718530f02a0c293e7a1a86797aea0437e1f026db9d6f',
        'gfx-on-hover' => '701343e3068b9959eb6eb3746220a0a408f8920d44056079f5cdd4821013e535',
        'gfx-on' => '78f13d959f878a71e26599538226fd508e31efa002497320bf7d1468f7abcae2',
        'current-player-lane' => 'f17189bcb46ed055292efcc00d72fadf96a53df9095b63e5c4d88eae859de4f5',
        'music-on-hover' => '018d603b80ec2067912736549ed873afa7e19f6a6554031bf7cc7276617c4047',
        'music-on' => 'd6a39b00dd8ae46a5662b23fb27306fa1b4e50a3d6a27c624b8b0b7e20f13184',
        'music-off-hover' => '018d603b80ec2067912736549ed873afa7e19f6a6554031bf7cc7276617c4047',
        'music-off' => '207d7a2af275f66eb85530b74d0e9fb2608fc56b07983133bd058baff2933aed',
        'roll-control-disabled' => '97aa7160d56f6bb3829fd06e6917eaa211523f56deaa570b83a10ceb691d4a03',
        'roll-control-hover' => 'ad66684776f71f1dca8090c35c5c3f42c85fda83d700a9691f4ed4d93ab5813a',
        'roll-control-pressed' => 'e4bfe1e0bcbf57f1ca0e6e56aecaf179876ad960448c3c5ae614e917794186e9',
        'roll-control' => 'a025d5536ce7774c64bfe2f77c61bd4ad5e001d35b7b088df68a5a8280b00ec6',
        'sound-on-hover' => 'c933349cf66957fa8a76575be9e6b83d73dd4582e818bb52cfde52caf924f0e0',
        'sound-on' => '67039aa71075de82588cd333cc8a7ae9c5adb8dde084730c9aa4bc684e2966c0',
        'sound-off-hover' => 'c933349cf66957fa8a76575be9e6b83d73dd4582e818bb52cfde52caf924f0e0',
        'sound-off' => 'd795164fcc67aeba101448703905c50b1d974a78cb6052719bbb210021bdf742',
        'microphone-wave-strip' => 'd473327ff5079748c695690b963946424e8c093cc9c11950e83728ebb5dcbdfe',
        'start-new-game' => '3b4b8606b2e6fbe736a5292b01a0938648fcc49c12257ea9b90fd6a9da29683a',
        'player-lane-1' => '3d0f26a80c094d7d6843adf774c2af2d8e1783a3f7a68aa1b3a15353cc3613c3',
        'on-hold-lane-1' => 'b217ff913da5b8ce4c6c22ea16d14c07db327595732a03b25d41537ae0a20925',
        'player-lane-2' => '2cd864d0ec4ae3de3ce5c4f94806ac019d3e8412b5f4dc52918dfa86ef7a6e35',
        'on-hold-lane-2' => 'c5fe73c3912f4b24adc02686c3155dea49ac96d0af811d1c61113a88447c52f3',
        'player-lane-3' => 'a3c2f944b38adc810d83893aa6c934a23c75c03eab48a5c87261fa5788846982',
        'on-hold-lane-3' => 'ec1f7d56812dc9af94cd8e077927d7f23859b062d0b32e7428cf81fe5a3415ac',
        'player-lane-4' => '273acc1579493800add37576cf4b91a726435241879d728f7208ded6c3e2173e',
        'on-hold-lane-4' => 'd9ea29d116bf0f7b6bf78ab763f09b92faf35fe704e96b2a15cd26650074f6d7',
        'score-row-500' => '65a4d35028d8efd3a675d2f4bbb1617410e1ac7e37854cf21f92b92cc791bed2',
        'score-row-501' => 'aadd70c3375ff48aff9c420143b85ac181a039e6130fdc841d10ebc1b6fd7f79',
        'score-row-502' => '47783cdb080cc278fa898238d507c8f09dbd375647e7ee4ae1e06280de4b2067',
        'score-row-503' => '175f4ecd4be4d3f0f846ce77e3bf74afa05eb3eb7d3f84ea9af5869aa73e99c4',
        'score-row-504' => '82e8295480300c6e1a30d1d0d2606763d7c05aaae1e841882a353612c5e92ec9',
        'score-row-505' => 'bb48dd04a3b58d07b2b749e94be3fa9cb844d7074cc33c81fb1d793bb3e9d01f',
        'score-row-506' => 'a94c0c3c5ce6bd6aa82d0419962f5e6343f1854263180c32ccbcc56a89815819',
        'score-row-507' => 'be0f9ea105e19570e133d86c3f1d28041dc60b7f3d74c1d4447cfc44c6ec303a',
        'score-row-508' => '8968f5b4189f16ed18184a964e902d2678c9a98fca47304983b9c338d2cc61f9',
        'score-row-509' => 'ea948aa009607998e4e6a0147eeeb4bb2c28cff9b91d89df55d791451ce5bb27',
        'score-row-510' => '63c4d016b088eb0d5d3e87467a886a78895bb16afb94de37dc3ea699dd06a16d',
        'score-row-511' => 'd5fbd559c8070873e5f8dcfca6d3bf43132a6f23edb7779ba475bddedbc64558',
        'score-row-512' => '16d35b3d952134b4a1364e824e3f5992483872cafbb7f09754752f621fd1a9e6',
        'score-row-513' => '9ae1c3eac6a4d86f6c93583bb235f67d47b7194ba8fa3fadcab3c61c80e0f5f0',
        'score-row-514' => 'd67b59999b2aaaf78f2c655c04fd5e31ad75415d94eb4f5af64a86a568365754',
        'score-row-515' => '4eef3da92cff176ec5322d1b899e72335de2a3eab5fa49a6eb182b6ed975c7c6',
        'score-row-516' => '321c32ddf8d9830f729669312f3e8f2346c6f4f764befe727fe5280675168968',
        'rolling-dice-a' => 'eb1bb0d9e62c0a58d01e9a4216213e977c011a85cee35d76a83b60bb553db52e',
        'rolling-dice-b' => 'a83ec77b08f41c81e3819c8d42bc264ed39a44ed24ec6149c28ee86d805b646a',
        'drum-motion' => '4f0843774e5b455b253280cdfb3b9a1de4d480f13949e2dfcf73129609b50beb',
        'classic-board' => 'b85f49fbf2b3d7f36ebbed5b44befd9748d3102a4d9133b6d3e66aff6cad5ea7',
    ];
    foreach ($slots as $slot => &$definition) {
        if (isset($definition['requiredSha256'], $pixelHashes[$slot])) $definition['requiredPixelSha256'] = $pixelHashes[$slot];
    }
    unset($definition);
    return $slots + [
        'reaction-523' => ['label' => 'Original personal-record celebration', 'installName' => 'reaction-523.png', 'sourceName' => '523@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 48, 'maximumHeight' => 1920, 'requiredWidth' => 48, 'requiredHeight' => 1920, 'requiredForClassic' => false, 'requiredSha256' => '0b2216b2931a0e0f04756e05311a667a0434d72e3d004c9206c17bbba754b590', 'requiredPixelSha256' => 'd852332503c1f33aafe2629fa09541517189a4c4623facba3df644caf023013d'],
        'control-sfx-left' => ['label' => 'Original idle control 519', 'installName' => 'control-sfx-left.png', 'sourceName' => '519@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 524288, 'maximumWidth' => 38, 'maximumHeight' => 216, 'requiredWidth' => 38, 'requiredHeight' => 216, 'requiredForClassic' => false, 'requiredSha256' => '1bbf81a0360413f4a8ab5c121e3260b465a6ebc40192f1a2bb42c5d268ecd17d', 'requiredPixelSha256' => '2163a69e69359df38d457ebbae33fd31f5faccbbfdb827b7a86828e3a2c427ac'],
        'control-sfx-right' => ['label' => 'Original idle control 520', 'installName' => 'control-sfx-right.png', 'sourceName' => '520@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 524288, 'maximumWidth' => 38, 'maximumHeight' => 216, 'requiredWidth' => 38, 'requiredHeight' => 216, 'requiredForClassic' => false, 'requiredSha256' => '1d248591d755c6e216fe9c505d6c01e84bb9510d67a2154824846f9905d887a1', 'requiredPixelSha256' => '5e8bfe5885e570345adb0a76a4f0b00c93befcb05e8ad1b763a92e03ccb42c7d'],
        'control-music-beater' => ['label' => 'Original idle control 521', 'installName' => 'control-music-beater.png', 'sourceName' => '521@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 524288, 'maximumWidth' => 48, 'maximumHeight' => 682, 'requiredWidth' => 48, 'requiredHeight' => 682, 'requiredForClassic' => false, 'requiredSha256' => 'dd896d77dbc0e0afc789a773a2540e1d0bc879c4716cea5cff05b971a2d299f8', 'requiredPixelSha256' => '44f12deb6f10c4f3736727d7c0e5aab5712c02d32146ecc9195174b69b77ad6e'],
        'reaction-524' => ['label' => 'Original reaction 524', 'installName' => 'reaction-524.png', 'sourceName' => '524@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 128, 'requiredHeight' => 3432, 'requiredForClassic' => false, 'requiredSha256' => '5726e920b07a9576306645fad1b9c2b1cc5571107be0c7159c9312881bf3ba81', 'requiredPixelSha256' => 'febf3b5ce3cf77cd60cf1b034bc87620106072f74b3946e00e0cb9ec60f1ec35'],
        'reaction-525' => ['label' => 'Original reaction 525', 'installName' => 'reaction-525.png', 'sourceName' => '525@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 108, 'requiredHeight' => 1680, 'requiredForClassic' => false, 'requiredSha256' => 'b96c1679f4de883e018530196d717117d026c6c09a500e1555dd68d7ddeb2e85', 'requiredPixelSha256' => '04f0ea5afde0e35806f3f2849d05b59c858e528718a24a3f7cc9230acf459452'],
        'reaction-526' => ['label' => 'Original reaction 526', 'installName' => 'reaction-526.png', 'sourceName' => '526@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 260, 'requiredForClassic' => false, 'requiredSha256' => '88dccccc3c37ba543686aeeaa3f3a8853728cc3ff9d36768df55db9a01c165cd', 'requiredPixelSha256' => '03d8126b81440a67f4cd7368edf58a3e577c2141f3e82adb192c617577cee07c'],
        'reaction-527' => ['label' => 'Original reaction 527', 'installName' => 'reaction-527.png', 'sourceName' => '527@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 182, 'requiredForClassic' => false, 'requiredSha256' => '10969caf122cabc6c0344bb3ac9262e86e5afde8c035477e59773ff3ba349e84', 'requiredPixelSha256' => '68f4eabae8815ca1b84689c4f67cff45d7b2963916fab53ceff77a4772268524'],
        'reaction-528' => ['label' => 'Original reaction 528', 'installName' => 'reaction-528.png', 'sourceName' => '528@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 286, 'requiredForClassic' => false, 'requiredSha256' => '7b1924b5bd21efa831a6188889c666c7ab9438657b16b05068ae238d540b131b', 'requiredPixelSha256' => 'e48a9f6a333f0a0c18adabf7c2444788cdcf9a8148b500efa3c7380a40432010'],
        'reaction-529' => ['label' => 'Original reaction 529', 'installName' => 'reaction-529.png', 'sourceName' => '529@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 416, 'requiredForClassic' => false, 'requiredSha256' => 'f3a95cf107ed44b4ed0bf525e8ef350b51fd5c66cc7c1ff02b1c4f423dd19f11', 'requiredPixelSha256' => 'f2b2e8a197f52d8d15197e26fb6e9143a20607f7c9376b38c8dd8ad66910ee57'],
        'reaction-530' => ['label' => 'Original reaction 530', 'installName' => 'reaction-530.png', 'sourceName' => '530@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 72, 'requiredHeight' => 2508, 'requiredForClassic' => false, 'requiredSha256' => '916c16c63a8c38619b2bf59f14ed97631de10e0426cbbe1da4c4d36a91167663', 'requiredPixelSha256' => 'a3307f7ce2a0d2c657820d9533a05e794f036596c41e76b45f710b0f656ad94d'],
        'reaction-531' => ['label' => 'Original reaction 531', 'installName' => 'reaction-531.png', 'sourceName' => '531@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 520, 'requiredForClassic' => false, 'requiredSha256' => 'f3193835e9edbf2681765f6b739fded933eb308a4d25d3acfbcb69c8b35d80dc', 'requiredPixelSha256' => 'dae30b66bbf55f7f82a66a2165118430d61a2d7848f93a76ea1e05e052bb63ae'],
        'reaction-532' => ['label' => 'Original reaction 532', 'installName' => 'reaction-532.png', 'sourceName' => '532@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 260, 'requiredForClassic' => false, 'requiredSha256' => '7f179fa793064835aadfe8115816c08d8e5179c361519237bd47ac20032f23b9', 'requiredPixelSha256' => '48ead37a7d8756a5850ec067a73cd38c559d61a52dbecdd1c9b8a831be11491b'],
        'reaction-533' => ['label' => 'Original reaction 533', 'installName' => 'reaction-533.png', 'sourceName' => '533@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 208, 'requiredForClassic' => false, 'requiredSha256' => 'c844422f271e676211a37b9488286dc39279d619c602b253692a48a5ac69b16d', 'requiredPixelSha256' => 'e53cb4758c237b00e775f53cb8c4f2ea0352a74ee8388a872fd2c9b8a350302f'],
        'reaction-534' => ['label' => 'Original reaction 534', 'installName' => 'reaction-534.png', 'sourceName' => '534@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 24, 'requiredHeight' => 260, 'requiredForClassic' => false, 'requiredSha256' => '085d52977c6b048db6bc8402fc8771cf271a33be1bdff032a0d20df451fd6f35', 'requiredPixelSha256' => 'cddf2198da65f39a72bb77506e0034e867f7f5746a6d7fd37de9d86f09f478e0'],
        'reaction-535' => ['label' => 'Original reaction 535', 'installName' => 'reaction-535.png', 'sourceName' => '535@2x.png', 'kind' => 'image', 'mime' => 'image/png', 'maximumBytes' => 4194304, 'maximumWidth' => 2048, 'maximumHeight' => 8192, 'requiredWidth' => 68, 'requiredHeight' => 1920, 'requiredForClassic' => false, 'requiredSha256' => '54c0431d735d2234e83d3f11a8d2fc80e69ea26b6885ccdde769841864f7496a', 'requiredPixelSha256' => '0c56318923b1e90b769c1a2f1d5be40e3e8b07c2a38dbfca7d28bf4408142ac7'],

        'upper-all-right-sound' => ['label' => 'Allright sound', 'installName' => 'upper-all-right-sound.wav', 'sourceName' => 'WAV_ALLRIGHT.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'upper-crash-sound' => ['label' => 'Crash sound', 'installName' => 'upper-crash-sound.wav', 'sourceName' => 'WAV_CRASH.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'idle-feeling-sound' => ['label' => 'Feeling sound', 'installName' => 'idle-feeling-sound.wav', 'sourceName' => 'WAV_FEELING.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'repeat-yahtzee-sound' => ['label' => 'Hipower sound', 'installName' => 'repeat-yahtzee-sound.wav', 'sourceName' => 'WAV_HIPOWER.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'low-power-sound' => ['label' => 'Lowpower sound', 'installName' => 'low-power-sound.wav', 'sourceName' => 'WAV_LOWPOWER.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'idle-roll-sound' => ['label' => 'Rollthe sound', 'installName' => 'idle-roll-sound.wav', 'sourceName' => 'WAV_ROLLTHE.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'idle-something-sound' => ['label' => 'Somethin sound', 'installName' => 'idle-something-sound.wav', 'sourceName' => 'WAV_SOMETHIN.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'yahtzee-way-to-go-sound' => ['label' => 'Waytogo sound', 'installName' => 'yahtzee-way-to-go-sound.wav', 'sourceName' => 'WAV_WAYTOGO.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'idle-yawn-sound' => ['label' => 'Yawn sound', 'installName' => 'idle-yawn-sound.wav', 'sourceName' => 'WAV_YAWN.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],
        'yahtzee-your-on-sound' => ['label' => 'Youron sound', 'installName' => 'yahtzee-your-on-sound.wav', 'sourceName' => 'WAV_YOURON.wav', 'kind' => 'wav', 'mime' => 'audio/wav', 'maximumBytes' => 1048576, 'requiredForClassic' => false],

        'background-music' => [
            'label' => 'Background music',
            'installName' => 'background-music.mp3',
            'kind' => 'mp3',
            'mime' => 'audio/mpeg',
            'maximumBytes' => 16777216,
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
    foreach (five_dice_media_pack_original_filename_map() as $name => $slot) {
        if (str_ends_with($name, '@2x.png')) {
            $stem = substr($name, 0, -7);
            foreach (['.png', '.gif', '.bmp'] as $extension) $map[$stem . $extension] = $slot;
        }
    }
    $map['mid_ytz.rmid'] = 'background-music';
    $map['mid_ytz.mid'] = 'background-music';
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
        ocx_resource_archive_restore($root, $slot, $definition, five_dice_media_pack_accepted_filename_map(),
            static function (array $payload, string $work) use ($slot): void {
                five_dice_media_stage_source($slot, $payload['bytes'], $work);
            });
    }
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
        // The protected installer writes this only after full source validation.
        // Reuse that proof only for identical bytes and the same approved pixels.
        $sourceProof = five_dice_media_source_inventory($resolvedRoot)[$slot] ?? [];
        $verifiedPixels = isset($definition['requiredPixelSha256'])
            && hash_equals($sha256, (string)($sourceProof['sha256'] ?? ''))
            && hash_equals((string)$definition['requiredPixelSha256'], (string)($sourceProof['verifiedPixelSha256'] ?? ''));
        $valid = str_starts_with($prefix, "\x89PNG\r\n\x1a\n")
            && is_array($image)
            && (string)($image['mime'] ?? '') === 'image/png'
            && (int)$image[0] >= 16
            && (int)$image[0] <= (int)($definition['maximumWidth'] ?? 512)
            && (int)$image[1] >= 16
            && (int)$image[1] <= (int)($definition['maximumHeight'] ?? 512)
            && (!isset($definition['requiredWidth']) || (int)$image[0] === (int)$definition['requiredWidth'])
            && (!isset($definition['requiredHeight']) || (int)$image[1] === (int)$definition['requiredHeight'])
            && (!isset($definition['requiredSha256']) || hash_equals(strtoupper((string)$definition['requiredSha256']), $sha256)
                || $verifiedPixels || (isset($definition['requiredPixelSha256']) && hash_equals($definition['requiredPixelSha256'], five_dice_media_pixel_hash($resolvedPath))));
        if (is_array($image)) $dimensions = [(int)$image[0], (int)$image[1]];
    } elseif ($definition['kind'] === 'wav') {
        $valid = five_dice_wav_signature_valid($resolvedPath);
    } elseif ($definition['kind'] === 'mp3') {
        $valid = five_dice_mp3_signature_valid($resolvedPath);
        if (!$valid && $slot === 'background-music' && five_dice_wav_signature_valid($resolvedPath)) {
            $valid = true; $definition['mime'] = 'audio/wav';
        }
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
    $artwork = ['supplied2x' => 0, 'original1x' => 0, 'required' => count(array_filter(
        five_dice_media_pack_slots(), static fn(array $slot): bool => $slot['kind'] === 'image' && !empty($slot['requiredForClassic'])
    ))];
    $sourceInventory = five_dice_media_source_inventory($directory);
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
            'requiredForClassic' => !empty($result['requiredForClassic']),
        ];
        if ($result['state'] === 'installed') {
            $installed[] = $public;
            if ($result['kind'] === 'image' && !empty($result['requiredForClassic'])) $artwork[five_dice_media_source_rank($result, $sourceInventory) === 1 ? 'original1x' : 'supplied2x']++;
        }
        elseif ($result['state'] === 'missing') $missing[] = $public;
        else $invalid[] = $public + ['guidance' => 'Replace this file with a supported file that matches the listed slot.'];
    }
    $knownNames = array_fill_keys(array_map(
        static fn(array $slot): string => (string)$slot['installName'],
        five_dice_media_pack_slots()
    ), true);
    foreach (scandir($directory) ?: [] as $name) {
        if ($name === '.' || $name === '..' || $name === '.source-selection.json' || isset($knownNames[$name])) continue;
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
    $classicComplete = array_filter($invalid, static fn(array $item): bool => !empty($item['requiredForClassic']) || $item['slot'] === 'unrecognized') === [];
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
        'requiredInstalledCount' => count(array_intersect($requiredSlots, array_keys($installedSlots))),
        'optionalInstalledCount' => count(array_diff(array_keys($installedSlots), $requiredSlots)),
        'missingCount' => count($missing),
        'invalidCount' => count($invalid),
        'requiredCount' => count($requiredSlots),
        'classicComplete' => $classicComplete,
        'classicAvailable' => $classicComplete,
        'installed' => $installed,
        'missing' => $missing,
        'invalid' => $invalid,
        'guidance' => 'Use the protected Installation Owner action to select the original source folder or a prepared pack. Classic becomes available only after all ' . count($requiredSlots) . ' required slots pass validation; otherwise the complete Built-in appearance is used.',
        'fallbackComplete' => true,
        'midiRequired' => false,
        'acceptedOriginalNames' => array_keys(five_dice_media_pack_original_filename_map()),
        'acceptedPreparedNames' => array_values(array_map(
            static fn(array $definition): string => (string)$definition['installName'],
            five_dice_media_pack_slots()
        )),
        'acceptedFilenameSlots' => five_dice_media_pack_accepted_filename_map(),
        'artworkSources' => $artwork,
        'sourceSelection' => 'prefer-doubled-five-dice',
        'imageSlotDimensions' => array_map(static fn(array $slot): array => [$slot['requiredWidth'], $slot['requiredHeight']],
            array_filter(five_dice_media_pack_slots(), static fn(array $slot): bool => $slot['kind'] === 'image')),
        'acceptedFilenamePriorities' => array_map(static fn(string $name): int =>
            str_contains($name, '@2x.png') || str_ends_with($name, '.mp3') ? 3
                : (in_array($name, array_column(five_dice_media_pack_slots(), 'installName'), true) ? 2 : 1),
            array_combine(array_keys(five_dice_media_pack_accepted_filename_map()), array_keys(five_dice_media_pack_accepted_filename_map()))),
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
        'availableMediaSlots' => array_column($pack['installed'], 'slot'),
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
    ocx_resource_archive_remove($resolvedPath);
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
    ocx_resource_archive_remove($resolved);
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
        'requiredCount' => count(array_filter(five_dice_media_pack_slots(), static fn(array $d): bool => !empty($d['requiredForClassic']))),
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
                if ($file['error'] !== UPLOAD_ERR_OK || $file['bytes'] < 1 || !is_file($file['tmpName'])
                    || (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmpName']))) {
                    throw new RuntimeException('Every selected pack file must upload completely.');
                }
                $safeName = five_dice_media_pack_safe_upload_name($file['fullPath'] !== '' ? $file['fullPath'] : $file['name']);
                if (str_ends_with($safeName, '.ocx')) {
                    $staticOcx[] = five_dice_media_stage_ocx($file['tmpName'], $attempt);
                    continue;
                }
                $slot = $nameMap[$safeName] ?? null;
                if (!is_string($slot) || !isset($slots[$slot])) {
                    throw new RuntimeException('The selected pack contains an unknown file. Choose only a recognized OCX, original media, or prepared media.');
                }
                five_dice_media_stage_source($slot, (string)file_get_contents($file['tmpName']), $attempt);
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
            if ((int)$progress['stagedCount'] < (int)$progress['requiredCount']) {
                throw new RuntimeException('The selected pack does not cover all required media slots.');
            }
            foreach (array_keys(five_dice_media_pack_slots()) as $slot) {
                $validation = five_dice_media_pack_validate_slot($slot, $attempt);
                if (($validation['state'] ?? '') === 'missing' && empty($validation['requiredForClassic'])) continue;
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
                $slotCount = (int)$progress['stagedCount'];
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
        $required = array_keys(array_filter($slots, static fn(array $d): bool => !empty($d['requiredForClassic'])));
        if (count($files) < count($required) || count($files) > count($slots)) {
            throw new RuntimeException('Choose the complete required Classic artwork and sound pack, with any optional files.');
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
                if ($file['error'] !== UPLOAD_ERR_OK || $file['bytes'] < 1 || !is_file($file['tmpName'])
                    || (PHP_SAPI !== 'cli' && !is_uploaded_file($file['tmpName']))) {
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
                five_dice_media_stage_source($slot, (string)file_get_contents($file['tmpName']), $attempt);
            }
            if (array_diff($required, array_keys($seen))) throw new RuntimeException('The selected pack does not cover all required media slots.');
            foreach (array_keys($seen) as $slot) {
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
                $slotCount = count($seen);
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
