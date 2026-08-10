import { getSyncState, onSyncStateChange } from './sync';

export async function initOfflineStatusUI() {
  // Find or create sync status container
  let statusContainer = document.getElementById('sync-status');
  if (!statusContainer) {
    statusContainer = document.createElement('div');
    statusContainer.id = 'sync-status';
    statusContainer.style.cssText = `
      position: fixed;
      bottom: 20px;
      right: 20px;
      z-index: 9999;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      font-size: 12px;
    `;
    document.body.appendChild(statusContainer);
  }

  // Initial render
  updateStatusUI(statusContainer);

  // Subscribe to updates
  onSyncStateChange(() => updateStatusUI(statusContainer!));
}

async function updateStatusUI(container: HTMLElement) {
  const state = await getSyncState();

  if (state.isOnline) {
    if (state.isSyncing) {
      container.innerHTML = `
        <div style="
          background: #2563eb;
          color: white;
          padding: 8px 12px;
          border-radius: 6px;
          display: flex;
          align-items: center;
          gap: 8px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        ">
          <svg style="animation: spin 1s linear infinite; width: 16px; height: 16px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10" stroke-dasharray="15.7" stroke-dashoffset="0"></circle>
          </svg>
          Syncing...
        </div>
        <style>
          @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
          }
        </style>
      `;
    } else if (state.lastSyncTime) {
      const timeAgo = getTimeAgo(state.lastSyncTime);
      container.innerHTML = `
        <div style="
          background: #10b981;
          color: white;
          padding: 8px 12px;
          border-radius: 6px;
          display: flex;
          align-items: center;
          gap: 8px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        ">
          <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="currentColor">
            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
          </svg>
          Synced ${timeAgo}
        </div>
      `;
    } else {
      container.innerHTML = `
        <div style="
          background: #6366f1;
          color: white;
          padding: 8px 12px;
          border-radius: 6px;
          display: flex;
          align-items: center;
          gap: 8px;
          box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        ">
          <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2m0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8m3.5-9c.83 0 1.5-.67 1.5-1.5S16.33 8 15.5 8 14 8.67 14 9.5s.67 1.5 1.5 1.5zm-7 0c.83 0 1.5-.67 1.5-1.5S9.33 8 8.5 8 7 8.67 7 9.5 7.67 11 8.5 11zm3.5 6.5c2.33 0 4.31-1.46 5.11-3.5H6.89c.8 2.04 2.78 3.5 5.11 3.5z"/>
          </svg>
          Online
        </div>
      `;
    }
  } else {
    container.innerHTML = `
      <div style="
        background: #ef4444;
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
      ">
        <svg style="width: 16px; height: 16px;" viewBox="0 0 24 24" fill="currentColor">
          <path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z"/>
        </svg>
        Offline - Queued: ${state.pendingCount}
      </div>
    `;
  }

  // Update every second if syncing
  if (state.isSyncing) {
    setTimeout(() => updateStatusUI(container), 1000);
  }
}

function getTimeAgo(timestamp: number): string {
  const seconds = Math.floor((Date.now() - timestamp) / 1000);

  if (seconds < 60) return 'now';
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
  return `${Math.floor(seconds / 86400)}d ago`;
}
