# StayLedger — Hostel Management System

StayLedger is a unified full-stack hostel management platform built for **Emerald Girls Hostel** (Group L) using **React**, **Laravel**, and **PostgreSQL (Neon)**. It centralizes resident access control, room and bed allocations, monthly fee billing, hostel expense tracking, and shared kitchen inventory management into a single cohesive system.

---

## Core Modules

* **User Accounts & Access Control:** Session-based authentication, role-based access control (Admin / Staff / Resident permissions), and user lifecycle management.
* **Room & Bed Allocations:** Floor, room, and bed capacity tracking, resident check-in/check-out workflows, room transfers, and occupancy analytics.
* **Monthly Fees & Billing:** Automated monthly fee generation, billing period summaries (`vw_monthly_fee_summary`), payment settlement tracking (`UNPAID`, `PARTIALLY PAID`, `PAID`), and stored-procedure validation.
* **Hostel Expenses:** Operational expense logging, categorization, and financial reporting.
* **Shared Kitchen Management:** Kitchen inventory tracking, meal/item consumption logs, and stock alerts.

---

## Tech Stack

| Layer | Technology |
| :--- | :--- |
| **Frontend** | React 18, Vite 5, JavaScript (ES6+), CSS3 |
| **Backend** | Laravel 13, PHP 8.4+ |
| **Database** | PostgreSQL 16 (Neon Serverless Cloud for production/staging; SQLite for fast local feature tests) |
| **Testing & CI/CD** | PHPUnit / Artisan Test, Playwright E2E, GitHub Actions |

---

## Project Structure

```text
stayledger-hostal-management/
├── .github/workflows/       # Automated CI/CD pipelines (PostgreSQL, PHPUnit, Vite, Playwright)
├── backend/                 # Unified Laravel 13 API & database migrations/schemas
│   ├── app/                 # Controllers, Models, Services, Middleware
│   ├── database/            # Migrations, seeders, and PostgreSQL schema/view scripts
│   ├── routes/              # Unified API & web session routes
│   └── tests/               # Feature & integration test suites
└── frontend/                # React + Vite single-page application
    ├── src/                 # Modular UI components (auth, allocations, fees, expenses, kitchen)
    └── vite.config.js       # Dev server & unified backend proxy configuration
