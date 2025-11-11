<?php
session_start();
$ref = $_SESSION['qrf_ref'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Request received - Vusana Funeral Services</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
  <style>body{padding:40px;text-align:center;}</style>
</head>
<body>
  <div class="container">
    <div class="col-md-8 mx-auto">
      <h1 class="mb-3">Thank you</h1>
      <?php if ($ref): ?>
        <p>Your request has been received. <strong>Reference number: <?php echo htmlspecialchars($ref); ?></strong></p>
        <p>Our coordinator will contact you shortly. If this is urgent, call <strong>010 123 4567</strong>.</p>
      <?php else: ?>
        <p>Your request has been received. Our coordinator will contact you shortly.</p>
      <?php endif; ?>
      <a href="page10.html" class="btn btn-primary">Return to site</a>
    </div>
  </div>
</body>
</html>
