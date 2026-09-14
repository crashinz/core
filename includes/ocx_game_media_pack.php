<?php
declare(strict_types=1);

require_once __DIR__ . '/ocx_static_media_extractor.php';

/** Installation-private Classic media owner for Build 000060 games. */

const OCX_GAME_MEDIA_MAX_TOTAL_BYTES = 134217728;
const OCX_GAME_MEDIA_MAX_BATCH_FILES = 192;
const OCX_GAME_MEDIA_ATTEMPT_TTL_SECONDS = 1800;
const OCX_GAME_MEDIA_PACK_EXTENSION_IDS = [
    'checkers',
    'chess',
    'acey-deucy',
    'battleship',
    'spades',
    'backgammon-first-party',
];

function ocx_game_media_slot_id(string $resource): string
{
    $withoutExtension = (string)preg_replace('/\.[a-z0-9]+$/i', '', trim($resource));
    return trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $withoutExtension)), '-');
}

function ocx_game_media_add_image(
    array &$slots,
    string $resource,
    ?array $dimensions = null,
    string $label = '',
    ?array $preparation = null
): void
{
    $slot = ocx_game_media_slot_id($resource);
    $extension = str_starts_with(strtolower($resource), 'gif_') || str_ends_with(strtolower($resource), '.gif') ? 'gif' : 'png';
    $original = str_contains($resource, '.') ? strtolower($resource) : strtolower($resource . '.' . $extension);
    $definition = [
        'label' => $label !== '' ? $label : ucwords(str_replace(['gif-', '-'], ['', ' '], $slot)),
        'installName' => $slot . '.' . $extension,
        'kind' => $extension,
        'mime' => 'image/' . $extension,
        'maximumBytes' => 4194304,
        'maximumWidth' => 2048,
        'maximumHeight' => 2048,
        'requiredForClassic' => true,
        'acceptedOriginalNames' => [$original],
    ];
    if (is_array($dimensions)) { $definition['requiredWidth'] = (int)$dimensions[0]; $definition['requiredHeight'] = (int)$dimensions[1]; }
    if ($preparation !== null) $definition['preparation'] = $preparation;
    $slots[$slot] = $definition;
}

function ocx_game_media_edge_matte_preparation(array $rgb): array
{
    if (count($rgb) !== 3 || array_filter($rgb, static fn(mixed $value): bool => !is_int($value) || $value < 0 || $value > 255)) {
        throw new LogicException('The OCX media matte descriptor is invalid.');
    }
    return [
        'owner' => 'edge-connected-source-matte-v1',
        'matteRgb' => array_values($rgb),
        'tolerance' => 0,
        'outputKind' => 'png',
        'outputMime' => 'image/png',
    ];
}

function ocx_game_media_add_wav(array &$slots, string $resource): void
{
    $slot = ocx_game_media_slot_id($resource);
    $slots[$slot] = [
        'label' => ucwords(str_replace(['wav-', '-'], ['', ' '], $slot)) . ' sound',
        'installName' => $slot . '.wav', 'kind' => 'wav', 'mime' => 'audio/wav',
        'maximumBytes' => 8388608, 'requiredForClassic' => true,
        'acceptedOriginalNames' => [strtolower($resource . '.wav')],
    ];
}

function ocx_game_media_checkers_slots(): array
{
    $slots = [];
    ocx_game_media_add_image($slots, 'classic-board.png', [460, 320], 'Classic board');
    $slots['classic-board']['acceptedOriginalNames'][] = '527.png';
    $piecePreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    $bitmapDimensions = [
        500 => [45, 50], 501 => [41, 45], 502 => [40, 40], 503 => [38, 38],
        504 => [45, 50], 505 => [41, 45], 506 => [40, 40], 507 => [38, 38],
        508 => [45, 50], 509 => [41, 45], 510 => [40, 40], 511 => [38, 38],
        512 => [45, 50], 513 => [41, 45], 514 => [40, 40], 515 => [38, 38],
        520 => [45, 1050], 521 => [38, 760], 522 => [45, 1000], 523 => [38, 760],
        524 => [5, 11], 525 => [5, 11], 526 => [45, 768],
    ];
    foreach ($bitmapDimensions as $resourceId => $dimensions) {
        ocx_game_media_add_image($slots, 'BITMAP_' . $resourceId . '.png', $dimensions, 'Checkers source bitmap ' . $resourceId, $piecePreparation);
        $slots['bitmap-' . $resourceId]['acceptedOriginalNames'][] = $resourceId . '.png';
    }
    foreach (['B','W','K_B','K_W'] as $piece) foreach (range(1,4) as $size) foreach (['','_H'] as $state) ocx_game_media_add_image($slots, 'GIF_' . $piece . '_' . $size . $state, null, '', $piecePreparation);
    foreach (['01','03','05','07','10','12','14','16','21','23','25','27','30','32','34','36','41','43','45','47','50','52','54','56','61','63','65','67','70','72','74','76'] as $frame) ocx_game_media_add_image($slots, 'GIF_DISCO' . $frame);
    foreach (['DRAW_D','DRAW_H','DRAW_P','DRAW_R','RESIGN_D','RESIGN_H','RESIGN_P','RESIGN_R','GFX_DH','GFX_DR','GFX_UH','GFX_UR','SFX_DH','SFX_DR','SFX_UH','SFX_UR','LAVATAR1','LAVATAR2','EMPTY'] as $name) ocx_game_media_add_image($slots, 'GIF_' . $name);
    ocx_game_media_add_image($slots, 'IDG_GAMEGROUP.gif');
    foreach (['WAV_DRAW','WAV_JUMP','WAV_LOCK','WAV_MORPH','WAV_MOVE','WAV_RESIGN','WAV_UNLOCK','WAV_VICTORY'] as $name) ocx_game_media_add_wav($slots, $name);
    return $slots;
}

