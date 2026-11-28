import React, { useEffect, useState } from 'react';
import { CheckCircle2, X } from 'lucide-react';
import { fetchWithAuth } from '../users/authFetch.js';
import './payments.css';

const API_BASE = (import.meta.env.VITE_LARAVEL_API_URL || '/fees-api').replace(/\/+$/, '');

export default function RecordPaymentModal({ feeId, fee, onClose, onSuccess }) {
  const [details, setDetails] = useState(fee || null);
  const [balance, setBalance] = useState(null);
  const [amount, setAmount] = useState('');
  const [paidDate, setPaidDate] = useState(new Date().toISOString().slice(0, 10));
  const [method, setMethod] = useState('CASH');
  const [referenceNo, setReferenceNo] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError('');
      try {
        const [feeResponse, balanceResponse] = await Promise.all([
          fetchWithAuth(`${API_BASE}/fees/${feeId}`),
          fetchWithAuth(`${API_BASE}/fees/${feeId}/balance`),
        ]);
        const feeData = await feeResponse.json().catch(() => ({}));
        const balanceData = await balanceResponse.json().catch(() => ({}));
        if (!feeResponse.ok || !balanceResponse.ok) {
          throw new Error(feeData.message || balanceData.message || 'Unable to load fee details.');
        }
        if (!cancelled) {
          setDetails(feeData.fee || fee);
          setBalance(balanceData);
        }
      } catch (err) {
        if (!cancelled) setError(err.message || 'Unable to load fee details.');
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    if (feeId) load();
    return () => { cancelled = true; };
  }, [feeId, fee]);

  const outstanding = balance ? Number(balance.outstanding_balance) : 0;
  const amountNumber = Number(amount);

  async function submit(event) {
    event.preventDefault();
    setError('');
    if (!amountNumber || amountNumber <= 0) {
      setError('Payment amount must be greater than zero.');
      return;
    }
    if (amountNumber > outstanding) {
      setError('Payment amount cannot exceed the outstanding balance.');
      return;
    }
    setSubmitting(true);
    try {
      const response = await fetchWithAuth(`${API_BASE}/fees/${feeId}/payments`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          amount: amountNumber,
          paid_date: paidDate,
          method,
          reference_no: referenceNo || null,
        }),
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.message || 'Unable to record payment.');
      onSuccess?.(data);
      onClose();
    } catch (err) {
      setError(err.message || 'Unable to record payment.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="mf-modal-backdrop" role="presentation" onMouseDown={event => event.target === event.currentTarget && onClose()}>
      <section className="mf-modal" role="dialog" aria-modal="true" aria-labelledby="record-payment-title">
        <div className="mf-modal-header">
          <div>
            <div className="mf-modal-eyebrow">Payment Record</div>
            <h2 id="record-payment-title" className="mf-modal-title">Record Payment</h2>
          </div>
          <button type="button" className="mf-close-btn" aria-label="Close" onClick={onClose} disabled={submitting}><X size={20} /></button>
        </div>
        {loading ? (
          <div className="pay-loading">Loading fee details...</div>
        ) : (
          <form onSubmit={submit}>
            <div className="pay-modal-body">
              {error && <div className="pay-error" role="alert">{error}</div>}
              {details && (
                <div className="mf-preview-box pay-fee-summary">
                  <div><span>Resident</span><strong>{details.resident_name}</strong></div>
                  <div><span>Fee</span><strong>{details.fee_code}</strong></div>
                  <div><span>Room</span><strong>{details.room_number || 'Unallocated'}</strong></div>
                  <div><span>Total fee</span><strong>LKR {Number(details.amount_due).toLocaleString('en-US', { minimumFractionDigits: 2 })}</strong></div>
                  <div><span>Previously paid</span><strong>LKR {Number(balance?.total_paid || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })}</strong></div>
                  <div className="pay-outstanding"><span>Outstanding</span><strong>LKR {outstanding.toLocaleString('en-US', { minimumFractionDigits: 2 })}</strong></div>
                </div>
              )}
              <div className="pay-form-grid">
                <label className="mf-form-group">Payment amount (LKR)
                  <input className="mf-input" type="number" min="0.01" step="0.01" value={amount} max={outstanding || undefined} onChange={e => setAmount(e.target.value)} required />
                </label>
                <label className="mf-form-group">Payment date
                  <input className="mf-input" type="date" value={paidDate} max={new Date().toISOString().slice(0, 10)} onChange={e => setPaidDate(e.target.value)} required />
                </label>
                <label className="mf-form-group">Method
                  <select className="mf-select" value={method} onChange={e => setMethod(e.target.value)}>
                    <option value="CASH">Cash</option>
                    <option value="BANK">Bank</option>
                    <option value="CARD">Card</option>
                  </select>
                </label>
                <label className="mf-form-group">Reference number
                  <input className="mf-input" type="text" value={referenceNo} maxLength={100} onChange={e => setReferenceNo(e.target.value)} placeholder="Optional transaction reference" />
                </label>
              </div>
            </div>
            <div className="mf-modal-footer">
              <button type="button" className="mf-btn mf-btn-secondary" onClick={onClose} disabled={submitting}>Cancel</button>
              <button type="submit" className="mf-btn mf-btn-primary" disabled={submitting || outstanding <= 0}>
                <CheckCircle2 size={16} />{submitting ? 'Recording...' : 'Record payment'}
              </button>
            </div>
          </form>
        )}
      </section>
    </div>
  );
}
