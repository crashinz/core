<?php
// Included only by chatroom.php after login, room lookup and ejection checks.
if (!isset($room, $user, $pdo)) { http_response_code(404); exit; }
$passwordError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_protect_post();
    try {
        if (room_access_unlock($pdo, $room, $user, $_POST['room_password'] ?? '')) {
            redirect_to('/chatroom.php?id=' . rawurlencode($room['public_id']));
        }
        $passwordError = 'Incorrect room password. Please try again.';
    } catch (RoomPasswordException $error) {
        $passwordError = $error->getMessage();
    }
}
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Private room</title>
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/styles.css')) ?>">
  <link rel="stylesheet" href="<?= e(app_url('/assets/css/room-access.css')) ?>">
</head>
<body class="room-password-page">
  <main class="panel room-password-panel">
    <h1>Private room</h1>
    <p>Enter the password to join <strong><?= e($room['name']) ?></strong>.</p>
    <form method="post" action="<?= e(app_url('/chatroom.php?id='.rawurlencode($room['public_id']))) ?>">
      <?= csrf_input() ?>
      <label>Room password<input type="password" name="room_password" required maxlength="72" autocomplete="off" autofocus></label>
      <?php if ($passwordError !== ''): ?><p class="room-password-error" role="alert"><?= e($passwordError) ?></p><?php endif; ?>
      <div class="room-password-actions"><button class="btn btn-primary" type="submit">Enter room</button><a class="btn" href="<?= e(app_url('/lobby.php')) ?>">Cancel</a></div>
    </form>
  </main>
</body>
</html>
