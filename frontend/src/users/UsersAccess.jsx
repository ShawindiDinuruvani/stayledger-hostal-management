import React, { useMemo, useState } from 'react';
import {
  Users,
  Search,
  UserPlus,
  ShieldCheck,
  UserRoundCheck,
  UserRoundX,
  Pencil,
  X,
  Check,
  AlertCircle,
  CheckCircle2,
  LoaderCircle,
  LockKeyhole,
  Filter,
} from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import '../allocations/allocations.css';
import './UsersAccess.css';

const EMPTY_NEW_USER = {
  name: '',
  email: '',
  password: '',
  role: 'staff',
  is_active: true,
};

const EMPTY_EDIT_USER = {
  name: '',
  email: '',
  password: '',
  role: 'staff',
  is_active: true,
};

function isActiveUser(value) {
  return value === true || value === 1 || value === '1';
}

function readableError(data, status, fallback) {
  if (status === 419) return 'Your session has expired. Please refresh the page and try again.';
  if (status === 401) return 'Your login session has ended. Please sign in again.';
  if (status === 403) return 'You do not have permission to manage user accounts.';
  if (status === 429) return 'Too many requests. Please try again shortly.';

  const details = data?.errors && typeof data.errors === 'object'
    ? Object.values(data.errors).flat().filter(Boolean).join(' ')
    : '';

  return details || data?.message || fallback;
}

async function saveUserRequest(path, method, payload) {
  // Use the same Laravel CSRF endpoint as the existing working Login/Logout flow.
  const csrfResponse = await fetch('/laravel/csrf-token', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });

  if (!csrfResponse.ok) {
    throw new Error('Could not initialise a secure session. Please refresh and try again.');
  }

  const csrfData = await csrfResponse.json().catch(() => ({}));
  if (!csrfData.csrf_token) {
    throw new Error('Security token is missing. Please refresh and try again.');
  }

  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrfData.csrf_token,
    },
    body: JSON.stringify(payload),
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(readableError(data, response.status, 'Unable to save user account.'));
  }

  if (!data.user) {
    throw new Error('The server did not return the saved user. Refresh to check the result.');
  }

  return data.user;
}

function validateUser({ name, email, password, role }, users, excludedId = null) {
  const cleanedName = name.trim();
  const cleanedEmail = email.trim();

  if (cleanedName.length < 2) return 'Please enter a name of at least 2 characters.';
  if (cleanedName.length > 255) return 'Name must contain 255 characters or fewer.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cleanedEmail)) {
    return 'Please enter a valid email address.';
  }
  if (cleanedEmail.length > 255) return 'Email must contain 255 characters or fewer.';
  if (!['admin', 'staff'].includes(role)) return 'Please select a valid role.';
  if (password !== undefined && (excludedId === null || password.length > 0) && password.length < 12) {
    return 'Password must contain at least 12 characters.';
  }

  const duplicateEmail = users.some((user) =>
    String(user.id) !== String(excludedId) &&
    String(user.email || '').trim().toLowerCase() === cleanedEmail.toLowerCase()
  );
  if (duplicateEmail) return 'Another user already has this email address.';

  return '';
}

