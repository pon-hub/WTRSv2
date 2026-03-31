<?php
require_once __DIR__ . '/../includes/session.php';
$user = current_user();

$adviserNotifs = [];
$notifications  = [];
$myLogs         = [];

// 1. ADVISER: Fetch pending thesis reviews assigned to this user
if ($user['role'] === 'adviser' || $user['role'] === 'admin') {
    $stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name 
                           FROM theses t 
                           JOIN users u ON t.author_id = u.id 
                           WHERE t.adviser_id = :adviser_id AND t.status = 'pending_review' 
                           ORDER BY t.created_at DESC");
    $stmt->execute(['adviser_id' => $user['id']]);
    $adviserNotifs = $stmt->fetchAll();
}

// 2. STUDENT: Fetch recent feedback on their own theses
$notifStmt = $pdo->prepare("SELECT tv.*, t.title, t.thesis_code, u.first_name as adviser_first, u.last_name as adviser_last, tv.status as version_status
                            FROM thesis_versions tv
                            JOIN theses t ON tv.thesis_id = t.id
                            JOIN users u ON t.adviser_id = u.id
                            WHERE t.author_id = :user_id AND tv.feedback IS NOT NULL AND tv.feedback != ''
                            ORDER BY tv.submitted_at DESC LIMIT 10");
$notifStmt->execute(['user_id' => $user['id']]);
$notifications = $notifStmt->fetchAll();

// 3. ALL: Fetch recent activity logs
$logStmt = $pdo->prepare("SELECT * FROM activity_logs 
                          WHERE user_id = :user_id 
                          ORDER BY created_at DESC LIMIT 15");
$logStmt->execute(['user_id' => $user['id']]);
$myLogs = $logStmt->fetchAll();

ob_start();
?>
<style>
  .notif-card { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-sm); margin-bottom: 2rem; overflow: hidden; }
  .notif-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--off-white); display: flex; align-items: center; gap: 0.75rem; font-family: 'Playfair Display', serif; font-size: 1.1rem; font-weight: 800; color: var(--text-dark); }
  .notif-header i { color: var(--crimson); }
  
  .notif-item { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--off-white); transition: background var(--transition); }
  .notif-item:last-child { border-bottom: none; }
  .notif-item:hover { background: var(--off-white); }
  
  .notif-row { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
  .notif-code { font-weight: 800; font-size: 0.85rem; color: var(--crimson); letter-spacing: 0.05em; }
  .notif-date { font-size: 0.75rem; color: var(--text-muted); }
  .notif-text { font-family: 'Georgia', serif; font-style: italic; font-size: 0.9rem; color: var(--text-dark); margin: 0.5rem 0; line-height: 1.6; }
  .notif-author { font-size: 0.75rem; font-weight: 700; color: var(--text-muted); }
  
  .notif-status { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; padding: 2px 8px; border-radius: 4px; border: 1px solid transparent; }
  .status-approved { background: #D1FAE5; color: #064E3B; border-color: #A7F3D0; }
  .status-revision { background: #FEF3C7; color: #92400E; border-color: #FDE68A; }
  
  .activity-row { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-bottom: 1px solid var(--off-white); font-size: 0.85rem; }
  .activity-tag { font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 3px; background: var(--border); color: var(--text-muted); margin-right: 0.75rem; }
</style>
<?php
$extraCss = ob_get_clean();

require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

  <main class="main-content">
    
    <!-- Page Header -->
    <div class="page-header">
      <div class="page-title">
        <h1>Recent <span>Notifications</span></h1>
        <p>Stay updated on archival submissions, peer reviews, and your personal account activity logs.</p>
      </div>
    </div>

    <div style="max-width: 900px;">

      <!-- Adviser: Pending Reviews -->
      <?php if ($user['role'] === 'adviser' && count($adviserNotifs) > 0): ?>
      <div class="alert alert-gold" style="padding: 1.5rem; margin-bottom: 2rem; border-radius: var(--radius-sm); border-left: 5px solid var(--gold);">
        <h3 style="font-family: 'Playfair Display', serif; font-size: 1.1rem; margin: 0 0 1rem; color: #92400E;"><i class="ph-fill ph-warning-circle"></i> Pending Reviews (<?= count($adviserNotifs) ?>)</h3>
        <?php foreach ($adviserNotifs as $an): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid rgba(0,0,0,0.05); font-size: 0.85rem;">
          <div>
            <strong><?= htmlspecialchars($an['first_name'] . ' ' . $an['last_name']) ?></strong> submitted 
            <em>"<?= htmlspecialchars(mb_substr($an['title'], 0, 60)) ?>..."</em>
          </div>
          <a href="<?= BASE_URL ?>faculty/review.php?id=<?= (int)$an['id'] ?>" style="color: var(--crimson); font-weight: 700; text-decoration: none;">Review →</a>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Feedback Notifications -->
      <?php if (count($notifications) > 0): ?>
      <div class="notif-card">
        <div class="notif-header">
          <i class="ph-fill ph-chat-centered-text"></i> Adviser Feedback
        </div>
        <?php foreach ($notifications as $n): ?>
        <div class="notif-item">
          <div class="notif-row">
            <span class="notif-code"><?= htmlspecialchars($n['thesis_code']) ?></span>
            <span class="notif-date"><?= date('M j, Y', strtotime($n['submitted_at'])) ?></span>
          </div>
          <p class="notif-text">"<?= htmlspecialchars($n['feedback']) ?>"</p>
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <span class="notif-author">— <?= htmlspecialchars($n['adviser_first'] . ' ' . $n['adviser_last']) ?></span>
            <span class="notif-status <?= $n['version_status'] === 'approved' ? 'status-approved' : 'status-revision' ?>">
              <?= strtoupper($n['version_status']) ?>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Activity Log -->
      <div class="notif-card">
        <div class="notif-header">
          <i class="ph-fill ph-clock-counter-clockwise"></i> Activity History
        </div>
        <?php if (count($myLogs) > 0): ?>
          <?php foreach ($myLogs as $log): ?>
          <div class="activity-row">
            <div>
              <span class="activity-tag"><?= htmlspecialchars($log['action_type']) ?></span>
              <span style="color: var(--text-dark); font-weight: 500;"><?= htmlspecialchars($log['description']) ?></span>
            </div>
            <span class="notif-date"><?= date('M j, h:i A', strtotime($log['created_at'])) ?></span>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="padding: 4rem 2rem; text-align: center; color: var(--text-muted);">
            <i class="ph ph-bell-simple-slash" style="font-size: 3rem; display: block; margin-bottom: 1rem; opacity: 0.2;"></i>
            No activity recorded yet.
          </div>
        <?php endif; ?>
      </div>

    </div>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
