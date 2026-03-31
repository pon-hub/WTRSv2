<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['adviser', 'admin']);

$user = current_user();

// ── Dashboard Stats ──────────────────────────────────────────────────────────

// Total theses assigned to this adviser (Admins see all assigned, Advisers see theirs)
if ($user['role'] === 'admin') {
    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM theses WHERE adviser_id IS NOT NULL");
} else {
    $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM theses WHERE adviser_id = :id");
    $stmtTotal->execute(['id' => $user['id']]);
}
$totalAssigned = (int)$stmtTotal->fetchColumn();

// Pending reviews
if ($user['role'] === 'admin') {
    $stmtPending = $pdo->query("SELECT COUNT(*) FROM theses WHERE status = 'pending_review'");
} else {
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM theses WHERE adviser_id = :id AND status = 'pending_review'");
    $stmtPending->execute(['id' => $user['id']]);
}
$pendingCount = (int)$stmtPending->fetchColumn();

// Active students
if ($user['role'] === 'admin') {
    $stmtStudents = $pdo->query("SELECT COUNT(DISTINCT author_id) FROM theses");
} else {
    $stmtStudents = $pdo->prepare("SELECT COUNT(DISTINCT author_id) FROM theses WHERE adviser_id = :id");
    $stmtStudents->execute(['id' => $user['id']]);
}
$activeStudents = (int)$stmtStudents->fetchColumn();

// Recent queue (latest 5)
$sqlQueue = "
    SELECT t.*, u.first_name, u.last_name, u.college,
           tv.version_number AS latest_version,
           tv.submitted_at   AS latest_submitted_at
    FROM theses t
    JOIN users u ON t.author_id = u.id
    LEFT JOIN thesis_versions tv ON tv.id = (
        SELECT id FROM thesis_versions
        WHERE thesis_id = t.id
        ORDER BY submitted_at DESC LIMIT 1
    )
";
if ($user['role'] !== 'admin') {
    $sqlQueue .= " WHERE t.adviser_id = :id ";
}
$sqlQueue .= " ORDER BY tv.submitted_at DESC LIMIT 5 ";

$stmtQueue = $pdo->prepare($sqlQueue);
if ($user['role'] !== 'admin') {
    $stmtQueue->execute(['id' => $user['id']]);
} else {
    $stmtQueue->execute();
}
$recentTheses = $stmtQueue->fetchAll();

ob_start();
?>
<style>
  /* Premium Table Actions matching Screenshot */
  .btn-view-details {
    background: #8B0000; /* Crimson Primary */
    color: white;
    padding: 0.6rem 1.25rem;
    border-radius: var(--radius-sm);
    font-size: 0.82rem;
    font-weight: 700;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    box-shadow: 0 4px 12px rgba(139, 0, 0, 0.25);
    transition: all 0.2s ease;
    border: none;
    width: fit-content;
  }
  
  .btn-view-details:hover {
    background: #700000;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(139, 0, 0, 0.35);
    color: white;
  }

  .btn-view-details i {
    font-weight: 800;
  }

  /* Status Badge refinement */
  .badge-pending {
    background: #FEF3C7;
    color: #92400E;
    border: 1px solid #FDE68A;
  }
</style>
<?php
$extraCss = ob_get_clean();

$current_page = 'index.php';
require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

