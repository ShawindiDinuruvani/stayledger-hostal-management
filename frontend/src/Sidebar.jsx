import React from 'react';
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
        <a href="#dashboard" className="sl-nav-item">
          <LayoutDashboard size={17} /><span>Dashboard</span>
        </a>
        <a href="#residents" className="sl-nav-item">
          <Users size={17} /><span>Residents</span><small className="sl-nav-badge">60</small>
        </a>
        <a href="#rooms" className="sl-nav-item">
          <Building2 size={17} /><span>Rooms & floors</span>
        </a>
        <a href="#allocations" className="sl-nav-item active">
          <ArrowLeftRight size={17} /><span>Allocations</span>
        </a>
        <a href="#fees" className="sl-nav-item">
          <Wallet size={17} /><span>Monthly fees</span>
        </a>
        <a href="#payments" className="sl-nav-item">
          <CreditCard size={17} /><span>Payments</span>
        </a>
        <a href="#expenses" className="sl-nav-item">
          <Receipt size={17} /><span>Expenses</span>
        </a>
        <a href="#kitchens" className="sl-nav-item">
          <Share2 size={17} /><span>Shared kitchens</span>
        </a>
        <a href="#reports" className="sl-nav-item">
          <BarChart3 size={17} /><span>Reports</span>
        </a>
      </nav>

      <div className="sl-nav-label" style={{ marginTop: 24 }}>ADMINISTRATION</div>
      <nav className="sl-nav">
        <a href="#audit" className="sl-nav-item">
          <ShieldCheck size={17} /><span>Audit history</span>
        </a>
        <a href="#users" className="sl-nav-item">
          <UserCheck size={17} /><span>Users & access</span>
        </a>
      </nav>
    </aside>
  );
}