import React, { useState } from 'react';
import { Leaf, Mail, LockKeyhole, Eye, EyeOff, ArrowRight, ShieldCheck, LoaderCircle } from 'lucide-react';
import './Login.css';

export default function Login({ onLogin }) {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  async function handleLogin(event) {
    event.preventDefault();
    if (loading) return;
    setError('');
    setLoading(true);

    try {
      const csrfResponse = await fetch('/laravel/csrf-token', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!csrfResponse.ok) throw new Error('Could not initialise login session.');

      const { csrf_token: token } = await csrfResponse.json();
      if (!token) throw new Error('Missing CSRF token.');

      const response = await fetch('/laravel/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': token,
        },
        body: JSON.stringify({ email: email.trim(), password }),
      });

      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        if (response.status === 429) throw new Error('Too many login attempts. Try again shortly.');
        if (response.status === 419) throw new Error('Your session expired. Refresh the page.');
        throw new Error(data.message || 'Invalid email or password.');
      }
      if (!data.user) throw new Error('Invalid login response from the server.');
      setPassword('');
      onLogin?.(data.user);
    } catch (err) {
      setError(err.message || 'Unable to sign in.');
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="sl-login-page">
      <div className="sl-login-shell">
        <section className="sl-login-showcase">
          <div className="sl-login-brand"><span className="sl-login-brand-icon"><Leaf size={24} /></span><strong>StayLedger.</strong></div>
          <div className="sl-login-showcase-content">
            <span className="sl-login-showcase-label">HOSTEL MANAGEMENT SYSTEM</span>
            <h2>A smarter way to manage your hostel.</h2>
            <p>One workspace for your team, residents and daily hostel operations.</p>
            <div className="sl-login-feature"><ShieldCheck size={19} /> Role-based access for your team</div>
          </div>
          <small>EMARALD GIRLS HOSTEL</small>
        </section>
        <main className="sl-login-panel">
          <div className="sl-login-form-container">
            <div className="sl-login-mobile-brand"><Leaf size={22} /> StayLedger.</div>
            <span className="sl-login-security-label"><ShieldCheck size={16} /> SECURE STAFF ACCESS</span>
            <h1>Welcome back</h1>
            <p className="sl-login-subtitle">Sign in to your StayLedger account to manage your workspace.</p>

            <form onSubmit={handleLogin} className="sl-login-form" aria-busy={loading}>
              <label htmlFor="login-email">Email Address</label>
              <div className="sl-login-input-wrap">
                <Mail size={19} className="sl-login-field-icon" />
                <input id="login-email" type="email" autoComplete="username"
                  placeholder="Enter your email address" required disabled={loading}
                  value={email} onChange={e => { setEmail(e.target.value); setError(''); }} />
              </div>
              <label htmlFor="login-password">Password</label>
              <div className="sl-login-input-wrap">
                <LockKeyhole size={19} className="sl-login-field-icon" />
                <input id="login-password" type={showPassword ? 'text' : 'password'}
                  autoComplete="current-password" placeholder="Enter your password" required
                  disabled={loading} value={password}
                  onChange={e => { setPassword(e.target.value); setError(''); }} />
                <button className="sl-login-password-toggle" type="button"
                  aria-label={showPassword ? 'Hide password' : 'Show password'}
                  aria-pressed={showPassword} onClick={() => setShowPassword(!showPassword)}
                  disabled={loading}>{showPassword ? <EyeOff size={19} /> : <Eye size={19} />}</button>
              </div>
              {error && <div className="sl-login-error" role="alert">{error}</div>}
              <button className="sl-login-submit" type="submit" disabled={loading}>
                {loading ? <><LoaderCircle size={19} className="sl-login-spinner" /> Signing in...</>
                  : <>Sign In <ArrowRight size={19} /></>}
              </button>
            </form>
            <div className="sl-login-info"><ShieldCheck size={17} />
              <span>This portal is for authorised hostel staff. Use your assigned credentials.</span>
            </div>
            <p className="sl-login-copyright">© {new Date().getFullYear()} StayLedger. All rights reserved.</p>
          </div>
        </main>
      </div>
    </div>
  );
}
