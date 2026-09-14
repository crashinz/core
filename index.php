<?php
require_once __DIR__ . '/includes/base.php';
$pdo = db();
if (!moderation_identity_policy_acceptance_storage_ready($pdo)) {
    redirect_to('/database-update.php');
}
redirect_to(current_user() ? '/lobby.php' : '/login.php');
