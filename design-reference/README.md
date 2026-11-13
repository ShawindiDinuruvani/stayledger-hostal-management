# StayLedger UI starter

React + Vite frontend for Emarald Girls Hostel. This is a UI prototype with fictional sample data, not a complete hostel system. No authentication or database is connected. Demo form changes are in memory and reset on refresh. Chart values are illustrative.

## Run locally

Install Node.js LTS, then open this folder in VS Code:

```powershell
npm ci
npm run dev
```

Open the URL printed by Vite. Build with `npm run build`.

## Add to your group repository

Copy package.json, package-lock.json, index.html and src/ into your existing `frontend/` folder. Remove its `.gitkeep` once files exist. Do not replace your repository root or backend. Never copy node_modules/, dist/ or .openai/ into the group repository.

## Team continuation

- src/main.jsx: shared sidebar/header, navigation, dashboard, sample data, module tables and demo forms.
- src/style.css: shared design tokens, responsive breakpoints, glass effects and reduced-motion support.
- The `spec` object defines each module's columns and labels. `getRows()` supplies fictional records.
- Move each module into its own src/pages/<Module>.jsx component before concurrent team development to avoid conflicts in the shared file.
- Keep shared layout and styles under one reviewer. Each module owner should preserve the shared visual language.
- Replace demo state with Laravel API calls, including loading/error/empty states and server validation. UI role labels do not enforce authorization.
- Fees generation is explicitly a preview; implement the MySQL procedure and Laravel endpoint before using it.
- Reports download CSV of demo table rows. The dashboard overview download lists sample residents; it is not a real financial export.
- Backup and restore remain backend/database work; there is no recovery action in this UI.

## Ownership

Athukorala: users/access; Dewmini: residents; Wijethunga: floors/rooms; Amarasingha: expenses; Dinuruvani: monthly fees; Karunarathna: payments/balances; Lekamge: allocations/transfers/checkout; Liyanage: kitchens; Paranamanage: dashboard/reports/performance; Yasanjith: audit/recovery.

Use only fictional data in the UI starter. Run the Laravel backend separately; this deployment hosts only the frontend.