<main class="main-content">

  <!-- Page Header -->
  <div class="page-header">
    <div class="page-title">
      <h1>Welcome, <span><?= htmlspecialchars($user['first_name']) ?></span></h1>
      <p>Oversee pending academic contributions, manage student roles, and maintain repository integrity.</p>
    </div>
    <a href="<?= BASE_URL ?>faculty/review.php?status=pending_review" class="btn btn-primary" style="text-decoration:none;">
      <i class="ph-bold ph-clipboard-text"></i> Review Queue
    </a>
  </div>

  <!-- Stat Cards -->
  <div class="stat-cards">
    <a href="<?= BASE_URL ?>faculty/review.php" class="stat-card accent-red" style="text-decoration:none;">
      <div class="stat-icon"><i class="ph-fill ph-books"></i></div>
      <div class="stat-title">Total Theses Assigned</div>
      <div class="stat-value"><?= $totalAssigned ?></div>
      <div class="stat-meta"><i class="ph ph-check-circle"></i> Total repository entries</div>
    </a>

    <a href="<?= BASE_URL ?>faculty/review.php?status=pending_review" class="stat-card accent-gold" style="text-decoration:none;">
      <div class="stat-icon"><i class="ph-fill ph-clock-counter-clockwise"></i></div>
      <div class="stat-title">Pending Reviews</div>
      <div class="stat-value"><?= $pendingCount ?></div>
      <div class="stat-meta"><i class="ph ph-warning"></i> Awaiting faculty decision</div>
    </a>

    <a href="<?= BASE_URL ?>faculty/students.php" class="stat-card accent-teal" style="text-decoration:none;">
      <div class="stat-icon"><i class="ph-fill ph-users"></i></div>
      <div class="stat-title">Active Students</div>
      <div class="stat-value"><?= $activeStudents ?></div>
      <div class="stat-meta"><i class="ph ph-identification-card"></i> Assigned research authors</div>
    </a>
  </div>

  <!-- Recent Queue Section -->
  <div class="section-header">
    <div>
      <div class="section-title">Submission Queue</div>
      <div class="section-sub">Latest research manuscripts dispatched for review</div>
    </div>
    <a href="<?= BASE_URL ?>faculty/review.php" class="btn btn-secondary" style="font-size:0.75rem; padding:0.5rem 1rem; text-decoration:none;">
      View Entire Queue <i class="ph-bold ph-arrow-right"></i>
    </a>
  </div>

  <div class="queue-table-wrap">
    <?php if (empty($recentTheses)): ?>
      <div class="empty-state">
        <i class="ph-fill ph-tray"></i>
        <h3>No Pending Submissions</h3>
        <p>When students submit manuscripts, they will appear here for your formal evaluation.</p>
      </div>
    <?php else: ?>
      <table class="queue-table">
        <thead>
          <tr>
            <th style="padding-left: 2rem;">Thesis Artifact</th>
            <th>Author</th>
            <th>Received</th>
            <th>Status / Version</th>
            <th style="text-align: right; padding-right: 2rem;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentTheses as $t): ?>
            <tr>
              <td style="padding-left: 2rem;">
                <span class="thesis-code-tag"><?= htmlspecialchars($t['thesis_code']) ?></span>
                <span class="thesis-title-sm"><?= htmlspecialchars($t['title']) ?></span>
              </td>
              <td>
                <div class="student-cell">
                  <div class="student-av"><?= htmlspecialchars(strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1))) ?></div>
                  <div>
                    <div class="student-nm"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></div>
                    <div class="student-cl"><?= htmlspecialchars($t['college']) ?></div>
                  </div>
                </div>
              </td>
              <td><?= $t['latest_submitted_at'] ? date('M j, Y', strtotime($t['latest_submitted_at'])) : '—' ?></td>
              <td>
                <div style="display:flex; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                  <span class="version-pill">v<?= htmlspecialchars($t['latest_version'] ?? '1.0') ?></span>
                  <?php if ($t['status'] === 'pending_review'): ?>
                    <span class="badge badge-pending"><span class="dot dot-pending"></span> PENDING REVIEW</span>
                  <?php elseif ($t['status'] === 'approved'): ?>
                    <span class="badge badge-approved"><span class="dot dot-approved"></span> APPROVED</span>
                  <?php elseif ($t['status'] === 'revision_requested'): ?>
                    <span class="badge badge-revision"><span class="dot dot-revision"></span> REVISION</span>
                  <?php else: ?>
                    <span class="badge badge-default"><?= strtoupper($t['status']) ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="text-align: right; padding-right: 2rem;">
                <a href="<?= BASE_URL ?>faculty/review.php?id=<?= (int)$t['id'] ?>" class="btn-view-details">
                  View Details <i class="ph-bold ph-arrow-right"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</main>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
