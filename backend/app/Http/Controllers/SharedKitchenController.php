<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use PDO;

class SharedKitchenController extends Controller
{
    public function index()
    {
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->query("SELECT * FROM kitchen_expense_summary_view ORDER BY expense_date DESC");

        return response()->json($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}