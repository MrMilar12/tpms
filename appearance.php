<?php
$pageTitle = 'Appearance';
require_once __DIR__ . '/includes/header.php';

// Require user to have selected a role
requireRoleSelection();

$defaultTheme = 'glass';
$defaultDensity = 'comfortable';
$defaultLayout = 'default';
$defaultBgPalette = 'theme';
$defaultBgEffects = 'soft';
$defaultGlassTone = 'balanced';
$defaultAccentColor = '#6366f1';
$defaultBgTintColor = '#c4b5fd';
$defaultTeacherTagColor = '#94a3b8';
$defaultSchoolHeadColor = '#ef4444';
?>

<section class="appearance-hero glass-card">
    <div>
        <p class="appearance-kicker">System Personalization</p>
        <h2 class="appearance-title">Appearance Settings</h2>
        <p class="appearance-subtitle">Tune theme, readability, backgrounds, and teacher-card colors. Changes are saved and applied across the entire system.</p>
    </div>
    <button type="button" class="btn btn-ghost btn-sm" id="resetAppearanceBtn">
        <i class="fas fa-rotate-left"></i> Reset All
    </button>
</section>

<div class="appearance-workbench">
    <div class="charts-grid appearance-layout appearance-controls">
    <div class="chart-card glass-card appearance-panel appearance-panel-wide">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-palette"></i> Theme</h3>
        </div>
        <div class="appearance-panel-body appearance-options-grid" id="themeOptions">
            <button type="button" class="appearance-option" data-theme="glass">
                <strong>Glass</strong>
                <span>Current dark glass look.</span>
            </button>
            <button type="button" class="appearance-option" data-theme="frosted-glass">
                <strong>Frosted Glass</strong>
                <span>Premium multi-layer frosted UI with soft glow and depth.</span>
            </button>
            <button type="button" class="appearance-option" data-theme="ios">
                <strong>iOS</strong>
                <span>Light, rounded, airy interface.</span>
            </button>
            <button type="button" class="appearance-option" data-theme="pastel-sky">
                <strong>Pastel Sky</strong>
                <span>Cool pastel blues with soft frosted layers.</span>
            </button>
            <button type="button" class="appearance-option" data-theme="pastel-sunset">
                <strong>Pastel Sunset</strong>
                <span>Warm peach-pink pastels with readable glass surfaces.</span>
            </button>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-layer-group"></i> Layout Density</h3>
        </div>
        <div id="layoutDensity" class="appearance-panel-body appearance-stack">
            <button type="button" class="appearance-option" data-density="comfortable" data-density-group="layout">
                <strong>Comfortable</strong>
                <span>Balanced spacing and card size.</span>
            </button>
            <button type="button" class="appearance-option" data-density="compact" data-density-group="layout">
                <strong>Compact</strong>
                <span>Tighter spacing for more content on screen.</span>
            </button>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-mobile-screen"></i> App View</h3>
        </div>
        <div id="layoutMode" class="appearance-panel-body appearance-stack">
            <button type="button" class="appearance-option" data-layout="default" data-layout-group="view">
                <strong>Default View</strong>
                <span>Classic full-width dashboard layout.</span>
            </button>
            <button type="button" class="appearance-option" data-layout="app" data-layout-group="view">
                <strong>App View</strong>
                <span>Framed app-style workspace with floating shell.</span>
            </button>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-fill-drip"></i> Background Palette</h3>
        </div>
        <div id="backgroundPalette" class="appearance-panel-body appearance-stack">
            <button type="button" class="appearance-option" data-bg-palette="theme" data-bg-palette-group="background">
                <strong>Theme Default</strong>
                <span>Use the selected theme background colors.</span>
            </button>
            <button type="button" class="appearance-option" data-bg-palette="custom-color" data-bg-palette-group="background">
                <strong>Color Background</strong>
                <span>Use your selected color as the full-page background tint.</span>
            </button>
            <div class="appearance-color-picker-wrap">
                <label for="bgTintColorPicker" class="form-label appearance-inline-label">Background Color</label>
                <input type="color" id="bgTintColorPicker" class="appearance-color-picker" value="#c4b5fd">
            </div>
            <div class="appearance-swatch-grid" id="bgTintSwatches">
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#93c5fd" style="--sw:#93c5fd;" title="Blue Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#a5b4fc" style="--sw:#a5b4fc;" title="Indigo Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#c4b5fd" style="--sw:#c4b5fd;" title="Violet Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#fbcfe8" style="--sw:#fbcfe8;" title="Pink Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#fca5a5" style="--sw:#fca5a5;" title="Rose Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#fdba74" style="--sw:#fdba74;" title="Orange Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#fde68a" style="--sw:#fde68a;" title="Yellow Tint"></button>
                <button type="button" class="appearance-color-swatch" data-bg-tint-color="#86efac" style="--sw:#86efac;" title="Green Tint"></button>
            </div>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-wand-magic-sparkles"></i> Background Effects</h3>
        </div>
        <div id="backgroundEffects" class="appearance-panel-body appearance-stack">
            <button type="button" class="appearance-option" data-bg-effects="off" data-bg-effects-group="effects">
                <strong>Off</strong>
                <span>Disable animated ambient background effects.</span>
            </button>
            <button type="button" class="appearance-option" data-bg-effects="soft" data-bg-effects-group="effects">
                <strong>Soft</strong>
                <span>Subtle gradient balls and gentle wave texture.</span>
            </button>
            <button type="button" class="appearance-option" data-bg-effects="vivid" data-bg-effects-group="effects">
                <strong>Vivid</strong>
                <span>Stronger ambient colors and more visible background motion.</span>
            </button>
            <button type="button" class="appearance-option" data-bg-effects="immersive" data-bg-effects-group="effects">
                <strong>Immersive Glass</strong>
                <span>Premium animated gradient, floating glass balls, glow lights, and noise depth.</span>
            </button>
            <button type="button" class="appearance-option" data-bg-effects="color-flow" data-bg-effects-group="effects">
                <strong>Animated Color Flow</strong>
                <span>Clean moving color gradients with smooth cinematic transitions.</span>
            </button>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-sliders"></i> Glass Readability</h3>
        </div>
        <div id="glassTone" class="appearance-panel-body appearance-stack">
            <button type="button" class="appearance-option" data-glass-tone="soft" data-glass-tone-group="readability">
                <strong>Soft</strong>
                <span>Lighter frosted look with subtle contrast.</span>
            </button>
            <button type="button" class="appearance-option" data-glass-tone="balanced" data-glass-tone-group="readability">
                <strong>Balanced</strong>
                <span>Default readability and depth.</span>
            </button>
            <button type="button" class="appearance-option" data-glass-tone="strong" data-glass-tone-group="readability">
                <strong>Strong</strong>
                <span>Higher contrast glass for clearer text.</span>
            </button>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-eye-dropper"></i> Accent Color</h3>
        </div>
        <div id="accentColorSection" class="appearance-panel-body appearance-stack">
            <div class="appearance-color-picker-wrap">
                <label for="accentColorPicker" class="form-label appearance-inline-label">Custom Accent</label>
                <input type="color" id="accentColorPicker" class="appearance-color-picker" value="#6366f1">
            </div>
            <div class="appearance-swatch-grid" id="accentSwatches">
                <button type="button" class="appearance-color-swatch" data-accent-color="#6366f1" style="--sw:#6366f1;" title="Indigo"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#0ea5e9" style="--sw:#0ea5e9;" title="Sky"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#14b8a6" style="--sw:#14b8a6;" title="Teal"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#22c55e" style="--sw:#22c55e;" title="Green"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#f59e0b" style="--sw:#f59e0b;" title="Amber"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#ef4444" style="--sw:#ef4444;" title="Red"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#f97316" style="--sw:#f97316;" title="Orange"></button>
                <button type="button" class="appearance-color-swatch" data-accent-color="#ec4899" style="--sw:#ec4899;" title="Pink"></button>
            </div>
        </div>
    </div>

    <div class="chart-card glass-card appearance-panel appearance-panel-wide">
        <div class="card-header">
            <h3 class="card-title"><i class="fas fa-id-badge"></i> Teacher Card Colors</h3>
        </div>
        <div id="teacherCardColorSection" class="appearance-panel-body appearance-stack">
            <div class="appearance-color-picker-wrap">
                <label for="teacherTagColorPicker" class="form-label appearance-inline-label">Teacher Tag Color</label>
                <input type="color" id="teacherTagColorPicker" class="appearance-color-picker" value="<?= clean($defaultTeacherTagColor) ?>">
            </div>
            <div class="appearance-swatch-grid" id="teacherTagSwatches">
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#94a3b8" style="--sw:#94a3b8;" title="Slate"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#0ea5e9" style="--sw:#0ea5e9;" title="Sky"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#22c55e" style="--sw:#22c55e;" title="Green"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#f59e0b" style="--sw:#f59e0b;" title="Amber"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#a855f7" style="--sw:#a855f7;" title="Purple"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#ec4899" style="--sw:#ec4899;" title="Pink"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#ef4444" style="--sw:#ef4444;" title="Red"></button>
                <button type="button" class="appearance-color-swatch" data-teacher-tag-color="#14b8a6" style="--sw:#14b8a6;" title="Teal"></button>
            </div>

            <div class="appearance-color-picker-wrap">
                <label for="schoolHeadColorPicker" class="form-label appearance-inline-label">School Head Highlight</label>
                <input type="color" id="schoolHeadColorPicker" class="appearance-color-picker" value="<?= clean($defaultSchoolHeadColor) ?>">
            </div>
            <div class="appearance-swatch-grid" id="schoolHeadSwatches">
                <button type="button" class="appearance-color-swatch" data-school-head-color="#ef4444" style="--sw:#ef4444;" title="Red"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#f97316" style="--sw:#f97316;" title="Orange"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#f59e0b" style="--sw:#f59e0b;" title="Amber"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#22c55e" style="--sw:#22c55e;" title="Green"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#0ea5e9" style="--sw:#0ea5e9;" title="Sky"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#6366f1" style="--sw:#6366f1;" title="Indigo"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#a855f7" style="--sw:#a855f7;" title="Purple"></button>
                <button type="button" class="appearance-color-swatch" data-school-head-color="#ec4899" style="--sw:#ec4899;" title="Pink"></button>
            </div>
        </div>
    </div>
    </div>

    <aside class="appearance-sidebar">
        <div class="chart-card glass-card appearance-panel appearance-preview-panel">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-mobile-screen-button"></i> Live Preview</h3>
            </div>
            <div class="appearance-preview-body">
                <div class="appearance-preview" id="previewBox">
                    <div class="preview-topbar">
                        <span class="preview-dot"></span>
                        <span class="preview-dot"></span>
                        <span class="preview-dot"></span>
                    </div>
                    <div class="preview-body">
                        <div class="preview-card"></div>
                        <div class="preview-card"></div>
                        <div class="preview-card preview-card-wide"></div>
                    </div>
                </div>
                <p class="text-muted appearance-preview-copy">
                    Every option updates this preview in real time and applies to all pages once selected.
                </p>
            </div>
        </div>

        <div class="chart-card glass-card appearance-panel appearance-help-panel">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-lightbulb"></i> Design Tips</h3>
            </div>
            <div class="appearance-panel-body appearance-help-list">
                <p><strong>Start with theme:</strong> Pick Glass, iOS, or Pastel first.</p>
                <p><strong>Set readability:</strong> Use Glass Tone to improve text contrast.</p>
                <p><strong>Match colors:</strong> Align Accent, Background, and Teacher Card tones.</p>
                <p><strong>Highlight roles:</strong> Use School Head color for stronger role emphasis.</p>
            </div>
        </div>
    </aside>
    </div>
