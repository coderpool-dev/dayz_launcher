(function () {
    'use strict';
    if (navigator.webdriver || window.self !== window.top) return;
    var started = document.visibilityState === 'visible' ? performance.now() : null;
    var visibleTime = 0;
    var interacted = false;
    var sending = false;
    var complete = false;
    var attempts = 0;
    var timer;

    function elapsed() {
        return visibleTime + (started === null ? 0 : performance.now() - started);
    }

    function confirm() {
        if (complete || sending || attempts >= 3 || !interacted || elapsed() < 5000) return;
        sending = true;
        attempts++;
        fetch('/api/traffic/confirm', {
            method: 'POST', credentials: 'same-origin', keepalive: true,
            headers: { 'X-Traffic-Event': 'engaged', 'Content-Type': 'application/json' }, body: '{}'
        }).then(function (response) {
            if (!response.ok) throw new Error('Traffic confirmation failed');
            return response.json();
        }).then(function (result) {
            complete = result.confirmed === true;
            if (complete) clearInterval(timer);
        }).catch(function () {}).finally(function () { sending = false; });
    }

    function interact(event) {
        if (!event.isTrusted || document.visibilityState !== 'visible') return;
        interacted = true;
        confirm();
    }

    ['pointerdown', 'touchstart', 'keydown', 'wheel'].forEach(function (name) {
        document.addEventListener(name, interact, { passive: true, capture: true });
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') started = performance.now();
        else if (started !== null) { visibleTime += performance.now() - started; started = null; }
        confirm();
    });
    window.addEventListener('pagehide', confirm);
    timer = setInterval(confirm, 1500);
})();
