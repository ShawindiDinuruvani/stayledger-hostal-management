import React, { useState, useEffect } from 'react';
import { 
  LayoutDashboard, 
  Users, 
  Building2, 
  ArrowLeftRight, 
  Wallet, 
  CreditCard, 
  Receipt, 
  Share2, 
  BarChart3, 
  ShieldCheck, 
  UserCheck, 
  Leaf, 
  ChevronDown 
} from 'lucide-react';

export default function Sidebar() {
  // දැනට සිටින පිටුවේ URL hash එක තබා ගැනීම (මුලින්ම එනවිට hash එකක් නැත්නම් default එකක් දීම)
  const [currentRoute, setCurrentRoute] = useState(window.location.hash || '#allocations');

  useEffect(() => {
    // පිටුව මාරු වන විට Sidebar එකට එය දැනුම් දීම
    const handleHashChange = () => {
      setCurrentRoute(window.location.hash);
    };
    
    window.addEventListener('hashchange', handleHashChange);
    return () => window.removeEventListener('hashchange', handleHashChange);
  }, []);

  return (
    <aside className="sl-sidebar">
      <div className="sl-brand-wrap">
        <span className="sl-brand-icon"><Leaf size={20} /></span>
        <span className="sl-brand-text">StayLedger<span className="sl-brand-dot">.</span></span>
      </div>

      <div className="sl-hostel-switch">
        <span className="sl-hostel-avatar">E</span>
        <div className="sl-hostel-info">
          <strong>Emarald Girls Hostel</strong>
          <small>Your hostel workspace</small>
        </div>
        <ChevronDown size={14} />
      </div>

      <div className="sl-nav-label">WORKSPACE</div>
      <nav className="sl-nav">
        <a href="#dashboard" className={`sl-nav-item ${currentRoute === '#dashboard' ? 'active' : ''}`}>
          <LayoutDashboard size={17} /><span>Dashboard</span>
        </a>
        <a href="#residents" className={`sl-nav-item ${currentRoute === '#residents' ? 'active' : ''}`}>
          <Users size={17} /><span>Residents</span><small className="sl-nav-badge">60</small>
        </a>
        <a href="#rooms" className={`sl-nav-item ${currentRoute === '#rooms' ? 'active' : ''}`}>
          <Building2 size={17} /><span>Rooms & floors</span>
        </a>
        <a href="#allocations" className={`sl-nav-item ${currentRoute === '#allocations' ? 'active' : ''}`}>
          <ArrowLeftRight size={17} /><span>Allocations</span>
        </a>
        <a href="#fees" className={`sl-nav-item ${currentRoute === '#fees' ? 'active' : ''}`}>
          <Wallet size={17} /><span>Monthly fees</span>
        </a>
        <a href="#payments" className={`sl-nav-item ${currentRoute === '#payments' ? 'active' : ''}`}>
          <CreditCard size={17} /><span>Payments</span>
        </a>
        <a href="#expenses" className={`sl-nav-item ${currentRoute === '#expenses' ? 'active' : ''}`}>
          <Receipt size={17} /><span>Expenses</span>
        </a>
        <a href="#kitchens" className={`sl-nav-item ${currentRoute === '#kitchens' ? 'active' : ''}`}>
          <Share2 size={17} /><span>Shared kitchens</span>
        </a>
        <a href="#reports" className={`sl-nav-item ${currentRoute === '#reports' ? 'active' : ''}`}>
          <BarChart3 size={17} /><span>Reports</span>
        </a>
      </nav>

      <div className="sl-nav-label" style={{ marginTop: 24 }}>ADMINISTRATION</div>
      <nav className="sl-nav">
        <a href="#audit" className={`sl-nav-item ${currentRoute === '#audit' ? 'active' : ''}`}>
          <ShieldCheck size={17} /><span>Audit history</span>
        </a>
        <a href="#users" className={`sl-nav-item ${currentRoute === '#users' ? 'active' : ''}`}>
          <UserCheck size={17} /><span>Users & access</span>
        </a>
      </nav>
    </aside>
  );
}