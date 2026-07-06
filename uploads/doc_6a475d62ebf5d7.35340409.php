<?php
require_once __DIR__ . '/DMS.php';

class Database {
    public function connect() {
        $conn = new mysqli('localhost', 'root', '', 'kiwi_dms');

        if ($conn->connect_error) {
            die('Database connection failed: ' . $conn->connect_error);
        }

        return $conn;
    }
}

function get_db() {
    static $conn = null;

    if ($conn === null) {
        $conn = (new Database())->connect();
    }

    return $conn;
}

function dms() {
    return new DMS();
}

function audit_log($user_id, $action_type, $description) {
    $db = get_db();

    if ($user_id === null) {
        $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action_type, description) VALUES (NULL, ?, ?)');
        $stmt->bind_param('ss', $action_type, $description);
    } else {
        $stmt = $db->prepare('INSERT INTO audit_logs (user_id, action_type, description) VALUES (?, ?, ?)');
        $stmt->bind_param('iss', $user_id, $action_type, $description);
    }

    $stmt->execute();
}