import React, { useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  CalendarDays,
  Check,
  Download,
  Plus,
  Receipt,
  Search,
  ShieldCheck,
  X
} from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import '../allocations/allocations.css';
import './expenses.css';

const API_BASE = 'http://localhost:8000/api';

const todayISO = () => new Date().toLocaleDateString('en-CA');

const formatDate = date =>
  new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', {
    day: '2-digit',
    month: 'short',
    year: 'numeric'
  });

const formatAmount = amount => `LKR ${Number(amount).toLocaleString('en-US')}`;

const emptyForm = () => ({
  description: '',
  category: '',
  facility: '',
  amount: '',
  date: todayISO()
});

export default function Expenses() {
  const [expenses, setExpenses] = useState([]);
  const [period, setPeriod] = useState(() => todayISO().slice(0, 7));
  const [search, setSearch] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [toast, setToast] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [apiError, setApiError] = useState('');
  const [form, setForm] = useState(emptyForm);

  useEffect(() => {
    let ignore = false;

    const loadExpenses = async () => {
      setLoading(true);
      setApiError('');

      try {
        const res = await fetch(`${API_BASE}/expenses`);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (!ignore) setExpenses(Array.isArray(data) ? data : []);
      } catch {
        if (!ignore) {
          setApiError('Could not load expenses. Make sure the PHP API is running on localhost:8000.');
        }
      } finally {
        if (!ignore) setLoading(false);
      }
    };

    loadExpenses();
    return () => {
      ignore = true;
    };
  }, []);

  const categories = useMemo(
    () => [...new Set(expenses.map(expense => expense.category).filter(Boolean))],
    [expenses]
  );

  const facilities = useMemo(
    () => [...new Set(expenses.map(expense => expense.facility).filter(Boolean))],
    [expenses]
  );

  const showToast = message => {
    setToast(message);
    setTimeout(() => setToast(''), 4000);
  };

  const filteredExpenses = useMemo(() => {
    const query = search.trim().toLowerCase();

    return expenses
      .filter(expense => expense.date.startsWith(period))
      .filter(expense => {
        if (!query) return true;
        return [expense.description, expense.category, expense.facility]
          .join(' ')
          .toLowerCase()
          .includes(query);
      })
      .sort((a, b) => b.date.localeCompare(a.date));
  }, [expenses, period, search]);

  const openModal = () => {
    setForm(emptyForm());
    setApiError('');
    setModalOpen(true);
  };

  const addExpense = async event => {
    event.preventDefault();
    if (saving) return;
    setSaving(true);
    setApiError('');

    try {
      const res = await fetch(`${API_BASE}/expenses`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          description: form.description.trim(),
          category: form.category.trim(),
          facility: form.facility.trim(),
          amount: Number(form.amount),
          date: form.date
        })
      });
      const data = await res.json().catch(() => ({}));

      if (!res.ok) throw new Error(data.message || `HTTP ${res.status}`);
      if (!data.expense) throw new Error('The API did not return the saved expense.');

      setExpenses(current => [data.expense, ...current]);
      setPeriod(data.expense.date.slice(0, 7));
      setModalOpen(false);
      showToast('Expense saved.');
    } catch (error) {
      setApiError(error.message || 'Could not save the expense.');
    } finally {
      setSaving(false);
    }
  };

  const exportCSV = () => {
    const headers = ['Description', 'Category', 'Facility', 'Amount', 'Date'];
    const rows = filteredExpenses.map(expense => [
      expense.description,
      expense.category,
      expense.facility,
      formatAmount(expense.amount),
      formatDate(expense.date)
    ]);
    const csv = [
      headers.join(','),
      ...rows.map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(','))
    ].join('\n');

    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = `stayledger-expenses-${period}.csv`;
    link.click();
    showToast('Exported expense records to CSV.');
  };

  return (
    <div className="sl-layout">
      <Sidebar />

      <main className="sl-workspace">
        <div className="sl-topbar">
          <div className="sl-breadcrumb">
            Workspace / <span>Expenses</span>
          </div>
          <div className="sl-topbar-right">
            <span className="sl-demo-tag"><i />Expense workspace</span>
            <div className="sl-user-pill">SD</div>
          </div>
        </div>

        <header className="sl-exp-heading">
          <div>
            <div className="sl-eyebrow"><span />EMARALD GIRLS HOSTEL</div>
            <h1>Expenses</h1>
            <p>A clearer picture of where every rupee goes.</p>
          </div>
          <div className="sl-header-actions">
            <label className="sl-period">
              <CalendarDays size={16} />
              <input
                type="month"
                aria-label="Reporting month"
                value={period}
                onChange={event => setPeriod(event.target.value)}
              />
            </label>
            <button className="sl-btn sl-btn-primary" onClick={openModal}>
              <Plus size={16} /> Add expense
            </button>
          </div>
        </header>

        <section className="sl-exp-intro">
          <span className="sl-exp-intro-icon"><Receipt size={22} /></span>
          <div>
            <h2>Everything you need, in one place.</h2>
            <p>Search, review and manage your expenses with a little more ease.</p>
          </div>
        </section>

        {apiError && !modalOpen && (
          <div className="sl-alert">
            <AlertCircle size={16} />
            <span>{apiError}</span>
          </div>
        )}

        <section className="sl-panel">
          <div className="sl-panel-header sl-exp-panel-head">
            <div>
              <h2>Expenses overview</h2>
              <p>{filteredExpenses.length} {filteredExpenses.length === 1 ? 'record' : 'records'} in this view</p>
            </div>
            <label className="sl-search">
              <Search size={15} />
              <input
                type="search"
                placeholder="Search expenses..."
                value={search}
                onChange={event => setSearch(event.target.value)}
              />
            </label>
          </div>

          <div className="sl-table-wrap">
            <table className="sl-table">
              <thead>
                <tr>
                  <th>Description</th>
                  <th>Category</th>
                  <th>Facility</th>
                  <th>Amount</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  <tr>
                    <td colSpan="5" className="sl-exp-empty">Loading expenses...</td>
                  </tr>
                ) : filteredExpenses.length > 0 ? (
                  filteredExpenses.map(expense => (
                    <tr key={expense.id}>
                      <td><strong>{expense.description}</strong></td>
                      <td>{expense.category}</td>
                      <td>{expense.facility}</td>
                      <td className="sl-exp-amount">{formatAmount(expense.amount)}</td>
                      <td>{formatDate(expense.date)}</td>
                    </tr>
                  ))
                ) : (
                  <tr>
                    <td colSpan="5" className="sl-exp-empty">
                      {search
                        ? 'No expenses match your search.'
                        : 'No expenses for this month yet. Add your first expense to get started.'}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="sl-exp-panel-footer">
            <span>Stored in the database</span>
            <button className="sl-exp-export" onClick={exportCSV}>
              <Download size={14} /> Export CSV
            </button>
          </div>
        </section>

        <p className="sl-exp-note">
          <ShieldCheck size={14} />
          Expenses are stored in the hostel database.
        </p>

        <footer className="sl-exp-page-footer">
          <span>StayLedger <i /> Thoughtfully made for everyday living.</span>
          <span>Emarald Girls Hostel</span>
        </footer>
      </main>

      {modalOpen && (
        <div className="sl-modal-backdrop" onClick={() => setModalOpen(false)}>
          <section
            className="sl-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="expense-modal-title"
            onClick={event => event.stopPropagation()}
          >
            <div className="sl-modal-top">
              <div>
                <span className="sl-eyebrow"><span />STAYLEDGER</span>
                <h3 id="expense-modal-title">Add expense</h3>
              </div>
              <button className="sl-close-btn" aria-label="Close dialog" onClick={() => setModalOpen(false)}>
                <X size={20} />
              </button>
            </div>

            <form className="sl-form" onSubmit={addExpense}>
              {apiError && (
                <div className="sl-alert">
                  <AlertCircle size={16} />
                  <span>{apiError}</span>
                </div>
              )}

              <label>
                Description
                <input
                  type="text"
                  required
                  placeholder="e.g. Kitchen tap repair"
                  value={form.description}
                  onChange={event => setForm({ ...form, description: event.target.value })}
                />
              </label>

              <label>
                Category
                <input
                  type="text"
                  required
                  list="expense-categories"
                  placeholder="e.g. Maintenance"
                  value={form.category}
                  onChange={event => setForm({ ...form, category: event.target.value })}
                />
                <datalist id="expense-categories">
                  {categories.map(category => (
                    <option key={category} value={category} />
                  ))}
                </datalist>
              </label>

              <label>
                Facility
                <input
                  type="text"
                  required
                  list="expense-facilities"
                  placeholder="e.g. Kitchen 1"
                  value={form.facility}
                  onChange={event => setForm({ ...form, facility: event.target.value })}
                />
                <datalist id="expense-facilities">
                  {facilities.map(facility => (
                    <option key={facility} value={facility} />
                  ))}
                </datalist>
              </label>

              <label>
                Amount (LKR)
                <input
                  type="number"
                  required
                  min="1"
                  step="0.01"
                  placeholder="0.00"
                  value={form.amount}
                  onChange={event => setForm({ ...form, amount: event.target.value })}
                />
              </label>

              <label>
                Date
                <input
                  type="date"
                  required
                  value={form.date}
                  onChange={event => setForm({ ...form, date: event.target.value })}
                />
              </label>

              <button type="submit" className="sl-btn sl-btn-primary sl-exp-save" disabled={saving}>
                <Check size={16} /> {saving ? 'Saving...' : 'Save expense'}
              </button>
            </form>
          </section>
        </div>
      )}

      {toast && (
        <div role="status" className="sl-toast">
          <Check size={17} />{toast}
        </div>
      )}
    </div>
  );
}
