/* =============================================================
   TPMS – Main JavaScript
   ============================================================= */

'use strict';

// ── Tala OS welcome animation ──────────────────────────────
(function() {
    const welcome = document.getElementById('talaOsWelcome');
    if (!welcome) return;
    if (document.body.classList.contains('app-window-embed')) return;
    if (document.documentElement.getAttribute('data-layout') !== 'app') return;

    let shouldShow = false;
    try {
        shouldShow = sessionStorage.getItem('tpms_tala_os_welcome') === '1';
        if (shouldShow) {
            sessionStorage.removeItem('tpms_tala_os_welcome');
        }
    } catch (e) {}

    if (!shouldShow) return;

    function playWelcome() {
        welcome.classList.add('is-active');
        window.setTimeout(function() {
            welcome.classList.remove('is-active');
        }, 1850);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', playWelcome, { once: true });
    } else {
        playWelcome();
    }
})();

// ── Sidebar Toggle ──────────────────────────────────────────
(function() {
    const toggle  = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const appDock = document.getElementById('appDock');
    const overlay = document.getElementById('sidebarOverlay');
    const appDrawer = document.getElementById('appDrawer');
    const appDrawerBackdrop = document.getElementById('appDrawerBackdrop');
    const appDrawerClose = document.getElementById('appDrawerClose');
    const dockDrawerBtn = document.getElementById('dockDrawerBtn');
    const wrapper = document.querySelector('.main-wrapper');
    if (!sidebar) return;

    const STORE_KEY = 'tpms_sidebar_collapsed';

    function isDesktop()  { return window.innerWidth > 900; }
    function isMobileNav() { return window.innerWidth <= 900; }
    function isAppLayout() { return document.documentElement.getAttribute('data-layout') === 'app'; }

    function open() {
        sidebar.classList.add('open');
        if (overlay) overlay.classList.add('active');
        if (isMobileNav()) document.body.style.overflow = 'hidden';
    }
    function close() {
        sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    function collapse() {
        sidebar.classList.add('collapsed');
        if (wrapper) wrapper.classList.add('collapsed');
        try { localStorage.setItem(STORE_KEY, '1'); } catch(e) {}
    }
    function expand() {
        sidebar.classList.remove('collapsed');
        if (wrapper) wrapper.classList.remove('collapsed');
        try { localStorage.setItem(STORE_KEY, '0'); } catch(e) {}
    }

    function openDrawer() {
        if (!appDrawer || !appDrawerBackdrop) return;
        appDrawer.classList.add('open');
        appDrawerBackdrop.classList.add('active');
        appDrawer.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
        if (!appDrawer || !appDrawerBackdrop) return;
        appDrawer.classList.remove('open');
        appDrawerBackdrop.classList.remove('active');
        appDrawer.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    window.tpmsAppDrawer = {
        open: openDrawer,
        close: closeDrawer,
        isOpen: function() {
            return !!(appDrawer && appDrawer.classList.contains('open'));
        }
    };

    function syncToggleMode() {
        if (!toggle) return;
        const icon = toggle.querySelector('i');
        if (!icon) return;
        if (isAppLayout()) {
            icon.className = 'fas fa-grip';
            toggle.title = 'Open app drawer';
        } else {
            icon.className = 'fas fa-bars';
            toggle.title = 'Toggle sidebar';
        }
    }

    function mountDockToViewport() {
        if (!appDock) return;
        if (appDock.parentElement !== document.body) {
            document.body.appendChild(appDock);
        }
    }

    if (toggle) {
        toggle.addEventListener('click', () => {
            if (isAppLayout()) {
                if (appDrawer && appDrawer.classList.contains('open')) {
                    closeDrawer();
                } else {
                    openDrawer();
                }
                return;
            }
            if (isDesktop()) {
                sidebar.classList.contains('collapsed') ? expand() : collapse();
            } else {
                sidebar.classList.contains('open') ? close() : open();
            }
        });
    }

    if (overlay) overlay.addEventListener('click', close);
    if (appDrawerBackdrop) appDrawerBackdrop.addEventListener('click', closeDrawer);
    if (appDrawerClose) appDrawerClose.addEventListener('click', closeDrawer);
    if (dockDrawerBtn) {
        dockDrawerBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!isAppLayout()) return;
            if (appDrawer && appDrawer.classList.contains('open')) {
                closeDrawer();
            } else {
                openDrawer();
            }
        });
    }

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            close();
            closeDrawer();
        }
    });

    // Restore desktop collapsed preference
    try {
        if (isDesktop() && localStorage.getItem(STORE_KEY) === '1') collapse();
    } catch(e) {}

    // Reset on resize to avoid stuck state
    window.addEventListener('resize', () => {
        if (isDesktop()) close();
        closeDrawer();
    });

    mountDockToViewport();
    syncToggleMode();
    try {
        const observer = new MutationObserver(syncToggleMode);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-layout'] });
    } catch (e) {}
})();

