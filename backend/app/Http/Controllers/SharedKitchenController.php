<?php


class SharedKitchenController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function handleRequest($method, $uri, $input) {
        if ($method === 'GET') {
            // Get Kitchen Expense Summary
            $stmt = $this->pdo->query("SELECT * FROM kitchen_expense_summary_view ORDER BY expense_date DESC");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: application/json');
            echo json_encode($data);
        }
    }
}