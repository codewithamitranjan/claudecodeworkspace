<?php
// modules/auth/login.php - Login page for Northwind Logistics
// Created 2008-04-05
// NOTE: no CSRF protection. We know. - code review 2013
// TODO: add CSRF token to login form (2011 - still outstanding)

if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

@session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
    header('Location: index.php?page=dashboard');
    exit;
}

$loginError   = '';
$loginSuccess = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_login'])) {

    // Get POST values - NO sanitization before SQL (that's the point)
    $username = $_POST['username']; // direct from POST
    $password = $_POST['password']; // direct from POST

    // Classic SQL injection vulnerability
    // username: admin'-- would log in as admin with no password
    // This has been in production since 2008. We found it in a 2012 pentest.
    // TODO: fix this with prepared statements (2012 - still not done as of 2013)
    $sql = "SELECT * FROM users WHERE username='" . $username . "' AND password='" . md5($password) . "' AND active=1";

    // If APP_DEBUG, we accidentally log the SQL including the plaintext password
    // This was how we found out about the pentest - Dave 2012
    if (APP_DEBUG) {
        // error_log("LOGIN SQL: " . $sql); // commented out but was here
    }

    $user = query_row($sql);

    if ($user) {
        // Successful login
        loginUser($user);

        // Redirect
        $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php?page=dashboard';
        // NOTE: $redirect is from GET, open redirect possible - pentest finding 2013
        header('Location: ' . $redirect);
        exit;
    } else {
        // Check if user exists at all (information disclosure - 2013 pentest finding)
        $userExists = query_row("SELECT id FROM users WHERE username='" . $username . "'");
        if ($userExists) {
            $loginError = 'Invalid password.'; // tells attacker the username is valid
        } else {
            $loginError = 'Invalid username or password.';
        }

        // Log failed attempt
        $safeUser = mysql_real_escape_string($username);
        $ip = $_SERVER['REMOTE_ADDR'];
        @query("INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                VALUES (0, 'login_failed', 'user', 0, 'Failed login attempt for: $safeUser', '$ip', NOW())");

        // Brute force protection - sort of
        // TODO: implement proper rate limiting (2012 - using session counter which is trivially bypassed)
        if (!isset($_SESSION['failed_logins'])) {
            $_SESSION['failed_logins'] = 0;
        }
        $_SESSION['failed_logins']++;
        if ($_SESSION['failed_logins'] > 5) {
            $loginError .= ' Too many failed attempts. Wait 5 minutes.';
            // NOTE: we don't actually enforce the 5 minute wait - 2013 note
            // TODO: add actual lockout
        }
    }
}

// Show message if redirected here
$msg = isset($_GET['msg']) ? $_GET['msg'] : '';
$reason = isset($_GET['reason']) ? $_GET['reason'] : '';

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
    "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
    <title><?php echo APP_NAME; ?> - Login</title>
    <style type="text/css">
        body { font-family: Arial, sans-serif; background: #f0f0f0; margin: 0; padding: 0; }
        #login-wrapper { width: 380px; margin: 80px auto; }
        #login-box { background: #fff; border: 1px solid #ccc; padding: 25px; }
        #login-box h2 { color: #003366; border-bottom: 2px solid #003366; padding-bottom: 8px; margin-top: 0; }
        .login-logo { text-align: center; background: #003366; color: #fff; padding: 15px; font-size: 18px; font-weight: bold; }
        .form-row { margin-bottom: 12px; }
        .form-row label { display: block; font-weight: bold; margin-bottom: 4px; font-size: 13px; }
        .form-row input[type=text], .form-row input[type=password] {
            width: 100%; padding: 6px; border: 1px solid #ccc; font-size: 13px;
            box-sizing: border-box; -webkit-box-sizing: border-box;
        }
        .btn-login { background: #003366; color: #fff; border: none; padding: 8px 20px;
                     font-size: 14px; cursor: pointer; width: 100%; }
        .btn-login:hover { background: #0055aa; }
        .error-msg  { background: #f2dede; border: 1px solid #a94442; color: #a94442;
                      padding: 8px; margin-bottom: 12px; font-size: 12px; }
        .success-msg { background: #dff0d8; border: 1px solid #3c763d; color: #3c763d;
                       padding: 8px; margin-bottom: 12px; font-size: 12px; }
        .info-msg   { background: #d9edf7; border: 1px solid #31708f; color: #31708f;
                      padding: 8px; margin-bottom: 12px; font-size: 12px; }
        #login-footer { text-align: center; color: #999; font-size: 10px; margin-top: 10px; }
    </style>
</head>
<body>

<div id="login-wrapper">
    <div class="login-logo">
        <?php echo APP_NAME; ?><br/>
        <span style="font-size:12px;font-weight:normal;">Freight &amp; Shipping Management</span>
    </div>
    <div id="login-box">
        <h2>Sign In</h2>

        <?php if ($loginError): ?>
        <div class="error-msg"><?php echo htmlspecialchars($loginError); ?></div>
        <?php endif; ?>

        <?php if ($msg == 'logged_out'): ?>
        <div class="success-msg">You have been successfully logged out.</div>
        <?php endif; ?>

        <?php if ($reason == 'session_expired'): ?>
        <div class="info-msg">Your session has expired. Please log in again.</div>
        <?php endif; ?>

        <!-- No CSRF token - known issue, TODO 2011 -->
        <form method="POST" action="index.php?page=login">
            <div class="form-row">
                <label for="username">Username:</label>
                <!-- Value echoed back without escaping - XSS via reflected username on error -->
                <input type="text" id="username" name="username"
                       value="<?php echo isset($_POST['username']) ? $_POST['username'] : ''; ?>"
                       autocomplete="off" />
            </div>
            <div class="form-row">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" autocomplete="off" />
            </div>
            <div class="form-row">
                <input type="submit" name="submit_login" value="Log In" class="btn-login" />
            </div>
        </form>

        <p style="font-size:11px;color:#999;margin-top:15px;">
            Forgot password? Contact your system administrator.<br/>
            <!-- NOTE: there is no password reset feature. It was going to be added in 2010. -->
        </p>
    </div>
    <div id="login-footer">
        <?php echo APP_NAME; ?> v<?php echo APP_VERSION; ?><br/>
        &copy; <?php echo date('Y'); ?> Northwind Logistics Inc.
    </div>
</div>

</body>
</html>
<?php
// Stop execution here so the main index.php doesn't render its footer/nav
// This is why the login page renders without the nav - it exits the include
// Not the cleanest approach but it works - 2009
exit;
?>
