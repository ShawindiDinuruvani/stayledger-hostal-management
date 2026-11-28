import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  AlertCircle,
  BedDouble,
  Building2,
  Check,
  Pencil,
  Plus,
  RefreshCw,
  Search,
  Trash2,
  X
} from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import { fetchWithAuth } from '../users/authFetch.js';
import '../allocations/allocations.css';
import './rooms.css';

const API_BASE = '/native-api';
const EMPTY_ROOM = { room_number: '', floor_id: '', capacity: '3', status: 'AVAILABLE' };
const EMPTY_FLOOR = { floor_name: '' };

export default function RoomsFloors({ onLogout, userRole }) {
  const [rooms, setRooms] = useState([]);
  const [floors, setFloors] = useState([]);
  const [loading, setLoading] = useState(true);
  const [apiError, setApiError] = useState('');
  const [search, setSearch] = useState('');
  const [floorFilter, setFloorFilter] = useState('All');
  const [activeTab, setActiveTab] = useState('rooms');
  const [modal, setModal] = useState('');
  const [roomForm, setRoomForm] = useState(EMPTY_ROOM);
  const [floorForm, setFloorForm] = useState(EMPTY_FLOOR);
  const [selectedRoom, setSelectedRoom] = useState(null);
  const [selectedFloor, setSelectedFloor] = useState(null);
  const [formError, setFormError] = useState('');
  const [saving, setSaving] = useState(false);
  const [toast, setToast] = useState('');

  const loadData = useCallback(async () => {
    setLoading(true);
    setApiError('');
    try {
      const response = await fetchWithAuth(`${API_BASE}/rooms-management`);
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || 'Could not load rooms and floors.');
      setRooms(Array.isArray(data.rooms) ? data.rooms : []);
      setFloors(Array.isArray(data.floors) ? data.floors : []);
    } catch (error) {
      setApiError(error.message || 'Could not load rooms and floors.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const metrics = useMemo(() => {
    const capacity = rooms.reduce((sum, room) => sum + Number(room.capacity), 0);
    const occupied = rooms.reduce((sum, room) => sum + Number(room.occupied_beds), 0);
    const available = rooms.reduce((sum, room) => (
      sum + (room.status === 'MAINTENANCE' ? 0 : Number(room.available_beds))
    ), 0);
    return {
      capacity,
      occupied,
      available,
      maintenance: rooms.filter(room => room.status === 'MAINTENANCE').length
    };
  }, [rooms]);

  const visibleRooms = useMemo(() => {
    const query = search.trim().toLowerCase();
    return rooms.filter(room => {
      const matchesFloor = floorFilter === 'All' || String(room.floor_id) === floorFilter;
      const matchesQuery =
        !query ||
        String(room.room_number).toLowerCase().includes(query) ||
        String(room.floor_name).toLowerCase().includes(query);
      return matchesFloor && matchesQuery;
    });
  }, [rooms, floorFilter, search]);

  const notify = message => {
    setToast(message);
    window.setTimeout(() => setToast(''), 3500);
  };

  const openRoom = room => {
    setSelectedRoom(room || null);
    setRoomForm(room
      ? {
          room_number: room.room_number,
          floor_id: String(room.floor_id),
          capacity: String(room.capacity),
          status: room.status
        }
      : { ...EMPTY_ROOM, floor_id: floors[0] ? String(floors[0].floor_id) : '' });
    setFormError('');
    setModal('room');
  };

  const openFloor = floor => {
    setSelectedFloor(floor || null);
    setFloorForm(floor ? { floor_name: floor.floor_name } : { ...EMPTY_FLOOR });
    setFormError('');
    setModal('floor');
  };

  const submitForm = async event => {
    event.preventDefault();
    setSaving(true);
    setFormError('');
    const isRoom = modal === 'room';
    const selected = isRoom ? selectedRoom : selectedFloor;
    const resource = isRoom ? 'rooms' : 'floors';
    const payload = isRoom ? roomForm : floorForm;
    const url = selected
      ? `${API_BASE}/${resource}/${selected.room_id || selected.floor_id}`
      : `${API_BASE}/${resource}`;

    try {
      const response = await fetchWithAuth(url, {
        method: selected ? 'PUT' : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || `Could not save ${isRoom ? 'room' : 'floor'}.`);
      setModal('');
      notify(data.message);
      await loadData();
    } catch (error) {
      setFormError(error.message || 'Could not save changes.');
    } finally {
      setSaving(false);
    }
  };

  const deleteItem = async (resource, id, label) => {
    if (!window.confirm(`Delete ${label}?`)) return;
    try {
      const response = await fetchWithAuth(`${API_BASE}/${resource}/${id}`, { method: 'DELETE' });
      const data = await response.json();
      if (!response.ok) throw new Error(data.message || `Could not delete ${label}.`);
      notify(data.message);
      await loadData();
    } catch (error) {
      setApiError(error.message || `Could not delete ${label}.`);
    }
  };

  return (
    <div className="sl-layout">
      <Sidebar onLogout={onLogout} userRole={userRole} />
      <main className="sl-workspace">
        <div className="sl-topbar">
          <div className="sl-breadcrumb">Workspace / <span>Rooms &amp; Floors</span></div>
          <div className="sl-topbar-right"><div className="sl-user-pill">SL</div></div>
        </div>

        <div className="sl-hero-banner">
          <div>
            <h1>Every bed, accounted for.</h1>
            <p>Manage floors and rooms, and see live capacity and availability across your hostel.</p>
          </div>
        </div>

        <header className="sl-alloc-header">
          <div>
            <h2>Rooms &amp; Floors</h2>
            <p>Floor structure, room capacity, and current bed availability.</p>
          </div>
          <div className="sl-header-actions">
            <button type="button" className="sl-btn sl-btn-secondary" onClick={loadData} disabled={loading}>
              <RefreshCw size={15} className={loading ? 'sl-spin' : ''} />
              {loading ? 'Syncing...' : 'Refresh'}
            </button>
            <button type="button" className="sl-btn sl-btn-primary" onClick={() => openFloor(null)}>
              <Plus size={16} /> Add Floor
            </button>
            <button type="button" className="sl-btn sl-btn-primary" onClick={() => openRoom(null)} disabled={!floors.length}>
              <Plus size={16} /> Add Room
            </button>
          </div>
        </header>

        {apiError && (
          <div className="sl-alert" role="alert">
            <AlertCircle size={16} /><span>{apiError}</span>
          </div>
        )}

        <section className="sl-kpi-grid">
          <div className="sl-kpi-card">
            <div className="sl-kpi-top"><span className="sl-kpi-icon"><Building2 size={20} /></span><span className="sl-badge">Structure</span></div>
            <span className="sl-kpi-label">Floors &amp; Rooms</span>
            <strong>{floors.length} / {rooms.length}</strong>
            <small>Configured floors and rooms</small>
          </div>
          <div className="sl-kpi-card">
            <div className="sl-kpi-top"><span className="sl-kpi-icon"><BedDouble size={20} /></span><span className="sl-badge">Capacity</span></div>
            <span className="sl-kpi-label">Beds in Use</span>
            <strong>{metrics.occupied} / {metrics.capacity}</strong>
            <small>{metrics.available} beds available</small>
          </div>
          <div className="sl-kpi-card">
            <div className="sl-kpi-top"><span className="sl-kpi-icon"><AlertCircle size={20} /></span><span className="sl-badge amber">Status</span></div>
            <span className="sl-kpi-label">Rooms in Maintenance</span>
            <strong>{metrics.maintenance}</strong>
            <small>Unavailable for allocation</small>
          </div>
        </section>

        <div className="sl-module-tabs">
          <button type="button" className={`sl-mod-tab ${activeTab === 'rooms' ? 'active' : ''}`} onClick={() => setActiveTab('rooms')}>
            <BedDouble size={16} /> Rooms &amp; availability
          </button>
          <button type="button" className={`sl-mod-tab ${activeTab === 'floors' ? 'active' : ''}`} onClick={() => setActiveTab('floors')}>
            <Building2 size={16} /> Floor management
          </button>
        </div>

        {activeTab === 'rooms' ? (
          <section className="sl-panel">
            <div className="sl-panel-header rooms-panel-header">
              <div>
                <h2>Room capacity &amp; availability</h2>
                <p>{visibleRooms.length} room(s) · capacity reflects maximum beds, availability excludes maintenance rooms.</p>
              </div>
              <div className="rooms-controls">
                <select aria-label="Filter by floor" value={floorFilter} onChange={event => setFloorFilter(event.target.value)}>
                  <option value="All">All floors</option>
                  {floors.map(floor => <option key={floor.floor_id} value={String(floor.floor_id)}>{floor.floor_name}</option>)}
                </select>
                <label className="sl-search">
                  <Search size={15} />
                  <input aria-label="Search rooms" placeholder="Search room or floor" value={search} onChange={event => setSearch(event.target.value)} />
                </label>
              </div>
            </div>
            <div className="sl-table-wrap">
              <table className="sl-table">
                <thead>
                  <tr><th>Room</th><th>Floor</th><th>Capacity</th><th>Occupied</th><th>Available</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                  {visibleRooms.map(room => (
                    <tr key={room.room_id}>
                      <td><strong>Room {room.room_number}</strong></td>
                      <td>{room.floor_name}</td>
                      <td>{room.capacity} beds</td>
                      <td>{room.occupied_beds} / {room.capacity}</td>
                      <td>{room.status === 'MAINTENANCE' ? '—' : `${room.available_beds} beds`}</td>
                      <td><span className={`sl-badge ${room.occupancy_status === 'Full' || room.status === 'MAINTENANCE' ? 'amber' : ''}`}>{room.status === 'MAINTENANCE' ? 'Maintenance' : room.occupancy_status}</span></td>
                      <td>
                        <div className="rooms-row-actions">
                          <button type="button" aria-label={`Edit room ${room.room_number}`} onClick={() => openRoom(room)}><Pencil size={15} /></button>
                          <button type="button" aria-label={`Delete room ${room.room_number}`} onClick={() => deleteItem('rooms', room.room_id, `Room ${room.room_number}`)}><Trash2 size={15} /></button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!visibleRooms.length && <div className="rooms-empty">{loading ? 'Loading rooms...' : 'No rooms match this view.'}</div>}
            </div>
          </section>
        ) : (
          <section className="rooms-floor-grid">
            {floors.map(floor => {
              const floorRooms = rooms.filter(room => Number(room.floor_id) === Number(floor.floor_id));
              const available = floorRooms.reduce((sum, room) => (
                sum + (room.status === 'MAINTENANCE' ? 0 : Number(room.available_beds))
              ), 0);
              return (
                <article className="rooms-floor-card" key={floor.floor_id}>
                  <div className="rooms-floor-card-top">
                    <span className="sl-kpi-icon"><Building2 size={19} /></span>
                    <div className="rooms-row-actions">
                      <button type="button" aria-label={`Edit ${floor.floor_name}`} onClick={() => openFloor(floor)}><Pencil size={15} /></button>
                      <button type="button" aria-label={`Delete ${floor.floor_name}`} onClick={() => deleteItem('floors', floor.floor_id, floor.floor_name)}><Trash2 size={15} /></button>
                    </div>
                  </div>
                  <h3>{floor.floor_name}</h3>
                  <p>{floor.room_count} rooms · {floor.total_capacity} beds total</p>
                  <div className="rooms-floor-stats">
                    <span><strong>{available}</strong> beds available</span>
                    <span><strong>{floorRooms.filter(room => room.status === 'MAINTENANCE').length}</strong> in maintenance</span>
                  </div>
                  <button type="button" className="sl-btn sl-btn-secondary" onClick={() => {
                    setFloorFilter(String(floor.floor_id));
                    setActiveTab('rooms');
                  }}>View floor rooms</button>
                </article>
              );
            })}
            {!floors.length && <div className="rooms-empty">No floors yet. Add a floor to start setting up rooms.</div>}
          </section>
        )}
      </main>

      {modal && (
        <div className="sl-modal-backdrop" onClick={() => setModal('')}>
          <div className="sl-modal" onClick={event => event.stopPropagation()}>
            <div className="sl-modal-top">
              <h3>{modal === 'room' ? `${selectedRoom ? 'Edit' : 'Add'} Room` : `${selectedFloor ? 'Edit' : 'Add'} Floor`}</h3>
              <button type="button" className="sl-close-btn" aria-label="Close dialog" onClick={() => setModal('')}><X size={20} /></button>
            </div>
            {formError && <div className="sl-alert" role="alert"><AlertCircle size={16} /><span>{formError}</span></div>}
            <form className="sl-form" onSubmit={submitForm}>
              {modal === 'room' ? (
                <>
                  <label>
                    Room number
                    <input required maxLength={10} value={roomForm.room_number} onChange={event => setRoomForm({ ...roomForm, room_number: event.target.value })} />
                  </label>
                  <label>
                    Floor
                    <select required value={roomForm.floor_id} onChange={event => setRoomForm({ ...roomForm, floor_id: event.target.value })}>
                      <option value="" disabled>Choose a floor</option>
                      {floors.map(floor => <option key={floor.floor_id} value={String(floor.floor_id)}>{floor.floor_name}</option>)}
                    </select>
                  </label>
                  <label>
                    Bed capacity (1–6)
                    <input type="number" min="1" max="6" required value={roomForm.capacity} onChange={event => setRoomForm({ ...roomForm, capacity: event.target.value })} />
                  </label>
                  <label>
                    Room status
                    <select value={roomForm.status} onChange={event => setRoomForm({ ...roomForm, status: event.target.value })}>
                      <option value="AVAILABLE">Available</option>
                      <option value="MAINTENANCE">Maintenance</option>
                    </select>
                  </label>
                  {selectedRoom && <p className="rooms-form-hint">Capacity cannot be set below the current number of active occupants.</p>}
                </>
              ) : (
                <label>
                  Floor name
                  <input required maxLength={50} value={floorForm.floor_name} onChange={event => setFloorForm({ floor_name: event.target.value })} />
                </label>
              )}
              <button type="submit" disabled={saving || (modal === 'room' && !floors.length)} className="sl-btn sl-btn-primary rooms-submit">
                <Check size={16} /> {saving ? 'Saving...' : 'Save changes'}
              </button>
            </form>
          </div>
        </div>
      )}
      {toast && <div className="sl-toast"><Check size={16} /> {toast}</div>}
    </div>
  );
}
