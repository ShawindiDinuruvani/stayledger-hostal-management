<?php

class ExpenseController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function handleRequest($method, $uri, $input) {
        if ($uri !== '/api/expenses') {
            http_response_code(404);
            echo json_encode(['message' => 'API Endpoint not found']);
            return;
        }

        if ($method === 'GET') {
            $stmt = $this->pdo->query("
                SELECT
                    expense_id AS id,
                    description,
                    category,
                    facility,
                    amount,
                    TO_CHAR(expense_date, 'YYYY-MM-DD') AS date
                FROM hostel_expenses
                ORDER BY expense_date DESC, expense_id DESC
            ");

            echo json_encode($stmt->fetchAll());
            return;
        }

        if ($method === 'POST') {
            try {
                $description = trim($input['description'] ?? '');
                $category = trim($input['category'] ?? '');
                $facility = trim($input['facility'] ?? '');
                $date = trim($input['date'] ?? '');
                $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT);

                if ($description === '' || $category === '' || $facility === '') {
                    throw new InvalidArgumentException('Description, category and facility are required.');
                }

                if ($amount === false || $amount <= 0) {
                    throw new InvalidArgumentException('Amount must be greater than zero.');
                }

                $parsedDate = DateTime::createFromFormat('Y-m-d', $date);
                if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
                    throw new InvalidArgumentException('A valid expense date is required.');
                }

                $stmt = $this->pdo->prepare("
                    INSERT INTO hostel_expenses (description, category, facility, amount, expense_date)
                    VALUES (?, ?, ?, ?, ?)
                    RETURNING
                        expense_id AS id,
                        description,
                        category,
                        facility,
                        amount,
                        TO_CHAR(expense_date, 'YYYY-MM-DD') AS date
                ");
                $stmt->execute([$description, $category, $facility, $amount, $date]);

                http_response_code(201);
                echo json_encode([
                    'message' => 'Expense saved.',
                    'expense' => $stmt->fetch()
                ]);
            } catch (InvalidArgumentException $e) {
                http_response_code(422);
                echo json_encode(['message' => $e->getMessage()]);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['message' => 'Could not save the expense.']);
            }
            return;
        }

        http_response_code(405);
        echo json_encode(['message' => 'Method not allowed']);
    }
}
