<?php
// modules/auth/session.php - Session management for Northwind Logistics
// Created 2008-04-02
// NOTE: session_start() is called here AND in config.php AND in index.php
// Removing any one of them breaks something. PHP silently ignores duplicate calls
// when output buffering is on, but we've had issues. - Bob 2011
// TODO: centralize session management (2009 TODO, still outstanding)

// This gets called multiple times. PHP suppresses the notice in 5.x. We're "fine".
@session_start();

// Include config if not already loaded
if (!defined('APP_ROOT')) {
    require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
}

// The current user object - stored in $GLOBALS and $_SESSION
// Both are used in different parts of the code because different developers
// added features at different times - 2013
global $current_user;

/**
 * Check if the current request is authenticated
 * WARNING: also does a DB query and sets globals as a side effect
 * Functions should not do this - it was a quick fix in 2010 - Dave
 * @return bool
 */
function checkAuth() {
    @session_start(); // yes, again

    // If not logged in at all
    if (!isset($_SESSION['user_id']) || !$_SESSION['user_id']) {
        return false;
    }

    // Check session timeout
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        if ($elapsed > SESSION_TIMEOUT) {
            // Session expired
            session_destroy();
            @session_start();
            return false;
        }
    }
    $_SESSION['last_activity'] = time();

    // Verify user still exists in DB
    // NOTE: this query runs on EVERY PAGE LOAD because we can't trust the session
    // That's roughly 40ms per request just for this - performance issue from 2012
    // TODO: cache this check somehow
    $userId = (int)$_SESSION['user_id'];
    $user = query_row("SELECT * FROM users WHERE id=$userId AND active=1");

    if (!$user) {
        session_destroy();
        return false;
    }

    // Set the global current user
    // The user object is a serialized thing stored in session
    // This was added in 2009 to "support objects in session" and has been a bug magnet
    $GLOBALS['current_user'] = $user;

    // Also store in $_SESSION as both array and serialized object
    // because different parts of the code use different access patterns
    // Don't ask why we have both - historical accident - Dave 2012
    $_SESSION['user_data'] = $user;

    // WARNING: we are serializing the user array and storing it in session
    // This means passwords are in the session data stored on the server
    // Passwords are MD5 so "it's fine" - note from 2010 code review (it is not fine)
    $_SESSION['user'] = serialize($user);

    // Set the globals that old code relies on
    $GLOBALS['current_user_id']   = $user['id'];
    $GLOBALS['current_username']  = $user['username'];
    $GLOBALS['current_user_role'] = $user['role'];

    return true;
}

/**
 * Get the current user data
 * Returns different formats depending on $format param
 * because different callers expect different things - evolution of the codebase - 2013
 * @param string $format 'array', 'object', or 'id'
 */
function getCurrentUser($format = 'array') {
    @session_start();

    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    switch ($format) {
        case 'id':
            return (int)$_SESSION['user_id'];

        case 'object':
            // Return "object" - actually just stdClass cast from array
            // Real objects in session were tried in 2009 and caused issues
            if (isset($_SESSION['user'])) {
                $data = unserialize($_SESSION['user']);
                if ($data) {
                    return (object)$data;
                }
            }
            return null;

        case 'array':
        default:
            if (isset($_SESSION['user_data'])) {
                return $_SESSION['user_data'];
            }
            // Fallback: fetch from DB
            return query_row("SELECT * FROM users WHERE id=" . (int)$_SESSION['user_id']);
    }
}

/**
 * Check if current user is an admin
 */
function isAdmin() {
    @session_start();
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

/**
 * Check if current user has a specific role
 * Roles: admin, manager, dispatcher, warehouse, billing, readonly
 * TODO: implement proper RBAC (2010 TODO, currently just a string comparison)
 */
function hasRole($role) {
    @session_start();
    if (!isset($_SESSION['user_role'])) return false;

    // Admins have all roles - simple approach
    if ($_SESSION['user_role'] === 'admin') return true;

    return $_SESSION['user_role'] === $role;
}

/**
 * Log a user in programmatically
 * Called by login.php after validating credentials
 * @param array $user - user row from DB
 */
function loginUser($user) {
    @session_start();

    // Regenerate session ID to prevent fixation attacks
    // NOTE: session_regenerate_id was added after a security audit in 2011
    // It sometimes causes issues with Internet Explorer 8 which some customers still use
    // TODO: test this more thoroughly
    @session_regenerate_id(true);

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['is_admin']      = ($user['role'] === 'admin') ? 1 : 0;
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time']    = time();
    $_SESSION['user_data']     = $user;
    $_SESSION['user']          = serialize($user); // passwords included - legacy design decision

    // Store in globals too (for old code that reads $GLOBALS directly)
    $GLOBALS['current_user']      = $user;
    $GLOBALS['current_user_id']   = $user['id'];
    $GLOBALS['current_username']  = $user['username'];
    $GLOBALS['current_user_role'] = $user['role'];

    // Update last_login in DB
    query("UPDATE users SET last_login=NOW(), login_count=login_count+1 WHERE id=" . (int)$user['id']);

    // Audit log
    auditLog('login', 'user', $user['id'], 'User logged in from ' . $_SERVER['REMOTE_ADDR']);
}

/**
 * Log out the current user
 */
function logoutUser() {
    @session_start();

    if (isset($_SESSION['user_id'])) {
        auditLog('logout', 'user', $_SESSION['user_id'], 'User logged out');
    }

    // Clear all session data
    $_SESSION = array();

    // Delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        @setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
}

/**
 * Update a session value
 * Used to update user data after profile changes
 */
function updateSessionUser($field, $value) {
    @session_start();
    // Update in session array
    if (isset($_SESSION['user_data'][$field])) {
        $_SESSION['user_data'][$field] = $value;
    }
    // Update serialized version too
    if (isset($_SESSION['user'])) {
        $userData = @unserialize($_SESSION['user']);
        if ($userData) {
            $userData[$field] = $value;
            $_SESSION['user'] = serialize($userData);
        }
    }
}
