import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
import RoomAllocations from './allocations/RoomAllocations.jsx';
import KitchenExpenseReport from './kitchens/KitchenExpenseReport.jsx';
import MonthlyFees from './fees/MonthlyFees.jsx';

function App() {
  const [currentRoute, setCurrentRoute] = useState(window.location.hash);

  useEffect(() => {
    
    const handleHashChange = () => {
      setCurrentRoute(window.location.hash);
    };
    
    window.addEventListener('hashchange', handleHashChange);
    return () => window.removeEventListener('hashchange', handleHashChange);
  }, []);

  
  if (currentRoute === '#kitchens') {
    return <KitchenExpenseReport />;
  }

  if (currentRoute === '#fees') {
    return <MonthlyFees />;
  }

  
  return <RoomAllocations />;
}

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
);