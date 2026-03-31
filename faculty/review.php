<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['adviser', 'admin']);

$user = current_user();
$thesis_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// ─── DETAIL VIEW ───────────────────────────────────────────────────────────────
if ($thesis_id) {
    // If Admin, they can see everything. If Adviser, only their assigned ones.
    if ($user['role'] === 'admin') {
        $stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.college
                               FROM theses t
                               JOIN users u ON t.author_id = u.id
                               WHERE t.id = :id");
        $stmt->execute(['id' => $thesis_id]);
    } else {
        $stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.college
                               FROM theses t
                               JOIN users u ON t.author_id = u.id
                               WHERE t.id = :id AND t.adviser_id = :adviser_id");
        $stmt->execute(['id' => $thesis_id, 'adviser_id' => $user['id']]);
    }
    $thesis = $stmt->fetch();

    if (!$thesis) {
        header('Location: ' . BASE_URL . 'faculty/review.php');
        exit;
    }

    $vStmt = $pdo->prepare("SELECT * FROM thesis_versions WHERE thesis_id = :id ORDER BY submitted_at DESC");
    $vStmt->execute(['id' => $thesis_id]);
    $versions = $vStmt->fetchAll();
    $latestVersion = $versions[0] ?? null;

    $error = null;
    $success = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $latestVersion && $latestVersion['status'] === 'pending') {
        $action   = $_POST['action'] ?? '';
        $feedback = trim($_POST['feedback'] ?? '');

        if (!in_array($action, ['approved', 'revision_requested', 'rejected'])) {
            $error = "Please select a valid formal decision.";
        } elseif (empty($feedback) && in_array($action, ['revision_requested', 'rejected'])) {
            $error = "Constructive feedback is mandatory for requesting revisions or rejections.";
        } else {
            $pdo->beginTransaction();
            try {
                // Map internal status for versioning
                $versionStatus = ($action === 'approved') ? 'approved' : 'rejected';

                // 1. Update Version Record
                $updV = $pdo->prepare("UPDATE thesis_versions SET status = ?, feedback = ? WHERE id = ?");
                $updV->execute([$versionStatus, $feedback, $latestVersion['id']]);

                // 2. Update Thesis Master Status
                $updT = $pdo->prepare("UPDATE theses SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $updT->execute([$action, $thesis_id]);

                // 3. Log Activity
                $logMsg = "Faculty review processed: '$action' for artifact {$thesis['thesis_code']}";
                $pdo->prepare("INSERT INTO activity_logs (user_id, action_type, description, ip_address) VALUES (?, 'review', ?, ?)")
                    ->execute([$user['id'], $logMsg, $_SERVER['REMOTE_ADDR']]);

                $pdo->commit();
                $success = "Review finalized successfully. Notification dispatched to student.";
                
                // Update local vars for immediate UI reflection
                $thesis['status'] = $action;
                $latestVersion['status'] = $versionStatus;
                $latestVersion['feedback'] = $feedback;

            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Transaction failed: " . $e->getMessage();
            }
        }
    }

    ob_start();
?>
<style>
  .review-layout { display: grid; grid-template-columns: 2.2fr 1fr; gap: 2.5rem; }
  
  .review-master-card { background: white; border-radius: var(--radius); border: 1px solid var(--border); padding: 3rem; margin-bottom: 2rem; box-shadow: var(--shadow-sm); position: relative; }
  .status-tag-floating { position: absolute; top: 2rem; right: 2rem; }

  .review-header { border-bottom: 2px solid var(--off-white); padding-bottom: 2rem; margin-bottom: 2.5rem; }
  .review-code { color: var(--gold); font-size: 0.75rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 1rem; display: block; }
  .review-title { font-family: var(--font-serif); font-size: 2.2rem; color: var(--text-dark); line-height: 1.25; }

  .author-strip { background: var(--off-white); padding: 1.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border); display: flex; align-items: center; gap: 1.25rem; margin-bottom: 3rem; }
  .av-box { width: 50px; height: 50px; background: var(--crimson); color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.25rem; }

  .abstract-display { font-family: 'Georgia', serif; font-size: 1.15rem; line-height: 1.8; color: var(--text-dark); border-left: 3px solid var(--gold); padding-left: 2rem; margin: 2rem 0 4rem; text-align: justify; }

  /* Evaluation Sidebar */
  .evaluation-sidebar { position: sticky; top: 2rem; }
  .eval-card { background: white; border-radius: var(--radius); border: 1px solid var(--border); padding: 2.5rem; box-shadow: var(--shadow-md); }
  .eval-header { display: flex; align-items: center; gap: 0.75rem; border-bottom: 1px solid var(--border); padding-bottom: 1rem; margin-bottom: 2rem; color: var(--crimson); }
  .eval-header h3 { font-family: var(--font-serif); font-size: 1.25rem; font-weight: 800; color: var(--text-dark); margin: 0; }

  .version-history-item { border-left: 2px dashed var(--border); padding-left: 2.5rem; position: relative; padding-bottom: 2.5rem; }
  .version-history-item::before { content: ''; position: absolute; left: -7px; top: 0; width: 12px; height: 12px; border-radius: 50%; background: white; border: 2px solid var(--border-strong); }
  .version-history-item.active::before { border-color: var(--crimson); background: var(--crimson); }
