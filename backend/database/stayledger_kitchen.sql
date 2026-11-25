-- 1. Kitchen-Floor Relationship Table
CREATE TABLE IF NOT EXISTS kitchens (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE, -- e.g., 'Kitchen 1', 'Kitchen 2'
    floor_id INT NOT NULL,
    FOREIGN KEY (floor_id) REFERENCES floors(floor_id)
);

-- Optional link column on hostel_expenses
ALTER TABLE hostel_expenses
ADD COLUMN IF NOT EXISTS kitchen_id INT NULL REFERENCES kitchens(id);

-- 2. Kitchen Expense Summary View
CREATE OR REPLACE VIEW kitchen_expense_summary_view AS
SELECT 
    COALESCE(k.name, e.facility) AS kitchen_name, 
    e.expense_date, 
    e.category,
    SUM(e.amount) AS total_expense
FROM hostel_expenses e
LEFT JOIN kitchens k 
    ON e.kitchen_id = k.id OR LOWER(TRIM(e.facility)) = LOWER(TRIM(k.name))
WHERE e.kitchen_id IS NOT NULL 
   OR LOWER(COALESCE(e.facility, '')) LIKE '%kitchen%'
GROUP BY COALESCE(k.name, e.facility), e.expense_date, e.category;