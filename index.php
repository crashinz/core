<?php
require_once __DIR__ . '/includes/base.php';
redirect_to(current_user() ? '/lobby.php' : '/login.php');
