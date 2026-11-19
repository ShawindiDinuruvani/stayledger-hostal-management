import React, { useState } from 'react';
import { KeyRound, ShieldCheck, AlertCircle, CheckCircle2, LoaderCircle } from 'lucide-react';
import Sidebar from '../Sidebar.jsx';
import '../allocations/allocations.css';
import './AccountSettings.css';

export default function AccountSettings({ user, onLogout, onPasswordChanged }) {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  async function changePassword(event) {
    event.preventDefault();
    if (saving) return;
    setError('');

    if (newPassword.length < 12) {
      setError('New password must have at least 12 characters.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('New password and confirmation do not match.');
      return;
    }

    setSaving(true);
    try {
      const tokenResponse = await fetch('/laravel/csrf-token', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!tokenResponse.ok) throw new Error('Could not start a secure session.');
      const { csrf_token } = await tokenResponse.json();
      if (!csrf_token) throw new Error('Missing CSRF token.');

      const response = await fetch('/laravel/account/password', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf_token,
        },
        body: JSON.stringify({
          current_password: currentPassword,
          password: newPassword,
          password_confirmation: confirmPassword,
        }),
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const details = Object.values(data.errors || {}).flat().join(' ');
        throw new Error(details || data.message || 'Unable to update password.');
      }

      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      onPasswordChanged?.();
    } catch (err) {
      setError(err.message || 'Unable to update password.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="sl-layout">
      <Sidebar onLogout={onLogout} userRole={user?.role} />
      <main className="sl-workspace">
        <div className="sl-topbar">
          <div className="sl-breadcrumb">Workspace / <span>My Account</span></div>
        </div>
        <div className="sl-account-page">
          <div className="sl-account-title">
            <span className="sl-account-icon"><KeyRound size={23} /></span>
            <div>
              <h1>My Account</h1>
              <p>View your account and manage your login security.</p>
            </div>
          </div>

          <section className="sl-account-card">
            <h2>Account details</h2>
            <div className="sl-account-details">
              <div><span>Name</span><strong>{user?.name}</strong></div>
              <div><span>Email</span><strong>{user?.email}</strong></div>
              <div><span>Role</span><strong>{user?.role === 'admin' ? 'Administrator' : 'Staff'}</strong></div>
            </div>
          </section>

          <section className="sl-account-card">
            <h2><ShieldCheck size={20} /> Change password</h2>
            <p>Use a strong password of at least 12 characters. You will be signed out after the change.</p>
            <form className="sl-account-form" onSubmit={changePassword}>
              <label htmlFor="account-current">Current password</label>
              <input id="account-current" autoComplete="current-password"
                type="password" value={currentPassword} required disabled={saving}
                onChange={e => setCurrentPassword(e.target.value)} />

              <label htmlFor="account-new">New password</label>
              <input id="account-new" type="password" autoComplete="new-password"
                value={newPassword} minLength={12} required disabled={saving}
                onChange={e => setNewPassword(e.target.value)} />

              <label htmlFor="account-confirm">Confirm new password</label>
              <input id="account-confirm" type="password" autoComplete="new-password"
                value={confirmPassword} minLength={12} required disabled={saving}
                onChange={e => setConfirmPassword(e.target.value)} />

              {error && <div className="sl-account-error" role="alert"><AlertCircle size={18} />{error}</div>}

              <button type="submit" className="sl-account-button" disabled={saving}>
                {saving ? <LoaderCircle size={18} className="sl-account-spin" /> : <CheckCircle2 size={18} />}
                {saving ? 'Updating...' : 'Update password'}
              </button>
            </form>
          </section>
        </div>
      </main>
    </div>
  );
}