// ── App Window Manager (app layout only) ───────────────────
(function() {
    const stage = document.getElementById('appWindowStage');
    const dock = document.getElementById('appDock');
    const dockMinimized = document.getElementById('appDockMinimized');
    const dockDivider = document.getElementById('appDockDivider');
    const emptyState = document.getElementById('appWindowEmpty');
    const currentAppTitle = document.getElementById('topbarCurrentAppTitle');
    const currentAppSubtitle = document.getElementById('topbarCurrentAppSubtitle');
    if (!stage || !dock || !dockMinimized || !dockDivider) return;

    const openWindows = new Map();
    let topZ = 400;
    let cascadeIndex = 0;
    let shellWindowingReady = false;
    let bootFallbackTimer = null;

    function isAppLayout() {
        return document.documentElement.getAttribute('data-layout') === 'app';
    }

    function isEmbeddedPage() {
        return document.body.classList.contains('app-window-embed');
    }

    function normalizeUrl(rawHref) {
        const url = new URL(rawHref, window.location.href);
        url.searchParams.delete('app_window');
        url.hash = '';
        return url.toString();
    }

    function buildWindowUrl(rawHref) {
        const url = new URL(rawHref, window.location.href);
        url.searchParams.set('app_window', '1');
        return url.toString();
    }

    function getWindowKey(rawHref) {
        return normalizeUrl(rawHref);
    }

    function getAppIdFromHref(rawHref) {
        try {
            const path = new URL(rawHref, window.location.href).pathname;
            const leaf = path.split('/').filter(Boolean).pop() || 'app';
            return leaf.replace(/\.php$/i, '') || 'app';
        } catch (_) {
            return 'app';
        }
    }

    function getIconClassFromNode(node) {
        if (!node || !node.querySelector) return 'fas fa-window-maximize';
        const icon = node.querySelector('i');
        return icon ? String(icon.className || 'fas fa-window-maximize').replace(/\s*nav-icon\b/g, '').trim() : 'fas fa-window-maximize';
    }

    function buildWindowMeta(rawHref, source, fallbackTitle) {
        const meta = source && typeof source === 'object' ? source : {};
        const title = meta.title || (source && source.dataset ? source.dataset.appWindowTitle : '') || fallbackTitle || 'App';
        return {
            title: title,
            appId: meta.appId || (source && source.dataset ? source.dataset.appId : '') || getAppIdFromHref(rawHref),
            iconClass: meta.iconClass || (source && source.dataset ? source.dataset.appIcon : '') || getIconClassFromNode(source) || 'fas fa-window-maximize'
        };
    }

    function getDockItem(appId) {
        return dock.querySelector('.app-dock-item[data-app-id="' + appId + '"]');
    }

    function getWindowsForApp(appId) {
        return Array.from(openWindows.values()).filter(function(entry) {
            return entry.appId === appId;
        });
    }

    function sortEntriesByRecency(entries) {
        return entries.slice().sort(function(a, b) {
            return (b.lastTouched || 0) - (a.lastTouched || 0);
        });
    }

    function getPreferredAppWindow(appId) {
        const entries = sortEntriesByRecency(getWindowsForApp(appId));
        if (!entries.length) return null;
        return entries.find(function(entry) {
            return !entry.window.classList.contains('is-minimized');
        }) || entries[0];
    }

    function syncDockState() {
        const pinnedItems = dock.querySelectorAll('.app-dock-item[data-app-id]');
        pinnedItems.forEach(function(item) {
            item.classList.remove('is-running', 'is-minimized', 'is-focused');
            item.removeAttribute('data-window-count');
        });

        dockMinimized.innerHTML = '';
        const dynamicButtons = [];
        const grouped = new Map();

        openWindows.forEach(function(entry) {
            const list = grouped.get(entry.appId) || [];
            list.push(entry);
            grouped.set(entry.appId, list);
        });

        grouped.forEach(function(entries, appId) {
            const sorted = sortEntriesByRecency(entries);
            const latest = sorted[0];
            const pinned = getDockItem(appId);
            const hasVisible = entries.some(function(entry) {
                return !entry.window.classList.contains('is-minimized');
            });
            const hasMinimized = entries.some(function(entry) {
                return entry.window.classList.contains('is-minimized');
            });
            const focused = entries.some(function(entry) {
                return entry.window.classList.contains('is-focused') && !entry.window.classList.contains('is-minimized');
            });

            if (pinned) {
                pinned.classList.add('is-running');
                if (hasMinimized) pinned.classList.add('is-minimized');
                if (focused) pinned.classList.add('is-focused');
                if (entries.length > 1) {
                    pinned.setAttribute('data-window-count', String(entries.length));
                }
                return;
            }

            if (!hasMinimized) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-dock-item app-dock-item-dynamic is-running is-minimized';
            if (hasVisible) button.classList.add('is-focused');
            button.setAttribute('data-dock-window-target', '1');
            button.setAttribute('data-app-id', appId);
            button.setAttribute('data-app-window-title', latest.title || 'App');
            button.setAttribute('data-app-icon', latest.iconClass || 'fas fa-window-maximize');
            button.setAttribute('title', latest.title || 'App');
            if (entries.length > 1) {
                button.setAttribute('data-window-count', String(entries.length));
            }
            button.innerHTML = '<span class="app-dock-icon"><i class="' + (latest.iconClass || 'fas fa-window-maximize') + '"></i></span>'
                + '<span class="app-dock-label">' + (latest.title || 'App') + '</span>';
            dynamicButtons.push(button);
        });

        dynamicButtons.forEach(function(button) {
            dockMinimized.appendChild(button);
        });

        const hasDynamic = dynamicButtons.length > 0;
        dock.classList.toggle('has-minimized-apps', hasDynamic);
        dockDivider.hidden = !hasDynamic;
        dockMinimized.hidden = !hasDynamic;
    }

    function getStageBounds() {
        return stage.getBoundingClientRect();
    }

    function toggleEmptyState() {
        const visibleWindow = Array.from(openWindows.values()).some(entry => !entry.window.classList.contains('is-minimized'));
        if (!emptyState) return;
        emptyState.classList.toggle('hidden', visibleWindow);
    }

    function setCurrentAppLabel(title, subtitle) {
        if (currentAppTitle) {
            currentAppTitle.textContent = title || 'Desktop';
        }
        if (currentAppSubtitle) {
            currentAppSubtitle.textContent = subtitle || 'No app selected';
        }
    }

    function looksLikeTalaLink(anchorOrUrl) {
        if (!anchorOrUrl) return false;
        if (typeof anchorOrUrl === 'object' && anchorOrUrl.getAttribute) {
            if (anchorOrUrl.matches('[data-tala-link="1"]')) return true;
            const href = anchorOrUrl.getAttribute('href') || '';
            return /\/chatbot(?:\.php)?(?:$|[?#/])/i.test(href);
        }
        return /\/chatbot(?:\.php)?(?:$|[?#/])/i.test(String(anchorOrUrl));
    }

    function openTalaApp() {
        if (window.tpmsTalaApp && typeof window.tpmsTalaApp.open === 'function') {
            if (window.tpmsAppDrawer && window.tpmsAppDrawer.isOpen && window.tpmsAppDrawer.isOpen()) {
                window.tpmsAppDrawer.close();
            }
            window.tpmsTalaApp.open();
            setCurrentAppLabel('Tala AI', 'Assistant app');
            activateWindowingShell();
            return true;
        }
        return false;
    }

    function activateWindowingShell() {
        if (shellWindowingReady || isEmbeddedPage()) return;
        shellWindowingReady = true;
        if (bootFallbackTimer) {
            clearTimeout(bootFallbackTimer);
            bootFallbackTimer = null;
        }
        document.body.classList.add('app-windowing-active');
    }

    function cancelWindowingShell() {
        if (bootFallbackTimer) {
            clearTimeout(bootFallbackTimer);
            bootFallbackTimer = null;
        }
        shellWindowingReady = false;
        document.body.classList.remove('app-windowing-active');
    }

    function focusWindow(entry) {
        openWindows.forEach(function(item) {
            item.window.classList.remove('is-focused');
        });
        topZ += 1;
        entry.lastTouched = Date.now();
        entry.window.style.zIndex = String(topZ);
        entry.window.classList.add('is-focused');
        setCurrentAppLabel(entry.title || 'App', 'Current app');
        syncDockState();
    }

    function clampWindow(entry) {
        if (entry.window.classList.contains('is-maximized')) return;
        const bounds = getStageBounds();
        const width = entry.window.offsetWidth;
        const height = entry.window.offsetHeight;
        const maxLeft = Math.max(0, bounds.width - width);
        const maxTop = Math.max(0, bounds.height - height);
        entry.left = Math.max(0, Math.min(entry.left, maxLeft));
        entry.top = Math.max(0, Math.min(entry.top, maxTop));
        entry.window.style.left = entry.left + 'px';
        entry.window.style.top = entry.top + 'px';
    }

    function restoreWindow(entry) {
        entry.window.classList.remove('is-minimized');
        entry.lastTouched = Date.now();
        focusWindow(entry);
        toggleEmptyState();
        syncDockState();
    }

    function minimizeWindow(entry) {
        if (entry.window.classList.contains('is-minimized')) return;
        entry.window.classList.add('is-minimized');
        entry.lastTouched = Date.now();
        const nextVisible = Array.from(openWindows.values()).filter(function(item) {
            return item !== entry && !item.window.classList.contains('is-minimized');
        }).sort(function(a, b) {
            return Number(b.window.style.zIndex || 0) - Number(a.window.style.zIndex || 0);
        })[0];
        if (nextVisible) {
            focusWindow(nextVisible);
        } else if (window.tpmsTalaApp && window.tpmsTalaApp.isOpen && window.tpmsTalaApp.isOpen()) {
            setCurrentAppLabel('Tala AI', 'Assistant app');
        } else {
            setCurrentAppLabel('Desktop', 'No app selected');
        }
        toggleEmptyState();
        syncDockState();
    }

    function closeWindow(entry) {
        entry.window.remove();
        openWindows.delete(entry.key);
        if (openWindows.size === 0) {
            cancelWindowingShell();
            setCurrentAppLabel('Desktop', 'No app selected');
        } else {
            const lastVisible = Array.from(openWindows.values()).filter(function(item) {
                return !item.window.classList.contains('is-minimized');
            }).sort(function(a, b) {
                return Number(b.window.style.zIndex || 0) - Number(a.window.style.zIndex || 0);
            })[0];
            if (lastVisible) {
                focusWindow(lastVisible);
            } else if (window.tpmsTalaApp && window.tpmsTalaApp.isOpen && window.tpmsTalaApp.isOpen()) {
                setCurrentAppLabel('Tala AI', 'Assistant app');
            }
        }
        toggleEmptyState();
        syncDockState();
    }

    function maximizeWindow(entry) {
        const bounds = getStageBounds();
        if (!entry.window.classList.contains('is-maximized')) {
            entry.restoreBox = {
                left: entry.left,
                top: entry.top,
                width: entry.window.offsetWidth,
                height: entry.window.offsetHeight
            };
            entry.window.classList.add('is-maximized');
            entry.window.style.left = '0px';
            entry.window.style.top = '0px';
            entry.window.style.width = bounds.width + 'px';
            entry.window.style.height = bounds.height + 'px';
        } else {
            entry.window.classList.remove('is-maximized');
            if (entry.restoreBox) {
                entry.left = entry.restoreBox.left;
                entry.top = entry.restoreBox.top;
                entry.window.style.width = entry.restoreBox.width + 'px';
                entry.window.style.height = entry.restoreBox.height + 'px';
                clampWindow(entry);
            }
        }
        focusWindow(entry);
    }

    function makeDraggable(entry, handle) {
        let dragging = false;
        let startX = 0;
        let startY = 0;
        let startLeft = 0;
        let startTop = 0;

        handle.addEventListener('pointerdown', function(e) {
            if (e.target.closest('button')) return;
            if (entry.window.classList.contains('is-maximized')) return;
            dragging = true;
            startX = e.clientX;
            startY = e.clientY;
            startLeft = entry.left;
            startTop = entry.top;
            handle.setPointerCapture(e.pointerId);
            focusWindow(entry);
        });

        handle.addEventListener('pointermove', function(e) {
            if (!dragging) return;
            entry.left = startLeft + (e.clientX - startX);
            entry.top = startTop + (e.clientY - startY);
            clampWindow(entry);
        });

        function stopDrag(e) {
            if (!dragging) return;
            dragging = false;
            try { handle.releasePointerCapture(e.pointerId); } catch (_) {}
        }

        handle.addEventListener('pointerup', stopDrag);
        handle.addEventListener('pointercancel', stopDrag);
        handle.addEventListener('dblclick', function() {
            maximizeWindow(entry);
        });
    }

    function makeResizable(entry, grip) {
        let resizing = false;
        let startX = 0;
        let startY = 0;
        let startWidth = 0;
        let startHeight = 0;

        grip.addEventListener('pointerdown', function(e) {
            if (entry.window.classList.contains('is-maximized')) return;
            resizing = true;
            startX = e.clientX;
            startY = e.clientY;
            startWidth = entry.window.offsetWidth;
            startHeight = entry.window.offsetHeight;
            grip.setPointerCapture(e.pointerId);
            focusWindow(entry);
        });

        grip.addEventListener('pointermove', function(e) {
            if (!resizing) return;
            const bounds = getStageBounds();
            const nextWidth = Math.max(360, Math.min(startWidth + (e.clientX - startX), bounds.width - entry.left));
            const nextHeight = Math.max(280, Math.min(startHeight + (e.clientY - startY), bounds.height - entry.top));
            entry.window.style.width = nextWidth + 'px';
            entry.window.style.height = nextHeight + 'px';
        });

        function stopResize(e) {
            if (!resizing) return;
            resizing = false;
            try { grip.releasePointerCapture(e.pointerId); } catch (_) {}
        }

        grip.addEventListener('pointerup', stopResize);
        grip.addEventListener('pointercancel', stopResize);
    }

    function updateTitleFromFrame(entry) {
        try {
            const doc = entry.iframe.contentDocument;
            if (!doc) return;
            const nextTitle = String(doc.title || '').split('–')[0].trim();
            if (nextTitle) {
                entry.title = nextTitle;
                entry.titleNode.textContent = nextTitle;
                if (entry.window.classList.contains('is-focused')) {
                    setCurrentAppLabel(nextTitle, 'Current app');
                }
                syncDockState();
            }
        } catch (_) {}
    }

    function markWindowLoadFailed(entry, message) {
        const frame = entry.window.querySelector('.app-window-frame');
        if (!frame || frame.querySelector('.app-window-fallback')) return;
        const note = document.createElement('div');
        note.className = 'app-window-fallback';
        note.innerHTML = '<div class="app-window-fallback-title">Window failed to load</div>'
            + '<div class="app-window-fallback-text">' + message + '</div>'
            + '<a class="app-window-fallback-link" href="' + entry.href + '">Open page directly</a>';
        frame.appendChild(note);
    }

    function bindEmbeddedWindowRouting(entry) {
        let doc;
        try {
            doc = entry.iframe.contentDocument;
        } catch (_) {
            return;
        }
        if (!doc || doc.__tpmsWindowRoutingBound) return;
        doc.__tpmsWindowRoutingBound = true;

        doc.addEventListener('click', function(e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
            const anchor = e.target.closest('a');
            if (!anchor) return;
            if (looksLikeTalaLink(anchor)) {
                e.preventDefault();
                window.parent.postMessage({ type: 'tpms-app-window-open-tala' }, window.location.origin);
                return;
            }
            const href = anchor.getAttribute('href') || '';
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
            if (anchor.hasAttribute('download')) return;
            if ((anchor.getAttribute('target') || '').toLowerCase() === '_blank') return;

            let resolved;
            try {
                resolved = new URL(anchor.href, entry.iframe.contentWindow.location.href);
            } catch (_) {
                return;
            }

            if (resolved.origin !== window.location.origin) return;
            if (/\/actions\/logout/i.test(resolved.pathname)) return;

            const lowerPath = resolved.pathname.toLowerCase();
            const looksLikePage = lowerPath.endsWith('.php') || !lowerPath.includes('/actions/');
            if (!looksLikePage) return;

            e.preventDefault();
            createWindow(resolved.toString(), buildWindowMeta(resolved.toString(), anchor, anchor.dataset.appWindowTitle || anchor.textContent.trim() || doc.title || 'App'));
        });
    }

    function createWindow(rawHref, meta) {
        if (window.tpmsAppDrawer && window.tpmsAppDrawer.isOpen && window.tpmsAppDrawer.isOpen()) {
            window.tpmsAppDrawer.close();
        }
        const key = getWindowKey(rawHref);
        const resolvedMeta = buildWindowMeta(rawHref, meta, typeof meta === 'string' ? meta : 'App');
        if (openWindows.has(key)) {
            const existing = openWindows.get(key);
            restoreWindow(existing);
            focusWindow(existing);
            return existing;
        }

        const bounds = getStageBounds();
        const win = document.createElement('section');
        win.className = 'app-window is-focused';

        const toolbar = document.createElement('div');
        toolbar.className = 'app-window-toolbar';

        const lights = document.createElement('div');
        lights.className = 'app-window-lights';
        lights.innerHTML = '<button type="button" class="app-window-light close" aria-label="Close"><i class="fas fa-xmark"></i><span>Close</span></button>'
            + '<button type="button" class="app-window-light minimize" aria-label="Minimize"><i class="fas fa-window-minimize"></i><span>Minimize</span></button>'
            + '<button type="button" class="app-window-light maximize" aria-label="Maximize"><i class="fas fa-expand"></i><span>Maximize</span></button>';

        const titleStack = document.createElement('div');
        titleStack.className = 'app-window-title-stack';
        const titleNode = document.createElement('div');
        titleNode.className = 'app-window-title';
        titleNode.textContent = resolvedMeta.title;
        const urlNode = document.createElement('div');
        urlNode.className = 'app-window-url';
        urlNode.textContent = new URL(rawHref, window.location.href).pathname.replace(/\//g, ' / ').trim();
        titleStack.appendChild(titleNode);
        titleStack.appendChild(urlNode);

        const actions = document.createElement('div');
        actions.className = 'app-window-actions';
        actions.innerHTML = '<button type="button" class="app-window-action" aria-label="Reload"><i class="fas fa-rotate-right"></i></button>';

        toolbar.appendChild(lights);
        toolbar.appendChild(titleStack);
        toolbar.appendChild(actions);

        const frameWrap = document.createElement('div');
        frameWrap.className = 'app-window-frame';
        const iframe = document.createElement('iframe');
        iframe.className = 'app-window-iframe';
        iframe.loading = 'lazy';
        iframe.src = buildWindowUrl(rawHref);
        frameWrap.appendChild(iframe);

        const resize = document.createElement('div');
        resize.className = 'app-window-resize';
        frameWrap.appendChild(resize);

        win.appendChild(toolbar);
        win.appendChild(frameWrap);
        stage.appendChild(win);

        const entry = {
            key: key,
            href: rawHref,
            title: resolvedMeta.title,
            appId: resolvedMeta.appId,
            iconClass: resolvedMeta.iconClass,
            window: win,
            iframe: iframe,
            titleNode: titleNode,
            loaded: false,
            left: Math.max(0, Math.min(40 + (cascadeIndex * 26), bounds.width - Math.min(980, bounds.width))),
            top: Math.max(0, Math.min(20 + (cascadeIndex * 20), bounds.height - 320)),
            restoreBox: null,
            lastTouched: Date.now()
        };

        cascadeIndex = (cascadeIndex + 1) % 8;
        win.style.left = entry.left + 'px';
        win.style.top = entry.top + 'px';
        focusWindow(entry);

        lights.querySelector('.close').addEventListener('click', function() { closeWindow(entry); });
        lights.querySelector('.minimize').addEventListener('click', function() { minimizeWindow(entry); });
        lights.querySelector('.maximize').addEventListener('click', function() { maximizeWindow(entry); });
        actions.querySelector('.app-window-action').addEventListener('click', function() { iframe.src = buildWindowUrl(rawHref); });
        win.addEventListener('pointerdown', function() { focusWindow(entry); });
        iframe.addEventListener('load', function() {
            entry.loaded = true;
            updateTitleFromFrame(entry);
            bindEmbeddedWindowRouting(entry);
            activateWindowingShell();
        });
        iframe.addEventListener('error', function() {
            markWindowLoadFailed(entry, 'The embedded app could not be opened inside a desktop window.');
        });

        window.setTimeout(function() {
            if (!entry.loaded) {
                markWindowLoadFailed(entry, 'The app window is taking too long to respond. You can still open the page directly.');
            }
        }, 7000);

        makeDraggable(entry, toolbar);
        makeResizable(entry, resize);
        openWindows.set(key, entry);
        toggleEmptyState();
        syncDockState();
        return entry;
    }

    function activateDockItem(item) {
        if (!item) return;
        const appId = item.dataset.appId || getAppIdFromHref(item.getAttribute('href') || item.dataset.href || '');
        const targetEntry = getPreferredAppWindow(appId);
        if (targetEntry) {
            restoreWindow(targetEntry);
            focusWindow(targetEntry);
            return;
        }
        if (looksLikeTalaLink(item)) {
            openTalaApp();
            return;
        }
        const href = item.getAttribute('href') || item.dataset.href;
        if (!href) return;
        createWindow(href, buildWindowMeta(href, item, item.dataset.appWindowTitle || item.textContent.trim() || 'App'));
    }

    function shouldIntercept(anchor) {
        if (!anchor || !isAppLayout() || isEmbeddedPage()) return false;
        if (!anchor.matches('[data-app-window-link="1"]') && !looksLikeTalaLink(anchor)) return false;
        if (anchor.hasAttribute('download')) return false;
        if ((anchor.getAttribute('target') || '').toLowerCase() === '_blank') return false;
        if ((anchor.getAttribute('href') || '').startsWith('javascript:')) return false;
        const href = anchor.getAttribute('href') || '';
        if (!href || href.startsWith('#')) return false;
        if (/\/actions\/logout/i.test(href)) return false;
        return true;
    }

    document.addEventListener('click', function(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        const anchor = e.target.closest('a');
        if (!shouldIntercept(anchor)) return;
        e.preventDefault();
        if (looksLikeTalaLink(anchor)) {
            openTalaApp();
            return;
        }
        createWindow(anchor.href, buildWindowMeta(anchor.href, anchor, anchor.dataset.appWindowTitle || anchor.textContent.trim() || 'App'));
    });

    dock.addEventListener('click', function(e) {
        const item = e.target.closest('.app-dock-item');
        if (!item || !dock.contains(item) || item.classList.contains('app-dock-drawer')) return;
        if (!isAppLayout()) return;
        e.preventDefault();
        e.stopPropagation();
        activateDockItem(item);
    });

    window.addEventListener('message', function(e) {
        if (e.origin !== window.location.origin) return;
        const data = e.data || {};
        if (data.type === 'tpms-app-window-open-tala') {
            openTalaApp();
            return;
        }
        if (data.type !== 'tpms-app-window-open' || !data.href) return;
        createWindow(data.href, buildWindowMeta(data.href, data, data.title || 'App'));
    });

    function bootCurrentPageWindow() {
        if (!isAppLayout() || isEmbeddedPage()) return;
        cancelWindowingShell();
        setCurrentAppLabel('Desktop', 'No app selected');
        toggleEmptyState();
        syncDockState();

        const desktopTarget = document.querySelector('.main-wrapper');
        if (desktopTarget && !desktopTarget.__tpmsDesktopDrawerBound) {
            desktopTarget.__tpmsDesktopDrawerBound = true;
            desktopTarget.addEventListener('dblclick', function(e) {
                if (e.target.closest('.topbar, .app-dock, .app-window, .app-drawer')) return;
                if (window.tpmsAppDrawer && typeof window.tpmsAppDrawer.open === 'function') {
                    window.tpmsAppDrawer.open();
                }
            });
        }
    }

    window.addEventListener('resize', function() {
        openWindows.forEach(function(entry) {
            clampWindow(entry);
            if (entry.window.classList.contains('is-maximized')) {
                const bounds = getStageBounds();
                entry.window.style.width = bounds.width + 'px';
                entry.window.style.height = bounds.height + 'px';
            }
        });
        syncDockState();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootCurrentPageWindow, { once: true });
    } else {
        bootCurrentPageWindow();
    }
})();

// ── Remove stray markdown nav leak (defensive cleanup) ─────
(function() {
    const suspicious = [
        '[** Schools]',
        '[** Districts]',
        '[** Reports]',
        '[** Appearance]',
        '[** Tala AI]',
        '[** Users]',
        '[** Logs]'
    ];

    function looksLikeLeakedNav(text) {
        if (!text) return false;
        const normalized = String(text).replace(/\s+/g, ' ').trim();
        return suspicious.some(function(token) {
            return normalized.includes(token);
        });
    }

    function cleanupLeakedNav() {
        const mainWrapper = document.querySelector('.main-wrapper');
        if (!mainWrapper) return;

        const bodyChildren = Array.from(document.body.children || []);
        bodyChildren.forEach(function(node) {
            if (!(node instanceof HTMLElement)) return;
            if (node.classList.contains('sidebar-overlay')) return;
            if (node.classList.contains('app-drawer-backdrop')) return;
            if (node.classList.contains('sidebar')) return;
            if (node.classList.contains('app-drawer')) return;
            if (node.classList.contains('main-wrapper')) return;
            if (looksLikeLeakedNav(node.textContent || '')) {
                node.remove();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', cleanupLeakedNav, { once: true });
    } else {
        cleanupLeakedNav();
    }
})();

// ── Auto-dismiss flash messages ─────────────────────────────
(function() {
    const flash = document.getElementById('flashMsg');
    if (flash) {
        setTimeout(() => {
            flash.style.transition = 'opacity .5s';
            flash.style.opacity    = '0';
            setTimeout(() => flash.remove(), 500);
        }, 5000);
    }
})();

// ── Password toggle ─────────────────────────────────────────
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', function() {
        const targetId = this.dataset.target;
        const input    = document.getElementById(targetId);
        if (!input) return;
        const isPass = input.type === 'password';
        input.type   = isPass ? 'text' : 'password';
        this.querySelector('i').className = isPass ? 'fas fa-eye-slash' : 'fas fa-eye';
    });
});

// ── Close modal on overlay click ────────────────────────────
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) overlay.style.display = 'none';
    });
});