</div>

<script>
(function() {
    const STORAGE_KEY = 'tpmsAppearance';
    const DEFAULT_PREFS = {
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
    const themeButtons = document.querySelectorAll('#themeOptions [data-theme]');
    const densityButtons = document.querySelectorAll('#layoutDensity [data-density][data-density-group="layout"]');
    const layoutButtons = document.querySelectorAll('#layoutMode [data-layout][data-layout-group="view"]');
    const bgPaletteButtons = document.querySelectorAll('#backgroundPalette [data-bg-palette][data-bg-palette-group="background"]');
    const bgEffectsButtons = document.querySelectorAll('#backgroundEffects [data-bg-effects][data-bg-effects-group="effects"]');
    const glassToneButtons = document.querySelectorAll('#glassTone [data-glass-tone][data-glass-tone-group="readability"]');
    const resetBtn = document.getElementById('resetAppearanceBtn');
    const previewBox = document.getElementById('previewBox');
    const accentColorPicker = document.getElementById('accentColorPicker');
    const accentColorSwatches = document.querySelectorAll('#accentSwatches [data-accent-color]');
    const bgTintColorPicker = document.getElementById('bgTintColorPicker');
    const bgTintColorSwatches = document.querySelectorAll('#bgTintSwatches [data-bg-tint-color]');
    const teacherTagColorPicker = document.getElementById('teacherTagColorPicker');
    const teacherTagColorSwatches = document.querySelectorAll('#teacherTagSwatches [data-teacher-tag-color]');
    const schoolHeadColorPicker = document.getElementById('schoolHeadColorPicker');
    const schoolHeadColorSwatches = document.querySelectorAll('#schoolHeadSwatches [data-school-head-color]');

    function isValidHexColor(value) {
        return /^#[0-9a-fA-F]{6}$/.test(String(value || ''));
    }

    function normalizeHexColor(value, fallback) {
        const next = String(value || '').trim();
        return isValidHexColor(next) ? next.toLowerCase() : fallback;
    }

    function hexToRgb(hex) {
        const normalized = normalizeHexColor(hex, '#6366f1').slice(1);
        const int = parseInt(normalized, 16);
        return {
            r: (int >> 16) & 255,
            g: (int >> 8) & 255,
            b: int & 255,
        };
    }

    function darkenHex(hex, amount) {
        const rgb = hexToRgb(hex);
        const clamp = (v) => Math.max(0, Math.min(255, v));
        const toHex = (v) => clamp(v).toString(16).padStart(2, '0');
        return '#' + toHex(rgb.r - amount) + toHex(rgb.g - amount) + toHex(rgb.b - amount);
    }

    function rgbaFromHex(hex, alpha) {
        const rgb = hexToRgb(hex);
        return 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
    }

    function contrastTextFromHex(hex) {
        const rgb = hexToRgb(hex);
        const brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        return brightness >= 160 ? '#0f172a' : '#f8fafc';
    }

    function applyReadableMode(theme, bgPalette, tintHex) {
        const tint = normalizeHexColor(tintHex, DEFAULT_PREFS.bgTintColor);
        const rgb = hexToRgb(tint);
        const brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        const isLightTint = brightness >= 160;
        const nextTheme = String(theme || DEFAULT_PREFS.theme);
        const nextBgPalette = String(bgPalette || DEFAULT_PREFS.bgPalette);
        let mode = 'light-text';

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
        const accent = normalizeHexColor(hex, DEFAULT_PREFS.accentColor);
        const rootStyle = document.documentElement.style;
        rootStyle.setProperty('--user-accent', accent);
        rootStyle.setProperty('--user-accent-dark', darkenHex(accent, 26));
        rootStyle.setProperty('--user-accent-glow', rgbaFromHex(accent, 0.28));
    }

    function applyBackgroundTintColor(hex) {
        const tint = normalizeHexColor(hex, DEFAULT_PREFS.bgTintColor);
        const rootStyle = document.documentElement.style;
        const rgb = hexToRgb(tint);
        const brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
        const isLight = brightness >= 160;
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
        const rootStyle = document.documentElement.style;
        const nextTheme = String(theme || DEFAULT_PREFS.theme);
        const nextBgPalette = String(bgPalette || DEFAULT_PREFS.bgPalette);

        if (nextTheme === 'frosted-glass' && nextBgPalette === 'custom-color') {
            const tint = normalizeHexColor(tintHex, DEFAULT_PREFS.bgTintColor);
            const rgb = hexToRgb(tint);
            const brightness = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
            const isLight = brightness >= 160;
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
        const tag = normalizeHexColor(tagHex, DEFAULT_PREFS.teacherTagColor);
        const head = normalizeHexColor(headHex, DEFAULT_PREFS.schoolHeadColor);
        const rootStyle = document.documentElement.style;
        rootStyle.setProperty('--teacher-card-tint-soft', rgbaFromHex(tag, 0.24));
        rootStyle.setProperty('--teacher-card-tint-mid', rgbaFromHex(tag, 0.16));
        rootStyle.setProperty('--teacher-card-base', rgbaFromHex(tag, 0.22));
        rootStyle.setProperty('--teacher-card-border', rgbaFromHex(tag, 0.44));
        rootStyle.setProperty('--teacher-tag-bg', rgbaFromHex(tag, 0.24));
        rootStyle.setProperty('--teacher-tag-border', rgbaFromHex(tag, 0.56));
        rootStyle.setProperty('--teacher-tag-text', contrastTextFromHex(tag));
        rootStyle.setProperty('--teacher-head-soft', rgbaFromHex(head, 0.28));
        rootStyle.setProperty('--teacher-head-mid', rgbaFromHex(head, 0.14));
        rootStyle.setProperty('--teacher-head-border', rgbaFromHex(head, 0.74));
        rootStyle.setProperty('--teacher-head-shadow', rgbaFromHex(head, 0.28));
        rootStyle.setProperty('--teacher-head-label-bg', rgbaFromHex(head, 0.22));
        rootStyle.setProperty('--teacher-head-label-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-chip-bg', rgbaFromHex(head, 0.22));
        rootStyle.setProperty('--teacher-head-chip-border', rgbaFromHex(head, 0.62));
        rootStyle.setProperty('--teacher-head-chip-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-info-text', contrastTextFromHex(head));
        rootStyle.setProperty('--teacher-head-icon', head);
    }

    function readPrefs() {
        try {
            const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
            const prefs = Object.assign({}, DEFAULT_PREFS, parsed);
            if (!['glass', 'frosted-glass', 'ios', 'pastel-sky', 'pastel-sunset'].includes(prefs.theme)) prefs.theme = DEFAULT_PREFS.theme;
            if (!['comfortable', 'compact'].includes(prefs.density)) prefs.density = DEFAULT_PREFS.density;
            if (!['default', 'app'].includes(prefs.layout)) prefs.layout = DEFAULT_PREFS.layout;
            if (['pastel-mix', 'pastel-aurora', 'pastel-candy'].includes(prefs.bgPalette)) {
                prefs.bgPalette = 'custom-color';
            }
            if (!['theme', 'custom-color'].includes(prefs.bgPalette)) prefs.bgPalette = DEFAULT_PREFS.bgPalette;
            if (!['off', 'soft', 'vivid', 'immersive', 'color-flow'].includes(prefs.bgEffects)) prefs.bgEffects = DEFAULT_PREFS.bgEffects;
            if (!['soft', 'balanced', 'strong'].includes(prefs.glassTone)) prefs.glassTone = DEFAULT_PREFS.glassTone;
            prefs.accentColor = normalizeHexColor(prefs.accentColor, DEFAULT_PREFS.accentColor);
            prefs.bgTintColor = normalizeHexColor(prefs.bgTintColor, DEFAULT_PREFS.bgTintColor);
            prefs.teacherTagColor = normalizeHexColor(prefs.teacherTagColor, DEFAULT_PREFS.teacherTagColor);
            prefs.schoolHeadColor = normalizeHexColor(prefs.schoolHeadColor, DEFAULT_PREFS.schoolHeadColor);
            return prefs;
        } catch (e) {
            return Object.assign({}, DEFAULT_PREFS);
        }
    }

    function writePrefs(nextPrefs) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(nextPrefs));
        } catch (e) {
            // Keep runtime state applied even if storage is unavailable.
        }
    }

    function applyPrefs(prefs) {
        const theme = prefs.theme || DEFAULT_PREFS.theme;
        const density = prefs.density || DEFAULT_PREFS.density;
        const layout = prefs.layout || DEFAULT_PREFS.layout;
        const bgPalette = prefs.bgPalette || DEFAULT_PREFS.bgPalette;
        const bgEffects = prefs.bgEffects || DEFAULT_PREFS.bgEffects;
        const glassTone = prefs.glassTone || DEFAULT_PREFS.glassTone;
        const accentColor = normalizeHexColor(prefs.accentColor, DEFAULT_PREFS.accentColor);
        const bgTintColor = normalizeHexColor(prefs.bgTintColor, DEFAULT_PREFS.bgTintColor);
        const teacherTagColor = normalizeHexColor(prefs.teacherTagColor, DEFAULT_PREFS.teacherTagColor);
        const schoolHeadColor = normalizeHexColor(prefs.schoolHeadColor, DEFAULT_PREFS.schoolHeadColor);
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-density', density);
        document.documentElement.setAttribute('data-layout', layout);
        document.documentElement.setAttribute('data-bg-palette', bgPalette);
        document.documentElement.setAttribute('data-bg-effects', bgEffects);
        document.documentElement.setAttribute('data-glass-tone', glassTone);
        applyAccentColor(accentColor);
        applyBackgroundTintColor(bgTintColor);
        applyFrostedCardTint(theme, bgPalette, bgTintColor);
        applyReadableMode(theme, bgPalette, bgTintColor);
        applyTeacherCardColors(teacherTagColor, schoolHeadColor);

        if (previewBox) {
            previewBox.setAttribute('data-preview-theme', theme);
            previewBox.setAttribute('data-preview-density', density);
            previewBox.setAttribute('data-preview-layout', layout);
        }

        themeButtons.forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.theme === theme);
        });
        densityButtons.forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.density === density);
        });
        layoutButtons.forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.layout === layout);
        });
        bgPaletteButtons.forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.bgPalette === bgPalette);
        });
        bgEffectsButtons.forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.bgEffects === bgEffects);
        });
        glassToneButtons.forEach(function(btn) {
            btn.classList.toggle('selected', btn.dataset.glassTone === glassTone);
        });
        if (accentColorPicker) {
            accentColorPicker.value = accentColor;
        }
        accentColorSwatches.forEach(function(btn) {
            btn.classList.toggle('selected', normalizeHexColor(btn.dataset.accentColor, '') === accentColor);
        });
        if (bgTintColorPicker) {
            bgTintColorPicker.value = bgTintColor;
        }
        bgTintColorSwatches.forEach(function(btn) {
            btn.classList.toggle('selected', normalizeHexColor(btn.dataset.bgTintColor, '') === bgTintColor);
        });
        if (teacherTagColorPicker) {
            teacherTagColorPicker.value = teacherTagColor;
        }
        teacherTagColorSwatches.forEach(function(btn) {
            btn.classList.toggle('selected', normalizeHexColor(btn.dataset.teacherTagColor, '') === teacherTagColor);
        });
        if (schoolHeadColorPicker) {
            schoolHeadColorPicker.value = schoolHeadColor;
        }
        schoolHeadColorSwatches.forEach(function(btn) {
            btn.classList.toggle('selected', normalizeHexColor(btn.dataset.schoolHeadColor, '') === schoolHeadColor);
        });
    }

    function saveAndApply(patch) {
        const currentPrefs = readPrefs();
        const prefs = Object.assign({}, currentPrefs, patch);
        writePrefs(prefs);
        applyPrefs(prefs);

        if (window.parent && window.parent !== window) {
            try {
                window.parent.postMessage({
                    type: 'tpms-appearance-sync',
                    prefs: prefs
                }, window.location.origin);
            } catch (e) {}
        }

        if (prefs.layout !== currentPrefs.layout && window.parent === window) {
            if (prefs.layout === 'app') {
                try { sessionStorage.setItem('tpms_tala_os_welcome', '1'); } catch (e) {}
            }
            window.location.reload();
        }
    }

    themeButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ theme: btn.dataset.theme });
        });
    });

    densityButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ density: btn.dataset.density });
        });
    });

    layoutButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ layout: btn.dataset.layout });
        });
    });

    bgPaletteButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ bgPalette: btn.dataset.bgPalette });
        });
    });

    bgEffectsButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ bgEffects: btn.dataset.bgEffects });
        });
    });

    glassToneButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ glassTone: btn.dataset.glassTone });
        });
    });

    if (accentColorPicker) {
        accentColorPicker.addEventListener('input', function() {
            saveAndApply({ accentColor: normalizeHexColor(this.value, DEFAULT_PREFS.accentColor) });
        });
    }

    accentColorSwatches.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ accentColor: normalizeHexColor(btn.dataset.accentColor, DEFAULT_PREFS.accentColor) });
        });
    });

    if (bgTintColorPicker) {
        bgTintColorPicker.addEventListener('input', function() {
            saveAndApply({
                bgTintColor: normalizeHexColor(this.value, DEFAULT_PREFS.bgTintColor),
                bgPalette: 'custom-color'
            });
        });
    }

    bgTintColorSwatches.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({
                bgTintColor: normalizeHexColor(btn.dataset.bgTintColor, DEFAULT_PREFS.bgTintColor),
                bgPalette: 'custom-color'
            });
        });
    });

    if (teacherTagColorPicker) {
        teacherTagColorPicker.addEventListener('input', function() {
            saveAndApply({ teacherTagColor: normalizeHexColor(this.value, DEFAULT_PREFS.teacherTagColor) });
        });
    }

    teacherTagColorSwatches.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ teacherTagColor: normalizeHexColor(btn.dataset.teacherTagColor, DEFAULT_PREFS.teacherTagColor) });
        });
    });

    if (schoolHeadColorPicker) {
        schoolHeadColorPicker.addEventListener('input', function() {
            saveAndApply({ schoolHeadColor: normalizeHexColor(this.value, DEFAULT_PREFS.schoolHeadColor) });
        });
    }

    schoolHeadColorSwatches.forEach(function(btn) {
        btn.addEventListener('click', function() {
            saveAndApply({ schoolHeadColor: normalizeHexColor(btn.dataset.schoolHeadColor, DEFAULT_PREFS.schoolHeadColor) });
        });
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            try {
                localStorage.removeItem(STORAGE_KEY);
            } catch (e) {}
            saveAndApply(Object.assign({}, DEFAULT_PREFS));
        });
    }

    const initialPrefs = readPrefs();
    writePrefs(initialPrefs);
    applyPrefs(initialPrefs);
})();
</script>

