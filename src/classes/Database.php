<?php
// Database Class for Connection and Operations

class Database {
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $user = DB_USER;
    private $password = DB_PASS;
    private $port = DB_PORT;
    private $connection;

    public function connect() {
        $this->connection = new mysqli(
            $this->host,
            $this->user,
            $this->password,
            $this->db_name,
            $this->port
        );

        // Check connection
        if ($this->connection->connect_error) {
            die('Connection Failed: ' . $this->connection->connect_error);
        }

        // Set charset to utf8
        $this->connection->set_charset('utf8');

        // Ensure cancellation columns exist on the bookings table.
        // The original schema added them via MariaDB-only ALTER TABLE … ADD COLUMN IF NOT EXISTS.
        // On MySQL 8 those statements silently fail, so we apply the migration here instead.
        $this->ensureCancellationColumns();

        // Ensure type/related_id columns exist on the notifications table.
        $this->ensureNotificationColumns();

        return $this->connection;
    }

    /**
     * Idempotent migration: add cancellation columns to bookings if missing.
     * Safe to call on every connect – checks information_schema first.
     */
    private function ensureCancellationColumns(): void {
        $db = $this->connection->real_escape_string($this->db_name);
        $columns = [
            'cancellation_reason' => "ALTER TABLE bookings ADD COLUMN cancellation_reason VARCHAR(255) DEFAULT NULL",
            'cancellation_fee'    => "ALTER TABLE bookings ADD COLUMN cancellation_fee    DECIMAL(10,2) DEFAULT 0.00",
            'refund_amount'       => "ALTER TABLE bookings ADD COLUMN refund_amount       DECIMAL(10,2) DEFAULT 0.00",
            'cancelled_at'        => "ALTER TABLE bookings ADD COLUMN cancelled_at        DATETIME DEFAULT NULL",
        ];
        foreach ($columns as $col => $ddl) {
            $check = $this->connection->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'bookings' AND COLUMN_NAME = '{$col}'"
            );
            if ($check && $check->num_rows === 0) {
                $this->connection->query($ddl);
            }
        }
    }

    /**
     * Idempotent migration: add type/related_id columns to notifications if missing.
     */
    private function ensureNotificationColumns(): void {
        $db = $this->connection->real_escape_string($this->db_name);
        $columns = [
            'type'       => "ALTER TABLE notifications ADD COLUMN type       VARCHAR(30)  NOT NULL DEFAULT 'info'",
            'related_id' => "ALTER TABLE notifications ADD COLUMN related_id  INT          NOT NULL DEFAULT 0",
        ];
        foreach ($columns as $col => $ddl) {
            $check = $this->connection->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'notifications' AND COLUMN_NAME = '{$col}'"
            );
            if ($check && $check->num_rows === 0) {
                $this->connection->query($ddl);
            }
        }
    }

    public function getConnection() {
        return $this->connection;
    }

    public function closeConnection() {
        if ($this->connection) {
            $this->connection->close();
        }
    }

    // Execute Select Query
    public function select($query) {
        $result = $this->connection->query($query);
        $data = array();
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                array_push($data, $row);
            }
            return $data;
        } else {
            return false;
        }
    }

    // Execute Single Row Query
    public function selectRow($query) {
        $result = $this->connection->query($query);
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return false;
    }

    // Execute Insert Query
    public function insert($table, $data) {
        $columns = implode(',', array_keys($data));
        $values = implode(',', array_map(function($value) {
            return "'" . $this->connection->real_escape_string($value) . "'";
        }, array_values($data)));

        $query = "INSERT INTO {$table} ({$columns}) VALUES ({$values})";
        
        if ($this->connection->query($query)) {
            return $this->connection->insert_id;
        } else {
            return false;
        }
    }

    // Execute Update Query
    public function update($table, $id_column, $id_value, $data) {
        $set = array();
        
        foreach ($data as $column => $value) {
            $set[] = "{$column} = '" . $this->connection->real_escape_string($value) . "'";
        }
        
        $query = "UPDATE {$table} SET " . implode(',', $set) . " WHERE {$id_column} = '{$id_value}'";
        
        return $this->connection->query($query);
    }

    // Execute Delete Query
    public function delete($table, $id_column, $id_value) {
        $query = "DELETE FROM {$table} WHERE {$id_column} = '{$id_value}'";
        return $this->connection->query($query);
    }

    // Execute Custom Query
    public function query($query) {
        return $this->connection->query($query);
    }

    // Get Last Error
    public function getError() {
        return $this->connection->error;
    }

    // Get Last Insert ID
    public function getLastId() {
        return $this->connection->insert_id;
    }

    // Count Rows
    public function countRows($query) {
        $result = $this->connection->query($query);
        return $result->num_rows;
    }
}
?>
