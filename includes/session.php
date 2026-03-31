<?php
// Central session and auth helper
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function is_logged_in() {
    return !empty($_SESSION['user_id']);
}

function current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login($allowedRoles = []) {
    if (!is_logged_in()) {
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }


    if (!empty($allowedRoles) && !in_array($_SESSION['user']['role'], $allowedRoles, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo '<h1>403 Forbidden</h1><p>You do not have access to this page.</p>';
        exit;
    }
}