<style>
.appearance-hero {
    margin-bottom: 16px;
    padding: 20px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    background:
      radial-gradient(circle at 10% 14%, rgba(99, 102, 241, 0.2), transparent 38%),
      radial-gradient(circle at 88% 20%, rgba(14, 165, 233, 0.18), transparent 32%),
      rgba(15, 23, 42, 0.34);
    color: var(--text);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.09);
}

.appearance-kicker {
    margin: 0 0 6px;
    font-size: 11px;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--text);
    font-weight: 700;
    opacity: .9;
}

.appearance-title {
    margin: 0;
    font-size: 24px;
    line-height: 1.2;
    color: var(--text);
    text-shadow: 0 1px 1px rgba(15, 23, 42, 0.22);
}

.appearance-subtitle {
    margin: 8px 0 0;
    color: var(--text);
    max-width: 72ch;
    font-size: 13px;
    opacity: .88;
}

.appearance-hero .btn {
    color: var(--text);
    border-color: rgba(148, 163, 184, 0.48);
    background: rgba(15, 23, 42, 0.26);
}

.appearance-hero .btn:hover {
    border-color: rgba(148, 163, 184, 0.64);
    background: rgba(15, 23, 42, 0.38);
}

html[data-readable-mode="dark-text"] .appearance-hero {
    background:
      radial-gradient(circle at 10% 14%, rgba(59, 130, 246, 0.16), transparent 38%),
      radial-gradient(circle at 88% 20%, rgba(14, 165, 233, 0.14), transparent 32%),
      rgba(255, 255, 255, 0.62);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.34);
}

