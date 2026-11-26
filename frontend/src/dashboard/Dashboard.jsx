import React, { useEffect, useState } from "react";
import Sidebar from "../Sidebar.jsx";

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
    <div className="sl-shell">
      <Sidebar onLogout={onLogout} userRole={userRole} />

      <div className="sl-main" style={styles.page}>
        {/* Top Breadcrumb */}
        <div style={styles.breadcrumb}>
          <span>Workspace</span>
          <span style={styles.breadcrumbSep}>/</span>
          <span style={styles.breadcrumbActive}>Dashboard, Reports & Performance</span>
        </div>

        {/* Hero Banner (Matches StayLedger Forest Green Banner) */}
        <div style={styles.banner}>
          <div>
            <h1 style={styles.bannerTitle}>Real-time hostel operations & financial ledger.</h1>
            <p style={styles.bannerSubtitle}>
              Track bed occupancy, unpaid monthly fees, reconciled income vs. expenses, and SQL query performance.
            </p>
          </div>
          <div style={styles.bannerPill}>Views • Indexes • EXPLAIN</div>
        </div>

        {/* Section Header + Filter Controls */}
        <div style={styles.sectionHeaderRow}>
          <div>
            <h2 style={styles.sectionTitle}>Executive Overview & Reconciliation</h2>
            <p style={styles.sectionSubtitle}>
              Filter by date range to reconcile income, expenses, and net balance.
            </p>
          </div>

          <div style={styles.controlsBar}>
            <label style={styles.dateField}>
              <span style={styles.dateLabel}>From</span>
              <input
                type="date"
                value={fromDate}
                onChange={(e) => setFromDate(e.target.value)}
                style={styles.dateInput}
              />
            </label>

            <label style={styles.dateField}>
              <span style={styles.dateLabel}>To</span>
              <input
                type="date"
                value={toDate}
                onChange={(e) => setToDate(e.target.value)}
                style={styles.dateInput}
              />
            </label>

            <button
              type="button"
              onClick={() => fetchDashboardAndReports(fromDate, toDate)}
              disabled={loading}
              style={styles.primaryBtn}
            >
              {loading ? "Reconciling..." : "Apply & Reconcile"}
            </button>

            <button
              type="button"
              onClick={handleRunExplain}
              disabled={explaining}
              style={styles.secondaryBtn}
            >
              {explaining ? "Analyzing..." : "EXPLAIN Query"}
            </button>
          </div>
        </div>

        {/* Alert Banner (Matches Soft Red Error Alert in Screenshot) */}
        {error && (
          <div style={styles.errorAlert}>
            <span style={styles.errorIcon}>!</span>
            <span>{error}</span>
          </div>
        )}

        {/* 5 Metric Cards Grid */}
        <div style={styles.kpiGrid}>
          <div style={styles.kpiCard}>
            <div style={styles.kpiTop}>
              <span style={styles.kpiLabel}>Occupancy Rate</span>
              <span style={styles.badgeGreen}>Live Capacity</span>
            </div>
            <div style={styles.kpiValue}>{occ.occupancy_rate}%</div>
            <div style={styles.kpiMeta}>
              {occ.occupied_beds} / {occ.total_capacity} beds occupied
            </div>
          </div>

          <div style={styles.kpiCard}>
            <div style={styles.kpiTop}>
              <span style={styles.kpiLabel}>Unpaid Fees</span>
              <span style={styles.badgeAmber}>Pending Dues</span>
            </div>
            <div style={styles.kpiValue}>{formatMoney(unpaid.unpaid_amount)}</div>
            <div style={styles.kpiMeta}>{unpaid.unpaid_count} unpaid fee records</div>
          </div>

          <div style={styles.kpiCard}>
            <div style={styles.kpiTop}>
              <span style={styles.kpiLabel}>Monthly Income</span>
              <span style={styles.badgeNeutral}>Payments</span>
            </div>
            <div style={styles.kpiValue}>{formatMoney(fin.monthly_income)}</div>
            <div style={styles.kpiMeta}>
              {fromDate} to {toDate}
            </div>
          </div>

          <div style={styles.kpiCard}>
            <div style={styles.kpiTop}>
              <span style={styles.kpiLabel}>Monthly Expenses</span>
              <span style={styles.badgeNeutral}>Outflow</span>
            </div>
            <div style={styles.kpiValue}>{formatMoney(fin.monthly_expense)}</div>
            <div style={styles.kpiMeta}>
              {fromDate} to {toDate}
            </div>
          </div>

          <div style={styles.kpiCard}>
            <div style={styles.kpiTop}>
              <span style={styles.kpiLabel}>Reconciled Net</span>
              <span style={styles.badgeGreen}>Balanced</span>
            </div>
            <div
              style={{
                ...styles.kpiValue,
                color: Number(fin.net_reconciled) < 0 ? "#b91c1c" : "#0f172a",
              }}
            >
              {formatMoney(fin.net_reconciled)}
            </div>
            <div style={styles.kpiMeta}>Income minus expenses</div>
          </div>
        </div>

        {/* Sub-navigation Pills (Matches StayLedger Pill Tabs) */}
        <div style={styles.tabRow}>
          <button
            type="button"
            onClick={() => setActiveTab("overview")}
            style={activeTab === "overview" ? styles.tabActive : styles.tabIdle}
          >
            Reconciliation Summary
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("monthly")}
            style={activeTab === "monthly" ? styles.tabActive : styles.tabIdle}
          >
            Monthly View Report ({rows.length})
          </button>
          <button
            type="button"
            onClick={() => setActiveTab("explain")}
            style={activeTab === "explain" ? styles.tabActive : styles.tabIdle}
          >
            EXPLAIN Performance Plan
          </button>
        </div>

        {/* Tab Content Container */}
        <div style={styles.panelCard}>
          {activeTab === "overview" && (
            <div>
              <h3 style={styles.panelTitle}>Totals Reconciliation Breakdown</h3>
              <p style={styles.panelDesc}>
                Cross-check filtered period totals against the SQL reporting view (<code>view_monthly_financial_summary</code>).
              </p>

              <div style={styles.tableWrap}>
                <table style={styles.table}>
                  <thead>
                    <tr>
                      <th style={styles.th}>Metric</th>
                      <th style={styles.thRight}>Filtered Period</th>
                      <th style={styles.thRight}>View Reconciled Total</th>
                      <th style={styles.thRight}>Variance</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td style={styles.td}>Total Income</td>
                      <td style={styles.tdRight}>{formatMoney(fin.monthly_income)}</td>
                      <td style={styles.tdRight}>{formatMoney(reconciled.total_income)}</td>
                      <td style={styles.tdRight}>
                        {formatMoney(Number(fin.monthly_income) - Number(reconciled.total_income))}
                      </td>
                    </tr>
                    <tr>
                      <td style={styles.td}>Total Expenses</td>
                      <td style={styles.tdRight}>{formatMoney(fin.monthly_expense)}</td>
                      <td style={styles.tdRight}>{formatMoney(reconciled.total_expense)}</td>
                      <td style={styles.tdRight}>
                        {formatMoney(Number(fin.monthly_expense) - Number(reconciled.total_expense))}
                      </td>
                    </tr>
                    <tr style={styles.totalRow}>
                      <td style={styles.td}><strong>Net Balance</strong></td>
                      <td style={styles.tdRight}><strong>{formatMoney(fin.net_reconciled)}</strong></td>
                      <td style={styles.tdRight}><strong>{formatMoney(reconciled.net_balance)}</strong></td>
                      <td style={styles.tdRight}>
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
              <h3 style={styles.panelTitle}>Monthly Income & Expense Reporting View</h3>
              <p style={styles.panelDesc}>
                Aggregated monthly series returned from <code>view_monthly_financial_summary</code>.
              </p>

              <div style={styles.tableWrap}>
                <table style={styles.table}>
                  <thead>
                    <tr>
                      <th style={styles.th}>Period (YYYY-MM)</th>
                      <th style={styles.thRight}>Income</th>
                      <th style={styles.thRight}>Expense</th>
                      <th style={styles.thRight}>Net Balance</th>
                    </tr>
                  </thead>
                  <tbody>
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={4} style={styles.emptyTd}>
                          No monthly view records found for this filter range.
                        </td>
                      </tr>
                    ) : (
                      rows.map((r, idx) => (
                        <tr key={r.period_ym ?? idx}>
                          <td style={styles.td}>{r.period_ym}</td>
                          <td style={styles.tdRight}>{formatMoney(r.total_income)}</td>
                          <td style={styles.tdRight}>{formatMoney(r.total_expense)}</td>
                          <td style={styles.tdRight}>{formatMoney(r.net_balance)}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                  {rows.length > 0 && (
                    <tfoot>
                      <tr style={styles.totalRow}>
                        <td style={styles.td}><strong>Reconciled Total</strong></td>
                        <td style={styles.tdRight}><strong>{formatMoney(reconciled.total_income)}</strong></td>
                        <td style={styles.tdRight}><strong>{formatMoney(reconciled.total_expense)}</strong></td>
                        <td style={styles.tdRight}><strong>{formatMoney(reconciled.net_balance)}</strong></td>
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            </div>
          )}

          {activeTab === "explain" && (
            <div>
              <h3 style={styles.panelTitle}>Database Execution Plan (EXPLAIN)</h3>
              <p style={styles.panelDesc}>
                Verify index usage and scan strategy across SQLite / PostgreSQL aggregate queries.
              </p>

              {!explainData ? (
                <div style={styles.emptyBox}>
                  Click <strong>EXPLAIN Query</strong> above to inspect the execution plan.
                </div>
              ) : (
                <div>
                  <div style={styles.explainMeta}>
                    <span><strong>Driver:</strong> {explainData.driver}</span>
                    <span><strong>SQL:</strong> <code>{explainData.query}</code></span>
                  </div>
                  <pre style={styles.codeBlock}>{JSON.stringify(explainData.plan, null, 2)}</pre>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

const styles = {
  page: {
    flex: 1,
    minWidth: 0,
    padding: "24px 28px",
    backgroundColor: "#f8fafc",
    minHeight: "100vh",
    color: "#0f172a",
    fontFamily: "Inter, system-ui, -apple-system, sans-serif",
  },
  breadcrumb: {
    display: "flex",
    alignItems: "center",
    gap: "8px",
    fontSize: "13px",
    color: "#64748b",
    marginBottom: "16px",
  },
  breadcrumbSep: {
    color: "#cbd5e1",
  },
  breadcrumbActive: {
    color: "#0f172a",
    fontWeight: 500,
  },
  banner: {
    background: "linear-gradient(135deg, #0b3b24 0%, #14532d 100%)",
    color: "#ffffff",
    borderRadius: "14px",
    padding: "24px 28px",
    display: "flex",
    justifyContent: "space-between",
    alignItems: "center",
    gap: "16px",
    marginBottom: "24px",
    flexWrap: "wrap",
  },
  bannerTitle: {
    margin: "0 0 6px 0",
    fontSize: "24px",
    fontWeight: 700,
    letterSpacing: "-0.02em",
  },
  bannerSubtitle: {
    margin: 0,
    fontSize: "14px",
    color: "#dcfce7",
  },
  bannerPill: {
    backgroundColor: "rgba(255, 255, 255, 0.14)",
    border: "1px solid rgba(255, 255, 255, 0.22)",
    color: "#f0fdf4",
    padding: "7px 14px",
    borderRadius: "999px",
    fontSize: "12px",
    fontWeight: 600,
  },
  sectionHeaderRow: {
    display: "flex",
    justifyContent: "space-between",
    alignItems: "flex-end",
    gap: "16px",
    marginBottom: "16px",
    flexWrap: "wrap",
  },
  sectionTitle: {
    margin: "0 0 4px 0",
    fontSize: "20px",
    fontWeight: 700,
    color: "#0f172a",
  },
  sectionSubtitle: {
    margin: 0,
    fontSize: "13.5px",
    color: "#64748b",
  },
  controlsBar: {
    display: "flex",
    alignItems: "center",
    gap: "10px",
    flexWrap: "wrap",
  },
  dateField: {
    display: "flex",
    alignItems: "center",
    gap: "6px",
    backgroundColor: "#ffffff",
    border: "1px solid #e2e8f0",
    borderRadius: "8px",
    padding: "6px 10px",
    fontSize: "13px",
  },
  dateLabel: {
    color: "#64748b",
    fontWeight: 500,
  },
  dateInput: {
    border: "none",
    outline: "none",
    fontSize: "13px",
    color: "#0f172a",
    backgroundColor: "transparent",
  },
  primaryBtn: {
    backgroundColor: "#0f3d28",
    color: "#ffffff",
    border: "none",
    borderRadius: "8px",
    padding: "9px 15px",
    fontSize: "13px",
    fontWeight: 600,
    cursor: "pointer",
  },
  secondaryBtn: {
    backgroundColor: "#ffffff",
    color: "#0f172a",
    border: "1px solid #cbd5e1",
    borderRadius: "8px",
    padding: "9px 14px",
    fontSize: "13px",
    fontWeight: 600,
    cursor: "pointer",
  },
  errorAlert: {
    display: "flex",
    alignItems: "center",
    gap: "10px",
    backgroundColor: "#fef2f2",
    border: "1px solid #fecaca",
    color: "#b91c1c",
    borderRadius: "10px",
    padding: "12px 16px",
    fontSize: "13.5px",
    marginBottom: "18px",
  },
  errorIcon: {
    width: "20px",
    height: "20px",
    borderRadius: "999px",
    border: "1.5px solid #dc2626",
    display: "inline-flex",
    alignItems: "center",
    justifyContent: "center",
    fontSize: "12px",
    fontWeight: 700,
    flexShrink: 0,
  },
  kpiGrid: {
    display: "grid",
    gridTemplateColumns: "repeat(auto-fit, minmax(205px, 1fr))",
    gap: "14px",
    marginBottom: "22px",
  },
  kpiCard: {
    backgroundColor: "#ffffff",
    border: "1px solid #e2e8f0",
    borderRadius: "12px",
    padding: "16px 18px",
  },
  kpiTop: {
    display: "flex",
    justifyContent: "space-between",
    alignItems: "center",
    gap: "8px",
    marginBottom: "10px",
  },
  kpiLabel: {
    fontSize: "13px",
    fontWeight: 500,
    color: "#475569",
  },
  kpiValue: {
    fontSize: "24px",
    fontWeight: 700,
    color: "#0f172a",
    marginBottom: "6px",
  },
  kpiMeta: {
    fontSize: "12px",
    color: "#64748b",
  },
  badgeGreen: {
    backgroundColor: "#dcfce7",
    color: "#166534",
    fontSize: "11px",
    fontWeight: 600,
    padding: "3px 8px",
    borderRadius: "999px",
  },
  badgeAmber: {
    backgroundColor: "#fef3c7",
    color: "#92400e",
    fontSize: "11px",
    fontWeight: 600,
    padding: "3px 8px",
    borderRadius: "999px",
  },
  badgeNeutral: {
    backgroundColor: "#f1f5f9",
    color: "#334155",
    fontSize: "11px",
    fontWeight: 600,
    padding: "3px 8px",
    borderRadius: "999px",
  },
  tabRow: {
    display: "flex",
    gap: "10px",
    marginBottom: "14px",
    flexWrap: "wrap",
  },
  tabActive: {
    backgroundColor: "#0f3d28",
    color: "#ffffff",
    border: "1px solid #0f3d28",
    borderRadius: "8px",
    padding: "8px 14px",
    fontSize: "13px",
    fontWeight: 600,
    cursor: "pointer",
  },
  tabIdle: {
    backgroundColor: "#ffffff",
    color: "#334155",
    border: "1px solid #e2e8f0",
    borderRadius: "8px",
    padding: "8px 14px",
    fontSize: "13px",
    fontWeight: 500,
    cursor: "pointer",
  },
  panelCard: {
    backgroundColor: "#ffffff",
    border: "1px solid #e2e8f0",
    borderRadius: "12px",
    padding: "20px",
  },
  panelTitle: {
    margin: "0 0 4px 0",
    fontSize: "16px",
    fontWeight: 700,
    color: "#0f172a",
  },
  panelDesc: {
    margin: "0 0 16px 0",
    fontSize: "13px",
    color: "#64748b",
  },
  tableWrap: {
    overflowX: "auto",
  },
  table: {
    width: "100%",
    borderCollapse: "collapse",
    fontSize: "13.5px",
  },
  th: {
    textAlign: "left",
    padding: "10px 12px",
    borderBottom: "1px solid #e2e8f0",
    color: "#475569",
    fontWeight: 600,
    backgroundColor: "#f8fafc",
  },
  thRight: {
    textAlign: "right",
    padding: "10px 12px",
    borderBottom: "1px solid #e2e8f0",
    color: "#475569",
    fontWeight: 600,
    backgroundColor: "#f8fafc",
  },
  td: {
    padding: "10px 12px",
    borderBottom: "1px solid #f1f5f9",
    color: "#0f172a",
  },
  tdRight: {
    padding: "10px 12px",
    borderBottom: "1px solid #f1f5f9",
    color: "#0f172a",
    textAlign: "right",
    fontVariantNumeric: "tabular-nums",
  },
  emptyTd: {
    padding: "18px 12px",
    textAlign: "center",
    color: "#64748b",
  },
  totalRow: {
    backgroundColor: "#f8fafc",
  },
  emptyBox: {
    padding: "20px",
    borderRadius: "8px",
    backgroundColor: "#f8fafc",
    border: "1px dashed #cbd5e1",
    color: "#475569",
    fontSize: "13.5px",
  },
  explainMeta: {
    display: "flex",
    flexDirection: "column",
    gap: "6px",
    fontSize: "13px",
    marginBottom: "12px",
    color: "#334155",
  },
  codeBlock: {
    backgroundColor: "#0f172a",
    color: "#a7f3d0",
    padding: "14px",
    borderRadius: "10px",
    fontSize: "12.5px",
    overflowX: "auto",
    margin: 0,
  },
};