function ocx_game_media_chess_slots(): array
{
    $slots = [];
    ocx_game_media_add_image($slots, 'classic-board.png', [460,320], 'Classic board'); $slots['classic-board']['acceptedOriginalNames'][] = '210.png';
    ocx_game_media_add_image($slots, 'classic-board-alternate.png', [460,320], 'Classic alternate board'); $slots['classic-board-alternate']['acceptedOriginalNames'][] = '211.png';
    $piecePreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    foreach (['B','KN','K','P','Q','R'] as $piece) foreach (['B','W'] as $color) foreach (range(1,4) as $size) foreach (['','_H'] as $state) ocx_game_media_add_image($slots, 'GIF_' . $piece . '_' . $color . $state . '_' . $size, null, '', $piecePreparation);
    foreach (['BD1','BD2','BU1','BU2','KD1','KD2','KU1','KU2','NUL','PD1','PD2','PD3','PD4','PD5','PD6','PD7','PD8','PU1','PU2','PU3','PU4','PU5','PU6','PU7','PU8','QD','QU','RD1','RD2','RU1','RU2'] as $name) ocx_game_media_add_image($slots, 'GIF_DEAD_' . $name);
    foreach (['DRAW_D','DRAW_H','DRAW_P','DRAW_R','RSGN_D','RSGN_H','RSGN_P','RSGN_R','GFX_DH','GFX_DR','GFX_UH','GFX_UR','SFX_DH','SFX_DR','SFX_UH','SFX_UR','LAVATAR1','LAVATAR2','EMPTY'] as $name) ocx_game_media_add_image($slots, 'GIF_' . $name);
    ocx_game_media_add_image($slots, 'IDG_GAMEGROUP.gif');
    foreach (['WAV_CHECK','WAV_DIE','WAV_DRAW','WAV_LOCK','WAV_MATE','WAV_MOVE','WAV_OOT','WAV_OUTTIME','WAV_RESIGN','WAV_UNLOCK','WAV_YOOT'] as $name) ocx_game_media_add_wav($slots,$name);
    return $slots;
}

function ocx_game_media_acey_deucy_slots(): array
{
    $slots=[];
    foreach ([[531,'classic-board'],[532,'classic-board-alternate'],[533,'classic-board-result']] as [$source,$name]) { ocx_game_media_add_image($slots,$name.'.png',[500,320],ucwords(str_replace('-',' ',$name))); $slots[$name]['acceptedOriginalNames'][]=$source.'.png'; }
    $piecePreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    $preparedSpriteNames = ['B','B_H','W','W_H','DICE1','DICE2','DICE3','DICE4','DICE5','DICE6'];
    foreach (['B','B_H','B_S','DICE1','DICE2','DICE3','DICE4','DICE5','DICE6','EMPTY','GFX_DH','GFX_DR','GFX_UH','GFX_UR','LAVATAR1','LAVATAR2','ROLL_D','ROLL_H','ROLL_P','ROLL_R','SFX_DH','SFX_DR','SFX_UH','SFX_UR','W','W_H','W_S'] as $name) {
        ocx_game_media_add_image($slots, 'GIF_' . $name, null, '', in_array($name, $preparedSpriteNames, true) ? $piecePreparation : null);
    }
    ocx_game_media_add_image($slots, 'IDG_GAMEGROUP.gif');
    foreach (['WAV_ACEYDEUCY','WAV_AWCRAP','WAV_AWW','WAV_BKGAMM','WAV_BTRLUK','WAV_DANGIT','WAV_DBLSIX','WAV_DICE','WAV_EAT','WAV_GAMMON','WAV_LOCK','WAV_MOVE','WAV_OUT','WAV_UCANT','WAV_UNLOCK','WAV_VICTORY','WAV_YGO','WAV_YMOVE','WAV_YMOVE2','WAV_YTURN'] as $name) ocx_game_media_add_wav($slots,$name);
    return $slots;
}

function ocx_game_media_battleship_slots(): array
{
    $slots=[];ocx_game_media_add_image($slots,'classic-board.png',[420,320],'Classic board');$slots['classic-board']['acceptedOriginalNames'][]='19.png';
    $shipPreparation = ocx_game_media_edge_matte_preparation([255, 255, 255]);
    $sunkShipPreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    $resultPreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    foreach (['1','1_SNF','2_H','2_H_SNF','2_V','2_V_SNF','3_H','3_H_SNF','3_V','3_V_SNF','4_H','4_H_SNF','4_V','4_V_SNF','5_H','5_H_SNF','5_V','5_V_SNF','AUTO_D','AUTO_H','AUTO_P','AUTO_R','END','GFX_DH','GFX_DR','GFX_UH','GFX_UR','HIT','ICONA1','ICONA2','ICONB1','ICONB2','MISS','MUSIC_DH','MUSIC_DR','MUSIC_UH','MUSIC_UR','M_OPPON','M_PLACE','M_SELECT','M_START','M_WAIT','SFX_DH','SFX_DR','SFX_UH','SFX_UR','START_D','START_H','START_P','START_R'] as $name) {
        $isShip = preg_match('/^(?:1|[2-5]_[HV])(?:_SNF)?$/', $name) === 1;
        $preparation = $isShip
            ? (str_ends_with($name, '_SNF') ? $sunkShipPreparation : $shipPreparation)
            : (in_array($name, ['HIT', 'MISS'], true) ? $resultPreparation : null);
        ocx_game_media_add_image($slots, 'GIF_' . $name, null, '', $preparation);
    }
    ocx_game_media_add_image($slots, 'IDG_GAMEGROUP.gif');
    foreach (['WAV_HIT','WAV_KEY','WAV_LOCATE','WAV_LOOSER','WAV_MIS','WAV_SHOOT','WAV_SINK','WAV_VICTORY','WAV_WHISTL','WAV_WIND','WAV_WIND1','WAV_WIND2','WAV_WIND3'] as $name) ocx_game_media_add_wav($slots,$name);
    $nativeMotionPreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    foreach ([
        2 => [40, 1400], 3 => [40, 1800], 4 => [24, 300], 5 => [18, 280],
        6 => [18, 320], 7 => [20, 180], 8 => [13, 728], 9 => [60, 4080],
        10 => [21, 735], 11 => [20, 1209], 12 => [24, 1767], 13 => [26, 2325],
        14 => [27, 2883], 15 => [39, 682], 16 => [57, 713], 17 => [74, 806],
        18 => [93, 775],
    ] as $resourceId => $dimensions) {
        $slot = 'dib-' . $resourceId;
        ocx_game_media_add_image(
            $slots,
            $slot . '.png',
            $dimensions,
            'Native Battleship motion strip DIB' . $resourceId,
            $resourceId >= 4 ? $nativeMotionPreparation : null
        );
        $slots[$slot]['acceptedOriginalNames'][] = $resourceId . '.png';
        $slots[$slot]['sourceResource'] = ['type' => 'BITMAP', 'id' => $resourceId, 'language' => 1033];
        $slots[$slot]['maximumHeight'] = max(2048, $dimensions[1]);
    }
    $slots['mid-battle'] = [
        'label' => 'Source MID BATTLE music',
        'installName' => 'mid-battle.wav',
        'kind' => 'wav',
        'mime' => 'audio/wav',
        'maximumBytes' => 16777216,
        'requiredForClassic' => true,
        'acceptedOriginalNames' => ['mid_battle.rmid'],
        'sourceResource' => ['type' => 'MIDIMUSIC', 'id' => 'MID_BATTLE', 'language' => 1033],
        'derivationOwner' => 'deterministic-static-rmid-browser-wave-v1',
    ];
    return $slots;
}

