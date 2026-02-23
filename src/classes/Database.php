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

        return $this->connection;
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
