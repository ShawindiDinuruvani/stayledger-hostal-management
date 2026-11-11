import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Building2,
  ArrowLeftRight,
  LogOut,
  Plus,
  Search,
  Check,
  AlertCircle,
  BedDouble,
  History,
  Lock,
  X,
  RefreshCw,
  Leaf,
  Download
} from 'lucide-react';
import Sidebar from './Sidebar.jsx';

const API_BASE = 'http://localhost:8000/api';

export default function RoomAllocations() {
  const [rooms, setRooms] = useState([]);
  const [allocations, setAllocations] = useState([]);
  const [residents, setResidents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [apiError, setApiError] = useState('');
  const [toast, setToast] = useState('');

  const [activeTab, setActiveTab] = useState('occupancy');
  const [floorFilter, setFloorFilter] = useState('All');
  const [statusFilter, setStatusFilter] = useState('All');
  const [selectedRoomFilter, setSelectedRoomFilter] = useState(null);
  const [search, setSearch] = useState('');

  const [activeModal, setActiveModal] = useState(null);
  const [selectedAlloc, setSelectedAlloc] = useState(null);
  const [formError, setFormError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const todayStr = new Date().toISOString().split('T')[0];

  const [allocForm, setAllocForm] = useState({
    resident_id: '',
    room_id: '',
    start_date: todayStr,
    remarks: ''
  });
  const [transferForm, setTransferForm] = useState({
    target_room_id: '',
    transfer_date: todayStr,
    reason: ''
  });
  const [checkoutForm, setCheckoutForm] = useState({
    checkout_date: todayStr,
    remarks: 'Standard checkout - keys returned'
  });

  const showToast = msg => {
    setToast(msg);
    setTimeout(() => setToast(''), 4000);
  };

  const fetchDashboardData = useCallback(async () => {
    setLoading(true);
    setApiError('');
    try {
      const res = await fetch(`${API_BASE}/allocations/dashboard`);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      setRooms(data.rooms || []);
      setAllocations(data.allocations || []);
      setResidents(data.residents || []);
    } catch (err) {
      setApiError('Could not connect to MySQL API on localhost:8000. Ensure PHP server & XAMPP MySQL are running.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchDashboardData();
  }, [fetchDashboardData]);

  const metrics = useMemo(() => {
    const totalBeds = rooms.reduce((sum, r) => sum + Number(r.capacity), 0);
    const occupiedBeds = rooms.reduce((sum, r) => sum + Number(r.occupied_beds), 0);
    const fullRooms = rooms.filter(r => r.occupancy_status === 'Full').length;
    const transfersCount = allocations.filter(a => a.status === 'Transferred').length;
    const unallocatedCount = residents.filter(r => !Number(r.is_allocated)).length;
    return {
      totalBeds,
      occupiedBeds,
      availableBeds: totalBeds - occupiedBeds,
      fullRooms,
      transfersCount,
      unallocatedCount
    };
  }, [rooms, allocations, residents]);

  const visibleRooms = useMemo(() => {
    if (floorFilter === 'All') return rooms;
    return rooms.filter(r => r.floor_name === floorFilter);
  }, [rooms, floorFilter]);

  const filteredAllocations = useMemo(() => {
    return allocations.filter(a => {
      if (activeTab === 'active' && a.status !== 'Active') return false;
      if (activeTab === 'history' && a.status === 'Active') return false;

      const matchesStatus = statusFilter === 'All' || a.status === statusFilter;
      const matchesRoom = !selectedRoomFilter || a.room === selectedRoomFilter;
      const q = search.toLowerCase();
      const matchesSearch =
        (a.residentName || '').toLowerCase().includes(q) ||
        (a.residentCode || '').toLowerCase().includes(q) ||
        (a.room || '').toLowerCase().includes(q) ||
        (a.allocId || '').toLowerCase().includes(q) ||
        (a.remarks || '').toLowerCase().includes(q);
      return matchesStatus && matchesRoom && matchesSearch;
    });
  }, [allocations, activeTab, statusFilter, selectedRoomFilter, search]);

  const handleAllocateSubmit = async e => {
    e.preventDefault();
    setFormError('');
    setSubmitting(true);
    try {
      const res = await fetch(`${API_BASE}/allocations`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(allocForm)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Allocation failed');
      setActiveModal(null);
      showToast(data.message);
      fetchDashboardData();
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleTransferSubmit = async e => {
    e.preventDefault();
    setFormError('');
    setSubmitting(true);
    try {
      const res = await fetch(`${API_BASE}/allocations/${selectedAlloc.allocation_id}/transfer`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(transferForm)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Transfer failed');
      setActiveModal(null);
      setSelectedAlloc(null);
      showToast(data.message);
      fetchDashboardData();
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const handleCheckoutSubmit = async e => {
    e.preventDefault();
    setFormError('');
    setSubmitting(true);
    try {
      const res = await fetch(`${API_BASE}/allocations/${selectedAlloc.allocation_id}/checkout`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(checkoutForm)
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.message || 'Checkout failed');
      setActiveModal(null);
      setSelectedAlloc(null);
      showToast(data.message);
      fetchDashboardData();
    } catch (err) {
      setFormError(err.message);
    } finally {
      setSubmitting(false);
    }
  };

  const openAllocateModal = (preselectedRoomId = '') => {
    setFormError('');
    const freeRes = residents.find(r => !Number(r.is_allocated))?.resident_id || '';
    const freeRoom = preselectedRoomId || rooms.find(r => r.available_beds > 0)?.room_id || '';
    setAllocForm({
      resident_id: freeRes,
      room_id: freeRoom,
      start_date: todayStr,
      remarks: ''
    });
    setActiveModal('allocate');
  };

  const exportCSV = () => {
    const headers = ['Code', 'Resident Code', 'Resident Name', 'Room', 'Floor', 'From Date', 'Until Date', 'Status', 'Remarks'];
    const rows = filteredAllocations.map(a => [
      a.allocId,
      a.residentCode,
      a.residentName,
      a.room,
      a.floor,
      a.startDate,
      a.endDate || 'Ongoing',
      a.status,
      a.remarks || ''
    ]);
    const csv = [
      headers.join(','),
      ...rows.map(r => r.map(v => `"${String(v).replaceAll('"', '""')}"`).join(','))
    ].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
    link.download = 'stayledger-room-allocations.csv';
    link.click();
    showToast('Exported allocation records to CSV.');
  };

  return (
    <div className="sl-layout">
      <Sidebar />

      <main className="sl-workspace">
        <div className="sl-topbar">
          <div className="sl-breadcrumb">
            Workspace / <span>Allocations & Rooms</span>
          </div>
          <div className="sl-topbar-right">
            <span>November 2026</span>
            <div className="sl-user-pill">NL</div>
          </div>
        </div>

        <div className="sl-hero-banner">
          <div>
            <h1>Efficient spaces make calm hostels.</h1>
            <p>Manage room allocations, seamless transfers, and smooth checkouts across all 20 rooms in real-time.</p>
          </div>
        </div>

        <header className="sl-alloc-header">
          <div>
            <h2>Room Allocations & Occupancy</h2>
            <p>Live overview of bed availability and active resident assignments.</p>
          </div>
          <div className="sl-header-actions">
            <button className="sl-btn sl-btn-secondary" onClick={exportCSV}>
              <Download size={15} /> Export CSV
            </button>
            <button className="sl-btn sl-btn-secondary" onClick={fetchDashboardData}>
              <RefreshCw size={15} /> Refresh DB
            </button>
            <button className="sl-btn sl-btn-primary" onClick={() => openAllocateModal()}>
              <Plus size={16} /> Allocate Room
            </button>
          </div>
        </header>

        {apiError && (
          <div className="sl-alert">
            <AlertCircle size={16} />
            <span>{apiError}</span>
          </div>
        )}

        <section className="sl-kpi-grid">
          <div className="sl-kpi-card">
            <div className="sl-kpi-top">
              <span className="sl-kpi-icon"><BedDouble size={20} /></span>
              <span className="sl-badge">Occupancy View</span>
            </div>
            <span className="sl-kpi-label">Occupied Beds</span>
            <strong>{metrics.occupiedBeds} / {metrics.totalBeds}</strong>
            <small>{metrics.availableBeds} beds currently open</small>
          </div>

          <div className="sl-kpi-card">
            <div className="sl-kpi-top">
              <span className="sl-kpi-icon"><Building2 size={20} /></span>
              <span className="sl-badge">Capacity Status</span>
            </div>
            <span className="sl-kpi-label">Available Rooms</span>
            <strong>{rooms.length - metrics.fullRooms} / {rooms.length}</strong>
            <small>{metrics.fullRooms} room(s) at full capacity</small>
          </div>

          <div className="sl-kpi-card">
            <div className="sl-kpi-top">
              <span className="sl-kpi-icon"><ArrowLeftRight size={20} /></span>
              <span className="sl-badge amber">Swaps</span>
            </div>
            <span className="sl-kpi-label">Room Transfers</span>
            <strong>{metrics.transfersCount}</strong>
            <small>Completed room transfers</small>
          </div>

          <div className="sl-kpi-card">
            <div className="sl-kpi-top">
              <span className="sl-kpi-icon"><Lock size={20} /></span>
              <span className="sl-badge">Queue</span>
            </div>
            <span className="sl-kpi-label">Unallocated Residents</span>
            <strong>{metrics.unallocatedCount}</strong>
            <small>Eligible for room allocation</small>
          </div>
        </section>

        <div className="sl-module-tabs">
          <button
            className={'sl-mod-tab ' + (activeTab === 'occupancy' ? 'active' : '')}
            onClick={() => setActiveTab('occupancy')}
          >
            <Building2 size={16} /> Rooms 01–20 Occupancy Grid
          </button>
          <button
            className={'sl-mod-tab ' + (activeTab === 'active' ? 'active' : '')}
            onClick={() => setActiveTab('active')}
          >
            <ArrowLeftRight size={16} /> Active Allocations & Transfers
          </button>
          <button
            className={'sl-mod-tab ' + (activeTab === 'history' ? 'active' : '')}
            onClick={() => setActiveTab('history')}
          >
            <History size={16} /> Allocation History & Checkouts
          </button>
        </div>

        {activeTab === 'occupancy' && (
          <section className="sl-panel">
            <div className="sl-panel-header">
              <div>
                <h2>Live Room Capacity</h2>
                <p>Showing Rooms 01 to 20 across First and Second floor</p>
              </div>
              <div className="sl-tabs">
                {['All', 'First floor', 'Second floor'].map(fl => (
                  <button
                    key={fl}
                    className={'sl-tab ' + (floorFilter === fl ? 'active' : '')}
                    onClick={() => setFloorFilter(fl)}
                  >
                    {fl}
                  </button>
                ))}
              </div>
            </div>

            <div className="sl-room-grid">
              {visibleRooms.map(rm => {
                const isSelected = selectedRoomFilter === rm.room_number;
                const isFull = rm.occupancy_status === 'Full';
                return (
                  <div
                    key={rm.room_id}
                    className={'sl-room-card ' + (isSelected ? 'selected' : '')}
                    onClick={() => {
                      setSelectedRoomFilter(isSelected ? null : rm.room_number);
                      setActiveTab('active');
                    }}
                  >
                    <div className="sl-room-top">
                      <div>
                        <strong>Room {rm.room_number}</strong>
                        <small>{rm.floor_name}</small>
                      </div>
                      <span className={'sl-badge ' + (isFull ? 'amber' : '')}>
                        {isFull ? 'Full' : `${rm.available_beds} free`}
                      </span>
                    </div>

                    <div className="sl-bed-bars">
                      {Array.from({ length: Number(rm.capacity) }, (_, idx) => (
                        <span
                          key={idx}
                          className={'sl-bed-bar ' + (idx < Number(rm.occupied_beds) ? 'occupied' : '')}
                        />
                      ))}
                    </div>

                    <div className="sl-room-footer">
                      <span>{rm.occupied_beds}/{rm.capacity} beds occupied</span>
                      {!isFull ? (
                        <button
                          className="sl-mini-btn"
                          onClick={e => {
                            e.stopPropagation();
                            openAllocateModal(rm.room_id);
                          }}
                        >
                          + Assign
                        </button>
                      ) : (
                        <span>Locked</span>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          </section>
        )}

        {(activeTab === 'active' || activeTab === 'history') && (
          <section className="sl-panel">
            <div className="sl-panel-header">
              <div>
                <h2>
                  {activeTab === 'active' ? 'Active Room Allocations & Transfers' : 'Allocation History & Checkouts'}
                  {selectedRoomFilter && (
                    <button
                      className="sl-mini-btn"
                      style={{ marginLeft: 10 }}
                      onClick={() => setSelectedRoomFilter(null)}
                    >
                      Room {selectedRoomFilter} ✕
                    </button>
                  )}
                </h2>
                <p>{filteredAllocations.length} record(s) matching current view</p>
              </div>

              <div className="sl-controls">
                <div className="sl-search">
                  <Search size={15} />
                  <input
                    placeholder="Search resident, room, code…"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                  />
                </div>
              </div>
            </div>

            <div className="sl-table-wrap">
              <table className="sl-table">
                <thead>
                  <tr>
                    <th>Code</th>
                    <th>Resident</th>
                    <th>Room</th>
                    <th>From Date</th>
                    <th>Until Date</th>
                    <th>Status</th>
                    <th>Remarks</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredAllocations.map(row => (
                    <tr key={row.allocation_id}>
                      <td><span className="sl-code">{row.allocId}</span></td>
                      <td>
                        <strong>{row.residentName}</strong>
                        <span className="sl-subtext">{row.residentCode}</span>
                      </td>
                      <td>
                        <strong>Room {row.room}</strong>
                        <span className="sl-subtext">{row.floor}</span>
                      </td>
                      <td>{row.startDate}</td>
                      <td>{row.endDate || <strong>Ongoing</strong>}</td>
                      <td>
                        <span
                          className={
                            'sl-badge ' +
                            (row.status === 'Transferred'
                              ? 'amber'
                              : row.status === 'Checked Out'
                              ? 'neutral'
                              : '')
                          }
                        >
                          {row.status}
                        </span>
                      </td>
                      <td>{row.remarks}</td>
                      <td>
                        {row.status === 'Active' ? (
                          <div className="sl-actions">
                            <button
                              className="sl-act-btn transfer"
                              onClick={() => {
                                setFormError('');
                                setSelectedAlloc(row);
                                const freeRm = rooms.find(
                                  r => r.available_beds > 0 && r.room_id !== row.room_id
                                );
                                setTransferForm({
                                  target_room_id: freeRm ? freeRm.room_id : '',
                                  transfer_date: todayStr,
                                  reason: ''
                                });
                                setActiveModal('transfer');
                              }}
                            >
                              <ArrowLeftRight size={13} /> Transfer
                            </button>
                            <button
                              className="sl-act-btn checkout"
                              onClick={() => {
                                setFormError('');
                                setSelectedAlloc(row);
                                setCheckoutForm({
                                  checkout_date: todayStr,
                                  remarks: 'Standard checkout - keys returned'
                                });
                                setActiveModal('checkout');
                              }}
                            >
                              <LogOut size={13} /> Checkout
                            </button>
                          </div>
                        ) : (
                          <span className="sl-subtext"><History size={12} /> Closed</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {filteredAllocations.length === 0 && (
                <div style={{ padding: 36, textAlign: 'center', color: '#6e8077' }}>
                  {loading ? 'Loading from MySQL...' : 'No matching allocation records found.'}
                </div>
              )}
            </div>
          </section>
        )}
      </main>

      {activeModal === 'allocate' && (
        <div className="sl-modal-backdrop" onClick={() => setActiveModal(null)}>
          <div className="sl-modal" onClick={e => e.stopPropagation()}>
            <div className="sl-modal-top">
              <h3>Allocate Room to Resident</h3>
              <button className="sl-close-btn" onClick={() => setActiveModal(null)}><X size={20} /></button>
            </div>
            {formError && <div className="sl-alert"><AlertCircle size={16} /><span>{formError}</span></div>}
            <form className="sl-form" onSubmit={handleAllocateSubmit}>
              <label>
                Select Resident
                <select
                  required
                  value={allocForm.resident_id}
                  onChange={e => setAllocForm({ ...allocForm, resident_id: e.target.value })}
                >
                  <option value="" disabled>-- Choose resident --</option>
                  {residents.map(r => (
                    <option key={r.resident_id} value={r.resident_id} disabled={Boolean(Number(r.is_allocated))}>
                      {r.full_name} ({r.resident_code}) {Number(r.is_allocated) ? '— [Already Active]' : ''}
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Target Room (Rooms 01–20)
                <select
                  required
                  value={allocForm.room_id}
                  onChange={e => setAllocForm({ ...allocForm, room_id: e.target.value })}
                >
                  <option value="" disabled>-- Choose room --</option>
                  {rooms.map(rm => (
                    <option key={rm.room_id} value={rm.room_id} disabled={rm.available_beds <= 0}>
                      Room {rm.room_number} ({rm.floor_name}) — {rm.occupied_beds}/{rm.capacity} Occupied
                    </option>
                  ))}
                </select>
              </label>
              <label>
                Start Date
                <input
                  type="date"
                  required
                  value={allocForm.start_date}
                  onChange={e => setAllocForm({ ...allocForm, start_date: e.target.value })}
                />
              </label>
              <label>
                Remarks
                <input
                  type="text"
                  placeholder="Optional allocation notes"
                  value={allocForm.remarks}
                  onChange={e => setAllocForm({ ...allocForm, remarks: e.target.value })}
                />
              </label>
              <button type="submit" disabled={submitting} className="sl-btn sl-btn-primary" style={{ width: '100%' }}>
                <Check size={16} /> {submitting ? 'Saving...' : 'Confirm Allocation'}
              </button>
            </form>
          </div>
        </div>
      )}

      {activeModal === 'transfer' && selectedAlloc && (
        <div className="sl-modal-backdrop" onClick={() => setActiveModal(null)}>
          <div className="sl-modal" onClick={e => e.stopPropagation()}>
            <div className="sl-modal-top">
              <h3>Transfer {selectedAlloc.residentName} (From Room {selectedAlloc.room})</h3>
              <button className="sl-close-btn" onClick={() => setActiveModal(null)}><X size={20} /></button>
            </div>
            {formError && <div className="sl-alert"><AlertCircle size={16} /><span>{formError}</span></div>}
            <form className="sl-form" onSubmit={handleTransferSubmit}>
              <label>
                New Destination Room
                <select
                  required
                  value={transferForm.target_room_id}
                  onChange={e => setTransferForm({ ...transferForm, target_room_id: e.target.value })}
                >
                  <option value="" disabled>-- Choose destination room --</option>
                  {rooms
                    .filter(r => r.room_id !== selectedAlloc.room_id)
                    .map(rm => (
                      <option key={rm.room_id} value={rm.room_id} disabled={rm.available_beds <= 0}>
                        Room {rm.room_number} ({rm.floor_name}) — {rm.available_beds} bed(s) free
                      </option>
                    ))}
                </select>
              </label>
              <label>
                Transfer Date
                <input
                  type="date"
                  required
                  min={selectedAlloc.startDate}
                  value={transferForm.transfer_date}
                  onChange={e => setTransferForm({ ...transferForm, transfer_date: e.target.value })}
                />
              </label>
              <label>
                Reason for Transfer
                <input
                  type="text"
                  required
                  placeholder="e.g., Moved to quiet study wing"
                  value={transferForm.reason}
                  onChange={e => setTransferForm({ ...transferForm, reason: e.target.value })}
                />
              </label>
              <button type="submit" disabled={submitting} className="sl-btn sl-btn-primary" style={{ width: '100%' }}>
                <ArrowLeftRight size={16} /> {submitting ? 'Transferring...' : 'Execute Atomic Transfer'}
              </button>
            </form>
          </div>
        </div>
      )}

      {activeModal === 'checkout' && selectedAlloc && (
        <div className="sl-modal-backdrop" onClick={() => setActiveModal(null)}>
          <div className="sl-modal" onClick={e => e.stopPropagation()}>
            <div className="sl-modal-top">
              <h3>Checkout {selectedAlloc.residentName} (Room {selectedAlloc.room})</h3>
              <button className="sl-close-btn" onClick={() => setActiveModal(null)}><X size={20} /></button>
            </div>
            {formError && <div className="sl-alert"><AlertCircle size={16} /><span>{formError}</span></div>}
            <form className="sl-form" onSubmit={handleCheckoutSubmit}>
              <label>
                Checkout Date
                <input
                  type="date"
                  required
                  min={selectedAlloc.startDate}
                  value={checkoutForm.checkout_date}
                  onChange={e => setCheckoutForm({ ...checkoutForm, checkout_date: e.target.value })}
                />
              </label>
              <label>
                Clearance Remarks
                <input
                  type="text"
                  required
                  value={checkoutForm.remarks}
                  onChange={e => setCheckoutForm({ ...checkoutForm, remarks: e.target.value })}
                />
              </label>
              <button type="submit" disabled={submitting} className="sl-btn sl-btn-primary" style={{ width: '100%' }}>
                <LogOut size={16} /> {submitting ? 'Processing...' : 'Confirm Checkout & Release Bed'}
              </button>
            </form>
          </div>
        </div>
      )}

      {toast && (
        <div className="sl-toast">
          <Check size={16} /> {toast}
        </div>
      )}
    </div>
  );
}