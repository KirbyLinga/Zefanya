/**
 * Buyer Messages Badge and Polling
 * Updates the unread message badge count every 5 seconds
 */

let messagePollInterval = null;

function updateMessagesBadge() {
    const badge = document.getElementById('messagesUnreadBadge');
    if (!badge) return;

    fetch('/buyer/messages/unread-count', {
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        const count = data.unread_count || 0;
        if (count > 0) {
            badge.textContent = Math.min(count, 9);
            badge.classList.remove('hidden');
            badge.classList.add('flex');
        } else {
            badge.classList.add('hidden');
            badge.classList.remove('flex');
        }
    })
    .catch(error => console.error('Error fetching unread message count:', error));
}

function startMessagesPolling() {
    if (messagePollInterval) return;
    
    // Update badge on page load
    updateMessagesBadge();
    
    // Poll every 5 seconds
    messagePollInterval = setInterval(updateMessagesBadge, 5000);
}

function stopMessagesPolling() {
    if (messagePollInterval) {
        clearInterval(messagePollInterval);
        messagePollInterval = null;
    }
}

// Start polling when page loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startMessagesPolling);
} else {
    startMessagesPolling();
}

// Pause polling when tab is hidden
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopMessagesPolling();
    } else {
        startMessagesPolling();
    }
});

// Clean up on page unload
window.addEventListener('beforeunload', stopMessagesPolling);

// Export for consistency
export { startMessagesPolling, stopMessagesPolling };
