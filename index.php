<?php
// index.php - Premium Login Gateway
?>
<!DOCTYPE html>
<html lang="en">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Vy CRM - Secure Login</title>
	<!-- Icons -->
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
	<link href="/assets/css/styles.css" rel="stylesheet">
</head>

<body>
	<div class="login-wrapper">
		<div class="login-left">
			<div class="login-card">
				<div class="brand-logo">
					<img src="images/logo.png" alt="Vy CRM">
				</div>
				<h2 class="login-title">Welcome Back</h2>
				<p class="login-subtitle">Enter your credentials to access the CRM</p>

				<!-- For aesthetic mockup, direct action to dashboard -->
				<form action="dashboard.php" method="GET">
					<div class="form-group">
						<label class="form-label">Email Address</label>
						<input type="email" class="form-control" name="email" placeholder="admin@vycrm.com" required>
					</div>
					<div class="form-group mb-4">
						<div class="flex justify-between items-center mb-1">
							<label class="form-label mb-0">Password</label>
							<a href="#" class="text-sm text-muted">Forgot password?</a>
						</div>
						<input type="password" class="form-control" name="password" placeholder="••••••••" required>
					</div>

					<button type="submit" class="btn-primary">Sign In</button>
				</form>
			</div>
		</div>
		<div class="login-right">
			<!-- Decorative Right Panel -->
			<div style="text-align:center; color:white;">
				<h1 style="font-size: 48px; margin-bottom: 20px; color:white;">Vy CRM</h1>
				<p style="font-size: 18px; opacity: 0.8; max-width: 400px; line-height: 1.6;">The next generation of
					customer relationship management. Faster, smarter, and infinitely more powerful.</p>
			</div>
		</div>
	</div>
</body>

</html>