html[data-readable-mode="dark-text"] .appearance-hero .btn {
    background: rgba(255, 255, 255, 0.72);
    border-color: rgba(71, 85, 105, 0.34);
}

html[data-readable-mode="dark-text"] .appearance-hero .btn:hover {
    background: rgba(255, 255, 255, 0.86);
    border-color: rgba(71, 85, 105, 0.46);
}

.appearance-layout {
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    align-items: start;
}

.appearance-workbench {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 16px;
    align-items: start;
}

.appearance-controls {
    margin: 0;
}

.appearance-sidebar {
    position: sticky;
    top: 86px;
    display: grid;
    gap: 14px;
}

.appearance-panel {
    border: 1px solid rgba(148, 163, 184, 0.22);
}

.appearance-panel-wide {
    grid-column: span 2;
}

.appearance-panel .card-header {
    padding: 14px 16px;
}

.appearance-panel-body {
    padding: 16px;
}

.appearance-options-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
}

.appearance-stack {
    display: grid;
    gap: 12px;
}

.appearance-inline-label {
    margin: 0;
}

.appearance-option {
    display: grid;
    gap: 6px;
    text-align: left;
    padding: 14px 16px;
    border-radius: 16px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    background: rgba(255, 255, 255, 0.06);
    color: inherit;
    box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
}
.appearance-option strong { font-size: 14px; }
.appearance-option span {
    font-size: 12px;
    color: var(--text);
    opacity: .86;
    line-height: 1.45;
}
.appearance-option:hover {
    transform: translateY(-1px);
    border-color: rgba(148, 163, 184, 0.42);
}
.appearance-option.selected {
    border-color: rgba(10, 132, 255, 0.5);
    box-shadow: 0 0 0 1px rgba(10, 132, 255, 0.12) inset, 0 10px 22px rgba(10, 132, 255, 0.12);
}

