    </main><!-- /.page-content -->
<?php if (empty($isAppWindow)): ?>
</div><!-- /.main-wrapper -->
<?php endif; ?>

<!-- ========================================================
     GLOBAL SCRIPTS
     ======================================================== -->
<script src="<?= APP_URL ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="<?= APP_URL ?>/assets/vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= urlencode((string)(is_file(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : '1')) ?>"></script>

<?php if (!empty($extraJs)): foreach ($extraJs as $js): ?>
<script src="<?= clean($js) ?>"></script>
<?php endforeach; endif; ?>

<!-- Flash message display using SweetAlert2 -->
<script>
(function() {
    <?php
    $flash = getFlash();
    if ($flash):
        $type = $flash['type'];
        $icon = ($type === 'success') ? 'success' : 'error';
        $title = ($type === 'success') ? 'Success!' : 'Error';
        $confirmText = ($type === 'success') ? 'OK' : 'Try Again';
    ?>
    const showFlashAlert = function() {
        if (typeof Swal === 'undefined') {
            return;
        }
        Swal.fire({
            icon: <?= json_encode($icon) ?>,
            title: <?= json_encode($title) ?>,
            text: <?= json_encode((string)$flash['msg']) ?>,
            confirmButtonText: <?= json_encode($confirmText) ?>,
            confirmButtonColor: <?= json_encode(($type === 'success') ? '#10b981' : '#ef4444') ?>,
            allowOutsideClick: false,
            allowEscapeKey: false
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', showFlashAlert, { once: true });
    } else {
        showFlashAlert();
    }
    <?php endif; ?>
})();
</script>

<script>
</script>

<script>
(function() {
    var STORAGE_KEY = 'tpmsAppearance';
    var DEFAULT_PREFS = {
        theme: 'ios',
        density: 'comfortable',
        layout: 'app',
        bgPalette: 'theme',
        bgEffects: 'soft',
        glassTone: 'balanced',
        accentColor: '#6366f1',
        bgTintColor: '#c4b5fd',
        teacherTagColor: '#94a3b8',
        schoolHeadColor: '#ef4444'
    };
    var quickThemeBtn = document.getElementById('quickThemeBtn');
    var ALLOWED_THEMES = ['glass', 'frosted-glass', 'ios', 'pastel-sky', 'pastel-sunset'];
    var ALLOWED_DENSITY = ['comfortable', 'compact'];
    var ALLOWED_LAYOUT = ['default', 'app'];
    var ALLOWED_BG = ['theme', 'custom-color'];
    var ALLOWED_BG_EFFECTS = ['off', 'soft', 'vivid', 'immersive', 'color-flow'];
    var ALLOWED_GLASS = ['soft', 'balanced', 'strong'];

    function isValidHexColor(value) {
        return /^#[0-9a-fA-F]{6}$/.test(String(value || ''));
    }

    function hexToRgb(hex) {
        var normalized = String(hex || '#6366f1').trim();
        if (!isValidHexColor(normalized)) normalized = '#6366f1';
        var int = parseInt(normalized.slice(1), 16);
        return {
            r: (int >> 16) & 255,
            g: (int >> 8) & 255,
            b: int & 255
        };
    }

    function darkenHex(hex, amount) {
        var rgb = hexToRgb(hex);
        var clamp = function(v) { return Math.max(0, Math.min(255, v)); };
        var toHex = function(v) { return clamp(v).toString(16).padStart(2, '0'); };
        return '#' + toHex(rgb.r - amount) + toHex(rgb.g - amount) + toHex(rgb.b - amount);
    }

    function rgbaFromHex(hex, alpha) {
        var rgb = hexToRgb(hex);
        return 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
    }

    function contrastTextFromHex(hex) {
        var rgb = hexToRgb(hex);
        var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        return brightness >= 160 ? '#0f172a' : '#f8fafc';
    }

    function applyReadableMode(theme, bgPalette, tintHex) {
        var tint = isValidHexColor(tintHex) ? String(tintHex).toLowerCase() : DEFAULT_PREFS.bgTintColor;
        var rgb = hexToRgb(tint);
        var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        var isLightTint = brightness >= 160;
        var nextTheme = String(theme || DEFAULT_PREFS.theme);
        var nextBgPalette = String(bgPalette || DEFAULT_PREFS.bgPalette);
        var mode = 'light-text';

        if (nextTheme === 'frosted-glass' && nextBgPalette !== 'custom-color') {
            mode = 'light-text';
        } else if (nextBgPalette === 'custom-color') {
            mode = isLightTint ? 'dark-text' : 'light-text';
        } else if (nextTheme === 'ios' || nextTheme === 'pastel-sky' || nextTheme === 'pastel-sunset') {
            mode = 'dark-text';
        }

        document.documentElement.setAttribute('data-readable-mode', mode);
    }

    function applyAccentColor(hex) {
        var accent = isValidHexColor(hex) ? String(hex).toLowerCase() : DEFAULT_PREFS.accentColor;
        var rootStyle = document.documentElement.style;
        rootStyle.setProperty('--user-accent', accent);
        rootStyle.setProperty('--user-accent-dark', darkenHex(accent, 26));
        rootStyle.setProperty('--user-accent-glow', rgbaFromHex(accent, 0.28));
    }

    function applyBackgroundTint(hex) {
        var tint = isValidHexColor(hex) ? String(hex).toLowerCase() : DEFAULT_PREFS.bgTintColor;
        var rootStyle = document.documentElement.style;
        var rgb = hexToRgb(tint);
        var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        var isLight = brightness >= 160;
        rootStyle.setProperty('--user-bg-solid', tint);
        rootStyle.setProperty('--user-bg-tint-soft', rgbaFromHex(tint, 0.24));
        rootStyle.setProperty('--user-bg-tint-mid', rgbaFromHex(tint, 0.18));
        rootStyle.setProperty('--user-bg-tint-deep', rgbaFromHex(tint, 0.14));
        rootStyle.setProperty('--auto-text', isLight ? '#0f172a' : '#f8fafc');
        rootStyle.setProperty('--auto-text-muted', isLight ? '#334155' : '#cbd5e1');
        rootStyle.setProperty('--auto-text-sub', isLight ? '#475569' : '#94a3b8');
        rootStyle.setProperty('--auto-glass-bg', isLight ? 'rgba(255, 255, 255, 0.72)' : 'rgba(15, 23, 42, 0.52)');
        rootStyle.setProperty('--auto-glass-border', isLight ? 'rgba(15, 23, 42, 0.16)' : 'rgba(148, 163, 184, 0.34)');
        rootStyle.setProperty('--auto-select-option-bg', isLight ? '#ffffff' : '#1e293b');
        rootStyle.setProperty('--auto-select-option-text', isLight ? '#0f172a' : '#f8fafc');
    }

    function applyFrostedCardTint(theme, bgPalette, tintHex) {
        var rootStyle = document.documentElement.style;
        var nextTheme = String(theme || DEFAULT_PREFS.theme);
        var nextBgPalette = String(bgPalette || DEFAULT_PREFS.bgPalette);

        if (nextTheme === 'frosted-glass' && nextBgPalette === 'custom-color') {
            var tint = isValidHexColor(tintHex) ? String(tintHex).toLowerCase() : DEFAULT_PREFS.bgTintColor;
            var rgb = hexToRgb(tint);
            var brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
            var isLight = brightness >= 160;
            rootStyle.setProperty('--app-card-bg', rgbaFromHex(tint, isLight ? 0.5 : 0.42));
            rootStyle.setProperty('--app-card-border', rgbaFromHex(tint, isLight ? 0.78 : 0.68));
            rootStyle.setProperty('--app-card-tint-soft', rgbaFromHex(tint, isLight ? 0.56 : 0.46));
            rootStyle.setProperty('--app-card-tint-mid', rgbaFromHex(tint, isLight ? 0.38 : 0.28));
            return;
        }

        rootStyle.removeProperty('--app-card-bg');
        rootStyle.removeProperty('--app-card-border');
        rootStyle.removeProperty('--app-card-tint-soft');
        rootStyle.removeProperty('--app-card-tint-mid');
    }

    function applyTeacherCardColors(tagHex, headHex) {
        var tag = isValidHexColor(tagHex) ? String(tagHex).toLowerCase() : DEFAULT_PREFS.teacherTagColor;
        var head = isValidHexColor(headHex) ? String(headHex).toLowerCase() : DEFAULT_PREFS.schoolHeadColor;
        var rootStyle = document.documentElement.style;
        rootStyle.setProperty('--teacher-card-tint-soft', rgbaFromHex(tag, 0.24));
        rootStyle.setProperty('--teacher-card-tint-mid', rgbaFromHex(tag, 0.16));
        rootStyle.setProperty('--teacher-card-base', rgbaFromHex(tag, 0.22));
        rootStyle.setProperty('--teacher-card-border', rgbaFromHex(tag, 0.44));
        rootStyle.setProperty('--teacher-tag-bg', rgbaFromHex(tag, 0.2));
        rootStyle.setProperty('--teacher-tag-border', rgbaFromHex(tag, 0.46));
        rootStyle.setProperty('--teacher-tag-text', contrastTextFromHex(tag));
        rootStyle.setProperty('--teacher-head-soft', rgbaFromHex(head, 0.26));
        rootStyle.setProperty('--teacher-head-mid', rgbaFromHex(head, 0.14));
        rootStyle.setProperty('--teacher-head-border', rgbaFromHex(head, 0.72));
        rootStyle.setProperty('--teacher-head-shadow', rgbaFromHex(head, 0.3));
        rootStyle.setProperty('--teacher-head-chip-bg', rgbaFromHex(head, 0.2));
        rootStyle.setProperty('--teacher-head-chip-border', rgbaFromHex(head, 0.6));
        rootStyle.setProperty('--teacher-head-chip-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-badge-bg', rgbaFromHex(head, 0.22));
        rootStyle.setProperty('--teacher-head-badge-border', rgbaFromHex(head, 0.66));
        rootStyle.setProperty('--teacher-head-badge-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-icon', head);
    }

    function readPrefs() {
        try {
            var parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
            var prefs = Object.assign({}, DEFAULT_PREFS, parsed);
            if (ALLOWED_THEMES.indexOf(prefs.theme) === -1) prefs.theme = DEFAULT_PREFS.theme;
            if (ALLOWED_DENSITY.indexOf(prefs.density) === -1) prefs.density = DEFAULT_PREFS.density;
            if (ALLOWED_LAYOUT.indexOf(prefs.layout) === -1) prefs.layout = DEFAULT_PREFS.layout;
            if (prefs.bgPalette === 'pastel-mix' || prefs.bgPalette === 'pastel-aurora' || prefs.bgPalette === 'pastel-candy') {
                prefs.bgPalette = 'custom-color';
            }
            if (ALLOWED_BG.indexOf(prefs.bgPalette) === -1) prefs.bgPalette = DEFAULT_PREFS.bgPalette;
            if (ALLOWED_BG_EFFECTS.indexOf(prefs.bgEffects) === -1) prefs.bgEffects = DEFAULT_PREFS.bgEffects;
            if (ALLOWED_GLASS.indexOf(prefs.glassTone) === -1) prefs.glassTone = DEFAULT_PREFS.glassTone;
            if (!isValidHexColor(prefs.accentColor)) prefs.accentColor = DEFAULT_PREFS.accentColor;
            if (!isValidHexColor(prefs.bgTintColor)) prefs.bgTintColor = DEFAULT_PREFS.bgTintColor;
            if (!isValidHexColor(prefs.teacherTagColor)) prefs.teacherTagColor = DEFAULT_PREFS.teacherTagColor;
            if (!isValidHexColor(prefs.schoolHeadColor)) prefs.schoolHeadColor = DEFAULT_PREFS.schoolHeadColor;
            return prefs;
        } catch (e) {
            return Object.assign({}, DEFAULT_PREFS);
        }
    }

    function writePrefs(prefs) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
    }

    function applyPrefsToDom(prefs) {
        document.documentElement.setAttribute('data-theme', prefs.theme || DEFAULT_PREFS.theme);
        document.documentElement.setAttribute('data-density', prefs.density || DEFAULT_PREFS.density);
        document.documentElement.setAttribute('data-layout', prefs.layout || DEFAULT_PREFS.layout);
        document.documentElement.setAttribute('data-bg-palette', prefs.bgPalette || DEFAULT_PREFS.bgPalette);
        document.documentElement.setAttribute('data-bg-effects', prefs.bgEffects || DEFAULT_PREFS.bgEffects);
        document.documentElement.setAttribute('data-glass-tone', prefs.glassTone || DEFAULT_PREFS.glassTone);
        applyAccentColor(prefs.accentColor || DEFAULT_PREFS.accentColor);
        applyBackgroundTint(prefs.bgTintColor || DEFAULT_PREFS.bgTintColor);
        applyFrostedCardTint(
            prefs.theme || DEFAULT_PREFS.theme,
            prefs.bgPalette || DEFAULT_PREFS.bgPalette,
            prefs.bgTintColor || DEFAULT_PREFS.bgTintColor
        );
        applyReadableMode(
            prefs.theme || DEFAULT_PREFS.theme,
            prefs.bgPalette || DEFAULT_PREFS.bgPalette,
            prefs.bgTintColor || DEFAULT_PREFS.bgTintColor
        );
        applyTeacherCardColors(prefs.teacherTagColor || DEFAULT_PREFS.teacherTagColor, prefs.schoolHeadColor || DEFAULT_PREFS.schoolHeadColor);
    }

    var startupPrefs = readPrefs();
    applyPrefsToDom(startupPrefs);
    writePrefs(startupPrefs);

    function syncPrefsFromExternal(nextPrefs) {
        if (!nextPrefs || typeof nextPrefs !== 'object') return;
        var currentLayout = document.documentElement.getAttribute('data-layout') || DEFAULT_PREFS.layout;
        var merged = Object.assign({}, readPrefs(), nextPrefs);
        writePrefs(merged);
        applyPrefsToDom(merged);
        if (merged.layout && merged.layout !== currentLayout) {
            if (merged.layout === 'app') {
                try { sessionStorage.setItem('tpms_tala_os_welcome', '1'); } catch (e) {}
            }
            window.location.reload();
        }
    }

    window.addEventListener('storage', function(event) {
        if (event.key !== STORAGE_KEY) return;
        syncPrefsFromExternal(readPrefs());
    });

    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) return;
        var data = event.data || {};
        if (data.type !== 'tpms-appearance-sync') return;
        syncPrefsFromExternal(data.prefs || {});
    });

    if (!quickThemeBtn) {
        return;
    }

    quickThemeBtn.addEventListener('click', function() {
        var prefs = readPrefs();
        var currentIndex = ALLOWED_THEMES.indexOf(prefs.theme);
        var nextTheme = ALLOWED_THEMES[(currentIndex + 1) % ALLOWED_THEMES.length];
        prefs.theme = nextTheme;
        if (!prefs.density) prefs.density = document.documentElement.getAttribute('data-density') || DEFAULT_PREFS.density;
        if (!prefs.layout) prefs.layout = document.documentElement.getAttribute('data-layout') || DEFAULT_PREFS.layout;
        if (!prefs.bgPalette) prefs.bgPalette = document.documentElement.getAttribute('data-bg-palette') || DEFAULT_PREFS.bgPalette;
        if (!prefs.bgEffects) prefs.bgEffects = document.documentElement.getAttribute('data-bg-effects') || DEFAULT_PREFS.bgEffects;
        if (!prefs.glassTone) prefs.glassTone = document.documentElement.getAttribute('data-glass-tone') || DEFAULT_PREFS.glassTone;
        if (!isValidHexColor(prefs.accentColor)) prefs.accentColor = DEFAULT_PREFS.accentColor;
        if (!isValidHexColor(prefs.bgTintColor)) prefs.bgTintColor = DEFAULT_PREFS.bgTintColor;
        if (!isValidHexColor(prefs.teacherTagColor)) prefs.teacherTagColor = DEFAULT_PREFS.teacherTagColor;
        if (!isValidHexColor(prefs.schoolHeadColor)) prefs.schoolHeadColor = DEFAULT_PREFS.schoolHeadColor;
        writePrefs(prefs);
        applyPrefsToDom(prefs);
    });
})();
</script>