</style>
<?php
    $extraCss = ob_get_clean();
    require_once __DIR__ . '/../includes/layout_top.php';
    require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

  <main class="main-content">
    
    <nav style="margin-bottom: 2.5rem; font-size: 0.75rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em;">
       <a href="<?= BASE_URL ?>faculty/index.php" style="color:var(--text-muted); text-decoration:none;">DASHBOARD</a>
       <i class="ph ph-caret-right" style="margin: 0 0.5rem; opacity:0.5;"></i>
       <a href="<?= BASE_URL ?>faculty/review.php" style="color:var(--text-muted); text-decoration:none;">REVIEW QUEUE</a>
       <i class="ph ph-caret-right" style="margin: 0 0.5rem; opacity:0.5;"></i>
       <span style="color:var(--crimson);">THESIS EVALUATION</span>
    </nav>

    <?php if ($error): ?>
      <div style="margin-bottom:2.5rem; padding:1.25rem 2rem; background:#991B1B; color:white; border-radius:var(--radius-sm); font-weight:700; box-shadow:var(--shadow-md);">
        <i class="ph-bold ph-warning-circle" style="margin-right:0.75rem;"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div style="margin-bottom:2.5rem; padding:1.25rem 2rem; background:#065F46; color:white; border-radius:var(--radius-sm); font-weight:700; box-shadow:var(--shadow-md);">
        <i class="ph-bold ph-check-circle" style="margin-right:0.75rem;"></i> <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <div class="review-layout">
      <!-- MAIN AREA -->
      <div>
        <section class="review-master-card">
          <div class="status-tag-floating">
            <?php if ($thesis['status'] === 'pending_review'): ?>
              <span class="badge badge-pending" style="padding: 0.5rem 1rem; border-radius: 20px; font-weight: 800; font-size: 0.75rem;"><span class="dot dot-pending"></span> AWAITING REVIEW</span>
            <?php elseif ($thesis['status'] === 'approved'): ?>
              <span class="badge badge-approved" style="padding: 0.5rem 1rem; border-radius: 20px; font-weight: 800; font-size: 0.75rem;"><span class="dot dot-approved"></span> VERIFIED & ARCHIVED</span>
            <?php else: ?>
              <span class="badge badge-default" style="padding: 0.5rem 1rem; border-radius: 20px; font-weight: 800; font-size: 0.75rem;"><?= strtoupper(str_replace('_', ' ', $thesis['status'])) ?></span>
            <?php endif; ?>
          </div>

          <div class="review-header">
             <span class="review-code"><?= htmlspecialchars($thesis['thesis_code']) ?></span>
             <h1 class="review-title"><?= htmlspecialchars($thesis['title']) ?></h1>
          </div>

          <div class="author-strip">
             <div class="av-box"><?= htmlspecialchars(strtoupper(substr($thesis['first_name'], 0, 1) . substr($thesis['last_name'], 0, 1))) ?></div>
             <div>
                <div style="font-weight: 800; color: var(--text-dark);"><?= htmlspecialchars($thesis['first_name'] . ' ' . $thesis['last_name']) ?></div>
                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;"><?= htmlspecialchars($thesis['college']) ?> &bull; Student Submitter</div>
             </div>
          </div>

          <h4 style="font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 1.5rem;">Intention & Abstract</h4>
          <div class="abstract-display"><?= nl2br(htmlspecialchars($thesis['abstract'])) ?></div>

          <h4 style="font-size: 0.75rem; font-weight: 800; color: var(--text-dark); border-bottom: 2px solid var(--off-white); padding-bottom: 1rem; margin-bottom: 2.5rem;">MANUSCRIPT ITERATIONS</h4>
          
          <div style="margin-left: 1rem;">
            <?php foreach ($versions as $index => $v): ?>
               <div class="version-history-item <?= $index === 0 ? 'active' : '' ?>">
                  <div style="background: var(--off-white); border: 1px solid var(--border); padding: 1.5rem; border-radius: var(--radius-sm); display: flex; justify-content: space-between; align-items: center;">
                     <div>
                        <div style="font-weight: 800; color: var(--text-dark);">Version <?= htmlspecialchars($v['version_number']) ?></div>
                        <div style="font-size: 0.8rem; color: var(--text-muted);">Submitted on <?= date('F j, Y \a\t h:i A', strtotime($v['submitted_at'])) ?></div>
                     </div>
                     <div style="display: flex; gap: 1rem; align-items: center;">
                        <?php if ($v['status'] === 'approved'): ?>
                           <span style="font-size: 0.65rem; font-weight: 800; color: #059669; letter-spacing: 0.1em;">APPROVED</span>
                        <?php elseif ($v['status'] === 'rejected'): ?>
                           <span style="font-size: 0.65rem; font-weight: 800; color: #DC2626; letter-spacing: 0.1em;">REJECTED</span>
                        <?php else: ?>
                           <span style="font-size: 0.65rem; font-weight: 800; color: var(--gold); letter-spacing: 0.1em;">PENDING</span>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>public/uploads/<?= htmlspecialchars($v['file_path']) ?>" target="_blank" class="btn-action-outline" style="padding: 0.5rem 1.25rem; font-weight: 800; text-decoration: none; display: flex; align-items: center; gap: 0.4rem; color: var(--crimson); border-color: var(--crimson);">
                           <i class="ph-bold ph-eye"></i> View
                        </a>
                     </div>
                  </div>
                  <?php if (!empty($v['feedback'])): ?>
                    <div style="margin-top: 1rem; background: white; padding: 1.25rem; border: 1px solid var(--border); border-radius: var(--radius-sm); font-family: 'Georgia', serif; font-size: 0.95rem; color: var(--text-dark); line-height: 1.6; border-left: 4px solid var(--border-strong);">
                       "<?= nl2br(htmlspecialchars($v['feedback'])) ?>"
                    </div>
                  <?php endif; ?>
               </div>
            <?php endforeach; ?>
          </div>
        </section>
      </div>

      <!-- SIDEBAR EVALUATION -->
      <aside class="evaluation-sidebar">
         <div class="eval-card">
            <div class="eval-header">
               <i class="ph-fill ph-shield-check"></i>
               <h3>Academic Evaluation</h3>
            </div>

            <?php if ($latestVersion && $latestVersion['status'] === 'pending' && $thesis['status'] === 'pending_review'): ?>
              <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5; margin-bottom: 2rem;">As a formal reviewer, your decision will dictate the archival lifecycle of this research artifact.</p>
              
              <form action="" method="POST">
                <div class="form-group" style="margin-bottom: 1.5rem;">
                   <label class="form-label" style="display: block; font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem;">Formal Decision</label>
                   <select name="action" required class="form-control" style="width: 100%; padding: 0.8rem; border-radius: 4px; border: 1px solid var(--border); font-family: 'Nunito';">
                      <option value="">Select an action...</option>
                      <option value="approved">Verify & Approve Publication</option>
                      <option value="revision_requested">Grant Revision Request</option>
                      <option value="rejected">Formal Rejection</option>
                   </select>
                </div>

                <div class="form-group" style="margin-bottom: 2rem;">
                   <label class="form-label" style="display: block; font-size: 0.65rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem;">Adviser Critique & Notes</label>
                   <textarea name="feedback" class="form-control" rows="10" placeholder="Provide scholarly feedback to the authors..." style="width: 100%; padding: 1rem; border-radius: 4px; border: 1px solid var(--border); font-family: 'Nunito'; line-height: 1.6;"></textarea>
                   <p style="font-size: 0.65rem; color: var(--text-muted); margin-top: 0.5rem; font-style: italic;">Feedback is mandatory for revisions/rejections.</p>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 1.25rem; font-weight: 800; font-size: 1rem;">
                   Finalize Formal Decision
                </button>
              </form>
            <?php else: ?>
               <div style="text-align: center; padding: 2rem 0;">
                  <i class="ph-bold ph-seal-check" style="font-size: 4rem; color: var(--crimson); opacity: 0.15; margin-bottom: 1.5rem; display: block;"></i>
                  <h4 style="font-family: var(--font-serif); font-size: 1.25rem; margin-bottom: 0.5rem;">Review Concluded</h4>
                  <p style="font-size: 0.85rem; color: var(--text-muted);">This research artifact has already been processed by the assigned faculty adviser.</p>
               </div>
               <div style="margin-top: 2rem; border-top: 1px solid var(--off-white); padding-top: 1.5rem;">
                  <a href="<?= BASE_URL ?>faculty/review.php" class="btn btn-secondary" style="width: 100%; justify-content: center; text-decoration: none; padding: 1rem; font-weight: 700;">Return to Queue</a>
               </div>
            <?php endif; ?>
         </div>
      </aside>
    </div>

  </main>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; exit; ?>