#themeOptions .appearance-option,
#backgroundPalette .appearance-option {
    position: relative;
    overflow: hidden;
}

#themeOptions .appearance-option::before,
#backgroundPalette .appearance-option::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 6px;
    border-radius: 16px 0 0 16px;
    opacity: .95;
}

#themeOptions .appearance-option[data-theme="glass"]::before { background: linear-gradient(180deg, #6366f1, #0ea5e9); }
#themeOptions .appearance-option[data-theme="frosted-glass"]::before { background: linear-gradient(180deg, #22d3ee, #f472b6); }
#themeOptions .appearance-option[data-theme="ios"]::before { background: linear-gradient(180deg, #0a84ff, #5e5ce6); }
#themeOptions .appearance-option[data-theme="pastel-sky"]::before { background: linear-gradient(180deg, #93c5fd, #a5b4fc); }
#themeOptions .appearance-option[data-theme="pastel-sunset"]::before { background: linear-gradient(180deg, #fda4af, #fdba74); }

#backgroundPalette .appearance-option[data-bg-palette="theme"]::before { background: linear-gradient(180deg, #94a3b8, #64748b); }
#backgroundPalette .appearance-option[data-bg-palette="custom-color"]::before { background: linear-gradient(180deg, #c4b5fd, #93c5fd); }

