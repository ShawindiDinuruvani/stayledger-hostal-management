<?php

namespace App\Http\Controllers;

use DateTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
use PDOException;

class ExpenseController extends Controller
{
    private function pdo(): PDO
    {
        return DB::connection()->getPdo();
    }

    public function index()
    {
        $stmt = $this->pdo()->query("
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

        return response()->json($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function store(Request $request)
    {
        try {
            $description = trim((string) $request->input('description', ''));
            $category = trim((string) $request->input('category', ''));
            $facility = trim((string) $request->input('facility', ''));
            $date = trim((string) $request->input('date', ''));
            $amount = filter_var($request->input('amount'), FILTER_VALIDATE_FLOAT);

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

            $stmt = $this->pdo()->prepare("
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

            return response()->json([
                'message' => 'Expense saved.',
                'expense' => $stmt->fetch(PDO::FETCH_ASSOC),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PDOException $e) {
            return response()->json(['message' => 'Could not save the expense.'], 500);
        }
    }
}