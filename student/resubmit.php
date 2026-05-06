<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['student']);

$user = current_user();
$thesis_id = $_GET['id'] ?? null;

if (!$thesis_id) {
  header('Location: index.php');
  exit;
}

// Fetch the specific thesis
$stmt = $pdo->prepare("SELECT t.*, u.first_name as adviser_first, u.last_name as adviser_last 
                       FROM theses t 
                       LEFT JOIN users u ON t.adviser_id = u.id 
                       WHERE t.id = :id AND t.author_id = :author_id");
$stmt->execute(['id' => $thesis_id, 'author_id' => $user['id']]);
$thesis = $stmt->fetch();

if (!$thesis) {
  header('Location: index.php');
  exit;
}

// Fetch the latest version for version incrementing and feedback
$vStmt = $pdo->prepare("SELECT * FROM thesis_versions WHERE thesis_id = :thesis_id ORDER BY id DESC LIMIT 1");
$vStmt->execute(['thesis_id' => $thesis_id]);
$latestVersion = $vStmt->fetch();

// Fetch existing co-authors
$existingCoAuthors = [];
if (!empty($thesis['co_authors'])) {
    $existingCoAuthors = array_map('trim', explode(',', $thesis['co_authors']));
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? '');
  $abstract = trim($_POST['abstract'] ?? '');
  $thesis_type = $_POST['thesis_type'] ?? 'solo';
  $co_authors = $_POST['co_authors'] ?? [];

  if (empty($title) || empty($abstract)) {
    $error = "Title and abstract are required.";
  } elseif ($thesis_type === 'group' && empty($co_authors[0] ?? '') && empty($existingCoAuthors)) {
    // If it's a group, they need at least one co-author (either new or existing that wasn't removed)
    // Actually we'll just process whatever is sent in $co_authors array
  }

  if (!$error) {
    $hasNewFile = isset($_FILES['manuscript']) && $_FILES['manuscript']['error'] === UPLOAD_ERR_OK;
    $fileProcessed = false;
    $versionNum = "1.0";
    $fileName = "";
    $fileSize = 0;

    if ($hasNewFile) {
      $file = $_FILES['manuscript'];
      $fileSize = $file['size'];
      $fileTmp = $file['tmp_name'];

      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      $fileType = finfo_file($finfo, $fileTmp);
      finfo_close($finfo);

      if ($fileType !== 'application/pdf') {
        $error = "Only PDF files are allowed.";
      } elseif ($fileSize > 20 * 1024 * 1024) {
        $error = "File size exceeds the 20MB limit.";
      } else {
        if ($latestVersion) {
          $versionNum = number_format(floatval($latestVersion['version_number']) + 0.1, 1);
        }

        $uploadDir = __DIR__ . '/../public/uploads/';
        if (!is_dir($uploadDir)) {
          mkdir($uploadDir, 0755, true);
        }
        $fileName = uniqid('thesis_') . '.pdf';
        $destination = $uploadDir . $fileName;

        if (move_uploaded_file($fileTmp, $destination)) {
          $fileProcessed = true;
        } else {
          $error = "Failed to move the uploaded file. Check directory permissions.";
        }
      }
    }

    if (!$error) {
      $pdo->beginTransaction();
      try {
        $coAuthorsStr = null;
        if ($thesis_type === 'group' && !empty($co_authors)) {
            $filtered = array_filter(array_map('trim', $co_authors));
            if (!empty($filtered)) {
                $coAuthorsStr = implode(', ', $filtered);
            }
        }

        // Update thesis metadata
        $updStmt = $pdo->prepare("UPDATE theses SET title = ?, abstract = ?, thesis_type = ?, co_authors = ?, status = 'pending_review', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updStmt->execute([$title, $abstract, $thesis_type, $coAuthorsStr, $thesis_id]);

        // Insert new version if file was uploaded
        if ($fileProcessed) {
          $insVStmt = $pdo->prepare("INSERT INTO thesis_versions (thesis_id, version_number, file_path, file_size, status) VALUES (?, ?, ?, ?, 'pending')");
          $insVStmt->execute([$thesis_id, $versionNum, $fileName, $fileSize]);
        }

        $pdo->commit();
        
        $_SESSION['student_dash_flash'] = [
          'type' => 'success',
          'message' => 'Revision submitted successfully.',
        ];
        header('Location: ' . BASE_URL . 'student/tracker.php?id=' . (int) $thesis_id, true, 303);
        exit;
      } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Database error: " . $e->getMessage();
      }
    }
  }
}

