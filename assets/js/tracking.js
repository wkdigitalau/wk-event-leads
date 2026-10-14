/* WK Event Leads first-party site activity helper.
 * Install in the HTML <head>, before GA/GTM. It strips the opaque referral
 * token from the browser URL before analytics scripts can read page_location.
 */
(function () {
    'use strict';
    var script = document.currentScript;
    if (!script) return;
    var endpoint = script.getAttribute('data-endpoint');
    if (!endpoint) return;

    var params = new URLSearchParams(window.location.search);
    var incoming = params.get('wkel_ref');
    var ref = incoming || '';
    try {
        if (incoming) sessionStorage.setItem('wkel_ref', incoming);
        else ref = sessionStorage.getItem('wkel_ref') || '';
    } catch (e) {}
    if (incoming) {
        params.delete('wkel_ref');
        var query = params.toString();
        window.history.replaceState(window.history.state, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
    }
    if (!/^[a-f0-9]{64}$/.test(ref)) return;

    function send(eventName, category) {
        var body = new URLSearchParams({
            wkel_ref: ref,
            event: eventName,
            path: window.location.pathname,
            category: category || ''
        });
        if (navigator.sendBeacon) {
            navigator.sendBeacon(endpoint, body);
        } else {
            fetch(endpoint, { method: 'POST', mode: 'no-cors', body: body, keepalive: true });
        }
    }

    send('page_view', '');
    document.addEventListener('click', function (event) {
        var element = event.target && event.target.closest ? event.target.closest('[data-wkel-track]') : null;
        if (!element) return;
        var category = (element.getAttribute('data-wkel-track') || '').toLowerCase().replace(/[^a-z0-9_-]/g, '').slice(0, 40);
        if (category) send('cta', category);
    }, true);
}());
