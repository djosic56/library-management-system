<?php
// header.php
require_once 'functions.php';
require_login();
?>
<style type="text/css">
	.nav-icon { width:20px; height:20px; vertical-align:-2px; margin-right:4px; }
</style>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
	<div class="container-fluid">
		<a class="navbar-brand" href="index.php">Library</a>
		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
			<span class="navbar-toggler-icon"></span>
		</button>
		<div class="collapse navbar-collapse" id="navbarNav">
			<ul class="navbar-nav me-auto">
				<li class="nav-item">               
					<a class="nav-link" href="books.php">
						<svg class="nav-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
						<path d="M4 5h12a2 2 0 0 1 2 2v12H6a2 2 0 0 1-2-2V5Z" stroke="#fff" stroke-width="2" stroke-linecap="round"></path>
						<path d="M8 5v14M16 5v14" stroke="#fff" stroke-width="2" stroke-linecap="round"></path>
						</svg>
						Books
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="authors.php">
						<svg class="nav-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
							<path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
							<path d="M4 21a8 8 0 0 1 16 0" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
						Authors
					</a>
				</li>
				<li class="nav-item">
					<a class="nav-link" href="https://j-sistem.hr/graphycs/index.php" target="_blank">
						<svg class="nav-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
							<path d="M3 3h18v18H3z" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
							<path d="M3 9h18M9 3v18" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
						</svg>
						Graphycs
					</a>
				</li>
				<?php if (is_admin()): ?>
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
						<i class="bi bi-shield-check"></i> Admin
					</a>
					<ul class="dropdown-menu" aria-labelledby="adminDropdown">
						<li>
							<a class="dropdown-item" href="manage_users.php">
								<i class="bi bi-people"></i> Manage Users
							</a>
						</li>
						<li><hr class="dropdown-divider"></li>
						<li>
							<a class="dropdown-item" href="statistics.php">
								<i class="bi bi-graph-up"></i> Statistics
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="users.php">
								<i class="bi bi-clock-history"></i> User Logs
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="backup.php">
								<i class="bi bi-download"></i> Database Backup
							</a>
						</li>
					</ul>
				</li>
				<?php endif; ?>
			</ul>
			<ul class="navbar-nav">
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown"
						aria-expanded="false">
						<i class="bi bi-person-circle"></i> <?=htmlspecialchars($_SESSION['username'])?> (<?=$_SESSION['level']?>)
					</a>
					<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
						<li>
							<a class="dropdown-item" href="change_password.php">
								<i class="bi bi-key"></i> Change Password
							</a>
						</li>
						<li><hr class="dropdown-divider"></li>
						<li>
							<a class="dropdown-item" href="logout.php">
								<i class="bi bi-box-arrow-right"></i> Logout
							</a>
						</li>
					</ul>
				</li>
			</ul>
		</div>
	</div>
</nav>