function ocx_game_media_spades_slots(): array
{
    $slots=[];ocx_game_media_add_image($slots,'classic-board.png',[420,308],'Classic board');$slots['classic-board']['acceptedOriginalNames'][]='208.png';
    foreach(['2','3','4','5','6','7','8','9','T','J','Q','K','A'] as $rank)foreach(['C','D','H','S'] as $suit)ocx_game_media_add_image($slots,'GIF_CARD_'.$rank.$suit,[54,72]);
    foreach(['ARROW_D','ARROW_D_OFF','ARROW_L','ARROW_L_OFF','ARROW_R','ARROW_R_OFF','ARROW_U','ARROW_U_OFF','BTN_DOWN','BTN_DOWN_HI','BTN_UP','BTN_UP_HI','CARD_BACK','CARD_BLANK','TEAM1','TEAM1_HILITE','TEAM2','TEAM2_HILITE'] as $name)ocx_game_media_add_image($slots,'GIF_'.$name);
    ocx_game_media_add_image($slots, 'IDG_GAMEGROUP.gif');
    foreach(['WAV_BLINDNIL','WAV_PLAYCARD','WAV_SET','WAV_SHUFFLE','WAV_YOURTURN','WAV_YOURTURN2'] as $name)ocx_game_media_add_wav($slots,$name);
    return $slots;
}

function ocx_game_media_backgammon_slots(): array
{
    $slots = [];
    foreach ([[531, 'classic-board'], [532, 'classic-board-alternate']] as [$source, $name]) {
        ocx_game_media_add_image($slots, $name . '.png', [460, 320], ucwords(str_replace('-', ' ', $name)));
        $slots[$name]['acceptedOriginalNames'][] = $source . '.png';
    }
    $checkerPreparation = ocx_game_media_edge_matte_preparation([0, 0, 0]);
    foreach ([
        515 => [60, 396], 516 => [60, 396], 517 => [60, 396], 518 => [60, 396],
        519 => [80, 380], 520 => [80, 380], 521 => [80, 380], 522 => [80, 380],
        523 => [77, 513], 524 => [77, 513], 525 => [77, 513], 526 => [77, 513],
        527 => [51, 1710], 528 => [51, 1710], 529 => [51, 1710], 530 => [51, 1710],
    ] as $source => $dimensions) {
        $slot = 'bitmap-' . $source;
        ocx_game_media_add_image($slots, $slot . '.png', $dimensions, 'Native Backgammon terminal strip ' . $source, $checkerPreparation);
        $slots[$slot]['acceptedOriginalNames'][] = $source . '.png';
    }
    $preparedSpriteNames = ['B','B_H','W','W_H','DICE1','DICE2','DICE3','DICE4','DICE5','DICE6'];
    foreach (['B','B_H','B_S','DICE1','DICE2','DICE3','DICE4','DICE5','DICE6','EMPTY','GFX_DH','GFX_DR','GFX_UH','GFX_UR','LAVATAR1','LAVATAR2','ROLL_D','ROLL_H','ROLL_P','ROLL_R','SFX_DH','SFX_DR','SFX_UH','SFX_UR','W','W_H','W_S'] as $name) {
        ocx_game_media_add_image(
            $slots,
            'GIF_' . $name,
            null,
            '',
            in_array($name, $preparedSpriteNames, true) ? $checkerPreparation : null
        );
    }
    ocx_game_media_add_image($slots, 'IDG_GAMEGROUP.gif');
    foreach (['WAV_BKGAMM','WAV_BTRLUK','WAV_DBLSIX','WAV_DICE','WAV_EAT','WAV_GAMMON','WAV_LOCK','WAV_MOVE','WAV_OUT','WAV_UCANT','WAV_UNLOCK','WAV_VICTORY','WAV_YGO','WAV_YMOVE','WAV_YTURN'] as $name) {
        ocx_game_media_add_wav($slots, $name);
    }
    return $slots;
}

const OCX_GAME_CLASSIC_MEDIA_PACK_EXTENSION_IDS = [
    'checkers', 'chess', 'acey-deucy', 'battleship', 'spades', 'backgammon-first-party',
];

function ocx_game_media_pack_slots(string $extensionId): array
{
    return match($extensionId){
        'checkers'=>ocx_game_media_checkers_slots(),'chess'=>ocx_game_media_chess_slots(),
        'acey-deucy'=>ocx_game_media_acey_deucy_slots(),'battleship'=>ocx_game_media_battleship_slots(),
        'spades'=>ocx_game_media_spades_slots(),'backgammon-first-party'=>ocx_game_media_backgammon_slots(),
        default=>throw new MultiplayerGameException('This Classic media pack is unavailable.','OCX_GAME_MEDIA_GAME_INVALID',404),
    };
}

