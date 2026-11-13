import React, { useState, useEffect } from 'react';
import Sidebar from '../Sidebar.jsx';

export default function KitchenExpenseReport() {
    const [expenses, setExpenses] = useState([]);

    useEffect(() => {
        fetch('http://localhost:8000/api/kitchens')
            .then(res => res.json())
            .then(data => {
                if(data && !data.error) {
                    setExpenses(data);
                }
            })
            .catch(err => console.error("Error fetching kitchen data: ", err));
    }, []);

    return (
        <div style={{ display: 'flex', minHeight: '100vh', backgroundColor: '#f9fafb', fontFamily: 'sans-serif' }}>
            <Sidebar />
            
            <div style={{ flex: 1, padding: '32px', marginLeft: '250px' }}>
                <div style={{ backgroundColor: '#135c38', color: 'white', padding: '32px', borderRadius: '12px', marginBottom: '24px' }}>
                    <h1 style={{ margin: 0, fontSize: '28px', fontWeight: 'bold' }}>Shared Kitchen Records</h1>
                    <p style={{ margin: '8px 0 0 0', opacity: 0.9 }}>
                        Track and manage facility-specific expenses for Kitchen 1 and Kitchen 2.
                    </p>
                </div>

                <div style={{ backgroundColor: 'white', padding: '24px', borderRadius: '12px', boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }}>
                    <h2 style={{ marginTop: 0, fontSize: '18px', color: '#111827' }}>Expense Summary Report</h2>
                    
                    <table style={{ width: '100%', borderCollapse: 'collapse', marginTop: '16px' }}>
                        <thead>
                            <tr style={{ backgroundColor: '#f3f4f6', textAlign: 'left', color: '#4b5563', fontSize: '14px' }}>
                                <th style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb', borderRadius: '8px 0 0 0' }}>Kitchen Name</th>
                                <th style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb' }}>Expense Date</th>
                                <th style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb' }}>Category</th>
                                <th style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb', borderRadius: '0 8px 0 0' }}>Total Expense (Rs.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            {expenses.length > 0 ? expenses.map((expense, index) => (
                                <tr key={index} style={{ fontSize: '14px', color: '#1f2937' }}>
                                    <td style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb' }}>{expense.kitchen_name}</td>
                                    <td style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb' }}>{expense.expense_date}</td>
                                    <td style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb' }}>{expense.category_name}</td>
                                    <td style={{ padding: '12px 16px', borderBottom: '1px solid #e5e7eb', fontWeight: '500' }}>{expense.amount}</td>
                                </tr>
                            )) : (
                                <tr>
                                    <td colSpan="4" style={{ padding: '24px', textAlign: 'center', color: '#6b7280' }}>
                                        No kitchen expenses recorded yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}