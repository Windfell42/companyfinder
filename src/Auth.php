<?php
namespace CompanyFinder;

/**
 * Minimal session-based authentication for the dashboard and its write
 * endpoints. Credentials live in config (`auth`), ideally overridden by a
 * gitignored auth.local.php written via bin/set-password.php.
 */
class Auth
{
    /** @param array<string,mixed> $config the `auth` config block */
    public function __construct(private array $config) {}

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    /** Start (or resume) the session. Safe to call repeatedly. */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name($this->config['session_name'] ?? 'companyfinder_session');
            session_start();
        }
    }

    /** Is the current visitor authenticated (or is auth disabled)? */
    public function check(): bool
    {
        if (!$this->enabled()) {
            return true;
        }
        $this->start();
        return !empty($_SESSION['cf_authed']);
    }

    /** Validate a username/password pair against the configured credentials. */
    public function attempt(string $username, string $password): bool
    {
        $userOk = hash_equals((string) ($this->config['username'] ?? ''), $username);
        $passOk = password_verify($password, (string) ($this->config['password_hash'] ?? ''));
        if ($userOk && $passOk) {
            $this->start();
            session_regenerate_id(true);
            $_SESSION['cf_authed'] = true;
            $_SESSION['cf_user'] = $username;
            return true;
        }
        return false;
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /**
     * Guard a browser page: redirect unauthenticated visitors to the login
     * form (preserving where they were headed) and stop execution.
     */
    public function requireWeb(string $loginUrl = 'login.php'): void
    {
        if ($this->check()) {
            return;
        }
        $target = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: ' . $loginUrl . '?next=' . rawurlencode($target));
        exit;
    }

    /**
     * Guard a JSON endpoint: emit 401 and stop for unauthenticated callers.
     */
    public function requireApi(): void
    {
        if ($this->check()) {
            return;
        }
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required', 'login' => true]);
        exit;
    }
}
