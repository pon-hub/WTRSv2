<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['adviser']);

$user = current_user();
$thesis_id = isset($_GET['id']) ? (int) $_GET['id'] : null;

// --- Filter & Search Logic ---
$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$params = ['adviser_id' => $user['id']];
$where = ["t.adviser_id = :adviser_id", "t.status <> 'draft'"];

if ($filterStatus !== 'all') {
  $where[] = "t.status = :status";
  $params['status'] = $filterStatus;
}
if ($search !== '') {
  $where[] = "(t.thesis_code LIKE :search OR t.title LIKE :search2 OR u.first_name LIKE :search3 OR u.last_name LIKE :search4)";
  $params['search'] = "%$search%";
  $params['search2'] = "%$search%";
  $params['search3'] = "%$search%";
  $params['search4'] = "%$search%";
}

$whereClause = "WHERE " . implode(' AND ', $where);

// Fetch Queue for Sidebar
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
    ORDER BY COALESCE(tv.submitted_at, t.created_at) DESC
");
$thesesStmt->execute($params);
$myQueue = $thesesStmt->fetchAll();

// ─── DETAIL VIEW ───────────────────────────────────────────────────────────────
$thesis = null;
$versions = [];
$latestVersion = null;
$error = null;
$success = null;

if ($thesis_id) {
  $stmt = $pdo->prepare("SELECT t.*, u.first_name, u.last_name, u.college
                           FROM theses t
                           JOIN users u ON t.author_id = u.id
                           WHERE t.id = :id AND t.adviser_id = :adviser_id");
  $stmt->execute(['id' => $thesis_id, 'adviser_id' => $user['id']]);
  $thesis = $stmt->fetch();

  if ($thesis) {
    $vStmt = $pdo->prepare("SELECT * FROM thesis_versions WHERE thesis_id = :id ORDER BY submitted_at DESC");
    $vStmt->execute(['id' => $thesis_id]);
    $versions = $vStmt->fetchAll();
    
    // Check if a specific version is requested, otherwise use latest
    $requested_v_id = isset($_GET['v']) ? (int)$_GET['v'] : null;
    if ($requested_v_id) {
        foreach($versions as $v) {
            if ($v['id'] == $requested_v_id) {
                $latestVersion = $v;
                break;
            }
        }
    }
    if (!$latestVersion) $latestVersion = $versions[0] ?? null;

    // --- Auto-mark Notifications as Read ---
    $markReadStmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_user_id = :recipient_id AND thesis_id = :thesis_id AND is_read = 0");
    $markReadStmt->execute(['recipient_id' => $user['id'], 'thesis_id' => $thesis_id]);

    // --- POST ACTIONS ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 1. Accept/Decline Request
        if (isset($_POST['request_action'])) {
            $action = $_POST['request_action'];
            if (in_array($action, ['accept_request', 'decline_request'], true)) {
                $pdo->beginTransaction();
                try {
                    if ($action === 'accept_request') {
                        $pdo->prepare("UPDATE theses SET status = 'pending_review', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$thesis_id]);
                        $thesis['status'] = 'pending_review';
                        $studentMsg = "Your thesis request was accepted by Prof. " . $user['last_name'] . ". Review has started.";
                        $success = "Thesis request accepted.";
                    } else {
                        $pdo->prepare("UPDATE theses SET adviser_id = NULL, status = 'draft', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$thesis_id]);
                        $studentMsg = "Your thesis request was declined by Prof. " . $user['last_name'] . ". Please choose another adviser.";
                        $success = "Thesis request declined.";
                    }
                    $pdo->prepare("INSERT INTO notifications (recipient_user_id, sender_user_id, thesis_id, type, message) VALUES (?, ?, ?, 'thesis_request_decision', ?)")
                        ->execute([$thesis['author_id'], $user['id'], $thesis_id, $studentMsg]);
                    $pdo->commit();
                    if ($action === 'decline_request') {
                        header("Location: review.php");
                        exit;
                    }
                } catch (Exception $e) { $pdo->rollBack(); $error = "Transaction failed: " . $e->getMessage(); }
            }
        }
        // 2. Formal Evaluation
        elseif (isset($_POST['action']) && in_array($_POST['action'], ['approved', 'revision_requested', 'rejected'])) {
            $action = $_POST['action'];
            $feedback = trim($_POST['feedback'] ?? '');
            if (empty($feedback) && in_array($action, ['revision_requested', 'rejected'])) {
                $error = "Feedback is mandatory for revisions or rejections.";
            } else {
                if (empty($feedback) && $action === 'approved') $feedback = 'Your manuscript has been verified and approved.';
                $pdo->beginTransaction();
                try {
                    $versionStatus = ($action === 'approved') ? 'approved' : 'rejected';
                    $pdo->prepare("UPDATE thesis_versions SET status = ?, feedback = ? WHERE id = ?")->execute([$versionStatus, $feedback, $latestVersion['id']]);
                    $pdo->prepare("UPDATE theses SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$action, $thesis_id]);
                    
                    $studentMsg = ($action === 'approved') ? "Your thesis has been Accepted." : (($action === 'rejected') ? "Thesis rejected." : "Revision requested.");
                    $pdo->prepare("INSERT INTO notifications (recipient_user_id, sender_user_id, thesis_id, type, message) VALUES (?, ?, ?, 'thesis_review_decision', ?)")
                        ->execute([$thesis['author_id'], $user['id'], $thesis_id, $studentMsg]);
                    if (!empty($feedback)) {
                        $pdo->prepare("INSERT INTO notifications (recipient_user_id, sender_user_id, thesis_id, type, message) VALUES (?, ?, ?, 'thesis_feedback', ?)")
                            ->execute([$thesis['author_id'], $user['id'], $thesis_id, $feedback]);
                    }
                    $pdo->commit();
                    $success = "Review processed successfully.";
                    $thesis['status'] = $action;
                    $latestVersion['status'] = $versionStatus;
                    $latestVersion['feedback'] = $feedback;
                } catch (Exception $e) { $pdo->rollBack(); $error = "Failed: " . $e->getMessage(); }
            }
        }
        // 3. Update Metadata
        elseif (isset($_POST['action']) && $_POST['action'] === 'update_metadata' && $thesis['status'] === 'approved') {
            $newTitle = trim($_POST['title'] ?? '');
            $newAbstract = trim($_POST['abstract'] ?? '');
            $newCoAuthors = trim($_POST['co_authors'] ?? '');
            if (empty($newTitle)) { $error = "Title cannot be empty."; }
            else {
                $upd = $pdo->prepare("UPDATE theses SET title = ?, abstract = ?, co_authors = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                if ($upd->execute([$newTitle, $newAbstract, $newCoAuthors, $thesis_id])) {
                    $thesis['title'] = $newTitle; $thesis['abstract'] = $newAbstract; $thesis['co_authors'] = $newCoAuthors;
                    $success = "Metadata refined.";
                }
            }
        }
        // 4. Publish
        elseif (isset($_POST['action']) && $_POST['action'] === 'publish_artifact' && $thesis['status'] === 'approved') {
            if ($pdo->prepare("UPDATE theses SET status = 'archived', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$thesis_id])) {
                $thesis['status'] = 'archived';
                $success = "Artifact published.";
                $pdo->prepare("INSERT INTO notifications (recipient_user_id, sender_user_id, thesis_id, type, message) VALUES (?, ?, ?, 'thesis_published', ?)")
                    ->execute([$thesis['author_id'], $user['id'], $thesis_id, "Your thesis has been formally published."]);
            }
        }
    }
  }
}

function get_step_class($currentStatus, $stepStatus) {
    $order = ['draft' => 1, 'pending_review' => 2, 'revision_requested' => 3, 'approved' => 4, 'archived' => 5];
    $curr = $order[$currentStatus] ?? 0;
    $target = $order[$stepStatus] ?? 0;
    if ($curr > $target) return 'completed';
    if ($curr === $target) return 'active';
    return '';
}

ob_start();
?>
<style>
    .faculty-layout { display: grid; grid-template-columns: 380px 1fr; gap: 0; height: calc(100vh - var(--topbar-h)); overflow: hidden; }
    .faculty-sidebar { background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; z-index: 10; }
    .sidebar-header { padding: 2rem 1.5rem; border-bottom: 1px solid var(--border); }
    .sidebar-filters { display: flex; gap: 0.4rem; margin-top: 1rem; overflow-x: auto; padding-bottom: 0.5rem; }
    .filter-pill { padding: 0.35rem 0.75rem; border-radius: 20px; font-size: 0.65rem; font-weight: 800; white-space: nowrap; background: var(--off-white); border: 1px solid var(--border); color: var(--text-muted); text-decoration: none; }
    .filter-pill.active { background: var(--crimson); color: white; border-color: var(--crimson); }
    .queue-scroll { flex: 1; overflow-y: auto; }
    .queue-item { display: block; padding: 1.5rem; border-bottom: 1px solid var(--off-white); text-decoration: none; color: inherit; transition: all 0.2s; }
    .queue-item:hover { background: var(--off-white); }
    .queue-item.active { background: var(--crimson-faint); border-left: 4px solid var(--crimson); }
    .queue-item-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
    .queue-item-code { font-size: 0.65rem; font-weight: 800; color: var(--crimson); }
    .queue-item-date { font-size: 0.65rem; color: var(--text-muted); }
    .queue-item-title { font-weight: 700; font-size: 0.9rem; line-height: 1.4; margin-bottom: 0.5rem; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .queue-item-student { font-size: 0.75rem; color: var(--text-mid); }

    .faculty-viewport { background: var(--off-white); overflow-y: auto; padding: 3rem; position: relative; }
    .stepper-wrap { display: flex; justify-content: space-between; margin-bottom: 4rem; position: relative; padding: 0 2rem; max-width: 900px; margin-left: auto; margin-right: auto; }
    .stepper-wrap::before { content: ''; position: absolute; top: 24px; left: 5rem; right: 5rem; height: 3px; background: var(--border); z-index: 1; }
    .step { position: relative; z-index: 2; text-align: center; width: 100px; }
    .step-icon { width: 50px; height: 50px; background: white; border: 3px solid var(--border); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 1.25rem; color: var(--text-muted); transition: all 0.3s; }
    .step.active .step-icon { border-color: var(--crimson); color: var(--crimson); box-shadow: 0 0 0 6px var(--crimson-faint); }
    .step.completed .step-icon { background: var(--crimson); border-color: var(--crimson); color: white; }
    .step-label { font-size: 0.65rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); }
    .step.active .step-label { color: var(--crimson); }

    .workspace-card { background: white; border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-sm); margin-bottom: 2rem; overflow: hidden; max-width: 900px; margin-left: auto; margin-right: auto; }
    .workspace-header { padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; background: var(--surface); }
    .workspace-body { padding: 2.5rem; }
    .version-nav { display: flex; gap: 0.5rem; margin-bottom: 2rem; border-bottom: 1px solid var(--off-white); padding-bottom: 1rem; overflow-x: auto; }
    .v-pill { padding: 0.4rem 0.8rem; border-radius: 4px; font-size: 0.7rem; font-weight: 800; text-decoration: none; background: white; border: 1px solid var(--border); color: var(--text-muted); white-space: nowrap; }
    .v-pill.active { background: var(--crimson); color: white; border-color: var(--crimson); }
    .main-content:has(.faculty-layout) { padding: 0 !important; overflow: hidden; }

    .pdf-preview-btn { background: var(--crimson-faint); color: var(--crimson); border: 1px solid var(--crimson); padding: 0.75rem 1.25rem; border-radius: var(--radius-sm); font-weight: 800; font-size: 0.8rem; display: flex; align-items: center; gap: 0.5rem; cursor: pointer; transition: all 0.2s; }
    .pdf-preview-btn:hover { background: var(--crimson); color: white; }
    .pdf-modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); align-items: center; justify-content: center; backdrop-filter: blur(4px); }
    .pdf-modal-content { background: white; width: 95%; height: 92vh; max-width: 1400px; border-radius: var(--radius-lg); overflow: hidden; display: flex; flex-direction: column; }

    /* Redesigned Evaluation UI */
    .decision-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; }
    .decision-card { background: white; border: 2px solid var(--border); border-radius: 12px; padding: 1.5rem; cursor: pointer; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); text-align: center; position: relative; overflow: hidden; }
    .decision-card:hover { border-color: var(--crimson-faint); background: var(--off-white); transform: translateY(-2px); }
    .decision-card.selected { border-color: var(--crimson); background: var(--crimson-faint); box-shadow: 0 4px 12px rgba(153, 27, 27, 0.1); }
    .decision-card .ph { font-size: 2rem; margin-bottom: 1rem; display: block; color: var(--text-muted); transition: color 0.2s; }
    .decision-card.selected .ph { color: var(--crimson); }
    .decision-card-title { font-weight: 800; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; display: block; margin-bottom: 0.5rem; }
    .decision-card-desc { font-size: 0.75rem; color: var(--text-muted); line-height: 1.4; }
    
    .feedback-area { margin-bottom: 2rem; }
    .feedback-label { display: block; font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 0.75rem; }
    .feedback-textarea { width: 100%; min-height: 150px; padding: 1.25rem; border-radius: 12px; border: 1px solid var(--border); background: white; font-size: 0.95rem; line-height: 1.6; transition: all 0.2s; resize: vertical; }
    .feedback-textarea:focus { outline: none; border-color: var(--crimson); box-shadow: 0 0 0 4px var(--crimson-faint); }
    
    .submit-btn { background: var(--crimson); color: white; border: 0; width: 100%; padding: 1.25rem; border-radius: 12px; font-weight: 800; font-size: 0.9rem; letter-spacing: 0.1em; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; gap: 0.75rem; }
    .submit-btn:hover { background: #7F1D1D; transform: scale(1.01); box-shadow: var(--shadow-md); }
    .submit-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
</style>
<?php
$extraCss = ob_get_clean();
require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

<main class="main-content">
  <div class="faculty-layout">
    
    <!-- LEFT: QUEUE -->
    <aside class="faculty-sidebar">
      <div class="sidebar-header">
        <h2 style="font-size: 1.25rem; font-family: var(--font-serif);">Review Queue</h2>
        <form method="GET" style="position: relative; margin-top: 1rem;">
          <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
          <i class="ph ph-magnifying-glass" style="position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: var(--text-muted);"></i>
          <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search student or code..." 
            style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border-radius: 8px; border: 1px solid var(--border); background: var(--off-white); font-size: 0.85rem;">
        </form>
        <div class="sidebar-filters">
          <?php foreach(['all'=>'ALL','pending_review'=>'PENDING','revision_requested'=>'REVISION','approved'=>'ACCEPTED'] as $k=>$l): ?>
            <a href="?status=<?= $k ?>&search=<?= urlencode($search) ?>" class="filter-pill <?= $filterStatus==$k?'active':'' ?>"><?= $l ?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="queue-scroll">
        <?php if(empty($myQueue)): ?>
          <div style="padding: 4rem 2rem; text-align: center; color: var(--text-muted);"><i class="ph ph-tray" style="font-size: 3rem; opacity: 0.2; margin-bottom: 1rem;"></i><p>Your review queue is empty.</p></div>
        <?php else: ?>
          <?php foreach($myQueue as $item): ?>
            <a href="?id=<?= $item['id'] ?>&status=<?= $filterStatus ?>&search=<?= urlencode($search) ?>" class="queue-item <?= $thesis_id==$item['id']?'active':'' ?>">
              <div class="queue-item-meta"><span class="queue-item-code"><?= htmlspecialchars($item['thesis_code']) ?></span><span class="queue-item-date"><?= time_ago($item['latest_submitted_at'] ?? $item['created_at']) ?></span></div>
              <div class="queue-item-title"><?= htmlspecialchars($item['title']) ?></div>
              <div class="queue-item-student"><?= htmlspecialchars($item['first_name'].' '.$item['last_name']) ?></div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </aside>

    <!-- RIGHT: VIEWPORT -->
    <section class="faculty-viewport">
      <?php if ($error): ?><div style="max-width: 900px; margin: 0 auto 2rem; padding: 1rem; background: #991B1B; color: white; border-radius: 8px; font-weight: 700;"><i class="ph ph-warning-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div style="max-width: 900px; margin: 0 auto 2rem; padding: 1rem; background: #065F46; color: white; border-radius: 8px; font-weight: 700;"><i class="ph ph-check-circle"></i> <?= htmlspecialchars($success) ?></div><?php endif; ?>

      <?php if (!$thesis): ?>
        <div style="height: 100%; display: flex; align-items: center; justify-content: center; text-align: center; color: var(--text-muted);">
          <div><i class="ph ph-scroll" style="font-size: 5rem; opacity: 0.1; margin-bottom: 1.5rem;"></i><h2 style="font-family: var(--font-serif); font-size: 1.5rem;">Manuscript Workspace</h2><p>Select a submission from the sidebar to begin.</p></div>
        </div>
      <?php else: ?>
        <div class="stepper-wrap">
          <?php foreach(['draft'=>'Draft','pending_review'=>'Review','revision_requested'=>'Revision','approved'=>'Accepted','archived'=>'Archived'] as $st=>$lb): ?>
            <div class="step <?= get_step_class($thesis['status'], $st) ?>"><div class="step-icon"><i class="ph ph-<?= $st=='draft'?'file-plus':($st=='pending_review'?'magnifying-glass':($st=='revision_requested'?'note-pencil':($st=='approved'?'seal-check':'archive'))) ?>"></i></div><div class="step-label"><?= $lb ?></div></div>
          <?php endforeach; ?>
        </div>

        <div class="workspace-card">
          <div class="workspace-header">
            <div><span style="font-size: 0.65rem; font-weight: 800; color: var(--crimson); text-transform: uppercase;">Reviewing Version <?= $latestVersion['version_number'] ?? '1.0' ?></span><h2 style="font-family: var(--font-serif); font-size: 1.25rem; margin-top: 0.25rem;"><?= htmlspecialchars($thesis['title']) ?></h2></div>
            <?php if($latestVersion): ?><button onclick="previewPdf('<?= htmlspecialchars($latestVersion['file_path']) ?>', 'v<?= $latestVersion['version_number'] ?>')" class="pdf-preview-btn"><i class="ph-fill ph-file-pdf"></i> PREVIEW</button><?php endif; ?>
          </div>
          <div class="workspace-body">
            <div class="version-nav">
              <?php foreach($versions as $v): ?><a href="?id=<?= $thesis_id ?>&v=<?= $v['id'] ?>&status=<?= $filterStatus ?>&search=<?= urlencode($search) ?>" class="v-pill <?= $latestVersion['id']==$v['id']?'active':'' ?>">v<?= $v['version_number'] ?></a><?php endforeach; ?>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 3rem;">
              <div><h4 style="font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 1rem;">Primary Author</h4><div style="display: flex; align-items: center; gap: 1rem;"><div style="width: 40px; height: 40px; background: var(--crimson-faint); color: var(--crimson); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800;"><?= strtoupper(substr($thesis['first_name'],0,1).substr($thesis['last_name'],0,1)) ?></div><div><div style="font-weight: 700;"><?= htmlspecialchars($thesis['first_name'].' '.$thesis['last_name']) ?></div><div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($thesis['college']) ?></div></div></div></div>
              <div><h4 style="font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 1rem;">Metadata</h4><div style="font-size: 0.85rem; line-height: 1.6;">Code: <strong style="color: var(--crimson);"><?= htmlspecialchars($thesis['thesis_code']) ?></strong><br>Submitted: <strong><?= date('M j, Y', strtotime($latestVersion['submitted_at'] ?? $thesis['created_at'])) ?></strong><br>Status: <span class="badge badge-<?= $thesis['status'] ?>"><?= strtoupper($thesis['status']) ?></span></div></div>
            </div>

            <div style="margin-bottom: 3rem;"><h4 style="font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 1rem;">Abstract</h4><div style="background: var(--off-white); padding: 1.5rem; border-radius: 8px; font-size: 0.95rem; line-height: 1.6;"><?= nl2br(htmlspecialchars($thesis['abstract'])) ?></div></div>

            <hr style="border: 0; border-top: 1px solid var(--border-faint); margin: 3rem 0;">

            <?php if ($thesis['status'] === 'draft'): ?>
              <div style="background: var(--off-white); padding: 2rem; border-radius: 12px; border: 1px solid var(--border); text-align: center;">
                <h3 style="font-family: var(--font-serif); margin-bottom: 1rem;">Advisory Request</h3>
                <p style="margin-bottom: 2rem; color: var(--text-muted);">The student has requested you as their adviser. Review the manuscript before deciding.</p>
                <form action="" method="POST" style="display: flex; gap: 1rem;">
                  <button type="submit" name="request_action" value="accept_request" class="btn btn-primary" style="flex: 1; background: #065F46; border: 0;">ACCEPT</button>
                  <button type="submit" name="request_action" value="decline_request" class="btn btn-secondary" style="flex: 1;">DECLINE</button>
                </form>
              </div>
            <?php elseif ($latestVersion && $latestVersion['status'] === 'pending'): ?>
              <div style="margin-top: 2rem;">
                <h3 style="font-family: var(--font-serif); font-size: 1.5rem; margin-bottom: 0.5rem;">Formal Evaluation</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem; margin-bottom: 2rem;">Please review the manuscript thoroughly and provide your final decision.</p>
                
                <form action="" method="POST" id="evaluationForm">
                  <input type="hidden" name="action" id="decisionInput" required>
                  
                  <div class="decision-grid">
                    <div class="decision-card" onclick="selectDecision('approved', this)">
                      <i class="ph ph-seal-check"></i>
                      <span class="decision-card-title">Approve</span>
                      <span class="decision-card-desc">Manuscript meets all institutional standards.</span>
                    </div>
                    <div class="decision-card" onclick="selectDecision('revision_requested', this)">
                      <i class="ph ph-note-pencil"></i>
                      <span class="decision-card-title">Revision</span>
                      <span class="decision-card-desc">Minor or major adjustments are required.</span>
                    </div>
                    <div class="decision-card" onclick="selectDecision('rejected', this)">
                      <i class="ph ph-x-circle"></i>
                      <span class="decision-card-title">Reject</span>
                      <span class="decision-card-desc">Manuscript does not meet the requirements.</span>
                    </div>
                  </div>

                  <div class="feedback-area">
                    <label class="feedback-label">Feedback & Critique</label>
                    <textarea name="feedback" class="feedback-textarea" placeholder="Provide detailed feedback for the student..."></textarea>
                  </div>

                  <button type="submit" class="submit-btn" id="submitBtn" disabled>
                    <span>SUBMIT FORMAL DECISION</span>
                    <i class="ph ph-paper-plane-tilt"></i>
                  </button>
                </form>
              </div>
            <?php elseif ($thesis['status'] === 'approved'): ?>
              <div style="background: #F0FDF4; border: 1px solid #BBF7D0; padding: 2rem; border-radius: 12px;">
                <h3 style="font-family: var(--font-serif); color: #166534; margin-bottom: 1rem;">Archival Refinement</h3>
                <form action="" method="POST">
                  <input type="hidden" name="action" value="update_metadata">
                  <div style="margin-bottom: 1rem;"><label style="font-size: 0.7rem; font-weight: 800; color: #166534; display: block;">Formal Title</label><input type="text" name="title" class="form-control" style="background: white;" value="<?= htmlspecialchars($thesis['title']) ?>" required></div>
                  <div style="margin-bottom: 1rem;"><label style="font-size: 0.7rem; font-weight: 800; color: #166534; display: block;">Co-authors</label><input type="text" name="co_authors" class="form-control" style="background: white;" value="<?= htmlspecialchars($thesis['co_authors'] ?? '') ?>"></div>
                  <div style="margin-bottom: 1rem;"><label style="font-size: 0.7rem; font-weight: 800; color: #166534; display: block;">Abstract</label><textarea name="abstract" rows="4" class="form-control" style="background: white;"><?= htmlspecialchars($thesis['abstract']) ?></textarea></div>
                  <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn-secondary" style="flex: 1; background: white; color: #166534;">SAVE METADATA</button>
                    <button type="submit" name="action" value="publish_artifact" class="btn btn-primary" style="flex: 2; background: #166534; border: 0;">PUBLISH TO ARCHIVE</button>
                  </div>
                </form>
              </div>
            <?php else: ?>
              <div style="text-align: center; padding: 2rem;"><i class="ph ph-check-circle" style="font-size: 3rem; color: var(--crimson); opacity: 0.2;"></i><p>Review Concluded: <strong><?= strtoupper($thesis['status']) ?></strong></p></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <!-- PDF Preview Modal -->
  <div id="pdfPreviewModal" class="pdf-modal">
    <div class="pdf-modal-content">
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); background: var(--surface);">
        <div style="display: flex; align-items: center; gap: 0.75rem;"><i class="ph-fill ph-file-pdf" style="color: var(--crimson); font-size: 1.5rem;"></i><h3 id="modalTitle" style="margin: 0; font-family: var(--font-serif);">Manuscript Preview</h3></div>
        <button onclick="closeModal()" style="background: var(--off-white); border: 0; width: 2.5rem; height: 2.5rem; border-radius: 50%; font-size: 1.5rem; cursor: pointer;">&times;</button>
      </div>
      <div style="flex: 1; display: grid; grid-template-columns: 1fr 320px; overflow: hidden;">
        <div style="background: #525659;"><iframe id="pdfFrame" src="" style="width: 100%; height: 100%; border: 0;"></iframe></div>
        <div style="background: var(--surface); border-left: 1px solid var(--border); padding: 2rem; overflow-y: auto;">
          <h4 style="font-size: 0.7rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 1.5rem;">Details</h4>
          <div style="margin-bottom: 1rem;"><label style="display: block; font-size: 0.65rem; font-weight: 800; color: var(--crimson); text-transform: uppercase;">Title</label><div style="font-weight: 700; font-size: 0.95rem;"><?= htmlspecialchars($thesis['title'] ?? '') ?></div></div>
          <div style="margin-bottom: 1rem;"><label style="display: block; font-size: 0.65rem; font-weight: 800; color: var(--crimson); text-transform: uppercase;">Author</label><div style="font-weight: 700;"><?= htmlspecialchars(($thesis['first_name']??'').' '.($thesis['last_name']??'')) ?></div></div>
          <?php if(!empty($thesis['co_authors'])): ?><div style="margin-bottom: 1rem;"><label style="display: block; font-size: 0.65rem; font-weight: 800; color: var(--crimson); text-transform: uppercase;">Co-authors</label><div><?= htmlspecialchars($thesis['co_authors']) ?></div></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    function previewPdf(path, ver) {
      const modal = document.getElementById('pdfPreviewModal');
      const frame = document.getElementById('pdfFrame');
      document.getElementById('modalTitle').innerText = "Manuscript Evaluation (" + ver + ")";
      frame.src = "<?= BASE_URL ?>public/uploads/" + path;
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
    function closeModal() {
      const modal = document.getElementById('pdfPreviewModal');
      modal.style.display = 'none';
      document.body.style.overflow = 'auto';
      document.getElementById('pdfFrame').src = "";
    }

    function selectDecision(value, element) {
      document.getElementById('decisionInput').value = value;
      document.querySelectorAll('.decision-card').forEach(c => c.classList.remove('selected'));
      element.classList.add('selected');
      document.getElementById('submitBtn').disabled = false;
    }
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
  </script>
</main>
<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>