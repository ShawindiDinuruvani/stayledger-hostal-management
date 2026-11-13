
-- 1. Kitchen-Floor Relationship Table (Table creation)
CREATE TABLE kitchens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL, -- e.g., 'Kitchen 1', 'Kitchen 2'
    floor_id INT NOT NULL,
    FOREIGN KEY (floor_id) REFERENCES floors(id)
);

-- 2. Kitchen Expense Summary View (Database contribution)
CREATE VIEW kitchen_expense_summary_view AS
SELECT 
    k.name AS kitchen_name, 
    e.date AS expense_date, 
    c.name AS category,
    SUM(e.amount) AS total_expense
FROM expenses e
JOIN kitchens k ON e.kitchen_id = k.id
JOIN expense_categories c ON e.category_id = c.id
GROUP BY k.name, e.date, c.name;