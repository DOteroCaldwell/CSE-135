/*
 * CSE 135 — HW3 collector.js
 * Served from collector.ucsdwrestlingclub.com, executed on test.ucsdwrestlingclub.com.
 *
 * Collects three categories of data and POSTs each to /log:
 *   static      — one payload per pageview, after load
 *   performance — one payload per pageview, after load
 *   activity    — batched stream of events until the page goes away
 *
 * Vanilla JS, no dependencies, no build step (course constraint).
 */
(function () {
    'use strict';

    var ORIGIN   = 'https://collector.ucsdwrestlingclub.com';
    var ENDPOINT = ORIGIN + '/log';
    var PIXEL    = ORIGIN + '/px.gif';

    var COOKIE_NAME   = 'sid';
    var COOKIE_DOMAIN = '.ucsdwrestlingclub.com';

    var IDLE_MS       = 2000;   // spec: gaps of >= 2s count as idle
    var MOUSE_MS      = 100;    // throttle for mousemove
    var SCROLL_MS     = 150;    // throttle for scroll
    var FLUSH_MS      = 5000;   // periodic activity flush
    var FLUSH_AT      = 50;     // flush early once the queue reaches this
    var QUEUE_MAX     = 500;    // hard cap, oldest dropped
    var RESOURCE_MAX  = 100;    // per resources payload, keeps the beacon well under quota

    /* ---------------------------------------------------------------- utils */

    function uuid() {
        if (window.crypto && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        if (window.crypto && crypto.getRandomValues) {
            var b = new Uint8Array(16);
            crypto.getRandomValues(b);
            b[6] = (b[6] & 0x0f) | 0x40;
            b[8] = (b[8] & 0x3f) | 0x80;
            var h = [];
            for (var i = 0; i < 16; i++) {
                h.push((b[i] + 0x100).toString(16).slice(1));
            }
            return h.slice(0, 4).join('') + '-' + h.slice(4, 6).join('') + '-' +
                   h.slice(6, 8).join('') + '-' + h.slice(8, 10).join('') + '-' +
                   h.slice(10, 16).join('');
        }
        return 'nocrypto-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function readCookie(name) {
        var parts = String(document.cookie || '').split('; ');
        for (var i = 0; i < parts.length; i++) {
            var eq = parts[i].indexOf('=');
            if (eq > -1 && parts[i].slice(0, eq) === name) {
                return decodeURIComponent(parts[i].slice(eq + 1));
            }
        }
        return null;
    }

    /* ------------------------------------------------------------ sessioning */

    /*
     * The session id is minted server-side by mod_usertrack on the test vhost and
     * logged there as %{cookie}n, which is what lets a database row be joined to
     * an access-log line. We only read it.
     *
     * The client-side mint below is a fallback for when that cookie is absent
     * (module not enabled, a response that slipped past it, cookies blocked).
     * sidSource is reported with every payload so the two cases stay
     * distinguishable in the data rather than silently blending together.
     */
    var sidSource = 'server';
    var sid = readCookie(COOKIE_NAME);

    if (!sid) {
        sid = uuid();
        sidSource = 'client';
        try {
            document.cookie = COOKIE_NAME + '=' + encodeURIComponent(sid) +
                '; path=/; domain=' + COOKIE_DOMAIN + '; SameSite=Lax' +
                (location.protocol === 'https:' ? '; Secure' : '');
        } catch (e) {
            sidSource = 'client-nocookie';
        }
        if (readCookie(COOKIE_NAME) !== sid) {
            sidSource = 'client-nocookie';
        }
    }

    // Distinguishes the individual page load from the session that contains it.
    var pvid = uuid();

    /* -------------------------------------------------------------- transport */

    /*
     * text/plain is deliberate. sendBeacon with a CORS-safelisted content type
     * issues a no-cors request: no OPTIONS preflight, and no CORS response headers
     * required for it to be delivered. Switching this to application/json would
     * add a preflight to every single beacon. The server parses the body as JSON
     * regardless of the declared type.
     */
    function send(type, data) {
        var body;
        try {
            body = JSON.stringify({
                type: type,
                sid: sid,
                sidSource: sidSource,
                pvid: pvid,
                page: location.pathname + location.search,
                href: location.href,
                referrer: document.referrer || null,
                sentAt: Date.now(),
                data: data
            });
        } catch (e) {
            return false;
        }

        if (navigator.sendBeacon) {
            try {
                var blob = new Blob([body], { type: 'text/plain;charset=UTF-8' });
                if (navigator.sendBeacon(ENDPOINT, blob)) {
                    return true;
                }
                // returns false when the payload exceeds the UA's beacon quota
            } catch (e) { /* fall through */ }
        }

        try {
            fetch(ENDPOINT, {
                method: 'POST',
                body: body,
                credentials: 'include',
                keepalive: true,
                headers: { 'Content-Type': 'text/plain;charset=UTF-8' }
            })['catch'](function () {});
            return true;
        } catch (e) {
            return false;
        }
    }

    /* ----------------------------------------------------------------- static */

    function connectionInfo() {
        var c = navigator.connection || navigator.mozConnection ||
                navigator.webkitConnection;
        if (!c) {
            return null;
        }
        return {
            effectiveType: c.effectiveType || null,
            type: c.type || null,
            downlinkMbps: typeof c.downlink === 'number' ? c.downlink : null,
            rttMs: typeof c.rtt === 'number' ? c.rtt : null,
            saveData: !!c.saveData
        };
    }

    // Round-trip test rather than trusting navigator.cookieEnabled, which lies in
    // some configurations. Both are reported.
    function cookieSupport() {
        var probe = 'cse135probe';
        var wrote = false;
        try {
            document.cookie = probe + '=1; path=/; SameSite=Lax';
            wrote = readCookie(probe) === '1';
            document.cookie = probe + '=; path=/; Max-Age=0; SameSite=Lax';
        } catch (e) {
            wrote = false;
        }
        return { roundTrip: wrote, navigatorFlag: !!navigator.cookieEnabled };
    }

    /*
     * Two separate questions:
     *   inline   — is the browser applying CSS at all? (a <style> we control)
     *   external — did a linked stylesheet actually load and produce rules?
     * A reader mode or text browser fails the first; a blocked/failed stylesheet
     * request fails only the second.
     */
    function cssSupport() {
        var inline = false;
        var style = null;
        var probe = null;
        try {
            style = document.createElement('style');
            style.textContent =
                '#cse135-css-probe{position:absolute!important;left:-9999px!important;' +
                'top:-9999px!important;width:7px!important;height:3px!important}';
            document.head.appendChild(style);

            probe = document.createElement('div');
            probe.id = 'cse135-css-probe';
            document.body.appendChild(probe);

            inline = window.getComputedStyle(probe).width === '7px';
        } catch (e) {
            inline = false;
        } finally {
            if (probe && probe.parentNode) { probe.parentNode.removeChild(probe); }
            if (style && style.parentNode) { style.parentNode.removeChild(style); }
        }

        var external = false;
        var sheets = document.styleSheets || [];
        for (var i = 0; i < sheets.length; i++) {
            if (!sheets[i].href) {
                continue;
            }
            try {
                if (sheets[i].cssRules && sheets[i].cssRules.length) {
                    external = true;
                    break;
                }
            } catch (e) {
                // cross-origin sheet: unreadable, but it did load
                external = true;
                break;
            }
        }

        return { inline: inline, external: external };
    }

    /*
     * Fetches a real 1x1 from the collector rather than a data: URI — a data URI
     * can still decode when remote images are blocked, which is exactly the case
     * we are trying to detect. The request also drops a line carrying this sid
     * into the collector's own access log, which is a handy cross-check.
     */
    function imageSupport(done) {
        var settled = false;
        var img = new Image();

        function finish(supported, how) {
            if (settled) { return; }
            settled = true;
            clearTimeout(timer);
            done({ supported: supported, via: how });
        }

        var timer = setTimeout(function () { finish(false, 'timeout'); }, 3000);
        img.onload  = function () { finish(true,  'load');  };
        img.onerror = function () { finish(false, 'error'); };
        img.src = PIXEL + '?probe=img&sid=' + encodeURIComponent(sid) +
                  '&pvid=' + encodeURIComponent(pvid) + '&t=' + Date.now();
    }

    function sendStatic() {
        imageSupport(function (images) {
            send('static', {
                userAgent: navigator.userAgent,
                language: navigator.language || null,
                languages: navigator.languages ? [].slice.call(navigator.languages) : null,
                platform: navigator.platform || null,
                cookies: cookieSupport(),
                css: cssSupport(),
                images: images,
                // If this code is running, scripting is on by definition. The
                // JS-disabled case cannot report itself; it is captured by the
                // <noscript> pixel on each page, which shows up in the collector
                // access log with no matching payload here.
                javascript: true,
                screen: {
                    width: screen.width,
                    height: screen.height,
                    availWidth: screen.availWidth,
                    availHeight: screen.availHeight,
                    colorDepth: screen.colorDepth,
                    pixelDepth: screen.pixelDepth,
                    devicePixelRatio: window.devicePixelRatio || null
                },
                window: {
                    innerWidth: window.innerWidth,
                    innerHeight: window.innerHeight,
                    outerWidth: window.outerWidth,
                    outerHeight: window.outerHeight
                },
                connection: connectionInfo(),
                timezone: (function () {
                    try {
                        return Intl.DateTimeFormat().resolvedOptions().timeZone;
                    } catch (e) { return null; }
                })(),
                timezoneOffsetMin: new Date().getTimezoneOffset()
            });
        });
    }

    /* ------------------------------------------------------------ performance */

    function sendPerformance() {
        var out = {
            navigationTiming: null,
            legacyTiming: null,
            timeOrigin: null,
            loadStartMs: null,
            loadEndMs: null,
            loadStartEpoch: null,
            loadEndEpoch: null,
            totalLoadMs: null,
            source: null
        };

        var nav = null;
        try {
            if (performance.getEntriesByType) {
                var entries = performance.getEntriesByType('navigation');
                if (entries && entries.length) {
                    nav = entries[0];
                }
            }
        } catch (e) { /* fall through to legacy */ }

        if (nav) {
            out.source = 'PerformanceNavigationTiming';
            // the whole timing object, as the spec asks for
            out.navigationTiming = nav.toJSON ? nav.toJSON() : JSON.parse(JSON.stringify(nav));
            out.timeOrigin = performance.timeOrigin || null;
            // Relative to timeOrigin: startTime is 0 by definition.
            out.loadStartMs = nav.startTime;
            out.loadEndMs = nav.loadEventEnd;
            out.totalLoadMs = Math.round(nav.loadEventEnd - nav.startTime);
            if (out.timeOrigin) {
                out.loadStartEpoch = Math.round(out.timeOrigin + nav.startTime);
                out.loadEndEpoch = Math.round(out.timeOrigin + nav.loadEventEnd);
            }
        } else if (performance.timing) {
            out.source = 'performance.timing';
            var t = performance.timing;
            var legacy = {};
            for (var k in t) {
                if (typeof t[k] === 'number') { legacy[k] = t[k]; }
            }
            out.legacyTiming = legacy;
            out.loadStartEpoch = t.navigationStart || null;
            out.loadEndEpoch = t.loadEventEnd || null;
            if (t.navigationStart && t.loadEventEnd) {
                out.totalLoadMs = t.loadEventEnd - t.navigationStart;
            }
        }

        send('performance', out);
    }

    /* -------------------------------------------------------------- resources */

    /*
     * Navigation timing can size the subresource window
     * (loadEventStart - domContentLoadedEventEnd) but cannot say what filled it.
     * PerformanceResourceTiming names each file, which is what turns "the image
     * tail is slow" into "these four PNGs are 11 MB".
     *
     * Two collection points, deliberately:
     *   at load   — everything the document pulled in to reach the load event
     *   on leave  — everything lazy-loaded AFTER it
     *
     * The second matters more than it looks. This site lazy-loads half its product
     * images via data-src, so a load-time-only snapshot would systematically
     * under-count exactly the images that are deferred, and the weight report would
     * be biased toward whatever happens to load eagerly. Sampling both windows is
     * what keeps the ranking honest.
     */

    var lateResources = [];

    /*
     * transferSize === 0 is ambiguous and the report has to disambiguate it:
     *   0 with a non-zero decodedBodySize => served from cache
     *   0 with a zero    decodedBodySize => cross-origin without Timing-Allow-Origin
     * Both sizes are kept so the two cases stay distinguishable downstream.
     */
    function resourceEntry(r) {
        function num(v) { return typeof v === 'number' ? Math.round(v * 100) / 100 : null; }
        return {
            name: String(r.name || '').slice(0, 1000),
            initiatorType: r.initiatorType || null,
            startTime: num(r.startTime),
            duration: num(r.duration),
            transferSize: num(r.transferSize),
            encodedBodySize: num(r.encodedBodySize),
            decodedBodySize: num(r.decodedBodySize),
            nextHopProtocol: r.nextHopProtocol || null,
            renderBlockingStatus: r.renderBlockingStatus || null,
            deliveryType: r.deliveryType || null
        };
    }

    /*
     * Excludes instrumentation side-effects, NOT everything this collector owns.
     *
     * The distinction matters and it is easy to get backwards. The /log beacons and
     * the px.gif probe are traffic that exists only because we are measuring —
     * counting them would measure the observer. But collector.js itself is a
     * synchronous, render-blocking <script> in the <head> of every page: it is real
     * page weight that a real visitor really pays for, and it is a legitimate
     * candidate answer to "what should we fix". Filtering it out because it happens
     * to be ours is exactly the kind of convenient blind spot this platform is
     * supposed not to have.
     */
    function isOwnTraffic(name) {
        var n = String(name || '');
        return n.indexOf(ENDPOINT) === 0 || n.indexOf(PIXEL) === 0;
    }

    function sendResourceBatch(list, reason) {
        if (!list.length) {
            return;
        }
        var out = list;
        if (out.length > RESOURCE_MAX) {
            // Keep the slowest rather than the first N: an arbitrary prefix would
            // bias the ranking toward whatever the parser happened to reach first.
            out = out.slice().sort(function (a, b) {
                return (b.duration || 0) - (a.duration || 0);
            }).slice(0, RESOURCE_MAX);
        }
        send('resources', { reason: reason, entries: out });
    }

    function sendResources() {
        var out = [];
        try {
            var entries = performance.getEntriesByType('resource') || [];
            for (var i = 0; i < entries.length; i++) {
                if (isOwnTraffic(entries[i].name)) { continue; }
                out.push(resourceEntry(entries[i]));
            }
        } catch (e) {
            return;
        }
        sendResourceBatch(out, 'load');
    }

    // buffered:false, and registered only after the load-time snapshot has been
    // taken, so the observer yields strictly later entries and nothing is sent twice.
    function watchLateResources() {
        try {
            if (!window.PerformanceObserver) { return; }
            var po = new PerformanceObserver(function (list) {
                var es = list.getEntries();
                for (var i = 0; i < es.length; i++) {
                    if (isOwnTraffic(es[i].name)) { continue; }
                    if (lateResources.length >= RESOURCE_MAX) { return; }
                    lateResources.push(resourceEntry(es[i]));
                }
            });
            po.observe({ type: 'resource', buffered: false });
        } catch (e) { /* observer unsupported: load-time snapshot still went out */ }
    }

    /* --------------------------------------------------------------- activity */

    var queue = [];
    var lastActivityAt = Date.now();
    var pageEnteredAt = Date.now();
    var leaveSent = false;

    function flush(reason) {
        if (!queue.length) { return; }
        var batch = queue;
        queue = [];
        send('activity', { reason: reason || 'interval', events: batch });
    }

    function push(event, detail) {
        var now = Date.now();

        /*
         * Idle detection. The spec wants the gap recorded once it ENDS, with its
         * duration — so the check happens on the next activity, not on a timer.
         * Emitted before the event that ended it so the ordering reads correctly.
         */
        var gap = now - lastActivityAt;
        if (gap >= IDLE_MS) {
            queue.push({
                event: 'idle',
                at: now,
                page: location.pathname,
                detail: { endedAt: now, startedAt: lastActivityAt, durationMs: gap }
            });
        }
        lastActivityAt = now;

        queue.push({
            event: event,
            at: now,
            page: location.pathname,
            detail: detail || null
        });

        if (queue.length > QUEUE_MAX) {
            queue.splice(0, queue.length - QUEUE_MAX);
        }
        if (queue.length >= FLUSH_AT) {
            flush('size');
        }
    }

    function describe(el) {
        if (!el || el === window || !el.tagName) { return null; }
        var out = el.tagName.toLowerCase();
        if (el.id) { out += '#' + el.id; }
        if (el.className && typeof el.className === 'string') {
            out += '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.');
        }
        return out.slice(0, 120);
    }

    /*
     * Keystroke privacy.
     *
     * The checkout page collects a card number and a CVV in plain
     * <input type="text"> fields, so recording key values verbatim would write
     * payment details into the analytics database. Every keydown/keyup is still
     * recorded — timing, target, modifiers, and the key name for non-printable
     * keys — but a printable character typed into any editable field is masked.
     * Nothing the user types into a field is ever transmitted.
     */
    function isEditable(el) {
        if (!el || !el.tagName) { return false; }
        var tag = el.tagName.toLowerCase();
        if (tag === 'textarea' || tag === 'select') { return true; }
        if (tag === 'input') {
            var t = (el.getAttribute('type') || 'text').toLowerCase();
            return ['button', 'submit', 'reset', 'checkbox', 'radio', 'file', 'image']
                .indexOf(t) === -1;
        }
        return !!el.isContentEditable;
    }

    function keyDetail(e) {
        var key = e.key === undefined ? null : e.key;
        var printable = typeof key === 'string' && key.length === 1;
        var editable = isEditable(e.target);
        return {
            key: (printable && editable) ? '•' : key,
            code: e.code || null,
            masked: printable && editable,
            repeat: !!e.repeat,
            modifiers: {
                alt: !!e.altKey, ctrl: !!e.ctrlKey,
                meta: !!e.metaKey, shift: !!e.shiftKey
            },
            target: describe(e.target)
        };
    }

    /* --------------------------------------------------------------- wiring */

    // Capture phase, and addEventListener rather than window.onerror = ..., so a
    // later `window.onerror = fn` on the page cannot clobber this.
    window.addEventListener('error', function (e) {
        if (e && e.target && e.target !== window && e.target.tagName) {
            push('resource-error', {
                tag: e.target.tagName.toLowerCase(),
                url: String(e.target.src || e.target.href || '').slice(0, 500),
                target: describe(e.target)
            });
            return;
        }
        push('error', {
            message: e && e.message ? String(e.message).slice(0, 500) : null,
            source: e && e.filename ? String(e.filename).slice(0, 500) : null,
            line: e ? e.lineno : null,
            column: e ? e.colno : null,
            stack: (e && e.error && e.error.stack)
                ? String(e.error.stack).slice(0, 1000) : null
        });
    }, true);

    window.addEventListener('unhandledrejection', function (e) {
        var reason = e ? e.reason : null;
        push('unhandled-rejection', {
            message: reason
                ? String((reason && reason.message) || reason).slice(0, 500)
                : null,
            stack: (reason && reason.stack) ? String(reason.stack).slice(0, 1000) : null
        });
    });

    var lastMouse = 0;
    document.addEventListener('mousemove', function (e) {
        var now = Date.now();
        if (now - lastMouse < MOUSE_MS) {
            // still counts as activity for idle purposes, just not recorded
            lastActivityAt = now;
            return;
        }
        lastMouse = now;
        push('mousemove', { x: e.clientX, y: e.clientY, pageX: e.pageX, pageY: e.pageY });
    }, true);

    document.addEventListener('mousedown', function (e) {
        push('click', {
            x: e.clientX, y: e.clientY, pageX: e.pageX, pageY: e.pageY,
            button: e.button,
            buttonName: ['left', 'middle', 'right', 'back', 'forward'][e.button] || 'other',
            target: describe(e.target)
        });
    }, true);

    document.addEventListener('contextmenu', function (e) {
        push('contextmenu', { x: e.clientX, y: e.clientY, target: describe(e.target) });
    }, true);

    var lastScroll = 0;
    window.addEventListener('scroll', function () {
        var now = Date.now();
        if (now - lastScroll < SCROLL_MS) {
            lastActivityAt = now;
            return;
        }
        lastScroll = now;
        push('scroll', {
            scrollX: window.scrollX || window.pageXOffset || 0,
            scrollY: window.scrollY || window.pageYOffset || 0,
            maxY: Math.max(
                document.body ? document.body.scrollHeight : 0,
                document.documentElement ? document.documentElement.scrollHeight : 0
            )
        });
    }, true);

    document.addEventListener('keydown', function (e) { push('keydown', keyDetail(e)); }, true);
    document.addEventListener('keyup',   function (e) { push('keyup',   keyDetail(e)); }, true);

    /* ------------------------------------------------------- enter and leave */

    push('pageenter', {
        at: pageEnteredAt,
        title: document.title,
        referrer: document.referrer || null,
        visibility: document.visibilityState || null
    });

    function sendLeave(reason) {
        if (leaveSent) { return; }
        leaveSent = true;

        var now = Date.now();
        // A page abandoned mid-idle still had an idle gap; record it rather than
        // losing it, since the "break" ended when the page went away.
        var gap = now - lastActivityAt;
        if (gap >= IDLE_MS) {
            queue.push({
                event: 'idle',
                at: now,
                page: location.pathname,
                detail: {
                    endedAt: now, startedAt: lastActivityAt,
                    durationMs: gap, endedBy: 'pageleave'
                }
            });
        }

        queue.push({
            event: 'pageleave',
            at: now,
            page: location.pathname,
            detail: {
                at: now,
                reason: reason,
                timeOnPageMs: now - pageEnteredAt,
                visibility: document.visibilityState || null
            }
        });

        flush('pageleave');
        sendResourceBatch(lateResources, 'pageleave');
        lateResources = [];
    }

    // pagehide covers navigation and bfcache; visibilitychange covers the mobile
    // and Safari cases where pagehide is unreliable.
    window.addEventListener('pagehide', function () { sendLeave('pagehide'); });
    window.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { sendLeave('hidden'); }
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') { sendLeave('hidden'); }
    });

    // Restored from bfcache: it is a fresh view of the page, so re-arm.
    window.addEventListener('pageshow', function (e) {
        if (e && e.persisted) {
            leaveSent = false;
            pageEnteredAt = Date.now();
            lastActivityAt = pageEnteredAt;
            push('pageenter', { at: pageEnteredAt, restoredFromBfcache: true });
        }
    });

    setInterval(function () { flush('interval'); }, FLUSH_MS);

    /* ------------------------------------------------------------ kick it off */

    // loadEventEnd is 0 until the load event has finished dispatching, so the
    // performance payload is deferred a tick past it.
    function afterLoad() {
        setTimeout(function () {
            sendStatic();
            sendPerformance();
            sendResources();
            watchLateResources();
        }, 0);
    }

    if (document.readyState === 'complete') {
        afterLoad();
    } else {
        window.addEventListener('load', afterLoad);
    }
})();