export default function UsersAccess({
  users = [],
  currentUser = null,
  onStaffCreated,
  onStaffUpdated,
  onLogout,
}) {
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  const [showAddForm, setShowAddForm] = useState(false);
  const [newStaff, setNewStaff] = useState({ ...EMPTY_NEW_USER });
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState('');

  const [editingUser, setEditingUser] = useState(null);
  const [editStaff, setEditStaff] = useState({ ...EMPTY_EDIT_USER });
  const [editSaving, setEditSaving] = useState(false);
  const [editError, setEditError] = useState('');
  const [notice, setNotice] = useState('');

  const counts = useMemo(() => ({
    total: users.length,
    active: users.filter((user) => isActiveUser(user.is_active)).length,
    inactive: users.filter((user) => !isActiveUser(user.is_active)).length,
    admins: users.filter((user) => user.role === 'admin').length,
  }), [users]);

  const filteredUsers = useMemo(() => {
    const query = search.trim().toLowerCase();
    return users.filter((user) => {
      const matchesSearch = !query ||
        `${user.name || ''} ${user.email || ''} ${user.role || ''}`
          .toLowerCase().includes(query);
      const matchesRole = roleFilter === 'all' || user.role === roleFilter;
      const matchesStatus = statusFilter === 'all' ||
        (statusFilter === 'active' ? isActiveUser(user.is_active) : !isActiveUser(user.is_active));
      return matchesSearch && matchesRole && matchesStatus;
    });
  }, [users, search, roleFilter, statusFilter]);

  function openAddForm() {
    setShowAddForm(true);
    setEditingUser(null);
    setFormError('');
    setNotice('');
  }

  function startEditing(user) {
    setEditingUser(user);
    setEditStaff({
      name: user.name || '',
      email: user.email || '',
      password: '',
      role: user.role || 'staff',
      is_active: isActiveUser(user.is_active),
    });
    setEditError('');
    setShowAddForm(false);
    setNotice('');
  }

  async function handleSaveStaff(event) {
    event.preventDefault();
    if (saving) return;

    setFormError('');
    const validationError = validateUser(newStaff, users);
    if (validationError) {
      setFormError(validationError);
      return;
    }

    setSaving(true);
    try {
      const payload = {
        name: newStaff.name.trim(),
        email: newStaff.email.trim(),
        password: newStaff.password,
        role: newStaff.role,
        is_active: newStaff.is_active,
      };
      const createdUser = await saveUserRequest('/laravel/admin/users', 'POST', payload);
      onStaffCreated?.(createdUser);
      setNewStaff({ ...EMPTY_NEW_USER });
      setShowAddForm(false);
      setNotice(`Account for ${createdUser.name || 'the user'} was created successfully.`);
    } catch (error) {
      setFormError(error.message || 'Failed to create user.');
    } finally {
      setSaving(false);
    }
  }

  async function handleUpdateStaff(event) {
    event.preventDefault();
    if (!editingUser || editSaving) return;

    setEditError('');
    const validationError = validateUser(editStaff, users, editingUser.id);
    if (validationError) {
      setEditError(validationError);
      return;
    }

    setEditSaving(true);
    try {
      const payload = {
        name: editStaff.name.trim(),
        email: editStaff.email.trim(),
        role: editStaff.role,
        is_active: editStaff.is_active,
        ...(editStaff.password && String(currentUser?.id) !== String(editingUser.id)
          ? { password: editStaff.password } : {}),
      };
      const updatedUser = await saveUserRequest(
        `/laravel/admin/users/${encodeURIComponent(editingUser.id)}`,
        'PUT',
        payload
      );
      onStaffUpdated?.(updatedUser);
      setEditingUser(null);
      setNotice(`Account for ${updatedUser.name || 'the user'} was updated successfully.`);
    } catch (error) {
      setEditError(error.message || 'Failed to update user.');
    } finally {
      setEditSaving(false);
    }
  }

  return (
    <div className="sl-layout">
      <Sidebar onLogout={onLogout} />
      <main className="sl-workspace sl-users-workspace">
        <div className="sl-topbar">
          <div className="sl-breadcrumb">Workspace / <span>Users &amp; Access</span></div>
        </div>

        <div className="sl-users-view">
          <header className="sl-users-hero">
            <div>
              <div className="sl-users-eyebrow"><ShieldCheck size={15} /> ADMINISTRATION</div>
              <h1>Users &amp; Access</h1>
              <p>Manage team accounts, roles and secure access to your hostel.</p>
            </div>
            <div className="sl-users-hero-icon" aria-hidden="true"><Users size={35} /></div>
          </header>

          <div className="sl-users-stats" aria-label="User account overview">
            <div className="sl-users-stat"><span className="sl-users-stat-icon"><Users size={21} /></span><div><span className="sl-users-stat-label">Total Accounts</span><strong>{counts.total}</strong></div></div>
            <div className="sl-users-stat"><span className="sl-users-stat-icon is-green"><UserRoundCheck size={21} /></span><div><span className="sl-users-stat-label">Active Accounts</span><strong>{counts.active}</strong></div></div>
            <div className="sl-users-stat"><span className="sl-users-stat-icon is-purple"><ShieldCheck size={21} /></span><div><span className="sl-users-stat-label">Administrators</span><strong>{counts.admins}</strong></div></div>
            <div className="sl-users-stat"><span className="sl-users-stat-icon is-amber"><UserRoundX size={21} /></span><div><span className="sl-users-stat-label">Inactive Accounts</span><strong>{counts.inactive}</strong></div></div>
          </div>

          {notice && (
            <div className="sl-users-notice" role="status">
              <CheckCircle2 size={19} /><span>{notice}</span>
              <button type="button" onClick={() => setNotice('')} aria-label="Dismiss notification"><X size={17} /></button>
            </div>
          )}

          <section className="sl-users-card" aria-labelledby="sl-users-heading">
            <div className="sl-users-card-header">
              <div>
                <h2 id="sl-users-heading">Staff Accounts</h2>
                <p>View, create and update authorised accounts.</p>
              </div>
              <button className="sl-users-primary" type="button" onClick={showAddForm ? () => setShowAddForm(false) : openAddForm}>
                {showAddForm ? <X size={18} /> : <UserPlus size={18} />}
                {showAddForm ? 'Cancel' : 'Add Account'}
              </button>
            </div>

            {showAddForm && (
              <form className="sl-users-form" onSubmit={handleSaveStaff} aria-busy={saving}>
                <div className="sl-users-form-heading"><UserPlus size={19} /><h3>Create a new account</h3></div>
                <p className="sl-users-form-hint">Enter the new user's details. Only authorised admins should create accounts.</p>
                <div className="sl-users-form-grid">
                  <label className="sl-users-field" htmlFor="sl-create-name">Full Name
                    <input id="sl-create-name" type="text" autoComplete="name" value={newStaff.name} maxLength={255} minLength={2} required disabled={saving} onChange={(e) => setNewStaff({ ...newStaff, name: e.target.value })} placeholder="e.g. Nethmini Perera" />
                  </label>
                  <label className="sl-users-field" htmlFor="sl-create-email">Email Address
                    <input id="sl-create-email" type="email" autoComplete="off" value={newStaff.email} maxLength={255} required disabled={saving} onChange={(e) => setNewStaff({ ...newStaff, email: e.target.value })} placeholder="name@example.com" />
                  </label>
                  <label className="sl-users-field" htmlFor="sl-create-password">Temporary Password
                    <input id="sl-create-password" type="password" autoComplete="new-password" value={newStaff.password} minLength={12} required disabled={saving} onChange={(e) => setNewStaff({ ...newStaff, password: e.target.value })} placeholder="At least 12 characters" />
                    <span className="sl-users-field-hint"><LockKeyhole size={13} /> Minimum 12 characters</span>
                  </label>
                  <label className="sl-users-field" htmlFor="sl-create-role">Account Role
                    <select id="sl-create-role" value={newStaff.role} disabled={saving} onChange={(e) => setNewStaff({ ...newStaff, role: e.target.value })}>
                      <option value="staff">Staff</option>
                      <option value="admin">Admin</option>
                    </select>
                  </label>
                </div>
                <label className="sl-users-switch-row">
                  <input type="checkbox" checked={newStaff.is_active} disabled={saving} onChange={(e) => setNewStaff({ ...newStaff, is_active: e.target.checked })} />
                  <span><strong>Active account</strong><small>Allow this user to sign in immediately.</small></span>
                </label>
                {formError && <div className="sl-users-error" role="alert"><AlertCircle size={18} /><span>{formError}</span></div>}
                <div className="sl-users-form-actions">
                  <button className="sl-users-secondary" type="button" disabled={saving} onClick={() => setShowAddForm(false)}>Cancel</button>
                  <button className="sl-users-primary" type="submit" disabled={saving}>{saving ? <LoaderCircle className="sl-users-spin" size={18} /> : <Check size={18} />}{saving ? 'Creating...' : 'Create Account'}</button>
                </div>
              </form>
            )}

            {editingUser && (
              <form className="sl-users-form" onSubmit={handleUpdateStaff} aria-busy={editSaving}>
                <div className="sl-users-form-heading"><Pencil size={19} /><h3>Edit account</h3></div>
                <p className="sl-users-form-hint">Updating <strong>{editingUser.name}</strong>. Role and status changes take effect on the server.</p>
                <div className="sl-users-form-grid">
                  <label className="sl-users-field" htmlFor="sl-edit-name">Full Name
                    <input id="sl-edit-name" type="text" autoComplete="name" value={editStaff.name} maxLength={255} minLength={2} required disabled={editSaving} onChange={(e) => setEditStaff({ ...editStaff, name: e.target.value })} />
                  </label>
                  <label className="sl-users-field" htmlFor="sl-edit-email">Email Address
                    <input id="sl-edit-email" type="email" value={editStaff.email} maxLength={255} required disabled={editSaving} onChange={(e) => setEditStaff({ ...editStaff, email: e.target.value })} />
                  </label>
                  {String(currentUser?.id) !== String(editingUser.id) && (
                    <label className="sl-users-field" htmlFor="sl-edit-password">Reset Password (optional)
                      <input id="sl-edit-password" type="password" autoComplete="new-password"
                        value={editStaff.password || ''} minLength={12} disabled={editSaving}
                        onChange={(e) => setEditStaff({ ...editStaff, password: e.target.value })}
                        placeholder="Leave blank to keep password" />
                    </label>
                  )}
                  <label className="sl-users-field" htmlFor="sl-edit-role">Account Role
                    <select id="sl-edit-role" value={editStaff.role} disabled={editSaving} onChange={(e) => setEditStaff({ ...editStaff, role: e.target.value })}>
                      <option value="staff">Staff</option>
                      <option value="admin">Admin</option>
                    </select>
                  </label>
                </div>
                <label className="sl-users-switch-row">
                  <input type="checkbox" checked={editStaff.is_active} disabled={editSaving} onChange={(e) => setEditStaff({ ...editStaff, is_active: e.target.checked })} />
                  <span><strong>Active account</strong><small>Uncheck to prevent this user from signing in.</small></span>
                </label>
                {editError && <div className="sl-users-error" role="alert"><AlertCircle size={18} /><span>{editError}</span></div>}
                <div className="sl-users-form-actions">
                  <button className="sl-users-secondary" type="button" disabled={editSaving} onClick={() => setEditingUser(null)}>Cancel</button>
                  <button className="sl-users-primary" type="submit" disabled={editSaving}>{editSaving ? <LoaderCircle className="sl-users-spin" size={18} /> : <Check size={18} />}{editSaving ? 'Saving...' : 'Save Changes'}</button>
                </div>
              </form>
            )}

            <div className="sl-users-filters">
              <label className="sl-users-search" htmlFor="sl-users-search-input">
                <Search size={18} />
                <input id="sl-users-search-input" type="search" placeholder="Search name, email or role..." value={search} onChange={(e) => setSearch(e.target.value)} />
              </label>
              <div className="sl-users-filter-select"><Filter size={16} /><select aria-label="Filter by role" value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)}><option value="all">All Roles</option><option value="admin">Admin</option><option value="staff">Staff</option></select></div>
              <div className="sl-users-filter-select"><select aria-label="Filter by status" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}><option value="all">All Statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
            </div>

            <div className="sl-users-table-scroll">
              <table className="sl-users-table">
                <thead><tr><th scope="col">User</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead>
                <tbody>
                  {filteredUsers.map((user) => {
                    const active = isActiveUser(user.is_active);
                    return (
                      <tr key={user.id}>
                        <td><div className="sl-users-identity"><span className="sl-users-avatar" aria-hidden="true">{String(user.name || 'U').charAt(0).toUpperCase()}</span><strong>{user.name}</strong></div></td>
                        <td className="sl-users-email">{user.email}</td>
                        <td><span className={`sl-users-role ${user.role === 'admin' ? 'is-admin' : ''}`}>{user.role === 'admin' ? 'Admin' : 'Staff'}</span></td>
                        <td><span className={`sl-users-status ${active ? 'is-active' : 'is-inactive'}`}><span className="sl-users-status-dot" />{active ? 'Active' : 'Inactive'}</span></td>
                        <td><button type="button" className="sl-users-edit-btn" onClick={() => startEditing(user)}><Pencil size={15} /> Edit</button></td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
              {filteredUsers.length === 0 && (
                <div className="sl-users-empty"><Search size={24} /><strong>No matching accounts</strong><p>Try another search or change the filters.</p></div>
              )}
            </div>
            <footer className="sl-users-table-footer">Showing <strong>{filteredUsers.length}</strong> of <strong>{users.length}</strong> accounts</footer>
          </section>
        </div>
      </main>
    </div>
  );
}