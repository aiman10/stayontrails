<?php
// Forwards to the Flask annotator on localhost:5001. The slug filter mirrors
// flask-annotator/slug.py so the same model name reaches the same folder.
$model = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($_GET['model'] ?? '')));
$qs = $model !== '' ? '?model=' . urlencode($model) : '';
header('Location: http://localhost:5001/' . $qs, true, 302);
exit;
