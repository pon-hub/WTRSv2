<?php
// Ensure session is active and user is available
if (!isset($user)) {
    $user = current_user();
}

// Map roles to their respective portal titles
$portalTitle = 'WMSU Repository';
if (isset($user['role'])) {
    if ($user['role'] === 'student') $portalTitle = 'Student Portal — WMSU Repository';
    else if ($user['role'] === 'adviser') $portalTitle = 'Adviser Dashboard — WMSU Repository';
    else if ($user['role'] === 'admin') $portalTitle = 'Admin Portal — WMSU Repository';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($portalTitle) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,800;1,800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>

  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/global.css?v=<?= filemtime(__DIR__ . '/../assets/css/global.css') ?>">
  <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">

  
  <?php if (isset($extraCss)) echo $extraCss; ?>
</head>
<body>

<!-- ═══════════════════════════════════════════
     TOP BAR
═══════════════════════════════════════════ -->
<header class="topbar">
  <div class="topbar-left">
<?php
    $role = $user['role'] ?? 'student';
    $portalFolder = $role === 'adviser' ? 'faculty' : $role;
    $homePage = $role === 'admin' ? 'users.php' : 'index.php';
    $portalHome = BASE_URL . $portalFolder . '/' . $homePage;
?>
    <a href="<?= $portalHome ?>" class="logo">
      <img src="<?= BASE_URL ?>assets/images/wmsu-logo.png" alt="WMSU"
        onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
      <i class="ph-fill ph-books" style="display:none;"></i>
      WMSU Repository
    </a>

    <nav class="topbar-nav">
      <a href="<?= $portalHome ?>" class="<?= (isset($current_page) && $current_page == $homePage) ? 'active' : '' ?>">
        <?= ($role === 'student') ? 'Student Portal' : 'Dashboard' ?>
      </a>
      <a href="<?= BASE_URL ?>public/archive.php">Archives</a>

      <a href="<?= BASE_URL ?>public/guidelines.php">Guidelines</a>
    </nav>
  </div>
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>auth/logout.php" class="btn btn-secondary" style="text-decoration:none;">
      <i class="ph ph-sign-out"></i> Logout
    </a>

    <a href="<?= BASE_URL ?>user/notifications.php" class="icon-btn" title="Notifications">
      <i class="ph-fill ph-bell"></i>
    </a>

    <a href="<?= BASE_URL ?>user/profile.php" class="user-avatar" title="My Profile Settings" style="text-decoration:none;">
      <?= htmlspecialchars(strtoupper(substr($user['first_name'] ?? 'U', 0, 1) . substr($user['last_name'] ?? 'U', 0, 1))) ?>
    </a>

  </div>
</header>
