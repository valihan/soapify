<?php
require 'config.php';

try {
    $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS wsdls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        url VARCHAR(500) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        wsdl_id INT NOT NULL,
        method_name VARCHAR(255) NOT NULL,
        request_name VARCHAR(255) NOT NULL,
        request_xml MEDIUMTEXT NOT NULL,
        response_xml MEDIUMTEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (wsdl_id) REFERENCES wsdls(id) ON DELETE CASCADE
    )");
    
    echo "<h1>Установка завершена!</h1>";
    echo "<p>База данных и таблицы успешно созданы.</p>";
    echo "<p><a href='index.html'>Перейти к приложению</a></p>";
} catch (PDOException $e) {
    echo "<h1>Ошибка установки</h1>";
    echo "<p>Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Пожалуйста, проверьте доступы к MariaDB в файле <b>config.php</b>.</p>";
}
?>
