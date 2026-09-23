<?php
class Database {
    private $host = "127.0.0.1";
    private $db_name = "sgi_inventario";
    private $username = "root";
    private $password = ""; // Contraseña vacía por defecto en WAMP
    private $charset = "utf8mb4";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
        } catch(PDOException $e) {
            header('Content-Type: application/json');
            echo json_encode(["status" => "error", "message" => "Error de conexión: " . $e->getMessage()]);
            exit;
        }
        return $this->conn;
    }

    // Método para consultar columnas existentes de cualquier tabla y evitar errores de columna no encontrada
    public function getTableColumns($table) {
        if (!$this->conn) {
            $this->getConnection();
        }
        try {
            $stmt = $this->conn->query("DESCRIBE `$table`");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }
}