// Custom CSS for Resubmit View
ob_start();
?>
<style>
  .resubmit-card {
    background: white;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 4rem;
    box-shadow: var(--shadow-md);
    max-width: 900px;
    margin: 0 auto;
  }

  .resubmit-header {
    border-bottom: 2px solid var(--off-white);
    padding-bottom: 2.5rem;
    margin-bottom: 3.5rem;
    text-align: center;
  }

  .resubmit-header h1 {
    font-family: var(--font-serif);
    font-size: 2.5rem;
    margin-bottom: 1rem;
    color: var(--text-dark);
  }

  .resubmit-header p {
    color: var(--text-muted);
    font-size: 1.1rem;
  }

  .form-section {
    margin-bottom: 3rem;
  }

  .form-label {
    display: block;
    font-size: 0.7rem;
    font-weight: 800;
    color: var(--text-muted);
    letter-spacing: 0.15em;
    text-transform: uppercase;
    margin-bottom: 1.25rem;
  }

  .form-control {
    width: 100%;
    box-sizing: border-box;
    background: var(--off-white);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 0.95rem 1.15rem;
    font-family: var(--font-base);
    font-size: 1.06rem;
    color: var(--text-dark);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  }

  .form-control:focus {
    background: var(--surface);
    border-color: var(--crimson);
    box-shadow: 0 0 0 3px var(--crimson-faint);
    outline: none;
  }

  textarea.form-control {
    min-height: 200px;
    resize: vertical;
    line-height: 1.6;
  }

  .read-only-display {
    background: var(--off-white);
    padding: 1.5rem;
    border-radius: var(--radius-sm);
    border: 1px solid var(--border);
    color: var(--text-dark);
    font-weight: 700;
    font-size: 1.1rem;
  }

  .feedback-display {
    background: #FFF9E6;
    border-left: 4px solid var(--gold);
    padding: 2rem;
    border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
  }

  .feedback-meta {
    font-size: 0.7rem;
    font-weight: 800;
    color: var(--gold);
    letter-spacing: 0.1em;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
  }

  .feedback-text {
    font-family: 'Georgia', serif;
    font-style: italic;
    font-size: 1.1rem;
    color: var(--text-dark);
    line-height: 1.6;
  }

  .dropzone-refined {
    border: 2px dashed var(--border-strong);
    border-radius: var(--radius);
    padding: 5rem 2.5rem;
    text-align: center;
    background: var(--off-white);
    transition: all 0.2s;
    cursor: pointer;
    position: relative;
  }

  .dropzone-refined:hover {
    border-color: var(--crimson);
    background: var(--crimson-faint);
  }

  .dropzone-icon {
    font-size: 4rem;
    color: var(--crimson);
    margin-bottom: 1.5rem;
    opacity: 0.8;
  }
</style>
<?php
$extraCss = ob_get_clean();

require_once __DIR__ . '/../includes/layout_top.php';
require_once __DIR__ . '/../includes/layout_sidebar.php';
?>

