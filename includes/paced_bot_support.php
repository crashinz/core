<?php
declare(strict_types=1);
// Identity uses public session/version only, never a digest of hidden cards.
function paced_bot_position_key(array $context): string {
    return hash('sha256', (string)($context['sessionPublicId'] ?? '') . ':' . (int)($context['stateVersion'] ?? -1));
}
function paced_bot_task(array $state, int $viewer, array $context, string $engine): ?array {
    $actor = (int)($state['turnOrder'][(int)($state['turnIndex'] ?? -1)] ?? 0);
    if (($context['mode'] ?? '') !== 'practice' || ($context['status'] ?? '') !== 'active'
        || !in_array($context['viewerRole'] ?? '', ['master','player'], true)
        || $viewer <= 0 || !in_array($viewer, $state['turnOrder'] ?? [], true)
        || empty($state['bots'][(string)$actor]) || !empty($state['completed'])
        || !isset($context['sessionPublicId'], $context['stateVersion'])) return null;
    return ['action'=>'bot-step','engine'=>$engine,'positionKey'=>paced_bot_position_key($context),
        'displayName'=>$state['bots'][(string)$actor]['displayName'] ?? 'The bot','delayMs'=>2000];
}
function paced_bot_validate(array $state, int $viewer, array $payload, array $context, string $engine): int {
    $task = paced_bot_task($state, $viewer, $context + ['status'=>'active','viewerRole'=>'player'], $engine);
    if (!$task) throw new MultiplayerGameException('A Practice bot turn is not available.', 'GAME_BOT_UNAVAILABLE', 409);
    if (($payload['engine'] ?? '') !== $engine || !hash_equals($task['positionKey'], (string)($payload['positionKey'] ?? '')))
        throw new MultiplayerGameException('The bot position changed. Refresh before retrying.', 'GAME_BOT_POSITION_STALE', 409);
    return (int)$state['turnOrder'][(int)$state['turnIndex']];
}