.appearance-color-picker-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    border: 1px solid rgba(148, 163, 184, 0.22);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.05);
}

.appearance-color-picker {
    width: 52px;
    height: 36px;
    padding: 0;
    border: none;
    border-radius: 10px;
    background: transparent;
}

.appearance-color-picker::-webkit-color-swatch-wrapper {
    padding: 0;
}

.appearance-color-picker::-webkit-color-swatch {
    border: 1px solid rgba(148, 163, 184, 0.45);
    border-radius: 10px;
}

.appearance-swatch-grid {
    display: grid;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    gap: 8px;
}

.appearance-color-swatch {
    height: 30px;
    border-radius: 10px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: var(--sw);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.32);
    transition: transform .2s ease, outline-color .2s ease;
}

.appearance-color-swatch.selected {
    outline: 2px solid rgba(10,132,255,.5);
    outline-offset: 1px;
    transform: translateY(-1px);
}

.appearance-preview-panel {
    margin-top: 0;
}

.appearance-preview-body {
    padding: 18px;
    display: grid;
    gap: 14px;
}

.appearance-preview-copy {
    margin: 0;
    max-width: 56ch;
}

.appearance-help-list {
    display: grid;
    gap: 10px;
}

.appearance-help-list p {
    margin: 0;
    font-size: 13px;
    line-height: 1.5;
    color: var(--text);
    opacity: .9;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid rgba(148, 163, 184, 0.18);
    background: rgba(255, 255, 255, 0.04);
}

