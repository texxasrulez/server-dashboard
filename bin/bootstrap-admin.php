<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

$password = ensure_default_admin(true);
if ($password === null || $password === '') {
    echo "No action: users already exist.\n";
    exit(0);
}

echo "Created default admin user.\n";
echo "Username: admin\n";
echo "Password: {$password}\n";
echo "Sign in and rotate this password immediately.\n";
