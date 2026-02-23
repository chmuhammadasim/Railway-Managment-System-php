<?php
// Test password verification

$password = 'password123';
$hash = '$2y$10$e0MYzXyjpJS7Pd0RVvHwHeFtPQPEqFrCdLFLPqJhPBCHQqYdxDJum';

echo "Testing password: $password\n";
echo "Against hash: $hash\n\n";

if (password_verify($password, $hash)) {
    echo "✓ Password verification SUCCESSFUL!\n";
} else {
    echo "✗ Password verification FAILED!\n";
    echo "Creating new hash...\n";
    $new_hash = password_hash($password, PASSWORD_BCRYPT);
    echo "New hash: $new_hash\n";
}
?>