<style>
.tpms-tour-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    z-index: 7000;
    pointer-events: none;
}
.tpms-tour-panel {
    position: fixed;
    right: 18px;
    bottom: 18px;
    width: min(380px, calc(100vw - 24px));
    border-radius: 18px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(255, 255, 255, 0.94);
    color: #0f172a;
    box-shadow: 0 20px 50px rgba(15, 23, 42, 0.28);
    padding: 16px;
    z-index: 7003;
}
.tpms-tour-panel.panel-bottom-left {
    left: 18px;
    right: auto;
}
.tpms-tour-panel.panel-top-right {
    top: 18px;
    bottom: auto;
}
.tpms-tour-panel.panel-top-left {
    top: 18px;
    bottom: auto;
    left: 18px;
    right: auto;
}
.tpms-tour-kicker {
    margin: 0 0 6px;
    color: #475569;
    font-size: .76rem;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 700;
}
.tpms-tour-title {
    margin: 0;
    font-size: 1.08rem;
    color: #0f172a;
}
.tpms-tour-text {
    margin: 10px 0 0;
    color: #334155;
    line-height: 1.55;
    white-space: pre-wrap;
}
.tpms-tour-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
}
.tpms-tour-btn {
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: #ffffff;
    color: #0f172a;
    border-radius: 10px;
    min-height: 38px;
    padding: 0 12px;
    cursor: pointer;
    font-weight: 600;
}
.tpms-tour-btn.primary {
    background: #2563eb;
    border-color: #2563eb;
    color: #ffffff;
}
.tpms-tour-target {
    position: relative;
    z-index: 7002 !important;
    outline: 3px solid rgba(37, 99, 235, 0.7);
    border-radius: 14px;
    box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.22);
    transition: outline-color .2s ease;
}
body.tpms-tour-dock-visible .app-dock {
    position: fixed;
    left: 50%;
    bottom: max(10px, calc(env(safe-area-inset-bottom) + 6px));
    transform: translateX(-50%);
    display: inline-flex !important;
    align-items: stretch;
    justify-content: center;
    gap: 8px;
    width: max-content;
    max-width: calc(100vw - 24px);
    padding: 8px;
    border-radius: 22px;
    border: 1px solid rgba(148, 163, 184, .26);
    background: rgba(15, 23, 42, .68);
    backdrop-filter: blur(16px) saturate(140%);
    -webkit-backdrop-filter: blur(16px) saturate(140%);
    box-shadow: 0 16px 30px rgba(2, 6, 23, .3);
    overflow-x: auto;
    overflow-y: hidden;
    z-index: 7002;
}
html[data-theme="ios"] body.tpms-tour-dock-visible .app-dock,
html[data-theme="pastel-sky"] body.tpms-tour-dock-visible .app-dock,
html[data-theme="pastel-sunset"] body.tpms-tour-dock-visible .app-dock {
    background: rgba(255, 255, 255, .78);
    border-color: rgba(148, 163, 184, .24);
}
@media (max-width: 720px) {
    .tpms-tour-panel {
        left: 12px;
        right: 12px;
        bottom: 12px;
        width: auto;
    }
    .tpms-tour-panel.panel-bottom-left,
    .tpms-tour-panel.panel-top-right,
    .tpms-tour-panel.panel-top-left {
        left: 12px;
        right: 12px;
        width: auto;
    }
    .tpms-tour-panel.panel-top-right,
    .tpms-tour-panel.panel-top-left {
        top: 12px;
        bottom: auto;
    }
}
</style>

