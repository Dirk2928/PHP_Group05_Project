function initializeBrewskiSessionTimeout() {
    'use strict';

    var timeoutMeta = document.querySelector('meta[name="session-timeout-seconds"]');
    var absoluteMeta = document.querySelector('meta[name="session-absolute-remaining-seconds"]');
    var logoutMeta = document.querySelector('meta[name="logout-url"]');
    var timeoutSeconds = timeoutMeta ? Number(timeoutMeta.content) : 0;
    var absoluteRemainingSeconds = absoluteMeta ? Number(absoluteMeta.content) : 0;

    if (!Number.isFinite(timeoutSeconds) || timeoutSeconds <= 0 ||
        !Number.isFinite(absoluteRemainingSeconds) || !logoutMeta || !logoutMeta.content) {
        return;
    }

    var timeoutId;
    var lastActivityAt = Date.now();
    var absoluteDeadline = Date.now() + Math.max(0, absoluteRemainingSeconds) * 1000;

    function redirectToLogin() {
        try {
            sessionStorage.removeItem('brewski_cart');
            sessionStorage.removeItem('brewski_checkout_sync_complete');
        } catch (error) {
            console.warn('Could not clear the cart after session timeout:', error);
        }
        window.location.assign(logoutMeta.content);
    }

    function scheduleTimeout() {
        var now = Date.now();
        var idleDeadline = lastActivityAt + timeoutSeconds * 1000;
        var nextDeadline = Math.min(idleDeadline, absoluteDeadline);

        window.clearTimeout(timeoutId);
        if (nextDeadline <= now) {
            redirectToLogin();
            return;
        }

        timeoutId = window.setTimeout(redirectToLogin, nextDeadline - now);
    }

    function recordActivity() {
        lastActivityAt = Date.now();
        scheduleTimeout();
    }

    ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (eventName) {
        document.addEventListener(eventName, recordActivity, { passive: true });
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') {
            return;
        }

        if (Date.now() >= absoluteDeadline || Date.now() - lastActivityAt >= timeoutSeconds * 1000) {
            redirectToLogin();
        } else {
            scheduleTimeout();
        }
    });

    scheduleTimeout();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeBrewskiSessionTimeout, { once: true });
} else {
    initializeBrewskiSessionTimeout();
}
