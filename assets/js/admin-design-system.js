/**
 * Bricks MCP Design System — Admin JS (v2)
 *
 * Mirrors PHP ScaleComputer + ColorComputer for instant client-side preview.
 * Handles stepper nav, per-panel rendering, overrides with recompute-on-seed-change,
 * auto-save (debounced), apply, reset.
 */
(function () {
    'use strict';

    const DS = window.bricksMcpDesignSystem;
    if (!DS) return;

    let config    = DS.config;
    let saveTimer = null;

    // --- Compute Engine (PHP mirror) ---

    function lighten(hex, percent) {
        const [r, g, b] = hexToRgb(hex);
        const nr = Math.min(255, Math.round(r + (255 - r) * (percent / 100)));
        const ng = Math.min(255, Math.round(g + (255 - g) * (percent / 100)));
        const nb = Math.min(255, Math.round(b + (255 - b) * (percent / 100)));
        return rgbToHex(nr, ng, nb);
    }

    function darken(hex, percent) {
        const [r, g, b] = hexToRgb(hex);
        const nr = Math.max(0, Math.round(r * (1 - percent / 100)));
        const ng = Math.max(0, Math.round(g * (1 - percent / 100)));
        const nb = Math.max(0, Math.round(b * (1 - percent / 100)));
        return rgbToHex(nr, ng, nb);
    }

    function hexToRgb(hex) {
        hex = String(hex).replace('#', '');
        if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
        return [parseInt(hex.slice(0, 2), 16), parseInt(hex.slice(2, 4), 16), parseInt(hex.slice(4, 6), 16)];
    }

    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(c => c.toString(16).padStart(2, '0')).join('');
    }

    function deriveShades(hex, expanded, isNeutral) {
        const s = {
            base:        rgbToHex(...hexToRgb(hex)),
            ultra_dark:  isNeutral ? lighten(hex, 10) : darken(hex, 40),
            dark:        isNeutral ? lighten(hex, 25) : darken(hex, 20),
            light:       lighten(hex, 85),
            ultra_light: lighten(hex, 95),
        };
        if (expanded) {
            s.semi_dark  = isNeutral ? lighten(hex, 40) : darken(hex, 10);
            s.medium     = isNeutral ? lighten(hex, 60) : lighten(hex, 35);
            s.semi_light = isNeutral ? lighten(hex, 75) : lighten(hex, 65);
        }
        return s;
    }

    function deriveHover(hex) {
        return darken(hex, 10);
    }

    function deriveTransparencies(hex) {
        const [r, g, b] = hexToRgb(hex);
        const out = {};
        [90,80,70,60,50,40,30,20,10].forEach(pct => {
            out[pct] = `rgba(${r}, ${g}, ${b}, ${(pct / 100).toFixed(2)})`;
        });
        return out;
    }

    const SPACING_EXP = { xs:-2, s:-1, m:0, l:1, xl:2, xxl:3 };
    const TEXT_EXP    = { xs:-2, s:-1, m:0, mm:0.5, l:1, xl:2, xxl:3 };
    const HEADING_EXP = { h1:2, h2:1, h3:0, h4:-1, h5:-2, h6:-3 };

    function computeSteps(bm, bd, scale, exp) {
        const out = {};
        Object.entries(exp).forEach(([name, e]) => {
            out[name] = {
                mobile:  Math.round(bm * Math.pow(scale, e) * 100) / 100,
                desktop: Math.round(bd * Math.pow(scale, e) * 100) / 100,
            };
        });
        return out;
    }

    // --- Config helpers ---

    function setNested(obj, path, value) {
        const keys = path.split('.');
        let c = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            if (typeof c[keys[i]] !== 'object' || c[keys[i]] === null) c[keys[i]] = {};
            c = c[keys[i]];
        }
        c[keys[keys.length - 1]] = value;
    }

    function getNested(obj, path) {
        return path.split('.').reduce((c, k) => (c == null ? c : c[k]), obj);
    }

    // --- Recompute handlers ---

    function recomputeSection(section) {
        if (section === 'spacing') {
            const s = config.spacing;
            const steps = computeSteps(
                parseFloat(s.base_mobile)  || 20,
                parseFloat(s.base_desktop) || 24,
                parseFloat(s.scale)        || 1.5,
                SPACING_EXP
            );
            steps.section = {
                mobile:  Math.round(steps.xxl.mobile  * 1.25 * 100) / 100,
                desktop: Math.round(steps.xxl.desktop * 1.25 * 100) / 100,
            };
            config.spacing.steps = steps;
        } else if (section === 'typography_text') {
            const t = config.typography_text;
            config.typography_text.steps = computeSteps(
                parseFloat(t.base_mobile)  || 16,
                parseFloat(t.base_desktop) || 18,
                parseFloat(t.scale)        || 1.25,
                TEXT_EXP
            );
        } else if (section === 'typography_headings') {
            const h = config.typography_headings;
            config.typography_headings.steps = computeSteps(
                parseFloat(h.base_mobile)  || 28,
                parseFloat(h.base_desktop) || 35,
                parseFloat(h.scale)        || 1.25,
                HEADING_EXP
            );
        } else if (section.startsWith('colors.')) {
            const family = section.split('.')[1];
            const fam    = config.colors[family];
            if (!fam || !fam.shades || !fam.shades.base) return;
            const isNeutral = (family === 'base' || family === 'neutral');
            fam.shades = deriveShades(fam.shades.base, !!fam.expanded, isNeutral);
            fam.hover  = deriveHover(fam.shades.base);
        } else if (section === 'radius') {
            const base = parseInt(config.radius.base) || 8;
            config.radius.values = {
                radius:         base + 'px',
                radius_inside:  'calc(var(--radius) * 0.5)',
                radius_outside: 'calc(var(--radius) * 1.4)',
                radius_btn:     '.3em',
                radius_pill:    '9999px',
                radius_circle:  '50%',
                radius_s:       Math.floor(base * 0.7) + 'px',
                radius_m:       base + 'px',
                radius_l:       Math.floor(base * 1.5) + 'px',
                radius_xl:      Math.floor(base * 2.25) + 'px',
            };
        }
    }

    // --- Rendering ---

    function render() {
        document.querySelectorAll('.bwm-ds-panel input[data-field]').forEach(input => {
            const path = input.dataset.field;
            const val  = getNested(config, path);
            if (val == null) return;
            if (input.type === 'checkbox') input.checked = !!val;
            else input.value = val;
        });
        renderTransparencyStrips();
        renderTypePreviews();
        renderSwatches();
        renderGapIndicators();
        renderRadiusShapes();
        renderLivePreview();
    }

    function renderTransparencyStrips() {
        document.querySelectorAll('.bwm-ds-trans-strip').forEach(strip => {
            const family = strip.dataset.family;
            const fam    = config.colors[family];
            if (!fam) return;
            const hex = (family === 'white' || family === 'black') ? fam.hex : (fam.shades && fam.shades.base);
            if (!hex) return;
            const trans = deriveTransparencies(hex);
            strip.innerHTML = Object.entries(trans).map(
                ([pct, rgba]) => `<div class="bwm-ds-trans-swatch" style="background:${rgba}" title="${pct}%">${pct}%</div>`
            ).join('');
        });
    }

    function renderLivePreview() {
        const container = document.getElementById('bwm-ds-live-preview');
        if (!container) return;

        const colors  = config.colors || {};
        const primary = (colors.primary && colors.primary.shades && colors.primary.shades.base) || '#3b82f6';
        const primaryDark = (colors.primary && colors.primary.shades && colors.primary.shades.dark) || darken(primary, 20);
        const secondaryEnabled = colors.secondary && colors.secondary.enabled !== false;
        const secondary = secondaryEnabled
            ? ((colors.secondary.shades && colors.secondary.shades.base) || '#f59e0b')
            : primary;
        const accentEnabled = colors.accent && colors.accent.enabled !== false;
        const accent = accentEnabled
            ? ((colors.accent.shades && colors.accent.shades.base) || '#10b981')
            : primary;
        const base = (colors.base && colors.base.shades && colors.base.shades.base) || '#374151';

        const primaryShades = (colors.primary && colors.primary.shades) || deriveShades(primary, false, false);
        const accentShades  = (colors.accent  && colors.accent.shades)  || deriveShades(accent,  false, false);
        const baseShades    = (colors.base    && colors.base.shades)    || deriveShades(base,    false, true);

        const headSteps = (config.typography_headings && config.typography_headings.steps) || {};
        const textSteps = (config.typography_text     && config.typography_text.steps)     || {};
        const spaceSteps = (config.spacing            && config.spacing.steps)             || {};

        const h1 = (headSteps.h1 || {}).desktop || 55;
        const h2 = (headSteps.h2 || {}).desktop || 44;
        const h4 = (headSteps.h4 || {}).desktop || 28;
        const tm = (textSteps.m  || {}).desktop || 18;
        const ts = (textSteps.s  || {}).desktop || 15;
        const txs = (textSteps.xs || {}).desktop || 14;
        const tl = (textSteps.l  || {}).desktop || 23;
        const sxs = (spaceSteps.xs || {}).desktop || 11;
        const ss  = (spaceSteps.s  || {}).desktop || 16;
        const sm  = (spaceSteps.m  || {}).desktop || 24;
        const sl  = (spaceSteps.l  || {}).desktop || 36;
        const sxl = (spaceSteps.xl || {}).desktop || 54;

        const radius = parseInt((config.radius && config.radius.base) || 8) || 8;

        function tag(v) {
            return `<span class="bwm-ds-token-label">${v}</span>`;
        }

        container.innerHTML = `
        <div class="bwm-ds-mockup">
            <div class="bwm-ds-mockup-hero" style="background:linear-gradient(135deg,${primary},${primaryDark});padding:${sxl}px ${sl}px;">
                ${tag('--primary')}
                <div style="font-size:${h1}px;font-weight:700;color:#fff;line-height:calc(7px + 2ex);margin-bottom:${ss}px;">
                    ${tag('--h1')}Design System Preview
                </div>
                <div style="font-size:${tl}px;color:rgba(255,255,255,0.85);margin-bottom:${sm}px;max-width:480px;line-height:calc(10px + 2ex);">
                    ${tag('--text-l')}Live mockup using your current configuration values applied in real time.
                </div>
                <div style="display:flex;gap:${ss}px;flex-wrap:wrap;">
                    <div class="bwm-ds-mockup-btn" style="background:${secondary};color:#fff;padding:${sxs}px ${sm}px;border-radius:${radius}px;font-size:${ts}px;font-weight:600;">
                        ${tag('--secondary')}Get Started
                    </div>
                    <div class="bwm-ds-mockup-btn" style="background:rgba(255,255,255,0.2);color:#fff;padding:${sxs}px ${sm}px;border-radius:${radius}px;border:1px solid rgba(255,255,255,0.3);font-size:${ts}px;">
                        ${tag('--white-trans-20')}Learn More
                    </div>
                </div>
            </div>

            <div class="bwm-ds-mockup-body" style="display:flex;gap:${sl}px;padding:${sxl}px ${sl}px;background:#fff;">
                <div style="flex:2;min-width:0;">
                    <div style="font-size:${h2}px;font-weight:600;color:${baseShades.ultra_dark || '#222'};line-height:calc(7px + 2ex);margin-bottom:${ss}px;">
                        ${tag('--h2')}Features &amp; Benefits
                    </div>
                    <div style="font-size:${tm}px;color:${base};line-height:calc(10px + 2ex);margin-bottom:${sm}px;">
                        ${tag('--text-m')}Your design system generates consistent spacing, typography, and colors.
                        <span style="color:${primary};text-decoration:underline;">${tag('--primary')}Explore the docs</span>
                        to see how every token connects.
                    </div>

                    <div style="display:flex;gap:${ss}px;">
                        <div style="flex:1;background:${primaryShades.ultra_light || '#eef'};padding:${sm}px;border-radius:${radius}px;text-align:center;position:relative;">
                            ${tag('--primary-ultra-light')}
                            <div style="font-size:${h2}px;font-weight:700;color:${primary};">${tag('--primary')}106</div>
                            <div style="font-size:${ts}px;color:${base};margin-top:4px;">${tag('--text-s')}Variables</div>
                        </div>
                        <div style="flex:1;background:${accentShades.ultra_light || '#efe'};padding:${sm}px;border-radius:${radius}px;text-align:center;position:relative;">
                            ${tag('--accent-ultra-light')}
                            <div style="font-size:${h2}px;font-weight:700;color:${accent};">${tag('--accent')}9</div>
                            <div style="font-size:${ts}px;color:${base};margin-top:4px;">Categories</div>
                        </div>
                        <div style="flex:1;background:${baseShades.ultra_light || '#eee'};padding:${sm}px;border-radius:${radius}px;text-align:center;position:relative;">
                            ${tag('--base-ultra-light')}
                            <div style="font-size:${h2}px;font-weight:700;color:${baseShades.ultra_dark || '#222'};">31</div>
                            <div style="font-size:${ts}px;color:${base};margin-top:4px;">Palette Colors</div>
                        </div>
                    </div>
                </div>

                <div style="flex:1;min-width:0;">
                    <div style="background:#fff;border:1px solid ${baseShades.light || '#ddd'};border-radius:${radius}px;padding:${sm}px;box-shadow:0 1px 3px rgba(0,0,0,0.08);position:relative;">
                        ${tag('--radius')}
                        <div style="font-size:${h4}px;font-weight:600;color:${baseShades.ultra_dark || '#222'};line-height:calc(7px + 2ex);margin-bottom:${sxs}px;">
                            ${tag('--h4')}Quick Start
                        </div>
                        <div style="font-size:${ts}px;color:${base};line-height:calc(10px + 2ex);margin-bottom:${ss}px;">
                            ${tag('--text-s')}Adjust the seed values above, then click Apply to write all variables to your Bricks site.
                        </div>
                        <div class="bwm-ds-mockup-btn" style="display:inline-block;background:${primary};color:#fff;padding:${sxs}px ${sm}px;border-radius:${radius}px;font-size:${ts}px;font-weight:600;">
                            ${tag('--primary')}Apply Now
                        </div>
                    </div>
                </div>
            </div>

            <div style="background:${baseShades.ultra_dark || '#222'};padding:${sm}px ${sl}px;display:flex;justify-content:space-between;align-items:center;">
                ${tag('--base-ultra-dark')}
                <div style="font-size:${txs}px;color:${baseShades.light || '#aaa'};">
                    ${tag('--text-xs')}Design System v2 &mdash; Built with BricksCore
                </div>
                <div style="font-size:${txs}px;color:${primary};">
                    ${tag('--primary')}Documentation &rarr;
                </div>
            </div>
        </div>`;
    }

    function renderTypePreviews() {
        document.querySelectorAll('.bwm-ds-step-row').forEach(row => {
            const mobInput  = row.querySelector('input[data-field*=".mobile"]');
            const deskInput = row.querySelector('input[data-field*=".desktop"]');
            const mobPrev   = row.querySelector('.bwm-ds-type-preview-mob');
            const deskPrev  = row.querySelector('.bwm-ds-type-preview-desk');
            if (mobInput  && mobPrev)  mobPrev.style.fontSize  = (parseFloat(mobInput.value)  || 0) + 'px';
            if (deskInput && deskPrev) deskPrev.style.fontSize = (parseFloat(deskInput.value) || 0) + 'px';
        });
    }

    function resolveGapPx(value) {
        if (!value) return 16;
        const v = String(value).trim();
        // Direct Npx
        let m = v.match(/^(\d+(?:\.\d+)?)px$/);
        if (m) return parseFloat(m[1]);
        // First var(--space-X) reference
        m = v.match(/var\(\s*--space-([a-z]+)\s*\)/);
        if (m) {
            const step = m[1];
            const steps = (config.spacing && config.spacing.steps) || {};
            const px = steps[step] && steps[step].desktop;
            return px != null ? parseFloat(px) : 16;
        }
        return 16;
    }

    function renderGapIndicators() {
        document.querySelectorAll('.bwm-ds-gap-indicator').forEach(ind => {
            const key   = ind.dataset.gapKey;
            const input = document.querySelector('input[data-gap-input="' + key + '"]');
            if (!input) return;
            const px = resolveGapPx(input.value);
            ind.style.gap = Math.min(60, Math.max(2, px)) + 'px';
        });
    }

    function renderRadiusShapes() {
        document.querySelectorAll('.bwm-ds-radius-shape').forEach(shape => {
            const key   = shape.dataset.radiusKey;
            const input = document.querySelector('input[data-radius-input="' + key + '"]');
            if (!input) return;
            shape.style.borderRadius = input.value || '0';
        });
    }

    function renderSwatches() {
        document.querySelectorAll('.bwm-ds-step-row').forEach(row => {
            const swatch = row.querySelector('.bwm-ds-swatch');
            if (!swatch) return;
            const deskInput = row.querySelector('input[data-field*=".desktop"]');
            if (!deskInput) return;
            const px = Math.min(120, Math.max(4, parseFloat(deskInput.value) || 0));
            swatch.style.width = px + 'px';
        });
    }

    // --- Stepper ---

    function switchStep(stepName) {
        document.querySelectorAll('.bwm-ds-step').forEach(b => b.classList.toggle('bwm-ds-step-active', b.dataset.step === stepName));
        document.querySelectorAll('.bwm-ds-panel').forEach(p => p.classList.toggle('bwm-ds-panel-active', p.dataset.step === stepName));
    }

    // --- Events ---

    function onInput(e) {
        const input = e.target;
        const path  = input.dataset.field;
        if (!path) return;

        let value;
        if (input.type === 'checkbox')  value = input.checked;
        else if (input.type === 'number' || input.type === 'radio') value = parseFloat(input.value) || 0;
        else value = input.value;

        setNested(config, path, value);

        const recomp = input.dataset.recompute;
        if (recomp) recomputeSection(recomp);

        // Sync color picker ↔ hex text input.
        if (input.type === 'color') {
            const hexInput = input.parentElement.querySelector('.bwm-ds-hex');
            if (hexInput) hexInput.value = input.value;
        } else if (input.classList.contains('bwm-ds-hex') && /^#[0-9a-f]{6}$/i.test(input.value)) {
            const colorInput = input.parentElement.querySelector('input[type="color"]');
            if (colorInput) colorInput.value = input.value;
        }

        render();
        scheduleSave();

        const restructure = input.dataset.restructure;
        if (restructure) refreshPanel(restructure);
    }

    function refreshPanel(panelName) {
        const fd = new FormData();
        fd.append('action', 'bricks_mcp_ds_render_panel');
        fd.append('nonce',  DS.nonce);
        fd.append('panel',  panelName);
        fd.append('config', JSON.stringify(config));

        fetch(DS.ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success || !res.data || !res.data.html) return;
                const oldPanel = document.querySelector('.bwm-ds-panel[data-step="' + panelName + '"]');
                if (!oldPanel) return;
                const wasActive = oldPanel.classList.contains('bwm-ds-panel-active');
                const tmp = document.createElement('div');
                tmp.innerHTML = res.data.html.trim();
                const newPanel = tmp.firstElementChild;
                if (!newPanel) return;
                if (wasActive) newPanel.classList.add('bwm-ds-panel-active');
                oldPanel.replaceWith(newPanel);
                // After DOM swap, re-paint input values + previews from current config.
                render();
            })
            .catch(() => {});
    }

    function onStepClick(e) {
        const btn = e.target.closest('.bwm-ds-step');
        if (!btn) return;
        switchStep(btn.dataset.step);
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            const fd = new FormData();
            fd.append('action', 'bricks_mcp_ds_save_config');
            fd.append('nonce', DS.nonce);
            fd.append('config', JSON.stringify(config));
            fetch(DS.ajaxUrl, { method: 'POST', body: fd });
        }, 500);
    }

    function onApply() {
        const btn = document.getElementById('bwm-ds-apply');
        const status = document.getElementById('bwm-ds-status');
        btn.disabled = true;
        btn.textContent = 'Applying…';
        const fd = new FormData();
        fd.append('action', 'bricks_mcp_ds_apply');
        fd.append('nonce', DS.nonce);
        fd.append('config', JSON.stringify(config));
        fetch(DS.ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.textContent = 'Apply to Site';
                if (res.success) {
                    const d = res.data;
                    status.className = 'bwm-ds-status bwm-ds-status-success';
                    status.textContent = `Applied: ${d.variables_count} vars, ${d.palette_colors} palette colors. Last applied: ${d.last_applied}`;
                } else {
                    status.className = 'bwm-ds-status bwm-ds-status-error';
                    status.textContent = 'Error: ' + (res.data || 'Unknown');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Apply to Site';
                status.className = 'bwm-ds-status bwm-ds-status-error';
                status.textContent = 'Network error';
            });
    }

    function onReset(e) {
        e.preventDefault();
        if (!confirm('Reset all design system settings to defaults?')) return;
        const fd = new FormData();
        fd.append('action', 'bricks_mcp_ds_reset');
        fd.append('nonce', DS.nonce);
        fetch(DS.ajaxUrl, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    config = res.data.config;
                    render();
                }
            });
    }

    // --- Init ---

    function init() {
        document.querySelector('.bwm-design-system-wrap')?.addEventListener('input',  onInput);
        document.querySelector('.bwm-design-system-wrap')?.addEventListener('change', onInput);
        document.querySelector('.bwm-ds-stepper')?.addEventListener('click', onStepClick);
        document.getElementById('bwm-ds-apply')?.addEventListener('click', onApply);
        document.getElementById('bwm-ds-reset')?.addEventListener('click', onReset);

        if (DS.lastApplied) {
            const status = document.getElementById('bwm-ds-status');
            if (status) status.textContent = 'Last applied: ' + DS.lastApplied;
        }

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
