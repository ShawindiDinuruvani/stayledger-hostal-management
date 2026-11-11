import React from 'react';
import { createRoot } from 'react-dom/client';
import RoomAllocations from './RoomAllocations.jsx';
import './allocations.css';

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <RoomAllocations />
  </React.StrictMode>
);