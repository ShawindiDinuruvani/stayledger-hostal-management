import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Wallet,
  Calendar,
  Search,
  Plus,
  RefreshCw,
  CheckCircle2,
  AlertCircle,
  Clock,
  UserCheck,
  Building2,
  ChevronLeft,
  ChevronRight,
  Eye,
  X,
  FileText,
  DollarSign,
  Info,
  ShieldCheck,
  HelpCircle
} from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import './fees.css';

// Module-specific Laravel API URL with environment variable support (Port 8001)
const LARAVEL_API_BASE = (import.meta.env.VITE_LARAVEL_API_URL || 'http://localhost:8001/api').replace(/\/+$/, '');

// Format currency in Sri Lankan Rupees
const formatCurrency = (amount) => {
  if (amount === null || amount === undefined) return '—';
  const num = Number(amount);
  if (isNaN(num)) return '—';
  return 'LKR ' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

// Format billing period string (e.g. '2026-11' -> 'November 2026')
const formatPeriod = (periodStr) => {
  if (!periodStr || !periodStr.includes('-')) return periodStr || 'Current Month';
  const [year, month] = periodStr.split('-');
  const date = new Date(parseInt(year, 10), parseInt(month, 10) - 1, 1);
  return date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
};

// Avatar initials generator
const getInitials = (name = '') => {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map(w => w[0].toUpperCase())
    .join('');
};

export default function MonthlyFees({ onLogout, userRole }) {
  // Current date strings
  const today = new Date();
  const currentMonthStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
  const defaultDueDate = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-15`;

  // State
  const [fees, setFees] = useState([]);
  const [loading, setLoading] = useState(true);
  const [apiError, setApiError] = useState('');
  const [toast, setToast] = useState('');

  // Filters & Pagination
  const [selectedPeriod, setSelectedPeriod] = useState(currentMonthStr);
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState('All');
  const [currentPage, setCurrentPage] = useState(1);
  const [pagination, setPagination] = useState({ current_page: 1, per_page: 15, total: 0, total_pages: 0 });
  const [summary, setSummary] = useState({ total_records: 0, total_billed: 0 });

  // Modals
  const [activeModal, setActiveModal] = useState(null); // 'generate' | 'details'
  const [selectedFee, setSelectedFee] = useState(null);

  // Generation Form State
  const [genForm, setGenForm] = useState({
    billing_period: currentMonthStr,
    amount: '18000.00',
    due_date: defaultDueDate,
    remarks: ''
  });
  const [previewData, setPreviewData] = useState(null);
  const [previewLoading, setPreviewLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState('');

  // Toast Helper
  const showToast = (message) => {
    setToast(message);
    setTimeout(() => setToast(''), 4000);
  };

  // API Request Headers
  const apiHeaders = useMemo(() => ({
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }), []);

  // Fetch Fee List from Laravel API
  const fetchFees = useCallback(async () => {
    setLoading(true);
    setApiError('');
    try {
      const params = new URLSearchParams({
        period: selectedPeriod,
        search: searchQuery,
        status: statusFilter,
        page: currentPage.toString(),
        per_page: '15'
      });
      const res = await fetch(`${LARAVEL_API_BASE}/fees?${params.toString()}`, {
        headers: apiHeaders
      });
      if (!res.ok) {
        if (res.status === 401) {
          const errData = await res.json().catch(() => ({}));
          setApiError(errData.message || 'Access restricted. Please sign in with an authorized account.');
          setFees([]);
          return;
        }
        throw new Error(`HTTP ${res.status}: ${res.statusText}`);
      }
      const data = await res.json();
      setFees(data.fees || []);
      setPagination(data.pagination || { current_page: 1, per_page: 15, total: 0, total_pages: 0 });
      setSummary(data.summary || { total_records: 0, total_billed: 0 });
    } catch (err) {
      setApiError('Unable to load monthly fees. Please try again.');
      setFees([]);
    } finally {
      setLoading(false);
    }
  }, [selectedPeriod, searchQuery, statusFilter, currentPage, apiHeaders]);

  useEffect(() => {
    fetchFees();
  }, [fetchFees]);

  // Fetch Preview when opening Generate modal or changing generation period
  const fetchPreview = useCallback(async (period) => {
    if (!period || !/^\d{4}-(0[1-9]|1[0-2])$/.test(period)) return;
    setPreviewLoading(true);
    setFormError('');
    try {
      const res = await fetch(`${LARAVEL_API_BASE}/fees/preview?period=${encodeURIComponent(period)}`, {
        headers: apiHeaders
      });
      if (!res.ok) {
        const errData = await res.json().catch(() => ({}));
        throw new Error(errData.message || `HTTP ${res.status}`);
      }
      const data = await res.json();
      setPreviewData(data);
    } catch (err) {
      setFormError(err.message || 'Unable to load resident billing preview. Please try again.');
      setPreviewData(null);
    } finally {
      setPreviewLoading(false);
    }
  }, [apiHeaders]);

  const openGenerateModal = () => {
    setGenForm({
      billing_period: selectedPeriod || currentMonthStr,
      amount: '18000.00',
      due_date: defaultDueDate,
      remarks: ''
    });
    setFormError('');
    setActiveModal('generate');
    fetchPreview(selectedPeriod || currentMonthStr);
  };

  const handlePeriodChangeInModal = (e) => {
    const val = e.target.value;
    setGenForm(prev => ({
      ...prev,
      billing_period: val,
      due_date: val ? `${val}-15` : prev.due_date
    }));
    fetchPreview(val);
  };

  // Submit Generation Request to Backend
  const handleGenerateSubmit = async (e) => {
    e.preventDefault();
    setFormError('');

    const amt = parseFloat(genForm.amount);
    if (isNaN(amt) || amt <= 0) {
      setFormError('Fee amount must be a valid positive number.');
      return;
    }

    if (!genForm.due_date) {
      setFormError('Due date is required.');
      return;
    }

    setSubmitting(true);
    try {
      const res = await fetch(`${LARAVEL_API_BASE}/fees/generate`, {
        method: 'POST',
        headers: apiHeaders,
        body: JSON.stringify({
          billing_period: genForm.billing_period,
          amount: amt,
          due_date: genForm.due_date,
          remarks: genForm.remarks
        })
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data.message || 'Failed to generate monthly fees. Please try again.');
      }

      showToast(`Success: ${data.created_count} fee records created, ${data.skipped_count} skipped (already billed).`);
      setActiveModal(null);
      setSelectedPeriod(genForm.billing_period);
      setCurrentPage(1);
      fetchFees();
    } catch (err) {
      setFormError(err.message || 'Failed to generate monthly fees. Please try again.');
    } finally {
      setSubmitting(false);
    }
  };

  // View Details Modal
  const openDetailsModal = async (feeId) => {
    try {
      const res = await fetch(`${LARAVEL_API_BASE}/fees/${feeId}`, {
        headers: apiHeaders
      });
      if (!res.ok) throw new Error('Unable to load fee details. Please try again.');
      const data = await res.json();
      setSelectedFee(data.fee);
      setActiveModal('details');
    } catch (err) {
      const local = fees.find(f => f.fee_id === feeId);
      if (local) {
        setSelectedFee(local);
        setActiveModal('details');
      } else {
        showToast('Error loading details for this fee.');
      }
    }
  };

  // Keyboard accessibility: ESC closes modal
  useEffect(() => {
    const handleKeyDown = (e) => {
      if (e.key === 'Escape' && activeModal && !submitting) {
        setActiveModal(null);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [activeModal, submitting]);

  return (
    <div className="mf-container">
      <Sidebar onLogout={onLogout} userRole={userRole} />

      <main className="mf-workspace">
        {/* Topbar */}
        <header className="mf-topbar">
          <nav aria-label="Breadcrumb" className="mf-breadcrumb">
            <span>Workspace</span>
            <span>/</span>
            <span className="mf-breadcrumb-active">Monthly fees</span>
          </nav>
          <div className="mf-badge-tag">
            <span className="mf-badge-tag-dot" />
            <span>Accommodation Billing</span>
          </div>
        </header>

        {/* Hero Banner */}
        <section className="mf-hero-banner">
          <div className="mf-hero-eyebrow">
            <span>✦ EMARALD GIRLS HOSTEL</span>
          </div>
          <h1 className="mf-hero-title">Monthly Fee Management</h1>
          <p className="mf-hero-sub">
            Monthly accommodation fee generation, resident billing rosters, and fee schedule oversight.
          </p>
        </section>

        {/* Summary Metric Cards */}
        <section className="mf-metrics-grid" aria-label="Monthly Fee Summary">
          <article className="mf-metric-card">
            <div className="mf-metric-top">
              <span className="mf-metric-icon mf-icon-green">
                <Wallet size={19} />
              </span>
              <span className="mf-badge mf-badge-paid">Period Total</span>
            </div>
            <div className="mf-metric-label">Total Billed in {formatPeriod(selectedPeriod)}</div>
            <div className="mf-metric-val">{formatCurrency(summary.total_billed)}</div>
            <div className="mf-metric-hint">{summary.total_records} fee charges generated</div>
          </article>

          <article className="mf-metric-card">
            <div className="mf-metric-top">
              <span className="mf-metric-icon mf-icon-blue">
                <UserCheck size={19} />
              </span>
              <span className="mf-badge mf-badge-partial">Roster</span>
            </div>
            <div className="mf-metric-label">Billed Residents</div>
            <div className="mf-metric-val">{summary.total_records}</div>
            <div className="mf-metric-hint">Allocated residents for {formatPeriod(selectedPeriod)}</div>
          </article>

          <article className="mf-metric-card">
            <div className="mf-metric-top">
              <span className="mf-metric-icon mf-icon-amber">
                <Calendar size={19} />
              </span>
              <span className="mf-badge mf-badge-paid">Cycle</span>
            </div>
            <div className="mf-metric-label">Billing Period</div>
            <div className="mf-metric-val" style={{ fontSize: 18 }}>{formatPeriod(selectedPeriod)}</div>
            <div className="mf-metric-hint">Active billing schedule</div>
          </article>
        </section>

        {/* Action & Filter Bar */}
        <section className="mf-action-bar">
          <div className="mf-action-bar-left">
            <label className="mf-period-selector">
              <Calendar size={16} />
              <span>Billing Month:</span>
              <input
                type="month"
                aria-label="Filter billing month"
                value={selectedPeriod}
                onChange={(e) => {
                  setSelectedPeriod(e.target.value);
                  setCurrentPage(1);
                }}
              />
            </label>

            <div className="mf-search-box">
              <Search size={16} />
              <input
                type="search"
                aria-label="Search residents or fees"
                placeholder="Search resident name, code, or fee code..."
                value={searchQuery}
                onChange={(e) => {
                  setSearchQuery(e.target.value);
                  setCurrentPage(1);
                }}
              />
            </div>

            <button
              type="button"
              className="mf-btn mf-btn-secondary"
              onClick={fetchFees}
              title="Refresh Fee List"
              aria-label="Refresh Fee List"
            >
              <RefreshCw size={15} />
              <span>Refresh</span>
            </button>
          </div>

          <div className="mf-action-bar-right">
            <button
              type="button"
              className="mf-btn mf-btn-primary"
              onClick={openGenerateModal}
            >
              <Plus size={16} />
              <span>Generate monthly fees</span>
            </button>
          </div>
        </section>

        {/* Error Alert */}
        {apiError && (
          <div className="mf-toast" style={{ background: '#7f1d1d', marginBottom: 20 }} role="alert">
            <AlertCircle size={18} />
            <span>{apiError}</span>
          </div>
        )}

        {/* Main Fee Table Card */}
        <section className="mf-card">
          <div className="mf-card-header">
            <div>
              <h2 className="mf-card-title">Fee Ledger Overview</h2>
              <p className="mf-card-sub">
                Showing {fees.length} of {pagination.total} records for {formatPeriod(selectedPeriod)}
              </p>
            </div>

            <div className="mf-filter-pills" role="radiogroup" aria-label="Status filters">
              {['All', 'UNPAID', 'PARTIALLY_PAID', 'PAID'].map((status) => (
                <button
                  key={status}
                  type="button"
                  role="radio"
                  aria-checked={statusFilter === status}
                  className={`mf-pill ${statusFilter === status ? 'active' : ''}`}
                  onClick={() => {
                    setStatusFilter(status);
                    setCurrentPage(1);
                  }}
                >
                  {status === 'All' ? 'All Fees' : status.replace('_', ' ')}
                </button>
              ))}
            </div>
          </div>

          <div className="mf-table-wrap">
            <table className="mf-table" aria-label="Monthly Fee Table">
              <thead>
                <tr>
                  <th scope="col">Resident</th>
                  <th scope="col">Room & Floor</th>
                  <th scope="col">Billing Period</th>
                  <th scope="col">Amount Due</th>
                  <th scope="col">Payment Settlement</th>
                  <th scope="col">Billing Status</th>
                  <th scope="col" style={{ textAlign: 'right' }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {loading ? (
                  // Loading Skeleton
                  Array.from({ length: 5 }).map((_, idx) => (
                    <tr key={`skeleton-${idx}`}>
                      <td>
                        <div className="mf-skeleton mf-skeleton-line" style={{ width: 140 }} />
                        <div className="mf-skeleton mf-skeleton-line" style={{ width: 80, height: 10 }} />
                      </td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 100 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 80 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 90 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 120 }} /></td>
                      <td><div className="mf-skeleton mf-skeleton-line" style={{ width: 70 }} /></td>
                      <td style={{ textAlign: 'right' }}><div className="mf-skeleton mf-skeleton-line" style={{ width: 70, marginLeft: 'auto' }} /></td>
                    </tr>
                  ))
                ) : fees.length === 0 ? (
                  // Empty State
                  <tr>
                    <td colSpan={7}>
                      <div className="mf-empty">
                        <div className="mf-empty-icon">
                          <FileText size={24} />
                        </div>
                        <h4>No fee records found</h4>
                        <p>
                          {searchQuery
                            ? `No records match "${searchQuery}" for ${formatPeriod(selectedPeriod)}.`
                            : `No monthly fees have been generated for ${formatPeriod(selectedPeriod)} yet.`}
                        </p>
                        {!searchQuery && (
                          <button
                            type="button"
                            className="mf-btn mf-btn-primary"
                            onClick={openGenerateModal}
                          >
                            <Plus size={15} />
                            <span>Generate fees for {formatPeriod(selectedPeriod)}</span>
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ) : (
                  // Rows
                  fees.map((fee) => {
                    const isPaymentAvailable = fee.payment_available === true;

                    return (
                      <tr key={fee.fee_id}>
                        <td>
                          <div className="mf-res-cell">
                            <span className="mf-avatar">
                              {getInitials(fee.resident_name)}
                            </span>
                            <div>
                              <strong className="mf-res-name">{fee.resident_name}</strong>
                              <span className="mf-res-code">{fee.resident_code}</span>
                            </div>
                          </div>
                        </td>

                        <td>
                          {fee.room_number ? (
                            <div>
                              <span className="mf-room-pill">Room {fee.room_number}</span>
                              <span className="mf-room-floor">{fee.floor_name || 'Hostel Floor'}</span>
                            </div>
                          ) : (
                            <span style={{ color: 'var(--mf-text-muted)' }}>Unallocated</span>
                          )}
                        </td>

                        <td>
                          <span style={{ fontWeight: 500 }}>{formatPeriod(fee.billing_period)}</span>
                          <span style={{ display: 'block', fontSize: 11, color: 'var(--mf-text-muted)' }}>
                            {fee.fee_code}
                          </span>
                        </td>

                        <td>
                          <span className="mf-amount-main">{formatCurrency(fee.amount_due)}</span>
                        </td>

                        {/* Payment Settlement Status */}
                        <td>
                          {isPaymentAvailable ? (
                            <div>
                              <span style={{ fontWeight: 600, color: '#15803d' }}>
                                Paid: {formatCurrency(fee.paid_amount)}
                              </span>
                              <span style={{ display: 'block', fontSize: 11, color: 'var(--mf-text-muted)' }}>
                                Bal: {formatCurrency(fee.balance_amount)}
                              </span>
                            </div>
                          ) : (
                            <div>
                              <span style={{ fontSize: 12, color: '#6b7280', fontStyle: 'italic' }}>
                                Payment information unavailable
                              </span>
                            </div>
                          )}
                        </td>

                        <td>
                          <span className="mf-badge mf-badge-paid" style={{ background: '#eaf4ee', color: '#0f5338' }}>
                            {fee.fee_status || 'BILLED'}
                          </span>
                        </td>

                        <td style={{ textAlign: 'right' }}>
                          <button
                            type="button"
                            className="mf-btn mf-btn-subtle"
                            onClick={() => openDetailsModal(fee.fee_id)}
                            aria-label={`View fee details for ${fee.resident_name}`}
                          >
                            <Eye size={15} />
                            <span>Details</span>
                          </button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination Controls */}
          {pagination.total_pages > 1 && (
            <div className="mf-pagination">
              <div>
                Page {pagination.current_page} of {pagination.total_pages} ({pagination.total} total fees)
              </div>
              <div className="mf-pagination-controls">
                <button
                  type="button"
                  className="mf-page-btn"
                  disabled={currentPage <= 1 || loading}
                  onClick={() => setCurrentPage(prev => Math.max(1, prev - 1))}
                  aria-label="Previous Page"
                >
                  <ChevronLeft size={16} />
                </button>
                {Array.from({ length: pagination.total_pages }, (_, i) => i + 1).map((pg) => (
                  <button
                    key={pg}
                    type="button"
                    className={`mf-page-btn ${currentPage === pg ? 'active' : ''}`}
                    onClick={() => setCurrentPage(pg)}
                    aria-label={`Go to page ${pg}`}
                  >
                    {pg}
                  </button>
                ))}
                <button
                  type="button"
                  className="mf-page-btn"
                  disabled={currentPage >= pagination.total_pages || loading}
                  onClick={() => setCurrentPage(prev => Math.min(pagination.total_pages, prev + 1))}
                  aria-label="Next Page"
                >
                  <ChevronRight size={16} />
                </button>
              </div>
            </div>
          )}
        </section>

        {/* ================================================================== */}
        {/* MODAL A: GENERATE MONTHLY FEES                                     */}
        {/* ================================================================== */}
        {activeModal === 'generate' && (
          <div className="mf-modal-backdrop" onClick={() => !submitting && setActiveModal(null)}>
            <div
              className="mf-modal"
              role="dialog"
              aria-modal="true"
              aria-labelledby="modal-gen-title"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="mf-modal-header">
                <div>
                  <div className="mf-modal-eyebrow">Fee Generation</div>
                  <h3 id="modal-gen-title" className="mf-modal-title">Generate Monthly Fees</h3>
                </div>
                <button
                  type="button"
                  className="mf-close-btn"
                  aria-label="Close dialog"
                  disabled={submitting}
                  onClick={() => setActiveModal(null)}
                >
                  <X size={20} />
                </button>
              </div>

              <form onSubmit={handleGenerateSubmit}>
                <div className="mf-modal-body">
                  {formError && (
                    <div style={{ background: '#fef2f2', color: '#b91c1c', padding: '10px 14px', borderRadius: 8, marginBottom: 16, fontSize: 12.5 }}>
                      <AlertCircle size={15} style={{ verticalAlign: 'middle', marginRight: 6 }} />
                      {formError}
                    </div>
                  )}

                  <div className="mf-form-group">
                    <label className="mf-label" htmlFor="gen-period">
                      Billing Period (Month & Year) <span style={{ color: '#b91c1c' }}>*</span>
                    </label>
                    <input
                      id="gen-period"
                      type="month"
                      className="mf-input"
                      required
                      value={genForm.billing_period}
                      onChange={handlePeriodChangeInModal}
                      disabled={submitting}
                    />
                    <p className="mf-form-hint">
                      Selected period: <strong>{formatPeriod(genForm.billing_period)}</strong>
                    </p>
                  </div>

                  <div className="mf-form-group">
                    <label className="mf-label" htmlFor="gen-amount">
                      Monthly Fee Amount (LKR) <span style={{ color: '#b91c1c' }}>*</span>
                    </label>
                    <input
                      id="gen-amount"
                      type="number"
                      step="0.01"
                      min="1"
                      className="mf-input"
                      required
                      value={genForm.amount}
                      onChange={(e) => setGenForm({ ...genForm, amount: e.target.value })}
                      disabled={submitting}
                    />
                    <p className="mf-form-hint">
                      Standard monthly accommodation fee for allocated residents.
                    </p>
                  </div>

                  <div className="mf-form-group">
                    <label className="mf-label" htmlFor="gen-due-date">
                      Payment Due Date <span style={{ color: '#b91c1c' }}>*</span>
                    </label>
                    <input
                      id="gen-due-date"
                      type="date"
                      className="mf-input"
                      required
                      value={genForm.due_date}
                      onChange={(e) => setGenForm({ ...genForm, due_date: e.target.value })}
                      disabled={submitting}
                    />
                  </div>

                  <div className="mf-form-group">
                    <label className="mf-label" htmlFor="gen-remarks">
                      Remarks / Billing Notes (Optional)
                    </label>
                    <input
                      id="gen-remarks"
                      type="text"
                      className="mf-input"
                      placeholder="e.g., Standard monthly accommodation fee"
                      value={genForm.remarks}
                      onChange={(e) => setGenForm({ ...genForm, remarks: e.target.value })}
                      disabled={submitting}
                    />
                  </div>

                  {/* Live Eligibility Preview Panel */}
                  <div className="mf-preview-box">
                    <div className="mf-preview-header">
                      <strong>Resident billing preview</strong>
                      {previewLoading && <span style={{ fontSize: 11, color: 'var(--mf-sage)' }}>Checking roster...</span>}
                    </div>

                    {previewData ? (
                      <div>
                        <div className="mf-preview-count" style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
                          <span>Eligible: <strong style={{ color: '#15803d' }}>{previewData.eligible_count}</strong></span>
                          <span>Already billed: <strong style={{ color: '#b45309' }}>{previewData.already_billed_count}</strong></span>
                          {previewData.blocked_count > 0 && (
                            <span>Blocked (Partial stay): <strong style={{ color: '#b91c1c' }}>{previewData.blocked_count}</strong></span>
                          )}
                        </div>

                        <div className="mf-preview-roster" style={{ marginTop: 10 }}>
                          {previewData.preview_list && previewData.preview_list.length > 0 ? (
                            previewData.preview_list.map((res) => (
                              <div key={res.resident_id} className="mf-roster-item" style={{ flexDirection: 'column', alignItems: 'flex-start', gap: 4 }}>
                                <div style={{ display: 'flex', justifyContent: 'space-between', width: '100%', alignItems: 'center' }}>
                                  <div>
                                    <strong>{res.full_name}</strong>
                                    <span style={{ color: 'var(--mf-text-muted)', marginLeft: 8 }}>({res.resident_code})</span>
                                    <span style={{ color: '#84a98c', marginLeft: 8, fontSize: 11 }}>Room {res.room_number}</span>
                                    {res.resident_status === 'INACTIVE' && (
                                      <span style={{ color: '#d97706', marginLeft: 8, fontSize: 10, border: '1px solid #d97706', borderRadius: 4, padding: '1px 4px' }}>
                                        Checked Out Resident
                                      </span>
                                    )}
                                  </div>
                                  <div>
                                    {res.status === 'BLOCKED_POLICY_UNCONFIRMED' ? (
                                      <span className="mf-badge" style={{ fontSize: 10, background: '#fee2e2', color: '#991b1b' }}>
                                        Blocked (Partial Stay)
                                      </span>
                                    ) : res.status === 'ALREADY_BILLED' ? (
                                      <span className="mf-badge mf-badge-unpaid" style={{ fontSize: 10 }}>
                                        Billed ({res.fee_code})
                                      </span>
                                    ) : (
                                      <span className="mf-badge mf-badge-paid" style={{ fontSize: 10 }}>
                                        Ready to generate
                                      </span>
                                    )}
                                  </div>
                                </div>
                                {res.status === 'BLOCKED_POLICY_UNCONFIRMED' && (
                                  <div style={{ fontSize: 10.5, color: '#b91c1c', fontStyle: 'italic' }}>
                                    {res.block_reason || 'Partial-month stay detected; prorating policy unconfirmed by hostel management.'}
                                  </div>
                                )}
                              </div>
                            ))
                          ) : (
                            <div style={{ padding: 12, textAlign: 'center', color: 'var(--mf-text-muted)' }}>
                              No residents found for this period.
                            </div>
                          )}
                        </div>
                      </div>
                    ) : (
                      <p style={{ margin: 0, color: 'var(--mf-text-muted)' }}>
                        Select a billing period to preview eligible residents.
                      </p>
                    )}
                  </div>
                </div>

                <div className="mf-modal-footer">
                  <button
                    type="button"
                    className="mf-btn mf-btn-secondary"
                    disabled={submitting}
                    onClick={() => setActiveModal(null)}
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    className="mf-btn mf-btn-primary"
                    disabled={submitting || (previewData && previewData.eligible_count === 0)}
                  >
                    {submitting ? (
                      <>
                        <RefreshCw size={15} className="mf-icon-spin" />
                        <span>Generating fees...</span>
                      </>
                    ) : (
                      <>
                        <CheckCircle2 size={15} />
                        <span>Confirm & Generate</span>
                      </>
                    )}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}

        {/* ================================================================== */}
        {/* MODAL B: FEE DETAILS                                               */}
        {/* ================================================================== */}
        {activeModal === 'details' && selectedFee && (
          <div className="mf-modal-backdrop" onClick={() => setActiveModal(null)}>
            <div
              className="mf-modal"
              role="dialog"
              aria-modal="true"
              aria-labelledby="modal-det-title"
              onClick={(e) => e.stopPropagation()}
            >
              <div className="mf-modal-header">
                <div>
                  <div className="mf-modal-eyebrow">Fee Record Details</div>
                  <h3 id="modal-det-title" className="mf-modal-title">Fee #{selectedFee.fee_code}</h3>
                </div>
                <button
                  type="button"
                  className="mf-close-btn"
                  aria-label="Close dialog"
                  onClick={() => setActiveModal(null)}
                >
                  <X size={20} />
                </button>
              </div>

              <div className="mf-modal-body">
                <div className="mf-details-grid">
                  <div className="mf-details-item">
                    <div className="mf-details-label">Resident Name</div>
                    <div className="mf-details-val">{selectedFee.resident_name}</div>
                    <span style={{ fontSize: 11, color: 'var(--mf-text-muted)' }}>{selectedFee.resident_code}</span>
                  </div>

                  <div className="mf-details-item">
                    <div className="mf-details-label">Room Allocation</div>
                    <div className="mf-details-val">
                      {selectedFee.room_number ? `Room ${selectedFee.room_number}` : 'Unallocated'}
                    </div>
                    <span style={{ fontSize: 11, color: 'var(--mf-text-muted)' }}>
                      {selectedFee.floor_name || '—'}
                    </span>
                  </div>

                  <div className="mf-details-item">
                    <div className="mf-details-label">Billing Period</div>
                    <div className="mf-details-val">{formatPeriod(selectedFee.billing_period)}</div>
                    <span style={{ fontSize: 11, color: 'var(--mf-text-muted)' }}>{selectedFee.billing_period}</span>
                  </div>

                  <div className="mf-details-item">
                    <div className="mf-details-label">Payment Due Date</div>
                    <div className="mf-details-val">{selectedFee.due_date}</div>
                  </div>

                  <div className="mf-details-item">
                    <div className="mf-details-label">Amount Due</div>
                    <div className="mf-details-val" style={{ color: 'var(--mf-green)', fontSize: 16 }}>
                      {formatCurrency(selectedFee.amount_due)}
                    </div>
                  </div>

                  <div className="mf-details-item">
                    <div className="mf-details-label">Payment Status</div>
                    <div className="mf-details-val">
                      {selectedFee.payment_available ? (
                        <span className="mf-badge mf-badge-paid">
                          {selectedFee.payment_status}
                        </span>
                      ) : (
                        <span className="mf-badge" style={{ background: '#f3f4f6', color: '#4b5563' }}>
                          Unavailable
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="mf-details-item mf-details-item-full">
                    <div className="mf-details-label">Remarks</div>
                    <div className="mf-details-val" style={{ fontWeight: 400 }}>
                      {selectedFee.remarks || 'No additional remarks provided.'}
                    </div>
                  </div>

                  <div className="mf-details-item mf-details-item-full">
                    <div className="mf-details-label">Audit & Record Timestamps</div>
                    <div style={{ fontSize: 12, color: 'var(--mf-text-muted)' }}>
                      Created: {selectedFee.created_at || '—'} · Last Updated: {selectedFee.updated_at || '—'}
                    </div>
                  </div>
                </div>

                <div style={{ background: '#f8faf8', border: '1px solid var(--mf-border)', borderRadius: 8, padding: 12, fontSize: 12 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontWeight: 600, color: 'var(--mf-charcoal)', marginBottom: 4 }}>
                    <Info size={14} color="#84a98c" />
                    <span>Payment Information</span>
                  </div>
                  <p style={{ margin: 0, color: 'var(--mf-text-muted)' }}>
                    Payment records and settlement receipts are maintained in the payment ledger. When unlinked, settlement details are reported as unavailable.
                  </p>
                </div>
              </div>

              <div className="mf-modal-footer">
                <button
                  type="button"
                  className="mf-btn mf-btn-primary"
                  onClick={() => setActiveModal(null)}
                >
                  Close Details
                </button>
              </div>
            </div>
          </div>
        )}

        {/* Global Toast Message */}
        {toast && (
          <div className="mf-toast" role="status">
            <CheckCircle2 size={17} />
            <span>{toast}</span>
          </div>
        )}
      </main>
    </div>
  );
}