// ── Escape key closes modals ─────────────────────────────────
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.style.display = 'none';
        });
    }
});

// ── Debounce helper ──────────────────────────────────────────
function debounce(fn, delay) {
    let timer;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

// ── Auto-submit search with debounce ────────────────────────
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', debounce(function() {
        const form = this.closest('form');
        if (form) form.submit();
    }, 600));
}

// ── Form loading state ───────────────────────────────────────
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function() {
        const btn = this.querySelector('button[type="submit"]');
        if (btn && !btn.dataset.noLoad) {
            btn.disabled = true;
            const orig   = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';
            // Re-enable after 15s failsafe
            setTimeout(() => {
                btn.disabled  = false;
                btn.innerHTML = orig;
            }, 15000);
        }
    });
});

// ── Confirm dangerous actions ────────────────────────────────
function confirmDelete(id, name) {
    const modal = document.getElementById('deleteModal');
    if (!modal) return;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteId').value          = id;
    modal.style.display = 'flex';
}

// ── Table row highlight on hover (touch support) ─────────────
document.querySelectorAll('.data-table tbody tr').forEach(row => {
    row.addEventListener('touchstart', () => row.style.background = 'rgba(255,255,255,.04)');
    row.addEventListener('touchend',   () => row.style.background = '');
});