function ocx_game_media_pack_name_map(string $extensionId): array
{
    $map=[];foreach(ocx_game_media_pack_slots($extensionId) as $slot=>$definition){$map[strtolower((string)$definition['installName'])]=$slot;foreach((array)$definition['acceptedOriginalNames'] as $name)$map[strtolower((string)$name)]=$slot;}return $map;
}

function ocx_game_media_generation_setting(string $extensionId): string { return 'ocx_game_media_pack_active_generation_'.$extensionId; }
function ocx_game_media_category(string $extensionId,string $kind): string { return 'ocx-game-'.$extensionId.'-media-'.$kind; }
function ocx_game_media_generation_directory(string $extensionId,string $generation): ?string { if(!isset(OCX_GAME_EXTENSION_IDENTITIES[$extensionId])||!preg_match('/^generation-[a-f0-9-]{36}$/',$generation))return null;$path=security_private_storage_directory(ocx_game_media_category($extensionId,'generations')).DIRECTORY_SEPARATOR.$generation;return is_dir($path)?$path:null; }
function ocx_game_media_active_directory(PDO $pdo,string $extensionId): ?string { $generation=app_setting($pdo,ocx_game_media_generation_setting($extensionId),'');return $generation===''?null:ocx_game_media_generation_directory($extensionId,$generation); }

function ocx_game_media_with_lock(string $extensionId, callable $operation): mixed
{
    ocx_game_extension_identity($extensionId);
    $root = security_private_storage_directory(ocx_game_media_category($extensionId, 'locks'));
    $handle = fopen($root . DIRECTORY_SEPARATOR . 'active.lock', 'c+');
    if ($handle === false) throw new RuntimeException('The private media operation lock is unavailable.');
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new MultiplayerGameException('Another Classic media operation is active. Try again after it finishes.', 'OCX_GAME_MEDIA_BUSY', 409);
        }
        return $operation();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function ocx_game_media_validate_slot(string $extensionId,string $slot,?string $directory): array
{
    $definition=ocx_game_media_pack_slots($extensionId)[$slot]??null;if(!is_array($definition))return['state'=>'invalid','reason'=>'Unknown media slot.'];
    if($directory===null)return $definition+['slot'=>$slot,'state'=>'missing','path'=>null];$path=$directory.DIRECTORY_SEPARATOR.$definition['installName'];if(!is_file($path))return $definition+['slot'=>$slot,'state'=>'missing','path'=>null];
    $root=realpath($directory);$resolved=realpath($path);$bytes=$resolved===false?false:filesize($resolved);
    if($root===false||$resolved===false||!str_starts_with(strtolower($resolved).DIRECTORY_SEPARATOR,strtolower(rtrim($root,DIRECTORY_SEPARATOR)).DIRECTORY_SEPARATOR)||$bytes===false||$bytes<1||$bytes>(int)$definition['maximumBytes'])return $definition+['slot'=>$slot,'state'=>'invalid','path'=>null,'reason'=>'The file is unavailable or outside its safe size boundary.'];
    $valid=false;$dimensions=null;$kind=(string)$definition['kind'];
    if(in_array($kind,['gif','png'],true)){$image=@getimagesize($resolved);$prefix=(string)file_get_contents($resolved,false,null,0,8);$valid=is_array($image)&&(string)($image['mime']??'')===(string)$definition['mime']&&($kind==='gif'?in_array(substr($prefix,0,6),['GIF87a','GIF89a'],true):$prefix==="\x89PNG\r\n\x1a\n")&&(int)$image[0]>=1&&(int)$image[0]<=(int)$definition['maximumWidth']&&(int)$image[1]>=1&&(int)$image[1]<=(int)$definition['maximumHeight']&&(!isset($definition['requiredWidth'])||(int)$image[0]===(int)$definition['requiredWidth'])&&(!isset($definition['requiredHeight'])||(int)$image[1]===(int)$definition['requiredHeight']);if(is_array($image))$dimensions=[(int)$image[0],(int)$image[1]];}
    elseif($kind==='wav')$valid=five_dice_wav_signature_valid($resolved);
    if(!$valid)return $definition+['slot'=>$slot,'state'=>'invalid','path'=>null,'reason'=>'The file signature, detected type, or dimensions do not match this slot.'];
    return $definition+['slot'=>$slot,'state'=>'installed','path'=>$resolved,'bytes'=>(int)$bytes,'dimensions'=>$dimensions,'sha256'=>strtoupper(hash_file('sha256',$resolved))];
}

function ocx_game_media_prepared_path_is_valid(string $path, array $definition, array $preparation): bool
{
    if (!is_file($path) || (int)(filesize($path) ?: 0) < 1 || (int)(filesize($path) ?: 0) > (int)$definition['maximumBytes']) return false;
    $image = @getimagesize($path);
    $prefix = (string)@file_get_contents($path, false, null, 0, 8);
    return is_array($image)
        && (string)($image['mime'] ?? '') === (string)$preparation['outputMime']
        && $prefix === "\x89PNG\r\n\x1a\n"
        && (!isset($definition['requiredWidth']) || (int)$image[0] === (int)$definition['requiredWidth'])
        && (!isset($definition['requiredHeight']) || (int)$image[1] === (int)$definition['requiredHeight'])
        && ocx_game_media_prepared_alpha_contract($path);
}

