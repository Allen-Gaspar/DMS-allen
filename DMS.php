<?php
require_once __DIR__ . '/db.php';

class DMS {
    private $db;

    public function __construct() {
        $this->db = get_db();
    }

    public function create($table, $data) {
        $fields = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $fieldList = implode(', ', $fields);

        $sql = "INSERT INTO $table ($fieldList) VALUES ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $types = '';
        $values = [];
        foreach ($data as $value) {
            $types .= is_int($value) ? 'i' : (is_double($value) ? 'd' : 's');
            $values[] = $value;
        }

        $stmt->bind_param($types, ...$values);
        $stmt->execute();

        return (int) $stmt->insert_id;
    }

    public function read($table, $where = [], $order = '', $limit = '') {
        $sql = "SELECT * FROM $table";
        $params = [];
        $types = '';

        if ($where) {
            $conditions = [];
            foreach ($where as $field => $value) {
                $conditions[] = "$field = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : (is_double($value) ? 'd' : 's');
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if ($order !== '') {
            $sql .= ' ORDER BY ' . $order;
        }

        if ($limit !== '') {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function first($table, $where = []) {
        $result = $this->read($table, $where, '', '1');
        return $result[0] ?? null;
    }

        public function update($table, $data, $where = []) {
        $setFields = [];
        $params = [];
        $types = '';

        foreach ($data as $field => $value) {
            $setFields[] = "$field = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : (is_double($value) ? 'd' : 's');
        }

        $sql = "UPDATE $table SET " . implode(', ', $setFields);

        if ($where) {
            $conditions = [];
            foreach ($where as $field => $value) {
                $conditions[] = "$field = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : (is_double($value) ? 'd' : 's');
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return $stmt->affected_rows >= 0;
    }

    public function delete($table, $where = []) {
        $sql = "DELETE FROM $table";
        $params = [];
        $types = '';

        if ($where) {
            $conditions = [];
            foreach ($where as $field => $value) {
                $conditions[] = "$field = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : (is_double($value) ? 'd' : 's');
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $stmt = $this->db->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();

        return $stmt->affected_rows >= 0;
    }

}