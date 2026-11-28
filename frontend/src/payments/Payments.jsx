import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertCircle, Calendar, CheckCircle2, ChevronLeft, ChevronRight, CreditCard, Edit3, Plus, RefreshCw, Search, Undo2 } from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import { fetchWithAuth } from '../users/authFetch.js';
import RecordPaymentModal from './RecordPaymentModal.jsx';
import '../allocations/allocations.css';
import '../fees/fees.css';
import './payments.css';

const API_BASE = (import.meta.env.VITE_LARAVEL_API_URL || '/fees-api').replace(/\/+$/, '');

const money = value => `LKR ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

const getInitials = (name = '') =>
  name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map(word => word[0].toUpperCase())
    .join('');

export default function Payments({ onLogout, userRole }) {
  const [payments, setPayments] = useState([]);
  const [fees, setFees] = useState([]);
  const [pagination, setPagination] = useState({ current_page: 1, per_page: 15, total: 0, total_pages: 0 });
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [method, setMethod] = useState('');
  const [paymentType, setPaymentType] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [toast, setToast] = useState('');
  const [recordFeeId, setRecordFeeId] = useState(null);
  const [recordFee, setRecordFee] = useState(null);

  const headers = useMemo(() => ({ Accept: 'application/json' }), []);

  const showToast = message => {
    setToast(message);
    window.setTimeout(() => setToast(''), 4000);
  };

  const load = useCallback(async () => {
    setLoading(true);
    setError('');
    const params = new URLSearchParams({ page, per_page: '15' });
    if (search) params.set('search', search);
    if (dateFrom) params.set('date_from', dateFrom);
    if (dateTo) params.set('date_to', dateTo);
    if (method) params.set('method', method);
    if (paymentType) params.set('payment_type', paymentType);
    try {
      const [paymentResponse, feeResponse] = await Promise.all([
        fetchWithAuth(`${API_BASE}/payments?${params}`, { headers }),
        fetchWithAuth(`${API_BASE}/fees?per_page=100`, { headers }),
      ]);
      const paymentData = await paymentResponse.json().catch(() => ({}));
      const feeData = await feeResponse.json().catch(() => ({}));
      if (!paymentResponse.ok) throw new Error(paymentData.message || 'Unable to load payments.');
      setPayments(paymentData.payments || []);
      setPagination(paymentData.pagination || {});
      setFees(feeData.fees || []);
    } catch (err) {
      setError(err.message || 'Unable to load payment data.');
      setPayments([]);
    } finally {
      setLoading(false);
    }
  }, [headers, page, search, dateFrom, dateTo, method, paymentType]);

  useEffect(() => { load(); }, [load]);

  const collected = payments.filter(p => p.payment_type === 'PAYMENT').reduce((sum, p) => sum + Number(p.amount), 0);
  const outstanding = fees.reduce((sum, f) => sum + (f.balance_amount === null ? 0 : Number(f.balance_amount)), 0);
  const paymentTypes = [
    { value: '', label: 'All payments' },
    { value: 'PAYMENT', label: 'Payments' },
    { value: 'REVERSAL', label: 'Reversals' },
  ];

  async function openRecordModal() {
    const eligible = fees.find(f => Number(f.balance_amount || 0) > 0);
    if (!eligible) {
      setError('No monthly fee has an outstanding balance.');
      return;
    }
    setRecordFee(eligible);
    setRecordFeeId(eligible.fee_id);
  }

  async function reversePayment(payment) {
    if (!window.confirm(`Reverse payment ${payment.payment_code}? This creates a permanent reversal record.`)) return;
    const reason = window.prompt('Enter a reversal reason:', '');
    if (!reason || reason.trim().length < 3) return;
    try {
      const response = await fetchWithAuth(`${API_BASE}/payments/${payment.payment_id}/reverse`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reason }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || 'Unable to reverse payment.');
      showToast('Payment reversed successfully.');
      load();
    } catch (err) {
      setError(err.message || 'Unable to reverse payment.');
    }
  }

  async function correctPayment(payment) {
    if (!window.confirm(`Correct payment ${payment.payment_code}? This will reverse it and require replacement details.`)) return;
    const reason = window.prompt('Enter a correction reason:', '');
    if (!reason || reason.trim().length < 3) return;
    const amountText = window.prompt('Replacement payment amount (LKR):', String(Math.abs(Number(payment.amount))));
    if (!amountText) return;
    const paidDate = window.prompt('Replacement paid date (YYYY-MM-DD):', payment.paid_date);
    if (!paidDate) return;
    const methodInput = window.prompt('Replacement method (CASH, BANK, or CARD):', payment.method);
    if (!methodInput) return;
    const referenceNo = window.prompt('Replacement reference number (optional):', payment.reference_no || '') || null;
    try {
      const response = await fetchWithAuth(`${API_BASE}/payments/${payment.payment_id}/correct`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          reason,
          amount: Number(amountText),
          paid_date: paidDate,
          method: methodInput.trim().toUpperCase(),
          reference_no: referenceNo,
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || 'Unable to correct payment.');
      showToast('Payment corrected successfully.');
      load();
    } catch (err) {
      setError(err.message || 'Unable to correct payment.');
    }
  }

  return (
    <div className="mf-container">
      <Sidebar onLogout={onLogout} userRole={userRole} />
      <main className="mf-workspace">
        <header className="mf-topbar">
          <nav aria-label="Breadcrumb" className="mf-breadcrumb">
            <span>Workspace</span>
            <span>/</span>
            <span className="mf-breadcrumb-active">Payments</span>
          </nav>
          <div className="mf-badge-tag">
            <span className="mf-badge-tag-dot" />
            <span>Payment Collections</span>
          </div>
        </header>

        <section className="mf-hero-banner">
          <div className="mf-hero-eyebrow">
            <span>✦ EMARALD GIRLS HOSTEL</span>
          </div>
          <h1 className="mf-hero-title">Payment Management</h1>
          <p className="mf-hero-sub">
            Resident payment records, collection history, and payment audit oversight.
          </p>
        </section>

        <section className="mf-metrics-grid" aria-label="Payment Summary">
          <article className="mf-metric-card">
            <div className="mf-metric-top">
              <span className="mf-metric-icon mf-icon-green"><CreditCard size={19} /></span>
              <span className="mf-badge mf-badge-paid">Collections</span>
            </div>
            <div className="mf-metric-label">Total Collected</div>
            <div className="mf-metric-val">{money(collected)}</div>
            <div className="mf-metric-hint">Payments in the current view</div>
          </article>

          <article className="mf-metric-card">
            <div className="mf-metric-top">
              <span className="mf-metric-icon mf-icon-amber"><AlertCircle size={19} /></span>
              <span className="mf-badge mf-badge-unpaid">Outstanding</span>
            </div>
            <div className="mf-metric-label">Outstanding Balance</div>
            <div className="mf-metric-val">{money(outstanding)}</div>
            <div className="mf-metric-hint">Across loaded monthly fees</div>
          </article>

          <article className="mf-metric-card">
            <div className="mf-metric-top">
              <span className="mf-metric-icon mf-icon-blue"><CheckCircle2 size={19} /></span>
              <span className="mf-badge mf-badge-partial">Ledger</span>
            </div>
            <div className="mf-metric-label">Payment Records</div>
            <div className="mf-metric-val">{pagination.total || payments.length}</div>
            <div className="mf-metric-hint">Records matching current filters</div>
          </article>
        </section>

        <section className="mf-action-bar">
          <div className="mf-action-bar-left">
            <label className="mf-period-selector">
              <Calendar size={16} />
              <span>From:</span>
              <input
                type="date"
                aria-label="Filter payments from date"
                value={dateFrom}
                onChange={event => { setDateFrom(event.target.value); setPage(1); }}
              />
            </label>

            <label className="mf-period-selector">
              <Calendar size={16} />
              <span>To:</span>
              <input
                type="date"
                aria-label="Filter payments to date"
                value={dateTo}
                onChange={event => { setDateTo(event.target.value); setPage(1); }}
              />
            </label>

            <div className="mf-search-box">
              <Search size={16} />
              <input
                type="search"
                aria-label="Search payments"
                placeholder="Search code, resident, or reference..."
                value={search}
                onChange={event => { setSearch(event.target.value); setPage(1); }}
              />
            </div>

            <label className="pay-method-filter">
              <span>Method</span>
              <select
                value={method}
                onChange={event => { setMethod(event.target.value); setPage(1); }}
                aria-label="Filter by payment method"
              >
                <option value="">All methods</option>
                <option value="CASH">Cash</option>
                <option value="BANK">Bank</option>
                <option value="CARD">Card</option>
              </select>
            </label>

            <button type="button" className="mf-btn mf-btn-secondary" onClick={load} title="Refresh payment list">
              <RefreshCw size={15} />
              <span>Refresh</span>
            </button>
          </div>

          <div className="mf-action-bar-right">
            <button type="button" className="mf-btn mf-btn-primary" onClick={openRecordModal}>
              <Plus size={16} />
              <span>Record payment</span>
            </button>
          </div>
        </section>

        {error && (
          <div className="pay-error-banner" role="alert">
            <AlertCircle size={17} />
            <span>{error}</span>
            <button type="button" onClick={load} aria-label="Retry"><RefreshCw size={15} /></button>
          </div>
        )}

        <section className="mf-card">
          <div className="mf-card-header pay-card-header">
            <div>
              <h2 className="mf-card-title">Payment Ledger Overview</h2>
              <p className="mf-card-sub">Showing {payments.length} of {pagination.total || 0} payment records</p>
            </div>
            <div className="mf-filter-pills" role="radiogroup" aria-label="Payment type filters">
              {paymentTypes.map(type => (
                <button
                  key={type.value}
                  type="button"
                  role="radio"
                  aria-checked={paymentType === type.value}
                  className={`mf-pill ${paymentType === type.value ? 'active' : ''}`}
                  onClick={() => { setPaymentType(type.value); setPage(1); }}
                >
                  {type.label}
                </button>
              ))}
            </div>
          </div>

          <div className="mf-table-wrap">
            <table className="mf-table" aria-label="Payment History">
              <thead>
                <tr>
                  <th scope="col">Payment</th>
                  <th scope="col">Resident</th>
                  <th scope="col">Room & Fee</th>
                  <th scope="col">Amount</th>
                  <th scope="col">Paid Date</th>
                  <th scope="col">Method</th>
                  <th scope="col">Payment Type</th>
                  <th scope="col" style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  Array.from({ length: 5 }).map((_, index) => (
                    <tr key={`payment-skeleton-${index}`}>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 120 }} /><div className="mf-skeleton mf-skeleton-line" style={{ width: 80, height: 10 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 140 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 100 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 90 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 80 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 60 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 80 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 90, marginLeft: 'auto' }} /></td>
                    </tr>
                  ))
                ) : payments.length === 0 ? (
                  <tr>
                    <td colSpan={8}>
                      <div className="mf-empty">
                        <div className="mf-empty-icon"><CreditCard size={24} /></div>
                        <h4>No payment records found</h4>
                        <p>No payments match your current search and filters.</p>
                      </div>
                    </td>
                  </tr>
                ) : (
                  payments.map(payment => (
                    <tr key={payment.payment_id}>
                      <td>
                        <strong className="mf-res-name">{payment.payment_code}</strong>
                        {payment.reference_no && <span className="mf-res-code">{payment.reference_no}</span>}
                      </td>
                      <td>
                        <div className="mf-res-cell">
                          <span className="mf-avatar">{getInitials(payment.resident_name)}</span>
                          <div>
                            <strong className="mf-res-name">{payment.resident_name}</strong>
                            <span className="mf-res-code">{payment.resident_code}</span>
                          </div>
                        </div>
                      </td>
                      <td>
                        <span className="mf-room-pill">{payment.room_number ? `Room ${payment.room_number}` : 'Unallocated'}</span>
                        <span className="mf-room-floor">Fee #{payment.fee_id}</span>
                      </td>
                      <td className={payment.payment_type === 'REVERSAL' ? 'pay-negative' : ''}>
                        <span className="mf-amount-main">{money(payment.amount)}</span>
                      </td>
                      <td>{payment.paid_date}</td>
                      <td>{payment.method}</td>
                      <td>
                        <span className={`mf-badge ${payment.payment_type === 'REVERSAL' ? 'mf-badge-unpaid' : 'mf-badge-paid'}`}>
                          {payment.payment_type}
                        </span>
                      </td>
                      <td style={{ textAlign: 'right' }}>
                        {payment.payment_type === 'PAYMENT' && (
                          <div className="pay-actions">
                            <button type="button" className="mf-btn mf-btn-subtle" onClick={() => reversePayment(payment)} title="Reverse payment"><Undo2 size={14} /><span>Reverse</span></button>
                            <button type="button" className="mf-btn mf-btn-subtle" onClick={() => correctPayment(payment)} title="Correct payment"><Edit3 size={14} /><span>Correct</span></button>
                          </div>
                        )}
                      </td>
                    </tr>
                  )))}
              </tbody>
            </table>
          </div>

          {pagination.total_pages > 1 && (
            <div className="mf-pagination">
              <div>Page {pagination.current_page} of {pagination.total_pages} ({pagination.total} total payments)</div>
              <div className="mf-pagination-controls">
                <button type="button" className="mf-page-btn" disabled={page <= 1 || loading} onClick={() => setPage(current => Math.max(1, current - 1))} aria-label="Previous page">
                  <ChevronLeft size={16} />
                </button>
                {Array.from({ length: pagination.total_pages }, (_, index) => index + 1).map(pageNumber => (
                  <button
                    key={pageNumber}
                    type="button"
                    className={`mf-page-btn ${page === pageNumber ? 'active' : ''}`}
                    disabled={loading}
                    onClick={() => setPage(pageNumber)}
                    aria-label={`Go to page ${pageNumber}`}
                  >
                    {pageNumber}
                  </button>
                ))}
                <button type="button" className="mf-page-btn" disabled={page >= pagination.total_pages || loading} onClick={() => setPage(current => Math.min(pagination.total_pages, current + 1))} aria-label="Next page">
                  <ChevronRight size={16} />
                </button>
              </div>
            </div>
          )}
        </section>
      </main>
      {recordFeeId && <RecordPaymentModal feeId={recordFeeId} fee={recordFee} onClose={() => setRecordFeeId(null)} onSuccess={result => { showToast('Payment recorded successfully.'); setRecordFeeId(null); load(); }} />}
      {toast && <div className="pay-toast" role="status"><CheckCircle2 size={17} />{toast}</div>}
    </div>
  );
}
