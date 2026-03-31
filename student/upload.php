<?php
require_once __DIR__ . '/../includes/session.php';
require_login(['student']);

$user = current_user();
$error = null;
$success = null;

// Fetch advisers for the dropdown
$advStmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE role = 'adviser' AND status = 'active'");
$advStmt->execute();
$advisers = $advStmt->fetchAll();

// Check if thesis exists
$stmt = $pdo->prepare("SELECT id, title, abstract, adviser_id FROM theses WHERE author_id = ?");
$stmt->execute([$user['id']]);
$existingThesis = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $abstract = trim($_POST['abstract'] ?? '');
    $adviser_id = $_POST['adviser_id'] ?? null;
    
    if (empty($title) || empty($abstract) || empty($adviser_id)) {
        $error = "Please fill in all required metadata fields.";
    } elseif (!isset($_FILES['manuscript']) || $_FILES['manuscript']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please upload a valid PDF file.";
    } else {
        $file = $_FILES['manuscript'];
        $fileSize = $file['size'];
        $fileTmp = $file['tmp_name'];
        // basic mime check
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $fileType = finfo_file($finfo, $fileTmp);
        finfo_close($finfo);
        
        if ($fileType !== 'application/pdf') {
            $error = "Only PDF files are allowed.";
        } elseif ($fileSize > 20 * 1024 * 1024) { // 20MB limit
            $error = "File size exceeds the 20MB limit.";
        } else {
            $thesisId = null;
            $versionNum = "1.0";
            
            $uploadDir = __DIR__ . '/../public/uploads/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $fileName = uniqid('thesis_') . '.pdf';
            $destination = $uploadDir . $fileName;
            
            if (move_uploaded_file($fileTmp, $destination)) {
                $pdo->beginTransaction();
                try {
                    if ($existingThesis) {
                        $thesisId = $existingThesis['id'];
                        $updStmt = $pdo->prepare("UPDATE theses SET title = ?, abstract = ?, adviser_id = ?, status = 'pending_review', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $updStmt->execute([$title, $abstract, $adviser_id, $thesisId]);
                        
                        $vStmt = $pdo->prepare("SELECT version_number FROM thesis_versions WHERE thesis_id = ? ORDER BY id DESC LIMIT 1");
                        $vStmt->execute([$thesisId]);
                        $lastV = $vStmt->fetchColumn();
                        if ($lastV) {
                            $versionNum = number_format(floatval($lastV) + 0.1, 1);
                        }
                    } else {
                        $thesisCode = 'THS-' . date('Y') . '-' . strtoupper(substr(uniqid(), -4));
                        $insStmt = $pdo->prepare("INSERT INTO theses (thesis_code, title, abstract, author_id, adviser_id, status) VALUES (?, ?, ?, ?, ?, 'pending_review')");
                        $insStmt->execute([$thesisCode, $title, $abstract, $user['id'], $adviser_id]);
                        $thesisId = $pdo->lastInsertId();
                    }
                    
                    $insVStmt = $pdo->prepare("INSERT INTO thesis_versions (thesis_id, version_number, file_path, file_size, status) VALUES (?, ?, ?, ?, 'pending')");
                    $insVStmt->execute([$thesisId, $versionNum, $fileName, $fileSize]);
                    
                    $pdo->commit();
                    $success = "Thesis uploaded successfully and is now pending review.";
                    // Reload existing thesis to update placeholders
                    $stmt->execute([$user['id']]);
                    $existingThesis = $stmt->fetch();
                    
                    header("Refresh: 2; URL=index.php");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Database error: " . $e->getMessage();
                    if (file_exists($destination)) unlink($destination);
                }
            } else {
                $error = "Failed to move the uploaded file. Check directory permissions.";
            }
        }
    }
}

