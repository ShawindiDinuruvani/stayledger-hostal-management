import React, { useEffect, useState } from "react";
import Sidebar from "../Sidebar.jsx";
import "./Dashboard.css";

const API_BASE = "/api"; // Uses same-origin Vite proxy to backend

export default function Dashboard({ onLogout, userRole = "admin" }) {
  const [fromDate, setFromDate] = useState("2026-11-01");
  const [toDate, setToDate] = useState("2026-11-30");
  const [activeTab, setActiveTab] = useState("overview"); // overview | monthly | explain

  const [summary, setSummary] = useState(null);
  const [report, setReport] = useState(null);
  const [explainData, setExplainData] = useState(null);

  const [loading, setLoading] = useState(false);
  const [explaining, setExplaining] = useState(false);
  const [error, setError] = useState("");

  async function fetchDashboardAndReports(fromVal = fromDate, toVal = toDate) {
    setLoading(true);
    setError("");

    try {
      const qs = new URLSearchParams({ from: fromVal, to: toVal }).toString();
      const [sumRes, repRes] = await Promise.all([
        fetch(`${API_BASE}/dashboard/summary?${qs}`, { credentials: "include" }),
        fetch(`${API_BASE}/reports/monthly?${qs}`, { credentials: "include" }),
      ]);

      if (!sumRes.ok || !repRes.ok) {
        throw new Error(
          `Could not load dashboard/report APIs (summary: ${sumRes.status}, monthly: ${repRes.status}). Check PHP backend & session.`
        );
      }

      const [sumJson, repJson] = await Promise.all([sumRes.json(), repRes.json()]);
      setSummary(sumJson);
      setReport(repJson);
    } catch (err) {
      setError(err.message || "Unexpected error loading dashboard metrics.");
    } finally {
      setLoading(false);
    }
  }

  async function handleRunExplain() {
    setExplaining(true);
    setError("");
    try {
      const qs = new URLSearchParams({ from: fromDate, to: toDate }).toString();
      const res = await fetch(`${API_BASE}/performance/explain?${qs}`, {
        credentials: "include",
      });
      if (!res.ok) {
        throw new Error(`EXPLAIN endpoint returned ${res.status}`);
      }
      const data = await res.json();
      setExplainData(data);
      setActiveTab("explain");
    } catch (err) {
      setError(err.message || "Failed to run EXPLAIN plan.");
    } finally {
      setExplaining(false);
    }
  }

  useEffect(() => {
    fetchDashboardAndReports(fromDate, toDate);
  }, []);

  const occ = summary?.occupancy ?? {
    occupied_beds: 0,
    total_capacity: 0,
    occupancy_rate: 0,
  };
  const unpaid = summary?.unpaid_fees ?? {
    unpaid_count: 0,
    unpaid_amount: 0,
  };
  const fin = summary?.financials ?? {
    monthly_income: 0,
    monthly_expense: 0,
    net_reconciled: 0,
  };
  const rows = Array.isArray(report?.rows) ? report.rows : [];
  const reconciled = report?.reconciled ?? {
    total_income: fin.monthly_income,
    total_expense: fin.monthly_expense,
    net_balance: fin.net_reconciled,
  };

  const formatMoney = (val) =>
    Number(val || 0).toLocaleString("en-US", {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });

  return (
    <div className="sl-shell sl-dashboard-shell">
      <div className="sl-dashboard-sidebar">
        <Sidebar onLogout={onLogout} userRole={userRole} />
      </div>

      <main className="sl-main sl-dashboard-page">
        <div className="sl-dashboard-inner">
          {/* Top Breadcrumb */}
          <div className="sl-dashboard-breadcrumb">
            <span>Workspace</span>
            <span className="sl-dashboard-breadcrumb-sep">/</span>
            <span className="sl-dashboard-breadcrumb-active">Dashboard, Reports &amp; Performance</span>
          </div>

          {/* Hero Banner */}
          <div className="sl-dashboard-banner">
            <div className="sl-dashboard-banner-text">
              <h1 className="sl-dashboard-banner-title">Real-time hostel operations &amp; financial ledger.</h1>
              <p className="sl-dashboard-banner-subtitle">
                Track bed occupancy, unpaid monthly fees, reconciled income vs. expenses, and SQL query performance.
              </p>
            </div>
            <div className="sl-dashboard-banner-pill">Views • Indexes • EXPLAIN</div>
          </div>

          {/* Section Header + Filter Controls */}
          <div className="sl-dashboard-section-header">
            <div className="sl-dashboard-section-text">
              <h2 className="sl-dashboard-section-title">Executive Overview &amp; Reconciliation</h2>
              <p className="sl-dashboard-section-subtitle">
                Filter by date range to reconcile income, expenses, and net balance.
              </p>
            </div>

            <div className="sl-dashboard-controls">
              <label className="sl-dashboard-date-field">
                <span className="sl-dashboard-date-label">From</span>
                <input
                  type="date"
                  value={fromDate}
                  onChange={(e) => setFromDate(e.target.value)}
                  className="sl-dashboard-date-input"
                />
              </label>

              <label className="sl-dashboard-date-field">
                <span className="sl-dashboard-date-label">To</span>
                <input
                  type="date"
                  value={toDate}
                  onChange={(e) => setToDate(e.target.value)}
                  className="sl-dashboard-date-input"
                />
              </label>

              <button
                type="button"
                onClick={() => fetchDashboardAndReports(fromDate, toDate)}
                disabled={loading}
                className="sl-dashboard-btn-primary"
              >
                {loading ? "Reconciling..." : "Apply & Reconcile"}
              </button>

              <button
                type="button"
                onClick={handleRunExplain}
                disabled={explaining}
                className="sl-dashboard-btn-secondary"
              >
                {explaining ? "Analyzing..." : "EXPLAIN Query"}
              </button>
            </div>
          </div>

          {/* Alert Banner */}
          {error && (
            <div className="sl-dashboard-error" role="alert">
              <span className="sl-dashboard-error-icon">!</span>
              <span className="sl-dashboard-error-text">{error}</span>
            </div>
          )}

          {/* 5 Metric Cards Grid */}
          <div className="sl-dashboard-kpi-grid">
            <div className="sl-dashboard-kpi-card">
              <div className="sl-dashboard-kpi-top">
                <span className="sl-dashboard-kpi-label">Occupancy Rate</span>
                <span className="sl-dashboard-badge-green">Live Capacity</span>
              </div>
              <div className="sl-dashboard-kpi-value">{occ.occupancy_rate}%</div>
              <div className="sl-dashboard-kpi-meta">
                {occ.occupied_beds} / {occ.total_capacity} beds occupied
              </div>
            </div>

            <div className="sl-dashboard-kpi-card">
              <div className="sl-dashboard-kpi-top">
                <span className="sl-dashboard-kpi-label">Unpaid Fees</span>
                <span className="sl-dashboard-badge-amber">Pending Dues</span>
              </div>
              <div className="sl-dashboard-kpi-value">{formatMoney(unpaid.unpaid_amount)}</div>
              <div className="sl-dashboard-kpi-meta">{unpaid.unpaid_count} unpaid fee records</div>
            </div>

            <div className="sl-dashboard-kpi-card">
              <div className="sl-dashboard-kpi-top">
                <span className="sl-dashboard-kpi-label">Monthly Income</span>
                <span className="sl-dashboard-badge-neutral">Payments</span>
              </div>
              <div className="sl-dashboard-kpi-value">{formatMoney(fin.monthly_income)}</div>
              <div className="sl-dashboard-kpi-meta">
                {fromDate} to {toDate}
              </div>
            </div>

            <div className="sl-dashboard-kpi-card">
              <div className="sl-dashboard-kpi-top">
                <span className="sl-dashboard-kpi-label">Monthly Expenses</span>
                <span className="sl-dashboard-badge-neutral">Outflow</span>
              </div>
              <div className="sl-dashboard-kpi-value">{formatMoney(fin.monthly_expense)}</div>
              <div className="sl-dashboard-kpi-meta">
                {fromDate} to {toDate}
              </div>
            </div>

            <div className="sl-dashboard-kpi-card">
              <div className="sl-dashboard-kpi-top">
                <span className="sl-dashboard-kpi-label">Reconciled Net</span>
                <span className="sl-dashboard-badge-green">Balanced</span>
              </div>
              <div
                className={`sl-dashboard-kpi-value ${
                  Number(fin.net_reconciled) < 0 ? "sl-dashboard-kpi-value--negative" : ""
                }`}
              >
                {formatMoney(fin.net_reconciled)}
              </div>
              <div className="sl-dashboard-kpi-meta">Income minus expenses</div>
            </div>
          </div>

          {/* Sub-navigation Pills */}
          <div className="sl-dashboard-tabs">
            <button
              type="button"
              onClick={() => setActiveTab("overview")}
              className={`sl-dashboard-tab ${
                activeTab === "overview" ? "sl-dashboard-tab--active" : ""
              }`}
            >
              Reconciliation Summary
            </button>
            <button
              type="button"
              onClick={() => setActiveTab("monthly")}
              className={`sl-dashboard-tab ${
                activeTab === "monthly" ? "sl-dashboard-tab--active" : ""
              }`}
            >
              Monthly View Report ({rows.length})
            </button>
            <button
              type="button"
              onClick={() => setActiveTab("explain")}
              className={`sl-dashboard-tab ${
                activeTab === "explain" ? "sl-dashboard-tab--active" : ""
              }`}
            >
              EXPLAIN Performance Plan
            </button>
          </div>

          {/* Tab Content Container */}
          <div className="sl-dashboard-panel">
            {activeTab === "overview" && (
              <div>
                <h3 className="sl-dashboard-panel-title">Totals Reconciliation Breakdown</h3>
                <p className="sl-dashboard-panel-desc">
                  Cross-check filtered period totals against the SQL reporting view (<code>view_monthly_financial_summary</code>).
                </p>

                <div className="sl-dashboard-table-wrap">
                  <table className="sl-dashboard-table">
                    <thead>
                      <tr>
                        <th className="sl-dashboard-th">Metric</th>
                        <th className="sl-dashboard-th sl-dashboard-th--right">Filtered Period</th>
                        <th className="sl-dashboard-th sl-dashboard-th--right">View Reconciled Total</th>
                        <th className="sl-dashboard-th sl-dashboard-th--right">Variance</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr>
                        <td className="sl-dashboard-td">Total Income</td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          {formatMoney(fin.monthly_income)}
                        </td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          {formatMoney(reconciled.total_income)}
                        </td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          {formatMoney(Number(fin.monthly_income) - Number(reconciled.total_income))}
                        </td>
                      </tr>
                      <tr>
                        <td className="sl-dashboard-td">Total Expenses</td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          {formatMoney(fin.monthly_expense)}
                        </td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          {formatMoney(reconciled.total_expense)}
                        </td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          {formatMoney(Number(fin.monthly_expense) - Number(reconciled.total_expense))}
                        </td>
                      </tr>
                      <tr className="sl-dashboard-total-row">
                        <td className="sl-dashboard-td"><strong>Net Balance</strong></td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          <strong>{formatMoney(fin.net_reconciled)}</strong>
                        </td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          <strong>{formatMoney(reconciled.net_balance)}</strong>
                        </td>
                        <td className="sl-dashboard-td sl-dashboard-td--right">
                          <strong>
                            {formatMoney(Number(fin.net_reconciled) - Number(reconciled.net_balance))}
                          </strong>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {activeTab === "monthly" && (
              <div>
                <h3 className="sl-dashboard-panel-title">Monthly Income &amp; Expense Reporting View</h3>
                <p className="sl-dashboard-panel-desc">
                  Aggregated monthly series returned from <code>view_monthly_financial_summary</code>.
                </p>

                <div className="sl-dashboard-table-wrap">
                  <table className="sl-dashboard-table">
                    <thead>
                      <tr>
                        <th className="sl-dashboard-th">Period (YYYY-MM)</th>
                        <th className="sl-dashboard-th sl-dashboard-th--right">Income</th>
                        <th className="sl-dashboard-th sl-dashboard-th--right">Expense</th>
                        <th className="sl-dashboard-th sl-dashboard-th--right">Net Balance</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.length === 0 ? (
                        <tr>
                          <td colSpan={4} className="sl-dashboard-empty-td">
                            No monthly view records found for this filter range.
                          </td>
                        </tr>
                      ) : (
                        rows.map((r, idx) => (
                          <tr key={r.period_ym ?? idx}>
                            <td className="sl-dashboard-td">{r.period_ym}</td>
                            <td className="sl-dashboard-td sl-dashboard-td--right">
                              {formatMoney(r.total_income)}
                            </td>
                            <td className="sl-dashboard-td sl-dashboard-td--right">
                              {formatMoney(r.total_expense)}
                            </td>
                            <td className="sl-dashboard-td sl-dashboard-td--right">
                              {formatMoney(r.net_balance)}
                            </td>
                          </tr>
                        ))
                      )}
                    </tbody>
                    {rows.length > 0 && (
                      <tfoot>
                        <tr className="sl-dashboard-total-row">
                          <td className="sl-dashboard-td"><strong>Reconciled Total</strong></td>
                          <td className="sl-dashboard-td sl-dashboard-td--right">
                            <strong>{formatMoney(reconciled.total_income)}</strong>
                          </td>
                          <td className="sl-dashboard-td sl-dashboard-td--right">
                            <strong>{formatMoney(reconciled.total_expense)}</strong>
                          </td>
                          <td className="sl-dashboard-td sl-dashboard-td--right">
                            <strong>{formatMoney(reconciled.net_balance)}</strong>
                          </td>
                        </tr>
                      </tfoot>
                    )}
                  </table>
                </div>
              </div>
            )}

            {activeTab === "explain" && (
              <div>
                <h3 className="sl-dashboard-panel-title">Database Execution Plan (EXPLAIN)</h3>
                <p className="sl-dashboard-panel-desc">
                  Verify index usage and scan strategy across SQLite / PostgreSQL aggregate queries.
                </p>

                {!explainData ? (
                  <div className="sl-dashboard-empty-box">
                    Click <strong>EXPLAIN Query</strong> above to inspect the execution plan.
                  </div>
                ) : (
                  <div>
                    <div className="sl-dashboard-explain-meta">
                      <span><strong>Driver:</strong> {explainData.driver}</span>
                      <span><strong>SQL:</strong> <code>{explainData.query}</code></span>
                    </div>
                    <pre className="sl-dashboard-code-block">{JSON.stringify(explainData.plan, null, 2)}</pre>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </main>
    </div>
  );
}