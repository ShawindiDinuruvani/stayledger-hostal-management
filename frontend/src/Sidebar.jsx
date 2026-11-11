import React from 'react';
import { LayoutDashboard, Users, Building2, ArrowLeftRight, Leaf, ChevronDown, MoreHorizontal } from 'lucide-react';

export default function Sidebar() {
  return (
    <aside className="sl-sidebar">
      <a className="sl-brand" href="#allocations">
        <span className="sl-brand-icon"><Leaf size={22} /></span>
        StayLedger<span className="sl-brand-dot">.</span>
      </a>

      <div className="sl-hostel-switch">
        <span className="sl-hostel-avatar">E</span>
        <div>
          <strong>Emarald Girls Hostel</strong>
          <small>Hostel workspace</small>
        </div>
        <ChevronDown size={14} />
      </div>

      <div className="sl-nav-label">WORKSPACE</div>
      <nav className="sl-nav">
        <button className="sl-nav-item">
          <LayoutDashboard size={18} /><span>Dashboard</span>
        </button>
        <button className="sl-nav-item">
          <Users size={18} /><span>Residents</span><small>60</small>
        </button>
        <button className="sl-nav-item">
          <Building2 size={18} /><span>Rooms & floors</span>
        </button>
        <button className="sl-nav-item active">
          <ArrowLeftRight size={18} /><span>Allocations</span><i />
        </button>
      </nav>

      <div className="sl-sidebar-bottom">
        <div className="sl-profile">
          <span className="sl-avatar">NL</span>
          <div>
            <strong>Nimsara Lekamge</strong>
            <small>Allocations Module</small>
          </div>
          <MoreHorizontal size={16} />
        </div>
      </div>
    </aside>
  );
}