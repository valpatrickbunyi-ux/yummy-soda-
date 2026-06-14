<?php
require __DIR__ . '/../includes/auth.php';
logout_user();
header('Location: /yummy-soda/public/index.php');
exit;