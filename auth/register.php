<?php
require_once __DIR__ . '/../includes/session.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'student';
    $college = $_POST['college'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!$first_name || !$last_name || !$email || !$password || !$college) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strpos($email, '@wmsu.edu.ph') === false) {
        $error = 'Please use an official wmsu.edu.ph email address.';
    } elseif (strlen($password) < 8 || !preg_match('/[!@#\$%\^&\*]/', $password)) {
        $error = 'Password must be at least 8 chars and include a special character.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $error = 'Email is already registered. Try signing in.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role, college, status) VALUES (:first_name, :last_name, :email, :password, :role, :college, :status)');
            $stmt->execute([
                'first_name' => $first_name,
                'last_name' => $last_name,
                'email' => $email,
                'password' => $hash,
                'role' => $role,
                'college' => $college,
                'status' => 'pending',
            ]);
            $success = 'Registration successful. Wait for adviser activation before signing in.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Account - WMSU Thesis Repository</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@1,800&display=swap" rel="stylesheet">
  <script src="https://unpkg.com/@phosphor-icons/web"></script>
  <link rel="stylesheet" href="../assets/css/global.css">
  <link rel="stylesheet" href="../assets/css/login.css">
  <link rel="stylesheet" href="../assets/css/register.css">
</head>
<body class="auth-page">

  <!-- Left Side: Branding & Info -->
  <div class="auth-left auth-left-register" style="padding: 3rem 4rem;">
    <div class="auth-brand" style="margin-bottom: 3rem;">
      <div class="brand-seal-box" style="width: 72px; height: 72px; overflow: hidden; border-radius: 12px;">
        <img src="../assets/images/wmsu-logo.png" alt="WMSU Logo" style="width: 100%; height: 100%; object-fit: contain;" />
      </div>
      <div class="brand-text">
        <h2>WMSU</h2>
        <p>THESIS REPOSITORY</p>
      </div>
    </div>

    <div class="auth-hero-text" style="margin-top: 2rem;">
      <h1 style="font-size: 3.5rem;">WMSU<br><span class="gold-text">Repository</span></h1>
      <p style="font-size: 1rem; margin-bottom: 2rem;">Preserving institutional wisdom through a premium digital archive of undergraduate and graduate research.</p>
    </div>

    <div class="auth-info-card">
      <div class="aic-header">
        <i class="ph-fill ph-shield-check"></i> INSTITUTIONAL ACCESS
      </div>
      <p>Join the community of researchers and scholars. Please note that all accounts require verification from an authorized Faculty Adviser before full access is granted.</p>
    </div>

    <div class="avatar-stack-wrap" style="margin-top: auto;">
      <div class="avatar-stack">
        <img src="https://via.placeholder.com/40">
        <img src="https://via.placeholder.com/40">
        <img src="https://via.placeholder.com/40">
      </div>
      <div class="avatar-stack-text">
        JOINED BY 2,400+<br>RESEARCHERS
      </div>
    </div>
  </div>

  <!-- Right Side: Register Form -->
  <div class="auth-right" style="justify-content: flex-start; padding-left: 6%;">
    <div class="auth-form-wrapper" style="max-width: 650px;">
      <h2 style="font-size: 2rem;">Create your account</h2>
      <p style="margin-bottom: 3rem;">Begin your journey into the WMSU digital archives.</p>

      <?php if ($error): ?>
        <div class="alert alert-error" style="margin-bottom: 1rem; color: #B91C1C;"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success" style="margin-bottom: 1rem; color: #047857;"><?= htmlspecialchars($success) ?></div>
      <?php endif; ?>

      <form action="" method="POST">
        <div class="form-grid-2">
          <div class="input-group" style="margin-bottom: 0;">
            <div class="input-header"><span class="input-label" style="font-size: 0.65rem;">FIRST NAME</span></div>
            <input type="text" name="first_name" class="input-plain" placeholder="Juan" required value="<?= isset($first_name) ? htmlspecialchars($first_name) : '' ?>">
          </div>
          <div class="input-group" style="margin-bottom: 0;">
            <div class="input-header"><span class="input-label" style="font-size: 0.65rem;">LAST NAME</span></div>
            <input type="text" name="last_name" class="input-plain" placeholder="Dela Cruz" required value="<?= isset($last_name) ? htmlspecialchars($last_name) : '' ?>">
          </div>
        </div>

        <div class="form-grid-2">
          <div class="input-group" style="margin-bottom: 0;">
            <div class="input-header"><span class="input-label" style="font-size: 0.65rem;">WMSU EMAIL</span></div>
            <input type="email" name="email" class="input-plain" placeholder="juan.delacruz@wmsu.edu.ph" required value="<?= isset($email) ? htmlspecialchars($email) : '' ?>">
          </div>
          <div class="input-group" style="margin-bottom: 0;">
            <div class="input-header"><span class="input-label" style="font-size: 0.65rem;">ROLE</span></div>
            <select name="role" class="select-plain">
              <option value="student" <?= (!isset($role) || $role === 'student') ? 'selected' : '' ?>>Student</option>
              <option value="adviser" <?= (isset($role) && $role === 'adviser') ? 'selected' : '' ?>>Adviser</option>
            </select>
          </div>
        </div>

        <div class="input-group" style="margin-bottom: 0;">
          <div class="input-header"><span class="input-label" style="font-size: 0.65rem;">COLLEGE / DEPARTMENT</span></div>
          <select name="college" class="select-plain" required>
            <option value="">Select college</option>
            <option value="College of Computing Studies" <?= (isset($college) && $college === 'College of Computing Studies') ? 'selected' : '' ?>>College of Computing Studies</option>
            <option value="College of Engineering" <?= (isset($college) && $college === 'College of Engineering') ? 'selected' : '' ?>>College of Engineering</option>
            <option value="College of Liberal Arts" <?= (isset($college) && $college === 'College of Liberal Arts') ? 'selected' : '' ?>>College of Liberal Arts</option>
          </select>
        </div>

        <div class="input-group" style="margin-top: 1.5rem;">
          <div class="input-header"><span class="input-label" style="font-size: 0.65rem;">SECURITY PASSWORD</span></div>
          <div class="input-icon-right">
            <input type="password" name="password" class="input-plain" placeholder="••••••••••••" required>
            <i class="ph-bold ph-eye"></i>
          </div>
          <span class="password-hint">Minimum 8 characters with at least one special character.</span>
        </div>

        <div class="activation-callout">
          <div class="ac-icon"><i class="ph-bold ph-info"></i></div>
          <div class="ac-content">
            <h5>Activation Required</h5>
            <p>Once submitted, your registration will be sent to your department's repository chair. You will receive an email notification once your Faculty Adviser has activated your account.</p>
          </div>
        </div>

        <div class="register-action-row">
          <button type="submit" class="btn-register">Register Account <i class="ph-bold ph-arrow-right"></i></button>
          <div class="login-redirect">Already have an account? <a href="login.php">Sign In</a></div>
        </div>
      </form>

      <div class="bottom-legal-links">
        <a href="<?= BASE_URL ?>public/guidelines.php">GUIDELINES</a>
        <a href="#">PRIVACY POLICY</a>
        <a href="<?= BASE_URL ?>public/about.php">SUPPORT CENTER</a>
      </div>
    </div>
  </div>
</body>
</html>