function ocx_game_media_prepared_alpha_contract(string $path): bool
{
    if (!extension_loaded('gd')) return false;
    $image = @imagecreatefrompng($path);
    if ($image === false) return false;
    $width = imagesx($image); $height = imagesy($image);
    $hasTransparentBoundary = false; $hasOpaqueArtwork = false;
    for ($x = 0; $x < $width && !$hasTransparentBoundary; $x++) {
        foreach ([0, $height - 1] as $y) {
            if ((int)imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'] === 127) { $hasTransparentBoundary = true; break; }
        }
    }
    for ($y = 0; $y < $height && !$hasTransparentBoundary; $y++) {
        foreach ([0, $width - 1] as $x) {
            if ((int)imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'] === 127) { $hasTransparentBoundary = true; break; }
        }
    }
    for ($y = 0; $y < $height && !$hasOpaqueArtwork; $y++) for ($x = 0; $x < $width; $x++) {
        if ((int)imagecolorsforindex($image, imagecolorat($image, $x, $y))['alpha'] < 127) { $hasOpaqueArtwork = true; break; }
    }
    imagedestroy($image);
    return $hasTransparentBoundary && $hasOpaqueArtwork;
}

function ocx_game_media_prepare_edge_connected_matte(string $sourcePath, string $targetPath, array $preparation): void
{
    if (!extension_loaded('gd')) throw new RuntimeException('The private media preparation owner is unavailable.');
    $sourceBytes = @file_get_contents($sourcePath);
    $source = $sourceBytes === false ? false : @imagecreatefromstring($sourceBytes);
    if ($source === false) throw new RuntimeException('The original private sprite could not be decoded safely.');
    $width = imagesx($source); $height = imagesy($source);
    $image = imagecreatetruecolor($width, $height);
    if ($image === false) { imagedestroy($source); throw new RuntimeException('The private sprite preparation surface is unavailable.'); }
    imagealphablending($image, false); imagesavealpha($image, true);
    $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
    imagefill($image, 0, 0, $transparent);
    imagecopy($image, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);

    $matte = array_values((array)($preparation['matteRgb'] ?? []));
    $tolerance = (int)($preparation['tolerance'] ?? 0);
    $matches = static function (int $x, int $y) use ($image, $matte, $tolerance): bool {
        $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        return max(
            abs((int)$color['red'] - (int)$matte[0]),
            abs((int)$color['green'] - (int)$matte[1]),
            abs((int)$color['blue'] - (int)$matte[2])
        ) <= $tolerance;
    };
    $queue = new SplQueue(); $seen = [];
    for ($x = 0; $x < $width; $x++) { $queue->enqueue([$x, 0]); $queue->enqueue([$x, $height - 1]); }
    for ($y = 0; $y < $height; $y++) { $queue->enqueue([0, $y]); $queue->enqueue([$width - 1, $y]); }
    while (!$queue->isEmpty()) {
        [$x, $y] = $queue->dequeue(); $key = $y * $width + $x;
        if (isset($seen[$key]) || !$matches($x, $y)) continue;
        $seen[$key] = true;
        if ($x > 0) $queue->enqueue([$x - 1, $y]);
        if ($x + 1 < $width) $queue->enqueue([$x + 1, $y]);
        if ($y > 0) $queue->enqueue([$x, $y - 1]);
        if ($y + 1 < $height) $queue->enqueue([$x, $y + 1]);
    }
    foreach (array_keys($seen) as $key) imagesetpixel($image, $key % $width, intdiv($key, $width), $transparent);
    if (!imagepng($image, $targetPath, 9)) { imagedestroy($image); throw new RuntimeException('The private sprite preparation output could not be written.'); }
    imagedestroy($image);
}

function ocx_game_media_prepared_file(array $validatedSource, string $directory): array
{
    $preparation = $validatedSource['preparation'] ?? null;
    if (!is_array($preparation)) return $validatedSource;
    if (($preparation['owner'] ?? '') !== 'edge-connected-source-matte-v1') throw new RuntimeException('The private media preparation owner is invalid.');
    $root = realpath($directory);
    if ($root === false) throw new RuntimeException('The private media generation is unavailable.');
    $preparedDirectory = $root . DIRECTORY_SEPARATOR . '.prepared';
    if (!is_dir($preparedDirectory) && !mkdir($preparedDirectory, 0700, true) && !is_dir($preparedDirectory)) {
        throw new RuntimeException('The private prepared-media directory is unavailable.');
    }
    $slot = (string)$validatedSource['slot'];
    $target = $preparedDirectory . DIRECTORY_SEPARATOR . $slot . '.png';
    $metadataPath = $preparedDirectory . DIRECTORY_SEPARATOR . $slot . '.json';
    $sourceSha = strtoupper((string)$validatedSource['sha256']);
    $metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath), true) : null;
    if (is_array($metadata)
        && hash_equals($sourceSha, strtoupper((string)($metadata['sourceSha256'] ?? '')))
        && (string)($metadata['preparationOwner'] ?? '') === (string)$preparation['owner']
        && ocx_game_media_prepared_path_is_valid($target, $validatedSource, $preparation)) {
        return array_replace($validatedSource, [
            'path' => realpath($target), 'mime' => (string)$preparation['outputMime'],
            'bytes' => (int)filesize($target), 'sha256' => strtoupper(hash_file('sha256', $target)),
            'preparedFromSha256' => $sourceSha, 'preparationOwner' => (string)$preparation['owner'],
        ]);
    }
    $temporary = $preparedDirectory . DIRECTORY_SEPARATOR . '.attempt-' . uuid_v4() . '.png';
    try {
        ocx_game_media_prepare_edge_connected_matte((string)$validatedSource['path'], $temporary, $preparation);
        if (!ocx_game_media_prepared_path_is_valid($temporary, $validatedSource, $preparation)) {
            throw new RuntimeException('The prepared private sprite failed its output contract.');
        }
        if (is_file($target) && !unlink($target)) throw new RuntimeException('The stale prepared private sprite could not be replaced.');
        if (!rename($temporary, $target)) throw new RuntimeException('The prepared private sprite could not be activated.');
        $metadataJson = json_encode([
            'sourceSha256' => $sourceSha,
            'preparedSha256' => strtoupper(hash_file('sha256', $target)),
            'preparationOwner' => (string)$preparation['owner'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $metadataTemporary = $metadataPath . '.attempt-' . uuid_v4();
        if ($metadataJson === false || file_put_contents($metadataTemporary, $metadataJson . "\n", LOCK_EX) === false || !rename($metadataTemporary, $metadataPath)) {
            @unlink($metadataTemporary);
            throw new RuntimeException('The prepared private sprite provenance could not be recorded.');
        }
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
    return array_replace($validatedSource, [
        'path' => realpath($target), 'mime' => (string)$preparation['outputMime'],
        'bytes' => (int)filesize($target), 'sha256' => strtoupper(hash_file('sha256', $target)),
        'preparedFromSha256' => $sourceSha, 'preparationOwner' => (string)$preparation['owner'],
    ]);
}

function ocx_game_media_pack_status(PDO $pdo,string $extensionId): array
{
    $directory=ocx_game_media_active_directory($pdo,$extensionId);$installed=[];$missing=[];$invalid=[];
    foreach(array_keys(ocx_game_media_pack_slots($extensionId)) as $slot){$result=ocx_game_media_validate_slot($extensionId,$slot,$directory);if($result['state']==='installed'&&is_array($result['preparation']??null)&&$directory!==null){try{ocx_game_media_prepared_file($result,$directory);}catch(Throwable){$result['state']='invalid';$result['reason']='The source-backed private media preparation failed.';}}$public=['slot'=>$slot,'label'=>$result['label'],'installName'=>$result['installName'],'state'=>$result['state']];if($result['state']==='installed')$installed[]=$public;elseif($result['state']==='missing')$missing[]=$public;else$invalid[]=$public+['guidance'=>'Replace this file with the recognized original or prepared media for this slot.'];}
    $required=count(ocx_game_media_pack_slots($extensionId));return['extensionId'=>$extensionId,'installed'=>$installed,'missing'=>$missing,'invalid'=>$invalid,'installedCount'=>count($installed),'requiredCount'=>$required,'classicComplete'=>count($installed)===$required&&$invalid===[],'privateStorage'=>true,'publicWebRoot'=>false,'acceptedOriginalNames'=>array_keys(ocx_game_media_pack_name_map($extensionId))];
}

function ocx_game_media_safe_upload_name(string $name): string
{
    $name=str_replace('\\','/',trim($name));if($name===''||str_starts_with($name,'/')||preg_match('/^[A-Za-z]:/',$name)||preg_match('/[\x00-\x1F\x7F]/',$name))throw new RuntimeException('A selected file has an unsafe path.');$parts=explode('/',$name);if(array_filter($parts,static fn(string $part):bool=>$part===''||$part==='.'||$part==='..'))throw new RuntimeException('A selected file has an unsafe path.');return strtolower((string)end($parts));
}

function ocx_game_media_uploaded_files(): array
{
    $upload=$_FILES['files']??null;if(!is_array($upload)||!is_array($upload['name']??null))return[];$files=[];foreach($upload['name'] as $index=>$name)$files[]=['name'=>(string)$name,'fullPath'=>(string)(is_array($upload['full_path']??null)?($upload['full_path'][$index]??$name):$name),'tmpName'=>(string)($upload['tmp_name'][$index]??''),'error'=>(int)($upload['error'][$index]??UPLOAD_ERR_NO_FILE),'bytes'=>(int)($upload['size'][$index]??0)];return$files;
}

function ocx_game_media_delete_tree(string $path,string $root): void
{
    $resolvedRoot=realpath($root);$resolved=realpath($path);if($resolvedRoot===false||$resolved===false||$resolved===$resolvedRoot||!str_starts_with(strtolower($resolved).DIRECTORY_SEPARATOR,strtolower(rtrim($resolvedRoot,DIRECTORY_SEPARATOR)).DIRECTORY_SEPARATOR))throw new RuntimeException('The private cleanup boundary is invalid.');$items=scandir($resolved);if($items===false)throw new RuntimeException('The private cleanup inventory failed.');foreach($items as $item){if($item==='.'||$item==='..')continue;$child=$resolved.DIRECTORY_SEPARATOR.$item;if(is_dir($child))ocx_game_media_delete_tree($child,$resolvedRoot);elseif(!unlink($child))throw new RuntimeException('Private attempt cleanup failed.');}if(!rmdir($resolved))throw new RuntimeException('Private attempt cleanup failed.');
}

function ocx_game_media_prune_inactive_generations(string $extensionId, ?string $keepGeneration): int
{
    ocx_game_extension_identity($extensionId);
    if ($keepGeneration !== null && !preg_match('/^generation-[a-f0-9-]{36}$/', $keepGeneration)) {
        throw new RuntimeException('The active private media generation identity is invalid.');
    }
    $root = security_private_storage_directory(ocx_game_media_category($extensionId, 'generations'));
    $items = scandir($root);
    if ($items === false) throw new RuntimeException('The private media generation inventory failed.');
    $removed = 0;
    foreach ($items as $item) {
        if (!preg_match('/^generation-[a-f0-9-]{36}$/', $item) || $item === $keepGeneration) continue;
        $path = $root . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($path)) continue;
        ocx_game_media_delete_tree($path, $root);
        $removed++;
    }
    return $removed;
}

function ocx_game_media_attempt_directory(string $extensionId,string $attemptId): ?string
{
    if(!preg_match('/^attempt-[a-f0-9-]{36}$/',$attemptId))return null;$path=security_private_storage_directory(ocx_game_media_category($extensionId,'attempts')).DIRECTORY_SEPARATOR.$attemptId;return is_dir($path)?$path:null;
}

function ocx_game_media_cleanup_expired_attempts(string $extensionId): int
{
    $root = security_private_storage_directory(ocx_game_media_category($extensionId, 'attempts'));
    $items = scandir($root);
    if ($items === false) throw new RuntimeException('The private attempt inventory is unavailable.');
    $removed = 0;
    foreach ($items as $item) {
        if (!preg_match('/^attempt-[a-f0-9-]{36}$/', $item)) continue;
        $path = $root . DIRECTORY_SEPARATOR . $item;
        if (!is_dir($path)) continue;
        $metadataPath = $path . DIRECTORY_SEPARATOR . '.attempt.json';
        $metadata = is_file($metadataPath) ? json_decode((string)file_get_contents($metadataPath), true) : null;
        $createdAt = is_array($metadata) ? (int)($metadata['createdAt'] ?? 0) : 0;
        if ($createdAt > 0 && time() - $createdAt <= OCX_GAME_MEDIA_ATTEMPT_TTL_SECONDS) continue;
        ocx_game_media_delete_tree($path, $root);
        $removed++;
    }
    return $removed;
}

function ocx_game_media_attempt_metadata(string $extensionId,string $attempt,int $actorUserId): array
{
    $path=$attempt.DIRECTORY_SEPARATOR.'.attempt.json';$metadata=is_file($path)?json_decode((string)file_get_contents($path),true):null;if(!is_array($metadata)||(int)($metadata['ownerUserId']??0)!==$actorUserId||($metadata['extensionId']??'')!==$extensionId)throw new RuntimeException('The private pack attempt is unavailable or no longer authorized.');if(time()-(int)($metadata['createdAt']??0)>OCX_GAME_MEDIA_ATTEMPT_TTL_SECONDS){ocx_game_media_delete_tree($attempt,security_private_storage_directory(ocx_game_media_category($extensionId,'attempts')));throw new RuntimeException('The private pack attempt expired. Select the files again.');}return$metadata;
}

function ocx_game_media_attempt_progress(string $extensionId,string $attempt): array
{
    $count=0;$bytes=0;foreach(ocx_game_media_pack_slots($extensionId) as $definition){$path=$attempt.DIRECTORY_SEPARATOR.$definition['installName'];if(is_file($path)){$count++;$bytes+=(int)(filesize($path)?:0);}}return['stagedCount'=>$count,'requiredCount'=>count(ocx_game_media_pack_slots($extensionId)),'stagedBytes'=>$bytes];
}

function ocx_game_media_begin_attempt(PDO $pdo,string $extensionId,int $actorUserId): array
{
    ocx_game_extension_identity($extensionId);ocx_game_media_cleanup_expired_attempts($extensionId);$root=security_private_storage_directory(ocx_game_media_category($extensionId,'attempts'));$attemptId='attempt-'.uuid_v4();$attempt=$root.DIRECTORY_SEPARATOR.$attemptId;if(!mkdir($attempt,0770)&&!is_dir($attempt))throw new RuntimeException('The private validation attempt could not be created.');$metadata=['extensionId'=>$extensionId,'ownerUserId'=>$actorUserId,'createdAt'=>time(),'hadActivePack'=>ocx_game_media_active_directory($pdo,$extensionId)!==null];if(file_put_contents($attempt.DIRECTORY_SEPARATOR.'.attempt.json',json_encode($metadata,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false){ocx_game_media_delete_tree($attempt,$root);throw new RuntimeException('The private validation attempt could not be recorded.');}return['operation'=>'begun','attemptId'=>$attemptId,'progress'=>ocx_game_media_attempt_progress($extensionId,$attempt)];
}

function ocx_game_media_stage_attempt(PDO $pdo,string $extensionId,int $actorUserId,string $attemptId): array
{
    $attempt=ocx_game_media_attempt_directory($extensionId,$attemptId);
    if($attempt===null)throw new RuntimeException('The private pack attempt is unavailable.');
    ocx_game_media_attempt_metadata($extensionId,$attempt,$actorUserId);
    $attemptRoot=security_private_storage_directory(ocx_game_media_category($extensionId,'attempts'));
    try{
        $files=ocx_game_media_uploaded_files();
        if($files===[]||count($files)>OCX_GAME_MEDIA_MAX_BATCH_FILES)throw new RuntimeException('Select a bounded batch of recognized files.');
        $slots=ocx_game_media_pack_slots($extensionId);
        $map=ocx_game_media_pack_name_map($extensionId);
        $progress=ocx_game_media_attempt_progress($extensionId,$attempt);
        if((int)$progress['stagedBytes']+array_sum(array_column($files,'bytes'))>OCX_GAME_MEDIA_MAX_TOTAL_BYTES)throw new RuntimeException('The selected pack is outside the allowed total size.');
        $staticOcx=[];
        foreach($files as $file){
            if($file['error']!==UPLOAD_ERR_OK||$file['bytes']<1||!is_file($file['tmpName']))throw new RuntimeException('Every selected pack file must upload completely.');
            $safe=ocx_game_media_safe_upload_name($file['fullPath']!==''?$file['fullPath']:$file['name']);
            if(str_ends_with($safe,'.ocx')){
                $staticOcx[]=ocx_static_media_stage($file['tmpName'],$attempt,$map,$slots,static fn(string $slot,string $directory):array=>ocx_game_media_validate_slot($extensionId,$slot,$directory));
                continue;
            }
            $slot=$map[$safe]??null;
            if(!is_string($slot)||!isset($slots[$slot]))throw new RuntimeException('The selected pack contains an unknown file. Choose only a recognized OCX, original media, or prepared media.');
            $target=$attempt.DIRECTORY_SEPARATOR.$slots[$slot]['installName'];
            if(is_file($target))throw new RuntimeException('The selected pack contains duplicate files for one media slot.');
            $definition=(array)$slots[$slot];
            if(trim((string)($definition['derivationOwner']??''))!==''){
                $sourceBytes=file_get_contents($file['tmpName']);
                if(!is_string($sourceBytes))throw new RuntimeException('A selected source file could not be read safely.');
                $extension=strtolower((string)pathinfo($safe,PATHINFO_EXTENSION));
                $derived=ocx_static_media_derive_slot_bytes($sourceBytes,$extension,$definition);
                $temporary=$attempt.DIRECTORY_SEPARATOR.'.derived-'.bin2hex(random_bytes(8));
                try{
                    if(file_put_contents($temporary,$derived,LOCK_EX)===false||!rename($temporary,$target))throw new RuntimeException('A selected source file could not be derived privately.');
                }finally{
                    if(is_file($temporary))@unlink($temporary);
                }
            }else{
                $moved=PHP_SAPI==='cli'?copy($file['tmpName'],$target):move_uploaded_file($file['tmpName'],$target);
                if(!$moved||!is_file($target))throw new RuntimeException('A selected pack file could not be staged privately.');
            }
            $validation=ocx_game_media_validate_slot($extensionId,$slot,$attempt);
            if(($validation['state']??'')!=='installed')throw new RuntimeException((string)($validation['reason']??'A selected file failed validation.'));
        }
        $next=ocx_game_media_attempt_progress($extensionId,$attempt);
        if($staticOcx!==[])$next['staticOcx']=$staticOcx;
        return['operation'=>'staged','attemptId'=>$attemptId,'progress'=>$next];
    }catch(Throwable $error){
        if(is_dir($attempt))ocx_game_media_delete_tree($attempt,$attemptRoot);
        throw$error;
    }
}

function ocx_game_media_activate_attempt(PDO $pdo,string $extensionId,int $actorUserId,string $attemptId): array
{
    $attempt=ocx_game_media_attempt_directory($extensionId,$attemptId);
    if($attempt===null)throw new RuntimeException('The private pack attempt is unavailable.');
    $metadata=ocx_game_media_attempt_metadata($extensionId,$attempt,$actorUserId);
    $attemptRoot=security_private_storage_directory(ocx_game_media_category($extensionId,'attempts'));
    try{
        $progress=ocx_game_media_attempt_progress($extensionId,$attempt);
        if((int)$progress['stagedCount']!==count(ocx_game_media_pack_slots($extensionId)))throw new RuntimeException('The selected pack is incomplete. Add every required media slot before activation.');
        foreach(array_keys(ocx_game_media_pack_slots($extensionId)) as $slot){
            $validated=ocx_game_media_validate_slot($extensionId,$slot,$attempt);
            if(($validated['state']??'')!=='installed')throw new RuntimeException('A selected pack file no longer passes validation.');
            if(is_array($validated['preparation']??null))ocx_game_media_prepared_file($validated,$attempt);
        }
        @unlink($attempt.DIRECTORY_SEPARATOR.'.attempt.json');
        $generation='generation-'.uuid_v4();
        $generationRoot=security_private_storage_directory(ocx_game_media_category($extensionId,'generations'));
        $target=$generationRoot.DIRECTORY_SEPARATOR.$generation;
        if(!rename($attempt,$target))throw new RuntimeException('The validated pack could not be prepared for activation.');
        $previous=app_setting($pdo,ocx_game_media_generation_setting($extensionId),'');
        $transaction=!$pdo->inTransaction();
        try{
            if($transaction)$pdo->beginTransaction();
            set_app_setting($pdo,ocx_game_media_generation_setting($extensionId),$generation);
            set_app_setting($pdo,SETTINGS_REGISTRY_REVISION_SETTING,(string)(settings_registry_revision($pdo)+1));
            log_tool($pdo,$actorUserId,'ocx_game_classic_pack_'.(!empty($metadata['hadActivePack'])?'replace':'install'),null,null,$extensionId.': validated '.$progress['requiredCount'].'/'.$progress['requiredCount'].' installation-private media slots.');
            if($transaction)$pdo->commit();
        }catch(Throwable $error){
            if($transaction&&$pdo->inTransaction())$pdo->rollBack();
            if(is_dir($target))ocx_game_media_delete_tree($target,$generationRoot);
            throw$error;
        }
        $status=ocx_game_media_pack_status($pdo,$extensionId);
        if(empty($status['classicComplete']))throw new RuntimeException('The activated pack could not be reverified.');
        ocx_game_media_prune_inactive_generations($extensionId,$generation);
        return['status'=>$status,'operation'=>!empty($metadata['hadActivePack'])?'replaced':'installed'];
    }catch(Throwable $error){
        if(is_dir($attempt))ocx_game_media_delete_tree($attempt,$attemptRoot);
        throw$error;
    }
}

function ocx_game_media_abort_attempt(string $extensionId,int $actorUserId,string $attemptId): array
{
    $attempt=ocx_game_media_attempt_directory($extensionId,$attemptId);if($attempt!==null){ocx_game_media_attempt_metadata($extensionId,$attempt,$actorUserId);ocx_game_media_delete_tree($attempt,security_private_storage_directory(ocx_game_media_category($extensionId,'attempts')));}return['operation'=>'aborted'];
}

function ocx_game_media_remove(PDO $pdo,string $extensionId,int $actorUserId): array
{
    set_app_setting($pdo,ocx_game_media_generation_setting($extensionId),'');
    set_app_setting($pdo,SETTINGS_REGISTRY_REVISION_SETTING,(string)(settings_registry_revision($pdo)+1));
    log_tool($pdo,$actorUserId,'ocx_game_classic_pack_remove',null,null,$extensionId.': removed active installation-private pack; Built-in fallback remains available.');
    ocx_game_media_prune_inactive_generations($extensionId,null);
    return['status'=>ocx_game_media_pack_status($pdo,$extensionId),'operation'=>'removed'];
}

function ocx_game_media_authorized_file(PDO $pdo,string $extensionId,string $publicId,int $userId,string $slot): array
{
    $identity=ocx_game_extension_identity($extensionId);
    $session=multiplayer_game_require_member($pdo,$publicId,$userId);
    $sessionGameKey=(string)$session['game_key'];
    if($sessionGameKey!==(string)$identity['key'])throw new MultiplayerGameException('This private game media is unavailable.','OCX_GAME_MEDIA_ACCESS_DENIED',403);
    $definition=multiplayer_game_definition($pdo,$sessionGameKey);
    $presentation=multiplayer_game_presentation_projection($pdo,$definition,$userId);
    if(($presentation['effectivePack']??'')!=='classic')throw new MultiplayerGameException('Classic media is not active for this game.','OCX_GAME_MEDIA_UNAVAILABLE',404);
    $directory=ocx_game_media_active_directory($pdo,$extensionId);
    $result=ocx_game_media_validate_slot($extensionId,$slot,$directory);
    if(($result['state']??'')!=='installed'||$directory===null)throw new MultiplayerGameException('This private game media slot is unavailable.','OCX_GAME_MEDIA_UNAVAILABLE',404);
    return is_array($result['preparation']??null)?ocx_game_media_prepared_file($result,$directory):$result;
}