.appearance-preview-copy,
.appearance-inline-label {
    color: var(--text);
    opacity: .88;
}

/* Preview theme variants */
.appearance-preview[data-preview-theme="glass"] {
    background: rgba(255, 255, 255, 0.54);
    border-color: rgba(148, 163, 184, 0.18);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
}

.appearance-preview[data-preview-theme="glass"] .preview-topbar {
    background: rgba(255,255,255,.52);
}

.appearance-preview[data-preview-theme="glass"] .preview-dot {
    background: rgba(10, 132, 255, 0.42);
}

.appearance-preview[data-preview-theme="glass"] .preview-card {
    background: linear-gradient(135deg, rgba(10,132,255,.16), rgba(94,92,230,.12));
}

.appearance-preview[data-preview-theme="ios"] {
    background: rgba(255, 255, 255, 0.88);
    border-color: rgba(148, 163, 184, 0.22);
    box-shadow: 0 12px 24px rgba(15, 23, 42, 0.08);
}

.appearance-preview[data-preview-theme="ios"] .preview-topbar {
    background: rgba(255, 255, 255, 0.82);
}

.appearance-preview[data-preview-theme="ios"] .preview-dot {
    background: rgba(10, 132, 255, 0.58);
}

.appearance-preview[data-preview-theme="ios"] .preview-card {
    background: linear-gradient(135deg, rgba(10,132,255,.2), rgba(94,92,230,.16));
}

