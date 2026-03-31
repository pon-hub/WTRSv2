<?php
// Ensure session is started if not already
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['user']);
$currentFile = basename($_SERVER['PHP_SELF']);
?>
<header class="pub-navbar">
  <div class="container pub-nav-container">
    
    <!-- Logo -->
    <div class="nav-left">
      <a href="<?= BASE_URL ?>public/archive.php" class="pub-logo">
        <i class="ph-fill ph-books"></i>
        <span>WMSU <span style="font-weight:400; opacity:0.7;">Repository</span></span>
      </a>
    </div>

    <!-- Main Navigation -->
    <nav class="nav-center">
      <a href="<?= BASE_URL ?>public/archive.php" class="nav-link <?= ($currentFile == 'archive.php') ? 'active' : '' ?>">Browse Archives</a>
      <a href="<?= BASE_URL ?>public/guidelines.php" class="nav-link <?= ($currentFile == 'guidelines.php') ? 'active' : '' ?>">Guidelines</a>
      <a href="<?= BASE_URL ?>public/about.php" class="nav-link <?= ($currentFile == 'about.php') ? 'active' : '' ?>">About</a>
    </nav>

    <!-- Auth Actions -->
    <div class="nav-right">
      <?php if ($isLoggedIn): 
          $role = $_SESSION['user']['role'];
          $portalFolder = ($role === 'adviser') ? 'faculty' : $role;
          $homePage = ($role === 'admin') ? 'users.php' : 'index.php';
          $portalHome = BASE_URL . $portalFolder . '/' . $homePage;
      ?>
        <a href="<?= $portalHome ?>" class="nav-btn-solid">
          <i class="ph-bold ph-squares-four"></i> Go to Dashboard
        </a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>auth/login.php" class="nav-btn-text">Sign In</a>
        <a href="<?= BASE_URL ?>auth/register.php" class="nav-btn-solid">Register</a>
      <?php endif; ?>
    </div>

  </div>
</header>

