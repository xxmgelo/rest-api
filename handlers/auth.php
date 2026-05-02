<?php

/**
 * Handler: Authentication
 * POST /api/auth/login  — returns a Bearer token
 * POST /api/auth/logout — revokes the current token
 */

function handleAuthLogin(PDO $pdo, array $body): void
{
    $login = trim((string) ($body['login'] ?? $body['email'] ?? $body['username'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($login === '' || $password === '') {
        respond(400, null, 'login and password are required');
    }

    // Allow login with either username or email.
    $stmt = $pdo->prepare(
        'SELECT * FROM admins
         WHERE username = :username OR email = :email
         LIMIT 1'
    );
    $stmt->execute([
        ':username' => $login,
        ':email' => strtolower($login),
    ]);
    $admin = $stmt->fetch();

    // Use password_verify to check bcrypt hash (timing-safe)
    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        respond(401, null, 'Invalid credentials');
    }

    // Generate a cryptographically secure token
    $token = bin2hex(random_bytes(32)); // 64 hex chars

    // Store token in DB (expires in 24 hours)
    $stmt = $pdo->prepare(
        'INSERT INTO api_tokens (user_id, token, expires_at)
         VALUES (:uid, :token, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $stmt->execute([
        ':uid'   => $admin['id'],
        ':token' => $token,
    ]);

    respond(200, [
        'token'      => $token,
        'token_type' => 'Bearer',
        'expires_in' => '24 hours',
        'user'       => [
            'id'       => (int) $admin['id'],
            'username' => $admin['username'],
            'full_name' => $admin['full_name'],
            'email'    => $admin['email'],
            'role'     => $admin['role'],
        ],
    ]);
}

function handleAuthLogout(PDO $pdo): void
{
    $headers  = getallheaders();
    $auth     = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $rawToken = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';

    if ($rawToken) {
        $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE token = :token');
        $stmt->execute([':token' => $rawToken]);
    }

    respond(200, ['message' => 'Logged out successfully']);
}
