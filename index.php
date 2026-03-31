<?php
// Root entrypoint for WTRS.
// `index.html` can remain as the marketing page; main app starts here.

// NOTE: adjust base path if project is deployed under subdir
header('Location: auth/login.php');
exit;