<?php
} // END IF THESIS_ID

// ─── QUEUE LIST VIEW ───────────────────────────────────────────────────────────

$filterStatus = $_GET['status'] ?? 'all';
$search       = trim($_GET['search'] ?? '');

$params = [];
$where  = [];

if ($user['role'] !== 'admin') {
    $where[] = "t.adviser_id = :adviser_id";
    $params['adviser_id'] = $user['id'];
}

if ($filterStatus !== 'all') {
    $where[] = "t.status = :status";
    $params['status'] = $filterStatus;
}
if ($search !== '') {
    $where[] = "(t.thesis_code LIKE :search OR t.title LIKE :search)";
    $params['search'] = "%$search%";
}

$whereClause = !empty($where) ? "WHERE " . implode(' AND ', $where) : "";

$thesesStmt = $pdo->prepare("
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
    $whereClause
    ORDER BY tv.submitted_at DESC
");
$thesesStmt->execute($params);
$theses = $thesesStmt->fetchAll();

// Filter summaries
$countStmt = $pdo->prepare("SELECT status, COUNT(*) AS cnt FROM theses " . ($user['role'] === 'admin' ? "" : "WHERE adviser_id = :id") . " GROUP BY status");
if ($user['role'] !== 'admin') $countStmt->execute(['id' => $user['id']]);
else $countStmt->execute();
$counts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalInQueue = array_sum($counts);

function getCnt($c, $k) { return (int)($c[$k] ?? 0); }

require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

<main class="main-content">
  <div class="page-header">
    <div class="page-title">
      <h1>Review <span>Queue</span></h1>
      <p>Analyze and manage scholarly submissions assigned for institutional verification.</p>
    </div>
  </div>

  <!-- Filter Controls -->
  <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.5rem; gap: 1rem; flex-wrap: wrap;">
     <div class="filter-tabs" style="display: flex; gap: 0.5rem; background: var(--off-white); padding: 0.4rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
        <?php
          $tabs = [
            'all'                => 'All Artifacts',
            'pending_review'     => 'Pending',
            'approved'           => 'Approved',
            'revision_requested' => 'Revision',
            'rejected'           => 'Rejected'
          ];
          foreach ($tabs as $k => $l):
            $active = ($filterStatus === $k) ? 'background: var(--crimson); color: white; box-shadow: var(--shadow-sm);' : 'color: var(--text-muted);';
            $c = ($k === 'all') ? $totalInQueue : getCnt($counts, $k);
        ?>
          <a href="<?= BASE_URL ?>faculty/review.php?status=<?= $k ?>&search=<?= urlencode($search) ?>" style="text-decoration: none; padding: 0.5rem 1.25rem; border-radius: 4px; font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; transition: all 0.2s; <?= $active ?>">
             <?= $l ?> <span style="opacity: 0.6; margin-left: 0.4rem;"><?= $c ?></span>
          </a>
        <?php endforeach; ?>
     </div>

     <form method="GET" style="display: flex; gap: 0.5rem; align-items: center;">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <div style="position: relative;">
           <i class="ph ph-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
           <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search code or title..." style="padding: 0.75rem 1rem 0.75rem 2.8rem; border-radius: var(--radius-sm); border: 1px solid var(--border); background: white; font-size: 0.88rem; width: 280px;">
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 0.75rem 1.25rem;"><i class="ph-bold ph-funnel"></i></button>
     </form>
  </div>

  <!-- Table -->
  <div class="queue-table-wrap">
    <table class="queue-table">
      <thead>
        <tr>
          <th style="padding-left: 2rem;">Thesis Artifact</th>
          <th>Student Submitter</th>
          <th>Last Update</th>
          <th style="padding-right: 2rem; text-align: right;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($theses)): ?>
          <tr><td colspan="4" style="padding: 5rem; text-align: center; color: var(--text-muted);">No entries match the specified filter criteria.</td></tr>
        <?php else: ?>
          <?php foreach ($theses as $t): ?>
            <tr>
              <td style="padding-left: 2rem;">
                 <span class="thesis-code-tag"><?= htmlspecialchars($t['thesis_code']) ?></span>
                 <div style="font-weight: 800; color: var(--text-dark); margin-top: 0.25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 450px;"><?= htmlspecialchars($t['title']) ?></div>
              </td>
              <td>
                 <div class="student-cell">
                    <div class="student-av"><?= htmlspecialchars(strtoupper(substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1))) ?></div>
                    <div><div class="student-nm"><?= htmlspecialchars($t['first_name'] . ' ' . $t['last_name']) ?></div><div class="student-cl"><?= htmlspecialchars($t['college']) ?></div></div>
                 </div>
              </td>
              <td style="font-size: 0.8rem; color: var(--text-muted);"><?= $t['latest_submitted_at'] ? date('M j, Y', strtotime($t['latest_submitted_at'])) : date('M j, Y', strtotime($t['created_at'])) ?></td>
              <td style="padding-right: 2rem; text-align: right;">
                 <a href="<?= BASE_URL ?>faculty/review.php?id=<?= $t['id'] ?>" class="btn-view-details">View Details <i class="ph-bold ph-arrow-right"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>