<script>
(function() {
    var params = new window.URLSearchParams(window.location.search);
    if (params.get('tour') !== '1') {
        return;
    }

    var currentPage = <?= json_encode($currentPage ?? '') ?>;
    var appUrl = <?= json_encode(APP_URL) ?>;
    var tourFlow = params.get('tourFlow') || 'page';
    var voiceParam = params.get('tourVoice');
    var voiceStorageKey = 'tpmsTourVoice';
    var voiceEnabled = voiceParam === '1' || (voiceParam === null && localStorage.getItem(voiceStorageKey) === '1');
    localStorage.setItem(voiceStorageKey, voiceEnabled ? '1' : '0');

    var flowSequence = ['dashboard', 'teachers', 'schools', 'requirement_planning', 'reports', 'appearance', 'chatbot', 'updates'];
    var overlay = document.createElement('div');
    overlay.className = 'tpms-tour-overlay';

    var panel = document.createElement('div');
    panel.className = 'tpms-tour-panel';
    panel.innerHTML = ''
        + '<p class="tpms-tour-kicker">Guided Tour</p>'
        + '<h3 class="tpms-tour-title" id="tpmsTourTitle"></h3>'
        + '<p class="tpms-tour-text" id="tpmsTourText"></p>'
        + '<div class="tpms-tour-actions">'
        + '  <button type="button" class="tpms-tour-btn" id="tpmsTourVoiceBtn"></button>'
        + '  <button type="button" class="tpms-tour-btn" id="tpmsTourPrevBtn">Previous</button>'
        + '  <button type="button" class="tpms-tour-btn primary" id="tpmsTourNextBtn">Next</button>'
        + '  <button type="button" class="tpms-tour-btn" id="tpmsTourCloseBtn">Finish</button>'
        + '</div>';

    document.body.appendChild(overlay);
    document.body.appendChild(panel);

    var titleEl = document.getElementById('tpmsTourTitle');
    var textEl = document.getElementById('tpmsTourText');
    var nextBtn = document.getElementById('tpmsTourNextBtn');
    var prevBtn = document.getElementById('tpmsTourPrevBtn');
    var closeBtn = document.getElementById('tpmsTourCloseBtn');
    var voiceBtn = document.getElementById('tpmsTourVoiceBtn');
    var activeTarget = null;
    var currentStepIndex = 0;

    function setPanelPlacement(name) {
        panel.classList.remove('panel-bottom-left', 'panel-top-right', 'panel-top-left');
        if (name && name !== 'bottom-right') {
            panel.classList.add(name);
        }
    }

    function rectsOverlap(a, b) {
        return !(a.right <= b.left || a.left >= b.right || a.bottom <= b.top || a.top >= b.bottom);
    }

    function placePanelForTarget(target) {
        setPanelPlacement('bottom-right');
        if (!target) {
            return;
        }

        window.requestAnimationFrame(function() {
            var panelRect = panel.getBoundingClientRect();
            var targetRect = target.getBoundingClientRect();
            if (!rectsOverlap(panelRect, targetRect)) {
                return;
            }

            var gap = 24;
            var placements = [];
            if (window.innerHeight - targetRect.bottom >= panelRect.height + gap) {
                placements.push(targetRect.left > window.innerWidth / 2 ? 'bottom-left' : 'bottom-right');
            }
            if (targetRect.top >= panelRect.height + gap) {
                placements.push(targetRect.left > window.innerWidth / 2 ? 'top-left' : 'top-right');
            }
            if (targetRect.left >= panelRect.width + gap) {
                placements.push(targetRect.top > window.innerHeight / 2 ? 'top-left' : 'bottom-left');
            }

            setPanelPlacement(placements[0] || 'top-right');
        });
    }

    function speak(text) {
        if (!voiceEnabled || !('speechSynthesis' in window) || typeof window.SpeechSynthesisUtterance !== 'function') {
            return;
        }
        window.speechSynthesis.cancel();
        var utterance = new window.SpeechSynthesisUtterance(String(text || ''));
        utterance.rate = 1;
        utterance.pitch = 1;
        window.speechSynthesis.speak(utterance);
    }

    function syncVoiceButton() {
        voiceBtn.textContent = voiceEnabled ? 'Voice On' : 'Voice Off';
        voiceBtn.classList.toggle('primary', voiceEnabled);
    }

    function clearTarget() {
        if (activeTarget) {
            activeTarget.classList.remove('tpms-tour-target');
            activeTarget = null;
        }
        setPanelPlacement('bottom-right');
    }

    function syncStepState(step) {
        document.body.classList.toggle('tpms-tour-dock-visible', !!(step && step.forceDock));
    }

    function isElementVisible(target) {
        if (!target) {
            return false;
        }
        var styles = window.getComputedStyle(target);
        if (styles.display === 'none' || styles.visibility === 'hidden' || Number(styles.opacity || '1') === 0) {
            return false;
        }
        return !!(target.offsetWidth || target.offsetHeight || target.getClientRects().length);
    }

    function resolveStepTarget(step, allowHidden) {
        if (!step || !step.selector) {
            return null;
        }
        var matches = document.querySelectorAll(step.selector);
        var firstMatch = null;
        for (var i = 0; i < matches.length; i += 1) {
            if (!firstMatch) {
                firstMatch = matches[i];
            }
            if (isElementVisible(matches[i])) {
                return matches[i];
            }
        }
        return allowHidden ? firstMatch : null;
    }

    var commonSteps = [
        { selector: '.sidebar', title: 'Sidebar Navigation', text: 'Use the sidebar to move between the main TPMS modules like Dashboard, Teachers, Schools, Requirement Planning, Reports, Appearance, Updates, and Tala AI.' },
        { selector: '.topbar', title: 'Top Bar', text: 'The top bar shows the current page title and gives quick access to theme switching, Appearance settings, your account details, and logout.' },
        { selector: '#appDock', title: 'Quick App Dock', text: 'The app dock gives fast navigation to frequently used modules without reopening the sidebar.', allowHidden: true, forceDock: true }
    ];

    function buildTourSteps(pageSteps) {
        var shouldIncludeCommonSteps = tourFlow !== 'system' || currentPage === flowSequence[0];
        return shouldIncludeCommonSteps ? commonSteps.concat(pageSteps) : pageSteps;
    }

    var configs = {
        dashboard: {
            label: 'Dashboard Tour',
            steps: buildTourSteps([
                { selector: '.stats-grid', title: 'Summary Cards', text: 'These cards give you the fast system overview for teachers, schools, districts, and other key counts.' },
                { selector: '.dashboard-hero', title: 'Workforce Snapshot', text: 'This section highlights the school-type snapshot and high-level staffing overview.' },
                { selector: '.charts-grid', title: 'Charts And Distribution', text: 'Scroll through charts to understand district distribution, age, positions, and school coverage.' },
                { selector: '.coverage-card', title: 'Coverage Insights', text: 'This area focuses on school coverage and schools with or without staffing support.' },
                { selector: '#customizeToggle', title: 'Customize Dashboard', text: 'Use this button to adjust dashboard layout and card arrangement.' }
            ])
        },
        teachers: {
            label: 'Teachers Tour',
            steps: buildTourSteps([
                { selector: '.teachers-actionbar', title: 'Teacher Filters', text: 'Start here to search teachers by name, employee number, district, position, specialization, gender, or school.' },
                { selector: '.teachers-action-controls, .filter-bar.glass-card[style*="margin-top:10px"], .filter-bar.glass-card', title: 'Teacher Actions', text: 'This area holds page-level actions such as import, undo, view switching, or additional management controls when available.' },
                { selector: '.data-table, .teacher-grid, .teacher-card', title: 'Teacher Records', text: 'Review matching teacher records here. Open a teacher to inspect profile details and assignments.' },
                { selector: '.teachers-add-btn, a[href*="add_teacher.php"]', title: 'Add Or Update Teachers', text: 'If your role allows editing, use the add and edit actions to maintain teacher records.' }
            ])
        },
        schools: {
            label: 'Schools Tour',
            steps: buildTourSteps([
                { selector: '.filter-bar', title: 'School Search', text: 'Use the search box and filters to find schools by name, district, staffing, or school type.' },
                { selector: '.charts-grid', title: 'School Type Cards', text: 'These cards summarize totals for Elementary, JHS, SHS, ALS, and other school groupings.' },
                { selector: '.schools-district-panel, a[href*="teachers.php?school"]', title: 'District School Context', text: 'When filtered by district, this section helps you jump from schools to the related teachers assigned there.' },
                { selector: '#schoolsListView, #schoolsCardView', title: 'School Listings', text: 'Open a school entry to review school head assignment, teacher counts, and learner counts.' },
                { selector: '.filter-actions .btn-primary, .btn.btn-primary', title: 'Add School', text: 'Use add and update actions here to manage school details when you have permission.' }
            ])
        },
        requirement_planning: {
            label: 'Requirement Planning Tour',
            steps: buildTourSteps([
                { selector: '.filter-bar, .planning-filter, .planning-controls', title: 'Planning Filters', text: 'Use these controls to choose school context and planning assumptions before generating outputs.' },
                { selector: '.stats-grid, .planning-summary, .planning-kpis', title: 'Planning Summary', text: 'This section highlights key staffing metrics such as required teachers, available teachers, and computed shortage or surplus.' },
                { selector: '.data-table, .table-card, .planning-table', title: 'School Planning Table', text: 'Review the school-level planning breakdown, including criteria-based computations and recommendation status.' },
                { selector: '.chart-card, canvas, .planning-chart', title: 'Planning Visuals', text: 'Use charts to quickly spot overloaded, balanced, and understaffed schools for prioritization.' }
            ])
        },
        reports: {
            label: 'Reports Tour',
            steps: buildTourSteps([
                { selector: '#reportFilter', title: 'Report Filters', text: 'Refine reports by district, school, position, gender, or specialization before exporting.' },
                { selector: '.filter-actions', title: 'Export Actions', text: 'Use these buttons to export the current filtered report to CSV or Excel.' },
                { selector: '.stats-grid', title: 'Report Summary Cards', text: 'These cards show filtered totals and quick breakdowns for your current report selection.' },
                { selector: '#reportTable', title: 'Report Table', text: 'This table contains the detailed report rows for the active filters.' }
            ])
        },
        appearance: {
            label: 'Appearance Tour',
            steps: buildTourSteps([
                { selector: '.appearance-hero', title: 'Appearance Overview', text: 'This page controls the visual look of TPMS across all pages.' },
                { selector: '#themeOptions', title: 'Theme Options', text: 'Choose the interface theme style that best fits your preference.' },
                { selector: '#layoutDensity', title: 'Layout Density', text: 'Change content spacing between Comfortable and Compact depending on how much information you want to see.' },
                { selector: '#layoutMode', title: 'App View', text: 'Switch between the classic layout and app-style framed workspace.' },
                { selector: '#backgroundPalette', title: 'Background Controls', text: 'Switch between theme background or custom color background for the whole app.' },
                { selector: '#glassTone', title: 'Readability Controls', text: 'Adjust glass readability strength when you need stronger contrast.' },
                { selector: '#teacherCardColorSection', title: 'Teacher Card Colors', text: 'Adjust teacher tag and school head highlight colors for better visual emphasis.' }
            ])
        },
        chatbot: {
            label: 'Tala AI Tour',
            steps: buildTourSteps([
                { selector: '.jarvis-head', title: 'Assistant Controls', text: 'Use Start Tour to launch the guided system walkthrough and Voice Guide to enable spoken help.' },
                { selector: '.jarvis-thread', title: 'Conversation Area', text: 'The assistant answers teacher, school, report, settings, and navigation requests here.' },
                { selector: '#chatForm', title: 'Ask A Question', text: 'Type commands like show school type counts, open reports, or how do I use TPMS.' }
            ])
        },
        updates: {
            label: 'Updates Tour',
            steps: buildTourSteps([
                { selector: '.updates-hero', title: 'Version Overview', text: 'This page shows the current TPMS version and summarizes the latest implemented updates.' },
                { selector: '.guide-grid', title: 'How To Use TPMS', text: 'This section gives a quick written guide for the main workflow across Dashboard, Teachers, Schools, Reports, Appearance, and Tala AI.' },
                { selector: '.updates-card + .updates-card, .updates-shell', title: 'Change History', text: 'Use this page to review the latest technical changes, features, fixes, and prompt-driven updates in the system.' }
            ])
        }
    };

    var config = configs[currentPage];
    if (!config || !Array.isArray(config.steps) || !config.steps.length) {
        panel.remove();
        overlay.remove();
        return;
    }

    function availableStep(startIndex, direction) {
        var index = startIndex;
        while (index >= 0 && index < config.steps.length) {
            var step = config.steps[index];
            var target = resolveStepTarget(step, !!step.allowHidden);
            if (target) {
                return { index: index, step: step, target: target };
            }
            index += direction;
        }
        return null;
    }

    function finishTour() {
        clearTarget();
        syncStepState(null);
        if ('speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
        panel.remove();
        overlay.remove();
        var cleanUrl = new window.URL(window.location.href);
        cleanUrl.searchParams.delete('tour');
        cleanUrl.searchParams.delete('tourFlow');
        cleanUrl.searchParams.delete('tourVoice');
        window.history.replaceState({}, document.title, cleanUrl.toString());
    }

    function goToNextPage() {
        if (tourFlow !== 'system') {
            finishTour();
            return;
        }

        var currentIndex = flowSequence.indexOf(currentPage);
        if (currentIndex === -1 || currentIndex === flowSequence.length - 1) {
            finishTour();
            return;
        }

        var nextPage = flowSequence[currentIndex + 1];
        window.location.href = appUrl + '/' + nextPage + '.php?tour=1&tourFlow=system&tourVoice=' + (voiceEnabled ? '1' : '0');
    }

    function setStep(index) {
        var found = availableStep(index, index >= currentStepIndex ? 1 : -1);
        if (!found) {
            if (index >= config.steps.length - 1) {
                goToNextPage();
            }
            return;
        }

        currentStepIndex = found.index;
        clearTarget();
        syncStepState(found.step);
        activeTarget = resolveStepTarget(found.step, !!found.step.allowHidden) || found.target;
        activeTarget.classList.add('tpms-tour-target');
        activeTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        placePanelForTarget(activeTarget);

        titleEl.textContent = config.label + ' - Step ' + (currentStepIndex + 1) + ' of ' + config.steps.length + ': ' + found.step.title;
        textEl.textContent = found.step.text;
        prevBtn.disabled = currentStepIndex === 0;
        nextBtn.textContent = currentStepIndex === config.steps.length - 1
            ? (tourFlow === 'system' ? 'Next Page' : 'Finish')
            : 'Next';

        if (voiceEnabled) {
            speak(found.step.title + '. ' + found.step.text);
        }
    }

    prevBtn.addEventListener('click', function() {
        if (currentStepIndex <= 0) {
            return;
        }
        setStep(currentStepIndex - 1);
    });

    nextBtn.addEventListener('click', function() {
        if (currentStepIndex >= config.steps.length - 1) {
            goToNextPage();
            return;
        }
        setStep(currentStepIndex + 1);
    });

    closeBtn.addEventListener('click', finishTour);
    voiceBtn.addEventListener('click', function() {
        voiceEnabled = !voiceEnabled;
        localStorage.setItem(voiceStorageKey, voiceEnabled ? '1' : '0');
        syncVoiceButton();
        if (!voiceEnabled && 'speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        } else if (voiceEnabled) {
            speak(titleEl.textContent + '. ' + textEl.textContent);
        }
    });

    syncVoiceButton();
    setStep(0);
    window.addEventListener('resize', function() {
        if (activeTarget) {
            placePanelForTarget(activeTarget);
        }
    });
})();
</script>

<style>
.tala-bubble-overlay {
    position: fixed;
    inset: 0;
    background:
        radial-gradient(circle at 18% 16%, rgba(34, 211, 238, .24), transparent 36%),
        radial-gradient(circle at 82% 22%, rgba(20, 184, 166, .22), transparent 34%),
        radial-gradient(circle at 50% 68%, rgba(59, 130, 246, .16), transparent 44%),
        linear-gradient(165deg, rgba(2, 6, 23, .42), rgba(2, 6, 23, .84));
    z-index: 7600;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity .24s ease, visibility .24s ease;
}
.tala-bubble-overlay.active {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
}
.tala-bubble-panel {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%) scale(.96);
    width: min(500px, calc(100vw - 24px));
    border-radius: 30px;
    border: 1px solid rgba(125, 211, 252, .22);
    background:
        radial-gradient(circle at 12% 10%, rgba(125, 211, 252, .24), transparent 34%),
        radial-gradient(circle at 86% 16%, rgba(45, 212, 191, .18), transparent 32%),
        radial-gradient(circle at 50% 120%, rgba(56, 189, 248, .2), transparent 48%),
        linear-gradient(162deg, rgba(15, 23, 42, .86), rgba(15, 23, 42, .72));
    backdrop-filter: blur(18px) saturate(150%);
    -webkit-backdrop-filter: blur(18px) saturate(150%);
    box-shadow: 0 28px 66px rgba(2, 6, 23, .42), inset 0 1px 0 rgba(255,255,255,.18);
    color: #e2e8f0;
    z-index: 7601;
    overflow: hidden;
    opacity: 0;
    visibility: hidden;
    transition: transform .22s ease, opacity .2s ease, visibility .2s ease;
}
.tala-bubble-panel::before {
    content: '';
    position: absolute;
    inset: 0;
    pointer-events: none;
    background:
        linear-gradient(145deg, rgba(255,255,255,.17), rgba(255,255,255,.04) 32%, rgba(255,255,255,0) 58%),
        repeating-linear-gradient(125deg, rgba(148,163,184,.05) 0 2px, transparent 2px 26px);
    opacity: .52;
}
.tala-bubble-panel.active {
    transform: translate(-50%, -50%) scale(1);
    opacity: 1;
    visibility: visible;
}
.tala-water-core {
    width: 138px;
    height: 138px;
    margin: 16px auto 10px;
    border-radius: 50%;
    position: relative;
    background:
        radial-gradient(circle at 30% 28%, rgba(255,255,255,.72), rgba(255,255,255,.1) 34%, rgba(125, 211, 252, .48) 72%, rgba(20, 184, 166, .24));
    box-shadow:
        0 18px 38px rgba(8, 47, 73, .46),
        inset 0 -14px 26px rgba(14, 116, 144, .38),
        inset 0 12px 20px rgba(255,255,255,.24);
    overflow: hidden;
    animation: talaWaterFloat 4.2s ease-in-out infinite;
}
.tala-water-logo {
    position: absolute;
    left: 50%;
    top: 50%;
    width: 58%;
    height: 58%;
    transform: translate(-50%, -50%);
    object-fit: contain;
    filter: drop-shadow(0 8px 14px rgba(2, 6, 23, .34));
}
.tala-water-core::before,
.tala-water-core::after {
    content: '';
    position: absolute;
    border-radius: 50%;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    border: 2px solid rgba(125, 211, 252, .46);
    animation: talaRipple 3.2s ease-out infinite;
}
.tala-context-badge {
    border: 1px solid rgba(125, 211, 252, .35);
    border-radius: 999px;
    padding: 6px 10px;
    margin: 0 0 8px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: .72rem;
    color: #dbeafe;
    background: linear-gradient(145deg, rgba(14, 116, 144, .25), rgba(30, 64, 175, .22));
}
.tala-context-dot {
    width: 7px;
    height: 7px;
    border-radius: 999px;
    background: #38bdf8;
    box-shadow: 0 0 0 4px rgba(56, 189, 248, .18);
}
.tala-water-core::before {
    width: 70%;
    height: 70%;
}
.tala-water-core::after {
    width: 86%;
    height: 86%;
    animation-delay: 1.1s;
}
@keyframes talaRipple {
    0% {
        opacity: .7;
        transform: translate(-50%, -50%) scale(.72);
    }
    100% {
        opacity: 0;
        transform: translate(-50%, -50%) scale(1.34);
    }
}
@keyframes talaWaterFloat {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-5px);
    }
}
.tala-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 14px 14px;
    border-bottom: 1px solid rgba(125, 211, 252, .16);
}
.tala-title {
    margin: 0;
    font-size: 1.02rem;
    letter-spacing: .02em;
    color: #f8fafc;
}
.tala-sub {
    margin: 2px 0 0;
    color: #9bdaf0;
    font-size: .76rem;
    text-transform: uppercase;
    letter-spacing: .12em;
}
.tala-close {
    border: 1px solid rgba(148,163,184,.28);
    background: rgba(15,23,42,.68);
    color: #e2e8f0;
    border-radius: 9px;
    min-width: 32px;
    min-height: 32px;
    transition: transform .18s ease, border-color .18s ease, background-color .18s ease;
}
.tala-close:hover {
    transform: translateY(-1px);
    border-color: rgba(125, 211, 252, .42);
    background: rgba(30, 41, 59, .86);
}
.tala-head-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.tala-mini-btn {
    border: 1px solid rgba(148,163,184,.28);
    background: rgba(30,41,59,.56);
    color: #e2e8f0;
    border-radius: 999px;
    min-height: 32px;
    padding: 0 11px;
    font-size: .74rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
    transition: transform .18s ease, border-color .18s ease, background-color .18s ease;
}
.tala-mini-btn:hover {
    transform: translateY(-1px);
    border-color: rgba(125, 211, 252, .45);
    background: rgba(51, 65, 85, .72);
}
.tala-mini-btn.active {
    background: rgba(34,197,94,.22);
    border-color: rgba(34,197,94,.58);
    color: #dcfce7;
}
.tala-mini-btn.listening {
    background: rgba(239,68,68,.2);
    border-color: rgba(239,68,68,.52);
    color: #fee2e2;
}
.tala-thread {
    max-height: min(56vh, 460px);
    overflow: auto;
    display: grid;
    gap: 8px;
    padding: 14px;
    background:
        radial-gradient(circle at 18% 8%, rgba(56, 189, 248, .1), transparent 34%),
        radial-gradient(circle at 90% 70%, rgba(20, 184, 166, .08), transparent 32%);
}
.tala-thread::-webkit-scrollbar {
    width: 8px;
}
.tala-thread::-webkit-scrollbar-thumb {
    background: rgba(148, 163, 184, .32);
    border-radius: 999px;
}
.tala-row { display: grid; }
.tala-row.user { justify-items: end; }
.tala-row.assistant { justify-items: start; }
.tala-msg {
    max-width: 90%;
    border-radius: 14px;
    border: 1px solid rgba(125, 211, 252, .24);
    padding: 10px 12px;
    font-size: .92rem;
    line-height: 1.5;
    white-space: pre-wrap;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.14), 0 8px 18px rgba(2, 6, 23, .16);
}
.tala-row.user .tala-msg {
    background:
        radial-gradient(circle at 22% 18%, rgba(186, 230, 253, .22), transparent 34%),
        linear-gradient(140deg, rgba(14,165,233,.28), rgba(6,182,212,.14));
    color: #e6f7ff;
    border-color: rgba(56, 189, 248, .36);
}
.tala-row.assistant .tala-msg {
    background:
        radial-gradient(circle at 12% 12%, rgba(255,255,255,.2), transparent 38%),
        linear-gradient(144deg, rgba(148, 163, 184, .14), rgba(148, 163, 184, .08));
    color: #e5e7eb;
}
.tala-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 6px;
    padding: 10px 12px 14px;
    border-top: 1px solid rgba(125, 211, 252, .14);
}
.tala-input {
    min-height: 44px;
    border-radius: 999px;
    border: 1px solid rgba(125, 211, 252, .28);
    background:
        radial-gradient(circle at 18% 20%, rgba(186, 230, 253, .18), transparent 34%),
        linear-gradient(160deg, rgba(255,255,255,.12), rgba(255,255,255,.04));
    color: #f8fafc;
    padding: 0 14px;
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
}
.tala-input:focus {
    outline: none;
    border-color: rgba(56, 189, 248, .58);
    box-shadow: 0 0 0 3px rgba(56, 189, 248, .16);
    background:
        radial-gradient(circle at 18% 20%, rgba(186, 230, 253, .2), transparent 34%),
        linear-gradient(160deg, rgba(255,255,255,.18), rgba(255,255,255,.08));
}
.tala-input::placeholder { color: #94a3b8; }
.tala-form-hint {
    color: #95adc4;
    font-size: .72rem;
    line-height: 1.35;
    padding-left: 6px;
}
.tala-results-wrap {
    border: 1px solid rgba(125, 211, 252, .2);
    border-radius: 14px;
    padding: 10px;
    background: linear-gradient(145deg, rgba(15,23,42,.52), rgba(15,23,42,.34));
}
.tala-results-title {
    margin: 0 0 8px;
    color: #bae6fd;
    font-size: .76rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-weight: 700;
}
.tala-results-list {
    display: grid;
    gap: 7px;
}
.tala-results-group {
    margin-top: 10px;
    border-top: 1px dashed rgba(148, 163, 184, .24);
    padding-top: 8px;
}
.tala-results-group:first-child {
    margin-top: 0;
    border-top: 0;
    padding-top: 0;
}
.tala-group-title {
    margin: 0 0 6px;
    color: #bae6fd;
    font-size: .72rem;
    letter-spacing: .08em;
    text-transform: uppercase;
    font-weight: 700;
}
.tala-result-item {
    display: block;
    border: 1px solid rgba(148, 163, 184, .18);
    border-radius: 10px;
    padding: 8px 9px;
    background: rgba(15, 23, 42, .3);
    text-decoration: none;
    transition: transform .16s ease, border-color .16s ease, background-color .16s ease;
}
.tala-result-item:hover {
    transform: translateY(-1px);
    border-color: rgba(125, 211, 252, .4);
    background: rgba(30, 41, 59, .42);
}
.tala-result-main {
    color: #e2e8f0;
    font-size: .86rem;
    font-weight: 600;
    line-height: 1.35;
}
.tala-result-sub {
    margin-top: 2px;
    color: #94a3b8;
    font-size: .77rem;
    line-height: 1.35;
}
@media (max-width: 720px) {
    .tala-bubble-panel {
        width: calc(100vw - 16px);
        max-height: calc(100vh - 24px);
    }
    .tala-water-core {
        width: 108px;
        height: 108px;
    }
    .tala-title {
        font-size: .94rem;
    }
    .tala-mini-btn {
        min-height: 30px;
        padding: 0 9px;
    }
}
</style>

<div class="tala-bubble-overlay" id="talaBubbleOverlay" aria-hidden="true"></div>

<section class="tala-bubble-panel" id="talaBubblePanel" aria-hidden="true">
    <div class="tala-water-core" aria-hidden="true">
        <img src="<?= APP_URL ?>/assets/images/logo.png" alt="TPMS" class="tala-water-logo">
    </div>
    <div class="tala-head">
        <div>
            <h3 class="tala-title">Tala Assistant Interaction</h3>
            <p class="tala-sub">Data-only assistant</p>
        </div>
        <div class="tala-head-actions">
            <button type="button" class="tala-mini-btn" id="talaTourBtn">Tour</button>
            <button type="button" class="tala-mini-btn" id="talaSpeechBtn">Voice Off</button>
            <button type="button" class="tala-mini-btn" id="talaVoiceBtn">Mic</button>
            <button type="button" class="tala-close" id="talaBubbleClose" aria-label="Close">×</button>
        </div>
    </div>
    <div class="tala-thread" id="talaBubbleThread"></div>
    <form class="tala-form" id="talaBubbleForm">
        <input type="text" id="talaBubbleInput" class="tala-input" maxlength="900" placeholder="Ask data only: school counts, teacher filters, export..." required>
        <div class="tala-form-hint">Press Enter to send, or use Mic and stop speaking to auto-send.</div>
    </form>
</section>

<script>
(function() {
    var overlay = document.getElementById('talaBubbleOverlay');
    var panel = document.getElementById('talaBubblePanel');
    var closeBtn = document.getElementById('talaBubbleClose');
    var tourBtn = document.getElementById('talaTourBtn');
    var speechBtn = document.getElementById('talaSpeechBtn');
    var voiceBtn = document.getElementById('talaVoiceBtn');
    var form = document.getElementById('talaBubbleForm');
    var input = document.getElementById('talaBubbleInput');
    var thread = document.getElementById('talaBubbleThread');
    var appUrl = <?= json_encode(APP_URL) ?>;
    var csrf = <?= json_encode(csrfToken()) ?>;
    var TOUR_VOICE_KEY = 'tpmsTourVoice';
    var speechEnabled = localStorage.getItem(TOUR_VOICE_KEY) === '1';
    var recognition = null;
    var listening = false;
    var pendingVoiceText = '';
    var hasSpeechRecognition = typeof window.SpeechRecognition === 'function' || typeof window.webkitSpeechRecognition === 'function';

    if (!overlay || !panel || !form || !input || !thread || !closeBtn || !tourBtn || !speechBtn || !voiceBtn) {
        return;
    }

    function setOpen(open) {
        overlay.classList.toggle('active', !!open);
        overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
        panel.classList.toggle('active', !!open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        if (open) {
            input.focus();
        }
    }

    window.tpmsTalaApp = {
        open: function() { setOpen(true); },
        close: function() { setOpen(false); },
        isOpen: function() { return panel.classList.contains('active'); },
        focusInput: function() {
            if (panel.classList.contains('active')) {
                input.focus();
            }
        }
    };

    function push(role, text) {
        var row = document.createElement('div');
        row.className = 'tala-row ' + role;
        var msg = document.createElement('div');
        msg.className = 'tala-msg';
        msg.textContent = text;
        row.appendChild(msg);
        thread.appendChild(row);
        thread.scrollTop = thread.scrollHeight;
    }

    function detectResultType(items, explicitType) {
        if (explicitType === 'schools' || explicitType === 'teachers' || explicitType === 'districts' || explicitType === 'users' || explicitType === 'planning' || explicitType === 'forecast') {
            return explicitType;
        }
        if (!Array.isArray(items) || !items.length || !items[0] || typeof items[0] !== 'object') {
            return 'teachers';
        }
        if (Object.prototype.hasOwnProperty.call(items[0], 'district_name')) {
            return 'districts';
        }
        if (Object.prototype.hasOwnProperty.call(items[0], 'username') && Object.prototype.hasOwnProperty.call(items[0], 'role')) {
            return 'users';
        }
        if (Object.prototype.hasOwnProperty.call(items[0], 'teacher_count') || Object.prototype.hasOwnProperty.call(items[0], 'learner_count')) {
            return 'schools';
        }
        return 'teachers';
    }

    function renderResultItem(item, type) {
        var card = document.createElement('a');
        card.className = 'tala-result-item';
        card.target = '_self';

        var main = document.createElement('div');
        main.className = 'tala-result-main';

        var sub = document.createElement('div');
        sub.className = 'tala-result-sub';

        if (type === 'schools') {
            main.textContent = String(item.school_name || 'Unknown school');
            sub.textContent = 'Type: ' + String(item.school_type || 'Untagged')
                + ' | District: ' + String(item.district || '-')
                + ' | Teachers: ' + String(item.teacher_count != null ? item.teacher_count : '-')
                + ' | Learners: ' + String(item.learner_count != null ? item.learner_count : '-');
            card.href = appUrl + '/schools.php?q=' + encodeURIComponent(String(item.school_name || ''));
        } else if (type === 'districts') {
            main.textContent = String(item.district_name || 'Unknown district');
            sub.textContent = 'Schools: ' + String(item.school_count != null ? item.school_count : '-')
                + ' | Teachers: ' + String(item.teacher_count != null ? item.teacher_count : '-');
            card.href = appUrl + '/districts.php';
        } else if (type === 'users') {
            main.textContent = String(item.full_name || item.username || 'Unknown user');
            sub.textContent = 'Username: ' + String(item.username || '-')
                + ' | Role: ' + String(item.role || '-')
                + ' | Status: ' + (String(item.is_active) === '1' ? 'Active' : 'Inactive');
            card.href = appUrl + '/users.php?q=' + encodeURIComponent(String(item.username || ''));
        } else if (type === 'forecast') {
            main.textContent = String(item.school_name || 'School forecast result');
            sub.textContent = 'District: ' + String(item.district || '-')
                + ' | Growth: +' + String(item.growth_pct != null ? item.growth_pct : '-') + '%'
                + ' | Projected students: ' + String(item.projected_students != null ? item.projected_students : '-')
                + ' | Current teachers: ' + String(item.current_teachers != null ? item.current_teachers : '-')
                + ' | Required teachers: ' + String(item.recommended_teachers != null ? item.recommended_teachers : '-')
                + ' | Additional needed: ' + String(item.additional_teachers_needed != null ? item.additional_teachers_needed : '0');
            card.href = appUrl + '/schools.php?q=' + encodeURIComponent(String(item.school_name || ''));
        } else if (type === 'planning') {
            main.textContent = String(item.school_name || 'School planning result');
            sub.textContent = 'District: ' + String(item.district || '-')
                + ' | Students: ' + String(item.total_students != null ? item.total_students : '-')
                + ' | Teachers: ' + String(item.total_teachers != null ? item.total_teachers : '-')
                + ' | Recommended: ' + String(item.recommended_teachers != null ? item.recommended_teachers : '-')
                + ' | Shortage: ' + String(item.teacher_shortage != null ? item.teacher_shortage : '0')
                + ' | Surplus: ' + String(item.teacher_surplus != null ? item.teacher_surplus : '0');
            card.href = appUrl + '/schools.php?q=' + encodeURIComponent(String(item.school_name || ''));
        } else if (type === 'teachers') {
            var fullName = (String(item.last_name || '').trim() + ', ' + String(item.first_name || '').trim()).replace(/^,\s*/, '').trim();
            main.textContent = fullName || 'Unnamed teacher';
            sub.textContent = 'Emp#: ' + String(item.employee_number || '-')
                + ' | Position: ' + String(item.position || '-')
                + ' | School: ' + String(item.school_name || '-')
                + ' | District: ' + String(item.district || '-')
                + ' | Gender: ' + String(item.gender || '-');

            if (item.employee_number) {
                card.href = appUrl + '/teachers.php?q=' + encodeURIComponent(String(item.employee_number));
            } else if (fullName) {
                card.href = appUrl + '/teachers.php?q=' + encodeURIComponent(fullName);
            } else {
                card.href = appUrl + '/teachers.php';
            }
        } else {
            if (Object.prototype.hasOwnProperty.call(item, 'table') && Object.prototype.hasOwnProperty.call(item, 'rows')) {
                main.textContent = String(item.table || 'Table');
                sub.textContent = 'Rows: ' + String(item.rows != null ? item.rows : '-');
            } else {
                var keys = Object.keys(item || {});
                main.textContent = String(item.name || item.title || item.label || item.username || 'Record');
                sub.textContent = keys.slice(0, 4).map(function(k) {
                    return k + ': ' + String(item[k]);
                }).join(' | ');
            }
            card.href = '#';
        }

        card.appendChild(main);
        card.appendChild(sub);
        return card;
    }

    function pushEntityGroup(container, entityType, items, summary) {
        if (!Array.isArray(items) || !items.length) return;

        var group = document.createElement('div');
        group.className = 'tala-results-group';

        var groupTitle = document.createElement('p');
        groupTitle.className = 'tala-group-title';

        var labelMap = {
            teachers: 'Teachers',
            schools: 'Schools',
            districts: 'Districts',
            users: 'Users'
        };
        var label = labelMap[entityType] || 'Results';

        var shownKey = 'shown_' + entityType;
        var totalKey = entityType + '_total';
        if (summary && typeof summary[shownKey] === 'number' && typeof summary[totalKey] === 'number') {
            groupTitle.textContent = label + ' - Showing ' + summary[shownKey] + ' of ' + summary[totalKey];
        } else {
            groupTitle.textContent = label + ' - ' + items.length + ' shown';
        }
        group.appendChild(groupTitle);

        var list = document.createElement('div');
        list.className = 'tala-results-list';
        items.forEach(function(item) {
            list.appendChild(renderResultItem(item, entityType));
        });
        group.appendChild(list);
        container.appendChild(group);
    }

    function pushActiveContext(context) {
        if (!context || typeof context !== 'object') {
            return;
        }

        var label = String(context.label || '').trim();
        if (!label) {
            return;
        }

        var badge = document.createElement('div');
        badge.className = 'tala-context-badge';

        var dot = document.createElement('span');
        dot.className = 'tala-context-dot';
        badge.appendChild(dot);

        var text = document.createElement('span');
        text.textContent = 'Active context: ' + label;
        badge.appendChild(text);

        thread.appendChild(badge);
        thread.scrollTop = thread.scrollHeight;
    }

    function pushResults(items, explicitType, summary) {
        if (explicitType === 'universal' && items && typeof items === 'object' && !Array.isArray(items)) {
            var wrapUniversal = document.createElement('div');
            wrapUniversal.className = 'tala-results-wrap';

            var titleUniversal = document.createElement('p');
            titleUniversal.className = 'tala-results-title';
            titleUniversal.textContent = 'Universal Search Results';
            wrapUniversal.appendChild(titleUniversal);

            ['teachers', 'schools', 'districts', 'users'].forEach(function(groupType) {
                pushEntityGroup(wrapUniversal, groupType, items[groupType], summary || null);
            });

            thread.appendChild(wrapUniversal);
            thread.scrollTop = thread.scrollHeight;
            return;
        }

        if (!Array.isArray(items) || !items.length) {
            return;
        }

        var resultType = detectResultType(items, explicitType);
        var wrap = document.createElement('div');
        wrap.className = 'tala-results-wrap';

        var title = document.createElement('p');
        title.className = 'tala-results-title';
        if (summary && typeof summary.shown === 'number' && typeof summary.total === 'number') {
            var resultLabel = resultType === 'schools' ? 'Schools' : (resultType === 'districts' ? 'Districts' : (resultType === 'planning' ? 'Planning Analysis' : (resultType === 'forecast' ? 'Forecast Analysis' : 'Teachers')));
            title.textContent = resultLabel + ' - Showing ' + summary.shown + ' of ' + summary.total;
        } else {
            title.textContent = resultType === 'schools' ? 'Schools' : (resultType === 'districts' ? 'Districts' : (resultType === 'planning' ? 'Planning Analysis' : (resultType === 'forecast' ? 'Forecast Analysis' : 'Teachers')));
        }
        wrap.appendChild(title);

        var list = document.createElement('div');
        list.className = 'tala-results-list';

        items.forEach(function(item) {
            list.appendChild(renderResultItem(item, resultType));
        });

        wrap.appendChild(list);
        thread.appendChild(wrap);
        thread.scrollTop = thread.scrollHeight;
    }

    function loading(on) {
        input.disabled = !!on;
        if (!on) {
            input.focus();
        }
    }

    function speakAssistant(text) {
        if (!speechEnabled || !text || !('speechSynthesis' in window) || typeof window.SpeechSynthesisUtterance !== 'function') {
            return;
        }
        window.speechSynthesis.cancel();
        var utterance = new window.SpeechSynthesisUtterance(String(text));
        utterance.rate = 1;
        utterance.pitch = 1;
        window.speechSynthesis.speak(utterance);
    }

    function syncSpeechButton() {
        speechBtn.classList.toggle('active', speechEnabled);
        speechBtn.textContent = speechEnabled ? 'Voice On' : 'Voice Off';
    }

    function syncVoiceButton() {
        voiceBtn.classList.toggle('listening', listening);
        voiceBtn.textContent = listening ? 'Listening...' : 'Mic';
        voiceBtn.disabled = !hasSpeechRecognition;
        if (!hasSpeechRecognition) {
            voiceBtn.textContent = 'No Mic';
        }
    }

    async function submitMessage(message) {
        var text = String(message || '').trim();
        if (!text) return;
        push('user', text);
        input.value = '';
        loading(true);
        try {
            var data = await query(text);
            var reply = String(data.reply || 'No response available.');
            push('assistant', reply);
            speakAssistant(reply);
            var hasArrayResults = Array.isArray(data.results) && data.results.length;
            var hasUniversalResults = data.result_type === 'universal' && data.results && typeof data.results === 'object';
            if (hasArrayResults || hasUniversalResults) {
                pushActiveContext(data.active_context || null);
                pushResults(data.results, data.result_type, data.summary || null);
                var dataMsg = 'Showing returned records in data-only mode.';
                speakAssistant(dataMsg);
            }
            if (data.download && data.download.label) {
                if (!hasArrayResults && !hasUniversalResults) {
                    pushActiveContext(data.active_context || null);
                }
                var dlMsg = String(data.download.label);
                push('assistant', dlMsg);
                speakAssistant(dlMsg);
            }
        } catch (err) {
            var errMsg = 'Request failed. Please try again.';
            push('assistant', errMsg);
            speakAssistant(errMsg);
        } finally {
            loading(false);
        }
    }

    function stopVoiceCapture() {
        if (recognition) {
            recognition.stop();
        }
    }

    function startVoiceCapture() {
        if (!hasSpeechRecognition) {
            push('assistant', 'Voice input is not supported by this browser.');
            return;
        }
        if (listening) {
            stopVoiceCapture();
            return;
        }

        var recCtor = window.SpeechRecognition || window.webkitSpeechRecognition;
        recognition = new recCtor();
        pendingVoiceText = '';
        recognition.lang = 'en-US';
        recognition.continuous = false;
        recognition.interimResults = true;

        recognition.onstart = function() {
            listening = true;
            syncVoiceButton();
            input.placeholder = 'Listening... stop speaking to auto-send';
        };

        recognition.onresult = function(event) {
            var interim = '';
            for (var i = event.resultIndex; i < event.results.length; i += 1) {
                var transcript = String(event.results[i][0].transcript || '').trim();
                if (!transcript) continue;
                if (event.results[i].isFinal) {
                    pendingVoiceText += (pendingVoiceText ? ' ' : '') + transcript;
                } else {
                    interim += (interim ? ' ' : '') + transcript;
                }
            }
            input.value = (pendingVoiceText + (interim ? ' ' + interim : '')).trim();
        };

        recognition.onerror = function() {
            listening = false;
            syncVoiceButton();
            input.placeholder = 'Ask data only: school counts, teacher filters, export...';
        };

        recognition.onend = function() {
            listening = false;
            syncVoiceButton();
            input.placeholder = 'Ask data only: school counts, teacher filters, export...';
            var finalText = (pendingVoiceText || input.value || '').trim();
            if (finalText) {
                submitMessage(finalText);
            }
            pendingVoiceText = '';
        };

        recognition.start();
    }

    async function query(message) {
        var res = await fetch(<?= json_encode(APP_URL . '/actions/chatbot_query.php') ?>, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: JSON.stringify({ csrf_token: csrf, message: message })
        });
        var data = null;
        try {
            data = await res.json();
        } catch (e) {
            data = null;
        }
        if (!res.ok || !data) {
            throw new Error('Unable to process request.');
        }
        return data;
    }

    overlay.addEventListener('click', function() { setOpen(false); });
    closeBtn.addEventListener('click', function() { setOpen(false); });
    tourBtn.addEventListener('click', function() {
        window.location.href = appUrl + '/dashboard.php?tour=1&tourFlow=system&tourVoice=' + (speechEnabled ? '1' : '0');
    });
    speechBtn.addEventListener('click', function() {
        speechEnabled = !speechEnabled;
        localStorage.setItem(TOUR_VOICE_KEY, speechEnabled ? '1' : '0');
        syncSpeechButton();
        if (!speechEnabled && 'speechSynthesis' in window) {
            window.speechSynthesis.cancel();
        }
    });
    voiceBtn.addEventListener('click', startVoiceCapture);
    var talaParams = new window.URLSearchParams(window.location.search);
    if (talaParams.get('open_tala') === '1') {
        setOpen(true);
    }
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && panel.classList.contains('active')) {
            setOpen(false);
        }
    });

    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        var message = (input.value || '').trim();
        submitMessage(message);
    });

    // TEMPORARILY DISABLED: Intercept only explicit Tala AI links and open bubble instead of full page.
    /*
    document.querySelectorAll('a[data-tala-link="1"]').forEach(function(a) {
        a.addEventListener('click', function(e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }
            e.preventDefault();
            setOpen(true);
        });
    });
    */

    syncSpeechButton();
    syncVoiceButton();
    push('assistant', 'Hello. I am Tala AI in data-only mode. Ask me about teachers, schools, counts, summaries, or exports.');
})();
</script>

</body>
</html>
