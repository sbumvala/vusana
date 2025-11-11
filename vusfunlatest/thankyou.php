<?php
session_start();
$ref = $_SESSION['vfs_ref'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Application Received - Vusana Funeral Service</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
  <style>body{padding:40px;text-align:center;}</style>
</head>
<body>
  <div class="container">
    <div class="col-md-8 mx-auto">
      <h1 class="mb-3">Thank you</h1>
      <?php if ($ref): ?>
        <p>Your application has been received. <strong>Reference number: <?php echo htmlspecialchars($ref); ?></strong></p>
        <p>A confirmation email has been sent to the address you provided. Our consultant will contact you within 24–48 hours.</p>
      <?php else: ?>
        <p>Your application has been received. Our consultant will contact you shortly.</p>
      <?php endif; ?>
      <p>If this is urgent, call <strong>010 123 4567</strong>.</p>
      <a href="index.html" class="btn btn-primary">Return to Home</a>
    </div>
  </div>
</body>
</html>

