<?php
/**
 * AuditLog.php – Lightweight audit trail helper.
 * Usage:  AuditLog::log($db, 'UPDATE_BOOKING', 'bookings', 'Changed status to confirmed', 42);
 */
class AuditLog {

    /**
     * Write one audit entry. Safe to call from anywhere.
     *
     * @param Database $db
     * @param string   $action      e.g. CREATE_TRAIN, UPDATE_BOOKING, DELETE_USER
     * @param string   $module      e.g. trains, bookings, users, cargo, routes
     * @param string   $description Human-readable sentence
     * @param int|null $record_id   PK of the affected row
     * @param string   $old_value   JSON or plain text snapshot before change
     * @param string   $new_value   JSON or plain text snapshot after change
     */
    public static function log(
        $db,
        string $action,
        string $module,
        string $description  = '',
        ?int   $record_id    = null,
        string $old_value    = '',
        string $new_value    = ''
    ): void {
        $conn = $db->getConnection();
        if (!$conn) return;

        $user_id   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $user_role = $conn->real_escape_string($_SESSION['role'] ?? 'unknown');
        $action_e  = $conn->real_escape_string($action);
        $module_e  = $conn->real_escape_string($module);
        $desc_e    = $conn->real_escape_string($description);
        $old_e     = $conn->real_escape_string($old_value);
        $new_e     = $conn->real_escape_string($new_value);
        $rec_sql   = $record_id !== null ? (int)$record_id : 'NULL';
        $ip        = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
        $uid_sql   = $user_id  !== null ? $user_id : 'NULL';

        $conn->query(
            "INSERT INTO audit_logs
                (user_id, user_role, action, module, description, old_value, new_value, record_id, ip_address)
             VALUES
                ({$uid_sql}, '{$user_role}', '{$action_e}', '{$module_e}',
                 '{$desc_e}', '{$old_e}', '{$new_e}', {$rec_sql}, '{$ip}')"
        );
    }
}
?>