<main class="main-content">

  <!-- Breadcrumb -->
  <nav
    style="margin-bottom: 2.5rem; font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em;">
    <a href="index.php" style="color:var(--text-muted); text-decoration:none;">DASHBOARD</a>
    <i class="ph ph-caret-right" style="margin: 0 0.5rem; opacity:0.5;"></i>
    <a href="tracker.php?id=<?= $thesis_id ?>" style="color:var(--text-muted); text-decoration:none;">SUBMISSION
      DETAIL</a>
    <i class="ph ph-caret-right" style="margin: 0 0.5rem; opacity:0.5;"></i>
    <span style="color:var(--crimson);">REVISION SUBMISSION</span>
  </nav>

  <?php if ($error): ?>
    <div
      style="max-width:900px; margin: 0 auto 2rem; background:#991B1B; color:white; padding:1.25rem 2rem; border-radius:var(--radius-sm); font-weight:700;">
      <i class="ph-bold ph-warning-circle" style="margin-right:0.5rem;"></i> <?= htmlspecialchars($error) ?>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div
      style="max-width:900px; margin: 0 auto 2rem; background:#065F46; color:white; padding:1.25rem 2rem; border-radius:var(--radius-sm); font-weight:700;">
      <i class="ph-bold ph-check-circle" style="margin-right:0.5rem;"></i> <?= htmlspecialchars($success) ?>
    </div>
  <?php endif; ?>

  <form action="" method="POST" enctype="multipart/form-data" class="resubmit-card">

    <header class="resubmit-header">
      <h1>REVISION</h1>
      <p>Submitting changes to <strong><?= htmlspecialchars($thesis['thesis_code']) ?></strong> as Version
        <?= htmlspecialchars(isset($latestVersion['version_number']) ? number_format(floatval($latestVersion['version_number']) + 0.1, 1) : "1.0") ?>
      </p>
    </header>

    <div onclick="toggleDetails('proc-info')" style="cursor: pointer; font-size: 0.7rem; font-weight: 800; color: var(--crimson); display: flex; align-items: center; gap: 0.4rem; margin-bottom: 2rem;">
       <i class="ph ph-info"></i> SHOW DETAILED PROCESS DATA
    </div>
    <div id="proc-info" style="display: none; margin-bottom: 3rem; background: var(--off-white); padding: 1.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border);">
       <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
         <div><span style="font-size: 0.65rem; font-weight: 800; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">REGISTRY CREATED</span><strong><?= date('F j, Y, g:i A', strtotime($thesis['created_at'])) ?></strong></div>
         <div><span style="font-size: 0.65rem; font-weight: 800; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">LAST MODIFIED</span><strong><?= date('F j, Y, g:i A', strtotime($thesis['updated_at'])) ?></strong></div>
         <div><span style="font-size: 0.65rem; font-weight: 800; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">LATEST VERSION</span><strong>v<?= htmlspecialchars($latestVersion['version_number'] ?? '1.0') ?></strong></div>
         <div><span style="font-size: 0.65rem; font-weight: 800; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">AUTHOR REFERENCE</span><strong><?= htmlspecialchars($user['last_name'] . ', ' . $user['first_name']) ?></strong></div>
       </div>
    </div>

    <!-- Feedback -->
    <?php if ($latestVersion && !empty($latestVersion['feedback'])): ?>
      <div class="form-section">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.25rem;">
          <span class="form-label" style="margin: 0;">Active Faculty Feedback</span>
          <button type="button" id="previewBtn" class="btn btn-secondary" style="padding: 0.45rem 0.85rem; font-size: 0.75rem; font-weight: 800; display: flex; align-items: center; gap: 0.4rem;">
            <i class="ph ph-eye"></i> PREVIEW CURRENT
          </button>
        </div>
        <div class="feedback-display">
          <div class="feedback-meta"><i class="ph-fill ph-chat-centered-text"></i> DR.
            <?= strtoupper(htmlspecialchars($thesis['adviser_last'])) ?>
          </div>
          <div class="feedback-text">"<?= nl2br(htmlspecialchars($latestVersion['feedback'])) ?>"</div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Metadata Edit Section -->
    <div class="form-section">
      <span class="form-label">Thesis Information</span>
      
      <div style="margin-bottom: 1.5rem;">
        <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; display: block;">Title</label>
        <input type="text" name="title" required class="form-control" value="<?= htmlspecialchars($thesis['title']) ?>" autocomplete="off">
      </div>
      
      <div style="margin-bottom: 1.5rem;">
        <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; display: block;">Abstract</label>
        <textarea name="abstract" required class="form-control" rows="6"><?= htmlspecialchars($thesis['abstract']) ?></textarea>
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; display: block;">Keywords <span style="font-weight:700;opacity:0.85;">(optional)</span></label>
        <input type="text" class="form-control" placeholder="e.g. machine learning, WMSU" autocomplete="off">
        <span style="font-size: 0.8rem; color: var(--text-muted); display: block; margin-top: 0.4rem;">For your own reference only — not saved to the system yet.</span>
      </div>

      <div style="margin-bottom: 1.5rem;">
        <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; display: block;">Thesis Type</label>
        <div style="display: flex; gap: 1.5rem; margin-top: 0.75rem;">
          <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer; font-weight: 600; margin: 0; color: var(--text-dark);">
            <input type="radio" name="thesis_type" value="solo" required <?= $thesis['thesis_type'] === 'solo' ? 'checked' : '' ?> style="cursor: pointer;">
            Solo
          </label>
          <label style="display: flex; align-items: center; gap: 0.6rem; cursor: pointer; font-weight: 600; margin: 0; color: var(--text-dark);">
            <input type="radio" name="thesis_type" value="group" required <?= $thesis['thesis_type'] === 'group' ? 'checked' : '' ?> style="cursor: pointer;">
            Group
          </label>
        </div>
      </div>

      <div id="co-authors-section" style="display: <?= $thesis['thesis_type'] === 'group' ? 'block' : 'none' ?>; background: #f9fafb; padding: 1.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border); margin-bottom: 1.5rem;">
        <label style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); margin-bottom: 0.5rem; display: block;">Co-authors</label>
        <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">
          Add or remove co-authors who contributed to this thesis.
        </p>
        <div id="co-authors-list" style="display: flex; flex-direction: column; gap: 0.75rem; margin-bottom: 1rem;">
          <?php foreach ($existingCoAuthors as $ca): ?>
            <div class="co-author-input" style="display: flex; gap: 0.75rem; align-items: flex-end;">
              <div style="flex: 1;">
                <input type="text" name="co_authors[]" class="form-control" style="margin: 0;" required value="<?= htmlspecialchars($ca) ?>">
              </div>
              <button type="button" class="remove-co-author-btn" style="background: #FEE2E2; color: #991B1B; border: none; width: 2.55rem; height: 2.55rem; padding: 0; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">
                <i class="ph ph-x"></i>
              </button>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="button" id="add-co-author-btn" class="btn btn-secondary" style="width: fit-content; padding: 0.6rem 1rem; font-weight: 700;">
          <i class="ph-bold ph-plus"></i> Add co-author
        </button>
      </div>

    </div>

    <!-- Manuscript Action -->
    <div class="form-section">
      <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.25rem;">
        <span class="form-label" style="margin: 0;">Updated Manuscript Artifact (PDF)</span>
        <button type="button" id="previewSelectedBtn" class="btn btn-secondary" style="display: none; padding: 0.45rem 0.85rem; font-size: 0.75rem; font-weight: 800; border-color: var(--crimson); color: var(--crimson);">
          <i class="ph ph-file-search"></i> PREVIEW SELECTED
        </button>
      </div>
      <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem;">Optional. If you are only modifying metadata above, you may leave this blank. Upload a new PDF to create a new manuscript version.</p>
      <div class="dropzone-refined" onclick="document.getElementById('file-field').click()">
        <div class="dropzone-icon"><i class="ph-fill ph-cloud-arrow-up"></i></div>
        <h4 id="file-label"
          style="font-family: var(--font-serif); font-size: 1.25rem; font-weight: 800; margin-bottom: 0.5rem; color: var(--text-dark);">
          upload file</h4>
        <p style="font-size: 0.9rem; color: var(--text-muted);">or drag and drop verified PDF manuscript here</p>
        <input type="file" name="manuscript" id="file-field" accept="application/pdf" style="display:none;"
          onchange="handleFileSelect(this)">
      </div>
    </div>

    <!-- Actions -->
    <div style="display: flex; gap: 1.5rem; margin-top: 4rem;">
      <a href="tracker.php?id=<?= $thesis_id ?>" class="btn btn-secondary"
        style="flex:1; justify-content: center; text-decoration: none; padding: 1.25rem;">CANCEL </a>
      <button type="submit" class="btn btn-primary"
        style="flex:2; justify-content: center; padding: 1.25rem; font-weight: 800; font-size: 1rem;">
        <i class="ph-fill ph-paper-plane-tilt"></i> SUBMIT REVISION
      </button>
    </div>

  </form>

