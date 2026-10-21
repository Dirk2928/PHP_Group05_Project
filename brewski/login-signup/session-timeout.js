document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    var timeoutMeta = document.querySelector('meta[name="session-timeout-seconds"]');
    var logoutMeta = document.querySelector('meta[name="logout-url"]');
    var timeoutSeconds = timeoutMeta ? Number(timeoutMeta.content) : 0;

    if (!Number.isFinite(timeoutSeconds) || timeoutSeconds <= 0 || !logoutMeta || !logoutMeta.content) {
        return;
    }

    var timeoutId;
    var lastActivityAt = Date.now();

    function redirectToLogin() {
        window.location.assign(logoutMeta.content);
    }

    function resetTimeout() {
        lastActivityAt = Date.now();
        window.clearTimeout(timeoutId);
        timeoutId = window.setTimeout(redirectToLogin, timeoutSeconds * 1000);
    }

    ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(function (eventName) {
        document.addEventListener(eventName, resetTimeout, { passive: true });
    });

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') {
            return;
        }

        if (Date.now() - lastActivityAt >= timeoutSeconds * 1000) {
            redirectToLogin();
        } else {
            resetTimeout();
        }
    });

    resetTimeout();
});
