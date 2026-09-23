<?php
// C:\wamp64\www\sgi_inventario\api\config\db.php

$host     = 'localhost';
$dbname   = 'sgi_inventario'; // Cambia esto por el nombre exacto de tu Base de Datos en phpMyAdmin
$username = 'root';
$password = '';               // En WAMP por defecto la contraseña de root va vacía

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}