// Custom CSS for Upload
ob_start();
?>
<style>
  .upload-container { display: grid; grid-template-columns: 1.5fr 1fr; gap: 2.5rem; }
  
  .upload-card { background: white; border-radius: var(--radius); padding: 2.5rem; border: 1px solid var(--border); box-shadow: var(--shadow-sm); margin-bottom: 2rem; }
  
  .card-header { border-bottom: 2px solid var(--off-white); padding-bottom: 1.25rem; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem; }
  .card-header i { font-size: 1.5rem; color: var(--crimson); }
  .card-header h3 { font-family: var(--font-serif); font-size: 1.5rem; font-weight: 800; color: var(--text-dark); }
  
  .form-group { margin-bottom: 2rem; }
  .form-label { display: block; font-size: 0.7rem; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: var(--text-muted); margin-bottom: 0.75rem; }
  
  /* Dropzone Styling Refined */
  .dropzone-area {
    border: 2px dashed var(--border-strong);
    border-radius: var(--radius);
    padding: 4rem 2rem;
    text-align: center;
    background: var(--off-white);
    transition: all var(--transition);
    cursor: pointer;
    position: relative;
    overflow: hidden;
  }
  .dropzone-area:hover, .dropzone-area.drag-over { border-color: var(--crimson); background: var(--crimson-faint); }
  .dropzone-area i { font-size: 3.5rem; color: var(--crimson); margin-bottom: 1.25rem; display: block; opacity: 0.8; }
  .dropzone-area h4 { font-family: var(--font-serif); font-size: 1.25rem; color: var(--text-dark); margin-bottom: 0.5rem; font-weight: 800; }
  
  .selected-file { margin-top: 1.5rem; padding: 1.25rem; background: white; border: 1px solid var(--border); border-radius: var(--radius-sm); display: none; align-items: center; gap: 1rem; text-align: left; animation: fadeIn 0.3s ease; box-shadow: var(--shadow-sm); }
  .selected-file.active { display: flex; }
  .selected-file i { font-size: 2.5rem; color: #8B0000; margin: 0; }
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
        <h1>Research <span>Registry</span></h1>
        <?php if ($existingThesis): ?>
          <p>Submit a formal revision of your existing research artifact for academic peer evaluation.</p>
        <?php else: ?>
          <p>Initialize your research artifact in the repository and upload your primary manuscript.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Alert Notifications -->
    <?php if ($error): ?>
      <div style="margin-bottom: 2.5rem; padding: 1.25rem 2rem; border-radius: var(--radius-sm); font-weight:700; font-size: 0.95rem; color: #fff; background: #991B1B; box-shadow: var(--shadow-md);">
        <i class="ph-bold ph-warning-circle" style="margin-right:0.75rem; font-size:1.2rem; vertical-align:middle;"></i>
        <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div style="margin-bottom: 2.5rem; padding: 1.25rem 2rem; border-radius: var(--radius-sm); font-weight:700; font-size: 0.95rem; color: #fff; background: #065F46; box-shadow: var(--shadow-md);">
        <i class="ph-bold ph-check-circle" style="margin-right:0.75rem; font-size:1.2rem; vertical-align:middle;"></i>
        <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>

    <form action="<?= BASE_URL ?>student/upload.php" method="POST" enctype="multipart/form-data" class="upload-container">

      <!-- LEFT COLUMN: Metadata -->
      <div>
        <div class="upload-card">
          <div class="card-header">
            <i class="ph-fill ph-book-open"></i>
            <h3>Archive Metadata</h3>
          </div>

          <div class="form-group">
            <label class="form-label">Scholarly Title</label>
            <input type="text" name="title" required class="form-control" placeholder="Enter full research title..." value="<?= htmlspecialchars($existingThesis['title'] ?? '') ?>">
          </div>

          <div class="form-group">
            <label class="form-label">Academic Abstract</label>
            <textarea name="abstract" required class="form-control" rows="10" placeholder="Summarize objectives, methodology, and primary outcomes..."><?= htmlspecialchars($existingThesis['abstract'] ?? '') ?></textarea>
          </div>

          <div style="display: grid; grid-template-columns: 1.25fr 1fr; gap: 2rem;">
            <div class="form-group">
              <label class="form-label">Research Adviser</label>
              <select name="adviser_id" required class="form-control">
                <option value="">Select faculty member</option>
                <?php foreach ($advisers as $adv): ?>
                  <option value="<?= $adv['id'] ?>" <?= (isset($existingThesis['adviser_id']) && $existingThesis['adviser_id'] == $adv['id']) ? 'selected' : '' ?>>
                    Dr. <?= htmlspecialchars($adv['last_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Index Keywords</label>
              <input type="text" class="form-control" placeholder="e.g. AI, WMSU, Thesis">
            </div>
          </div>
        </div>
      </div>

      <!-- RIGHT COLUMN: File & Submit -->
      <div>
        <div class="upload-card">
          <div class="card-header">
            <i class="ph-fill ph-file-pdf"></i>
            <h3>Manuscript Document</h3>
          </div>

          <div class="dropzone-area" id="dropzone">
             <i class="ph-fill ph-cloud-arrow-up"></i>
             <h4>Dispatch PDF</h4>
             <p>or drag and drop validated file</p>
             <input type="file" name="manuscript" required accept="application/pdf" class="file-input" id="fileInput">
          </div>

          <div class="selected-file" id="selectedFile">
             <i class="ph-fill ph-file-pdf"></i>
             <div class="selected-file-info">
               <div class="selected-file-name" id="fileName">thesis_manuscript.pdf</div>
               <div class="selected-file-size" id="fileSize">2.4 MB</div>
             </div>
             <button type="button" class="icon-btn" id="removeFile"><i class="ph ph-x"></i></button>
          </div>

          <div style="margin-top: 2rem; text-align: center; color: var(--text-muted); font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em;">
             Max Payload: 20MB &bull; Format: PDF
          </div>
        </div>

        <div class="policy-box">
           <div class="policy-title"><i class="ph-fill ph-shield-check"></i> Integrity Affirmation</div>
           <p class="policy-text">
             "I hereby certify that this manuscript constitutes original scholarly work and adheres strictly to the Western Mindanao State University academic integrity policies."
           </p>
        </div>

        <div style="display: flex; flex-direction: column; gap: 1rem;">
          <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 1.25rem; font-weight: 800; font-size: 1rem;">
             <i class="ph-bold ph-upload-simple"></i> <?= $existingThesis ? 'DISPATCH REVISION' : 'INITIALIZE REGISTRY' ?>
          </button>
          <a href="index.php" class="btn btn-secondary" style="width: 100%; justify-content: center; text-decoration: none; padding: 1.25rem; font-weight: 700;">
             ABORT AND RETURN
          </a>
        </div>
      </div>

    </form>

  </main>

<?php
ob_start();
?>
<script>
  const dropzone = document.getElementById('dropzone');
  const fileInput = document.getElementById('fileInput');
  const selectedFile = document.getElementById('selectedFile');
  const fileNameDisp = document.getElementById('fileName');
  const fileSizeDisp = document.getElementById('fileSize');
  const removeBtn = document.getElementById('removeFile');

  fileInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
      const file = this.files[0];
      if (file.type !== 'application/pdf') {
        alert('Please select a valid PDF manuscript.');
        this.value = '';
        return;
      }
      fileNameDisp.textContent = file.name;
      fileSizeDisp.textContent = (file.size / (1024 * 1024)).toFixed(2) + ' MB';
      dropzone.style.display = 'none';
      selectedFile.classList.add('active');
    }
  });

  removeBtn.addEventListener('click', function() {
    fileInput.value = '';
    selectedFile.classList.remove('active');
    dropzone.style.display = 'block';
  });

  dropzone.addEventListener('dragover', (e) => { e.preventDefault(); dropzone.classList.add('drag-over'); });
  dropzone.addEventListener('dragleave', () => { dropzone.classList.remove('drag-over'); });
  dropzone.addEventListener('drop', (e) => { e.preventDefault(); dropzone.classList.remove('drag-over'); });
</script>
<?php
$extraScripts = ob_get_clean();
require_once __DIR__ . '/../includes/layout_bottom.php';
?>
