import React, { useEffect, useState } from 'react';
import ReactDOM from 'react-dom/client';

import Login from './users/Login.jsx';
import RoomAllocations from './allocations/RoomAllocations.jsx';
import UsersAccess from './users/UsersAccess.jsx';
import AccountSettings from './users/AccountSettings.jsx';

function App() {
  const [page, setPage] = useState(window.location.hash || '#allocations');
  const [user, setUser] = useState(null);
  const [checking, setChecking] = useState(true);
  const [authError, setAuthError] = useState('');
  const [retry, setRetry] = useState(0);
  const [staffUsers, setStaffUsers] = useState([]);
  const [staffLoading, setStaffLoading] = useState(false);
  const [staffError, setStaffError] = useState('');

  function handleLogin(loggedInUser) {
    setUser(loggedInUser);
    setAuthError('');
    window.location.hash = loggedInUser.role === 'admin' ? '#users' : '#allocations';
  }

  async function handleLogout() {
    try {
      const csrfResponse = await fetch('/laravel/csrf-token', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!csrfResponse.ok) throw new Error('Could not get CSRF token.');

      const { csrf_token: csrfToken } = await csrfResponse.json();
      if (!csrfToken) throw new Error('CSRF token missing.');

      const response = await fetch('/laravel/logout', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
      });
      if (!response.ok) throw new Error('Logout failed.');

      setUser(null);
      setStaffUsers([]);
      setStaffError('');
      window.location.hash = '#login';
    } catch (error) {
      window.alert(error.message || 'Could not log out. Please retry.');
    }
  }

  useEffect(() => {
    function onHashChange() {
      setPage(window.location.hash || '#allocations');
    }
    window.addEventListener('hashchange', onHashChange);
    return () => window.removeEventListener('hashchange', onHashChange);
  }, []);

  useEffect(() => {
    let cancelled = false;
    async function checkSession() {
      setChecking(true);
      setAuthError('');
      try {
        const response = await fetch('/laravel/me', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        if (response.status === 401) {
          if (!cancelled) setUser(null);
          return;
        }
        if (!response.ok) throw new Error('Unable to check login session.');
        const data = await response.json();
        if (!cancelled) setUser(data.user || null);
      } catch (error) {
        if (!cancelled) setAuthError(error.message || 'Backend connection failed.');
      } finally {
        if (!cancelled) setChecking(false);
      }
    }
    checkSession();
    return () => { cancelled = true; };
  }, [retry]);

  useEffect(() => {
    if (page !== '#users' || user?.role !== 'admin') return;
    let cancelled = false;

    async function loadUsers() {
      setStaffLoading(true);
      setStaffError('');
      try {
        const response = await fetch('/laravel/admin/users', {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) {
          if (!cancelled) {
            setUser(null);
            window.location.hash = '#login';
          }
          return;
        }
        if (!response.ok) throw new Error(data.message || 'Unable to load staff accounts.');
        if (!cancelled) setStaffUsers(Array.isArray(data.users) ? data.users : []);
      } catch (error) {
        if (!cancelled) setStaffError(error.message || 'Unable to load users.');
      } finally {
        if (!cancelled) setStaffLoading(false);
      }
    }

    loadUsers();
    return () => { cancelled = true; };
  }, [page, user?.id, user?.role]);

  if (checking) {
    return <main style={{ padding: 40 }} role="status">Checking login session...</main>;
  }

  if (authError) {
    return (
      <main style={{ padding: 40, textAlign: 'center' }}>
        <p role="alert">{authError}</p>
        <p>Check that the Laravel backend is running on port 8001.</p>
        <button type="button" onClick={() => setRetry(value => value + 1)}>Retry Connection</button>
      </main>
    );
  }

  if (page === '#login' || !user) {
    return <Login onLogin={handleLogin} />;
  }

  if (page === '#account') {
    return (
      <AccountSettings
        user={user}
        onLogout={handleLogout}
        onPasswordChanged={() => {
          setUser(null);
          setStaffUsers([]);
          window.location.hash = '#login';
        }}
      />
    );
  }

  if (page === '#users') {
    if (user.role !== 'admin') {
      return (
        <main style={{ padding: 40 }}>
          <p role="alert">Admin access required.</p>
          <a href="#allocations">Return to workspace</a>
        </main>
      );
    }

    if (staffLoading) return <main style={{ padding: 40 }} role="status">Loading staff accounts...</main>;

    if (staffError) {
      return (
        <main style={{ padding: 40 }}>
          <p role="alert">{staffError}</p>
          <button type="button" onClick={() => setRetry(value => value + 1)}>Reconnect</button>
        </main>
      );
    }

    return (
      <UsersAccess
        users={staffUsers}
        currentUser={user}
        onStaffCreated={created => {
          setStaffUsers(previous => [...previous, created]);
        }}
        onStaffUpdated={updated => {
          setStaffUsers(previous => previous.map(item => item.id === updated.id ? updated : item));
        }}
        onLogout={handleLogout}
      />
    );
  }

  // Other group modules retain their own UI; user access is checked first.
  return <RoomAllocations onLogout={handleLogout} userRole={user.role} />;
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);