</main>

  <!-- PDF Preview Modal -->
  <div id="pdfPreviewModal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.7); align-items: center; justify-content: center;">
    <div style="background: white; border-radius: var(--radius-lg); width: 90%; height: 90vh; max-width: 1000px; display: flex; flex-direction: column; box-shadow: var(--shadow-lg);">
      <div style="display: flex; justify-content: space-between; align-items: center; padding: 1.5rem; border-bottom: 1px solid var(--border); flex-shrink: 0;">
        <h3 id="modalTitle" style="margin: 0; font-size: 1.2rem; color: var(--text-dark); font-family: var(--font-serif);">Manuscript Preview</h3>
        <button type="button" onclick="closeModal()" style="background: none; border: none; font-size: 1.8rem; cursor: pointer; color: var(--text-muted);">&times;</button>
      </div>
      <div style="flex: 1; background: #f5f5f5;">
        <iframe id="pdfFrame" src="" type="application/pdf" style="width: 100%; height: 100%; border: none;"></iframe>
      </div>
    </div>
  </div>

<script>
  function toggleDetails(id) {
    const el = document.getElementById(id);
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
  }

  let selectedFileUrl = null;
  function handleFileSelect(input) {
    const label = document.getElementById('file-label');
    const previewBtn = document.getElementById('previewSelectedBtn');
    if (input.files && input.files[0]) {
      const file = input.files[0];
      label.innerText = file.name;
      previewBtn.style.display = 'flex';
      if (selectedFileUrl) URL.revokeObjectURL(selectedFileUrl);
      selectedFileUrl = URL.createObjectURL(file);
    } else {
        label.innerText = 'upload file';
        previewBtn.style.display = 'none';
    }
  }

  const modal = document.getElementById('pdfPreviewModal');
  const frame = document.getElementById('pdfFrame');
  const title = document.getElementById('modalTitle');

  document.getElementById('previewBtn')?.addEventListener('click', () => {
    title.innerText = "Current Version Preview (v<?= htmlspecialchars($latestVersion['version_number'] ?? '1.0') ?>)";
    frame.src = '<?= BASE_URL ?>public/uploads/<?= htmlspecialchars($latestVersion['file_path'] ?? '') ?>';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  });

  document.getElementById('previewSelectedBtn')?.addEventListener('click', () => {
    if (selectedFileUrl) {
      title.innerText = "New Selection Preview";
      frame.src = selectedFileUrl;
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
    }
  });

  function closeModal() {
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
    frame.src = "";
  }

  window.onclick = (e) => { if (e.target == modal) closeModal(); };
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

  (function() {
    const thesisTypeRadios = document.querySelectorAll('input[name="thesis_type"]');
    const coAuthorsSection = document.getElementById('co-authors-section');
    const addCoAuthorBtn = document.getElementById('add-co-author-btn');
    const coAuthorsList = document.getElementById('co-authors-list');
    let coAuthorCount = <?= count($existingCoAuthors) ?>;

    function toggleCoAuthorsSection() {
      const isGroup = document.querySelector('input[name="thesis_type"]:checked')?.value === 'group';
      coAuthorsSection.style.display = isGroup ? 'block' : 'none';
    }

    function createCoAuthorInput(index) {
      const div = document.createElement('div');
      div.className = 'co-author-input';
      div.style.cssText = 'display: flex; gap: 0.75rem; align-items: flex-end;';
      div.innerHTML = `
        <div style="flex: 1;">
          <input type="text" name="co_authors[]" placeholder="Student name" class="form-control" 
            style="margin: 0;" required>
        </div>
        <button type="button" class="remove-co-author-btn" style="background: #FEE2E2; color: #991B1B; border: none; width: 2.55rem; height: 2.55rem; padding: 0; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex-shrink: 0;">
          <i class="ph ph-x"></i>
        </button>
      `;
      return div;
    }

    // Attach events to existing remove buttons
    document.querySelectorAll('.remove-co-author-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            this.closest('.co-author-input').remove();
        });
    });

    addCoAuthorBtn.addEventListener('click', function(e) {
      e.preventDefault();
      const input = createCoAuthorInput(coAuthorCount++);
      coAuthorsList.appendChild(input);
      input.querySelector('.remove-co-author-btn').addEventListener('click', function(e) {
        e.preventDefault();
        input.remove();
      });
      input.querySelector('input').focus();
    });

    thesisTypeRadios.forEach(radio => {
      radio.addEventListener('change', toggleCoAuthorsSection);
    });
  })();
</script>

<?php require_once __DIR__ . '/../includes/layout_bottom.php'; ?>