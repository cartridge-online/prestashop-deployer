<?php
// /var/www/html/deploy/deploy.php

session_start();

define('LOG_FILE', __DIR__ . '/deployment.log');
function logAction($action, $details = '', $status = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp][$status] $action";
    if (!empty($details)) {
        $logMessage .= ": $details";
    }
    $logMessage .= "\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
}

// The password hash and GitHub token - will be set on first run
define('PASSWORD_HASH', ''); // Leave empty, will be set on first run
define('GITHUB_TOKEN', ''); // Leave empty, will be set on first run

function isAuthenticated() {
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function executeCommand($command) {
    $output = [];
    $returnVar = 0;
    exec($command . " 2>&1", $output, $returnVar);
    return [
        'output' => $output,
        'status' => $returnVar
    ];
}

// Handle first-time setup
if (empty(PASSWORD_HASH) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['initial_setup'])) {
    logAction('First-time setup initiated');
    $new_password = $_POST['new_password'];
    $github_token = $_POST['github_token'];
    $password_hash = hash('sha256', $new_password);

    try {
        // Update this file with the new password hash and GitHub token
        $current_file = file_get_contents(__FILE__);
        $updated_file = str_replace(
            [
                "define('PASSWORD_HASH', '');",
                "define('GITHUB_TOKEN', '');"
            ],
            [
                "define('PASSWORD_HASH', '$password_hash');",
                "define('GITHUB_TOKEN', '$github_token');"
            ],
            $current_file
        );
        file_put_contents(__FILE__, $updated_file);
        logAction('First-time setup completed', 'Password and token configured successfully');
    } catch (Exception $e) {
        logAction('First-time setup failed', $e->getMessage(), 'ERROR');
    }
    // Redirect to login after setup
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle token update
if (isAuthenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_token'])) {
    logAction('Token update initiated');
    $new_token = $_POST['new_github_token'];

    try{
        // Update this file with the new GitHub token
        $current_file = file_get_contents(__FILE__);
        $updated_file = str_replace(
            "define('GITHUB_TOKEN', '" . GITHUB_TOKEN . "');",
            "define('GITHUB_TOKEN', '$new_token');",
            $current_file
        );
        file_put_contents(__FILE__, $updated_file);
        logAction('Token update completed', 'GitHub token updated successfully');
    } catch (Exception $e) {
        logAction('Token update failed', $e->getMessage(), 'ERROR');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (hash('sha256', $_POST['password']) === PASSWORD_HASH) {
        $_SESSION['authenticated'] = true;
        logAction('Login successful', 'User authenticated');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $error = 'Invalid password';
        logAction('Login failed', 'Invalid password attempt', 'WARNING');
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    logAction('User logged out');
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle pull
if (isAuthenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deploy'])) {
    $repo = $_POST['repo'];
    $branch = $_POST['branch'];
    $target_path = 'var/www/html';

    logAction('Deployment initiated', "Repository: $repo, Branch: $branch");
    // Store deployment info in session
    $_SESSION['last_deployment'] = [
        'repo' => $repo,
        'branch' => $branch
    ];

    // Check if .git directory exists
    if (!is_dir($target_path . '/.git')) {
        logAction('Initializing new git repository');
        // Initialize git and add remote
        $commands = [
            "cd $target_path",
            "git init",
            "git remote add origin https://" . GITHUB_TOKEN . "@github.com/{$repo}.git",
            "git fetch origin",
            "git checkout -b {$branch} origin/{$branch}",
        ];
    } else {
        // Update existing repository
        logAction('Updating existing repository');
        $commands = [
            "cd $target_path",
            "git remote set-url origin https://" . GITHUB_TOKEN . "@github.com/{$repo}.git",
            "git fetch origin",
            "git reset --hard origin/{$branch}",
            "git clean -f -d"
        ];
    }

    $result = executeCommand(implode(" && ", $commands));
    $deploymentResult = $result['status'] === 0 ? 'Success' : 'Failed';
    $output = implode("\n", $result['output']);
    logAction('Deployment completed', "Status: $deploymentResult\nOutput: " . substr($output, 0, 500), $deploymentResult === 'Success' ? 'INFO' : 'ERROR');
}

// Handle push to GitHub
if (isAuthenticated() && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['push'])) {
    $commit_message = $_POST['commit_message'];
    $target_path = 'var/www/html';

    logAction('Push initiated', "Checking git status");

    // First check git status
    $status_command = "cd $target_path && git status --porcelain";
	$result = shell_exec($status_command);
    $status_output = $result ? trim($result) : '' ;

    // Check if we're ahead of origin
    $ahead_command = "cd $target_path && git status -sb";	
    $ahead_output = shell_exec($ahead_command);
    $is_ahead = $ahead_output ? preg_match('/ahead\s+(\d+)/', $ahead_output) : false;
	
	// get the branch from .git
	$git_branch = shell_exec("cd $target_path && git branch --show-current");
	
	if(!$git_branch){
		// Branch is not set
		logAction('Push cancelled', "Branch is not set in .git");
		$pushResult = 'Push Failed';
		$pushOutput = implode("\n", ['Branch is not set in .git']);

	} else if(empty($status_output) && !$is_ahead) {
        // Nothing to commit and not ahead of origin
        logAction('Push cancelled', "No changes to commit and no commits to push");
		$pushResult = 'Push Failed';
		$pushOutput = implode("\n", ['No changes to commit and no commits to push']);
    } else {
		$commands = ["cd $target_path"];

		if (!empty($status_output)) {
			// There are changes to commit
			array_push(
				$commands,
				"git add .",
				"git commit -m " . escapeshellarg($commit_message)
			);
			logAction('Committing changes', "Commit message: $commit_message");
		}

		// Either we have new commits to push or we just created a commit
		
		$commands[] = "git push origin " . $git_branch;
		logAction('Pushing to origin', "Branch: {$git_branch}");
		
		$result = executeCommand(implode(" && ", $commands));
		$pushResult = $result['status'] === 0 ? 'Push Successful' : 'Push Failed';
		$pushOutput = implode("\n", $result['output']);
		logAction('Push completed', "Status: $pushResult\nOutput: " . substr($pushOutput, 0, 500), $pushResult === 'Push Successful' ? 'INFO' : 'ERROR');
	}	
	
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Deployment Panel</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group select { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; }
        .button { background: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; }
        .button:hover { background: #0056b3; }
        .result { margin: 20px 0; padding: 10px; border: 1px solid #ccc; border-radius: 4px; }
        .success { color: green; }
        .error { color: red; }
        .logout { float: right; }
        .token-section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
    </style>
</head>
<body>
    <div class="container">
        <?php if (empty(PASSWORD_HASH)): ?>
            <!-- First-time setup form -->
            <h1>First-time Setup</h1>
            <form method="POST">
                <div class="form-group">
                    <label>Set Deployment Password:</label>
                    <input type="password" name="new_password" required>
                </div>
                <div class="form-group">
                    <label>GitHub Token:</label>
                    <input type="password" name="github_token" required>
                </div>
                <input type="hidden" name="initial_setup" value="1">
                <button type="submit" class="button">Set Password and Token</button>
            </form>

        <?php elseif (!isAuthenticated()): ?>
            <!-- Login form -->
            <h1>Login</h1>
            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="password" required>
                </div>
                <input type="hidden" name="login" value="1">
                <button type="submit" class="button">Login</button>
            </form>

        <?php else: ?>
            <!-- Deployment form -->
            <h1>Deployment Panel</h1>
            <a href="?logout" class="logout">Logout</a>

            <!-- GitHub Token Update Section -->
            <div class="token-section">
                <h3>GitHub Token</h3>
				<div class="form-group">
					<label>Current GitHub Token:</label>
					<input disabled type="text" value="<?php echo GITHUB_TOKEN ?>"/>
				</div>
				
                <form method="POST">
                    <div class="form-group">
                        <label>Update GitHub Token:</label>
                        <input type="password" name="new_github_token" placeholder="Enter new GitHub token">
                    </div>
                    <input type="hidden" name="update_token" value="1">
                    <button type="submit" class="button">Update Token</button>
                </form>
            </div>

            <!-- Deployment Section -->
            <form method="POST">
                <div class="form-group">
                    <label>Repository (format: username/repo):</label>
                    <input type="text" name="repo" value="<?php echo htmlspecialchars($_SESSION['last_deployment']['repo'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label>Branch:</label>
                    <input type="text" name="branch" value="<?php echo htmlspecialchars($_SESSION['last_deployment']['branch'] ?? 'main'); ?>" required>
                </div>
                <input type="hidden" name="deploy" value="1">
                <button type="submit" class="button">Deploy</button>
            </form>

            <?php if (isset($deploymentResult)): ?>
                <div class="result <?php echo $deploymentResult === 'Success' ? 'success' : 'error'; ?>">
                    <h3>Deployment Result: <?php echo $deploymentResult; ?></h3>
                    <pre><?php echo htmlspecialchars($output); ?></pre>
                </div>
            <?php endif; ?>

            <!-- Push Section -->
            <h3>Push Changes to GitHub</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Commit Message:</label>
                    <input type="text" name="commit_message" required>
                </div>
                <input type="hidden" name="push" value="1">
                <button type="submit" class="button">Push to GitHub</button>
            </form>

            <?php if (isset($pushResult)): ?>
                <div class="result <?php echo $pushResult === 'Push Successful' ? 'success' : 'error'; ?>">
                    <h3>Push Result: <?php echo $pushResult; ?></h3>
                    <pre><?php echo htmlspecialchars($pushOutput); ?></pre>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>