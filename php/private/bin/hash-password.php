<?php
// Create one AUTH_USERS entry:  email|Display Name|base64(bcrypt hash)
// Run on your own computer:  php bin/hash-password.php
// Join several entries with ";". The output contains only a hash, never the password.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function ask(string $q, bool $hidden = false): string
{
    fwrite(STDOUT, $q);
    if ($hidden && DIRECTORY_SEPARATOR === '/') system('stty -echo');
    $line = fgets(STDIN);
    if ($hidden && DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
        fwrite(STDOUT, "\n");
    }
    return rtrim((string) $line, "\r\n");
}

$email = strtolower(trim(ask('Email: ')));
if (!preg_match('/^[^@\s|;]+@[^@\s|;]+$/', $email)) {
    fwrite(STDERR, "That does not look like a valid email.\n");
    exit(1);
}
$name = trim(ask('Display name (e.g. Jonathan Perkins): ')) ?: $email;
if (preg_match('/[|;]/', $name)) {
    fwrite(STDERR, "The name must not contain \"|\" or \";\".\n");
    exit(1);
}
if (DIRECTORY_SEPARATOR !== '/') fwrite(STDOUT, "(On Windows the password is visible while you type.)\n");
$password = ask('Password (min 12 characters): ', true);
if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
if (ask('Repeat password: ', true) !== $password) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}
$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$entry = $email . '|' . $name . '|' . base64_encode($hash);
fwrite(STDOUT, "\nAUTH_USERS entry (join several users with ';'):\n\n$entry\n");
