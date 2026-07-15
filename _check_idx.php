<?php
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=broker_db', 'root', '');
$rows = $pdo->query("SHOW INDEX FROM investment_request_approvals")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT);
