import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { AlertCircle, ChevronLeft, ChevronRight, CreditCard, RefreshCw, Search, Undo2, Edit3, Plus, CheckCircle2 } from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import { fetchWithAuth } from '../users/authFetch.js';
import RecordPaymentModal from './RecordPaymentModal.jsx';
import './payments.css';

const API_BASE = (import.meta.env.VITE_LARAVEL_API_URL || '/fees-api').replace(/\/+$/, '');

const money = value => `LKR ${Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

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
    <div className="pay-container">
      <Sidebar onLogout={onLogout} userRole={userRole} />
      <main className="pay-workspace">
        <header className="pay-page-header">
          <div><span className="pay-eyebrow">FINANCE WORKSPACE</span><h1>Payments</h1><p>Record, review and audit hostel payments.</p></div>
          <button type="button" className="pay-btn pay-btn-primary" onClick={openRecordModal}><Plus size={16} />Record payment</button>
        </header>
        {error && <div className="pay-error-banner" role="alert"><AlertCircle size={17} />{error}<button type="button" onClick={load} aria-label="Retry"><RefreshCw size={15} /></button></div>}
        <section className="pay-stats">
          <article><span className="pay-stat-icon"><CreditCard size={20} /></span><p>Total collected</p><strong>{money(collected)}</strong><small>Current view</small></article>
          <article><span className="pay-stat-icon orange"><AlertCircle size={20} /></span><p>Outstanding balance</p><strong>{money(outstanding)}</strong><small>All loaded fees</small></article>
          <article><span className="pay-stat-icon blue"><CheckCircle2 size={20} /></span><p>Number of payments</p><strong>{pagination.total || payments.length}</strong><small>Matching records</small></article>
          <article><span className="pay-stat-icon purple"><RefreshCw size={20} /></span><p>Recent activity</p><strong>{payments.length}</strong><small>Payments in view</small></article>
        </section>
        <section className="pay-card">
          <div className="pay-card-header">
            <div><h2>Payment history</h2><p>{pagination.total || 0} records</p></div>
            <div className="pay-search"><Search size={15} /><input value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="Search code, resident or reference" /></div>
          </div>
          <div className="pay-filters">
            <label>From<input type="date" value={dateFrom} onChange={e => { setDateFrom(e.target.value); setPage(1); }} /></label>
            <label>To<input type="date" value={dateTo} onChange={e => { setDateTo(e.target.value); setPage(1); }} /></label>
            <label>Method<select value={method} onChange={e => { setMethod(e.target.value); setPage(1); }}><option value="">All methods</option><option>CASH</option><option>BANK</option><option>CARD</option></select></label>
            <label>Type<select value={paymentType} onChange={e => { setPaymentType(e.target.value); setPage(1); }}><option value="">All types</option><option value="PAYMENT">Payment</option><option value="REVERSAL">Reversal</option></select></label>
          </div>
          <div className="pay-table-wrap">
            <table className="pay-table">
              <thead><tr><th>Payment code</th><th>Resident</th><th>Room</th><th>Fee</th><th>Amount</th><th>Date</th><th>Method</th><th>Status / type</th><th>Actions</th></tr></thead>
              <tbody>
                {loading ? <tr><td colSpan={9} className="pay-empty">Loading payments...</td></tr> :
                  payments.length === 0 ? <tr><td colSpan={9} className="pay-empty">No payment records match your filters.</td></tr> :
                  payments.map(payment => (
                    <tr key={payment.payment_id}>
                      <td><strong>{payment.payment_code}</strong>{payment.reference_no && <small className="pay-ref">{payment.reference_no}</small>}</td>
                      <td>{payment.resident_name}<small className="pay-ref">{payment.resident_code}</small></td>
                      <td>{payment.room_number || '—'}</td>
                      <td>#{payment.fee_id}</td>
                      <td className={payment.payment_type === 'REVERSAL' ? 'pay-negative' : ''}>{money(payment.amount)}</td>
                      <td>{payment.paid_date}</td>
                      <td>{payment.method}</td>
                      <td><span className={`pay-badge ${payment.payment_type === 'REVERSAL' ? 'reversal' : ''}`}>{payment.payment_type}</span></td>
                      <td>
                        {payment.payment_type === 'PAYMENT' && (
                          <div className="pay-actions">
                            <button type="button" onClick={() => reversePayment(payment)} title="Reverse payment"><Undo2 size={14} /><span>Reverse</span></button>
                            <button type="button" onClick={() => correctPayment(payment)} title="Correct payment"><Edit3 size={14} /><span>Correct</span></button>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
              </tbody>
            </table>
          </div>
          {pagination.total_pages > 1 && (
            <div className="pay-pagination">
              <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}><ChevronLeft size={16} />Previous</button>
              <span>Page {pagination.current_page} of {pagination.total_pages}</span>
              <button type="button" disabled={page >= pagination.total_pages} onClick={() => setPage(page + 1)}>Next<ChevronRight size={16} /></button>
            </div>
          )}
        </section>
      </main>
      {recordFeeId && <RecordPaymentModal feeId={recordFeeId} fee={recordFee} onClose={() => setRecordFeeId(null)} onSuccess={result => { showToast('Payment recorded successfully.'); setRecordFeeId(null); load(); }} />}
      {toast && <div className="pay-toast" role="status"><CheckCircle2 size={17} />{toast}</div>}
    </div>
  );
}