.appearance-preview[data-preview-theme="pastel-sky"] {
    background: rgba(243, 248, 255, 0.92);
    border-color: rgba(125, 170, 255, 0.26);
    box-shadow: 0 12px 24px rgba(59, 130, 246, 0.12);
}

.appearance-preview[data-preview-theme="pastel-sky"] .preview-topbar {
    background: rgba(228, 240, 255, 0.9);
}

.appearance-preview[data-preview-theme="pastel-sky"] .preview-dot {
    background: rgba(59, 130, 246, 0.5);
}

.appearance-preview[data-preview-theme="pastel-sky"] .preview-card {
    background: linear-gradient(135deg, rgba(147,197,253,.38), rgba(165,180,252,.3));
}

.appearance-preview[data-preview-theme="pastel-sunset"] {
    background: rgba(255, 246, 244, 0.92);
    border-color: rgba(251, 146, 146, 0.3);
    box-shadow: 0 12px 24px rgba(244, 114, 182, 0.12);
}

.appearance-preview[data-preview-theme="pastel-sunset"] .preview-topbar {
    background: rgba(255, 235, 228, 0.9);
}

.appearance-preview[data-preview-theme="pastel-sunset"] .preview-dot {
    background: rgba(244, 114, 182, 0.56);
}

.appearance-preview[data-preview-theme="pastel-sunset"] .preview-card {
    background: linear-gradient(135deg, rgba(252,165,165,.36), rgba(253,186,116,.3));
}

/* Preview density variants */
.appearance-preview[data-preview-density="comfortable"] .preview-body {
    gap: 12px;
    padding: 16px;
}

.appearance-preview[data-preview-density="compact"] .preview-body {
    gap: 8px;
    padding: 12px;
}

.appearance-preview[data-preview-density="comfortable"] .preview-card {
    height: 52px;
}

.appearance-preview[data-preview-density="compact"] .preview-card {
    height: 40px;
}

/* Preview layout variants */
.appearance-preview[data-preview-layout="default"] {
    border-radius: 24px;
}

.appearance-preview[data-preview-layout="app"] {
    border-radius: 28px;
    border-width: 2px;
}
.appearance-preview {
    border-radius: 24px;
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, 0.18);
    background: rgba(255, 255, 255, 0.54);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.6);
}
.preview-topbar {
    display: flex;
    gap: 8px;
    padding: 12px 14px;
    background: rgba(255,255,255,.52);
}
.preview-dot {
    width: 10px; height: 10px;
    border-radius: 50%;
    background: rgba(10, 132, 255, 0.42);
}
.preview-body {
    display: grid;
    gap: 12px;
    padding: 16px;
}
.preview-card {
    height: 52px;
    border-radius: 18px;
    background: linear-gradient(135deg, rgba(10,132,255,.16), rgba(94,92,230,.12));
}
.preview-card-wide { height: 86px; }

@media (max-width: 760px) {
    .appearance-hero {
        flex-direction: column;
        align-items: flex-start;
    }

    .appearance-hero .btn {
        width: 100%;
        justify-content: center;
    }

    .appearance-swatch-grid {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 1200px) {
    .appearance-workbench {
        grid-template-columns: 1fr;
    }

    .appearance-sidebar {
        position: static;
    }

    .appearance-panel-wide {
        grid-column: auto;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