// ── Sticky table header highlight ───────────────────────────
(function() {
    const tables = document.querySelectorAll('.table-scroll');
    tables.forEach(wrap => {
        wrap.addEventListener('scroll', () => {
            wrap.classList.toggle('scrolled', wrap.scrollLeft > 0);
        });
    });
})();

// ── Chart.js global defaults ─────────────────────────────────
if (typeof Chart !== 'undefined') {
    Chart.defaults.color          = '#94a3b8';
    Chart.defaults.font.family    = "'Inter', system-ui, sans-serif";
    Chart.defaults.plugins.legend.labels.boxWidth  = 12;
    Chart.defaults.plugins.legend.labels.padding   = 16;
    Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(15,23,42,.92)';
    Chart.defaults.plugins.tooltip.borderColor     = 'rgba(99,102,241,.4)';
    Chart.defaults.plugins.tooltip.borderWidth     = 1;
    Chart.defaults.plugins.tooltip.padding         = 10;
    Chart.defaults.plugins.tooltip.titleFont       = { weight: '600' };
}

// ── Lazy-load images ─────────────────────────────────────────
if ('IntersectionObserver' in window) {
    const imgObs = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    imgObs.unobserve(img);
                }
            }
        });
    }, { rootMargin: '100px' });

    document.querySelectorAll('img[data-src]').forEach(img => imgObs.observe(img));
}

// ── AJAX helpers ─────────────────────────────────────────────
async function tpmsGet(url) {
    const res = await fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

async function tpmsPost(url, data) {
    const body = data instanceof FormData ? data : JSON.stringify(data);
    const headers = { 'X-Requested-With': 'XMLHttpRequest' };
    if (!(data instanceof FormData)) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, { method: 'POST', headers, body });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
}

// ── Toast alerts (for AJAX responses) ────────────────────────
function showToast(msg, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `alert alert-${type}`;
    toast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:9999;min-width:280px;animation:slideDown .3s ease';
    toast.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i> ${msg}
        <button class="alert-close" onclick="this.parentElement.remove()">×</button>`;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity    = '0';
        toast.style.transition = 'opacity .4s';
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}
