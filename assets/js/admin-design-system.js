/**
 * Bricks MCP Design System Generator — Admin JS
 *
 * Mirrors PHP computation engine for instant client-side preview.
 * Handles accordion UI, auto-save, apply, reset.
 */
(function () {
    'use strict';

    const DS = window.bricksMcpDesignSystem;
    if (!DS) return;

    let config = DS.config;
    let saveTimer = null;

    // --- Computation Engine (mirrors PHP) ---

    function lightenColor(hex, percent) {
        hex = hex.replace('#', '');
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const b = parseInt(hex.slice(4, 6), 16);
        const nr = Math.min(255, Math.round(r + (255 - r) * (percent / 100)));
        const ng = Math.min(255, Math.round(g + (255 - g) * (percent / 100)));
        const nb = Math.min(255, Math.round(b + (255 - b) * (percent / 100)));
        return '#' + [nr, ng, nb].map(c => c.toString(16).padStart(2, '0')).join('');
    }

    function darkenColor(hex, percent) {
        hex = hex.replace('#', '');
        const r = parseInt(hex.slice(0, 2), 16);
        const g = parseInt(hex.slice(2, 4), 16);
        const b = parseInt(hex.slice(4, 6), 16);
        const nr = Math.max(0, Math.round(r * (1 - percent / 100)));
        const ng = Math.max(0, Math.round(g * (1 - percent / 100)));
        const nb = Math.max(0, Math.round(b * (1 - percent / 100)));
        return '#' + [nr, ng, nb].map(c => c.toString(16).padStart(2, '0')).join('');
    }

    function generateClamp(mobilePx, desktopPx) {
        const cw = config.container_width || 1280;
        const cm = config.container_min || 380;
        const minRem = (mobilePx / 16).toFixed(2);
        const maxRem = (desktopPx / 16).toFixed(2);
        const slope = ((desktopPx - mobilePx) / (cw - cm)).toFixed(4);
        return `clamp(${minRem}rem, calc(${minRem}rem + ${slope} * (100vw - ${cm}px)), ${maxRem}rem)`;
    }

    function computeScale(baseMob, baseDesk, scale, steps) {
        const result = {};
        steps.forEach(([name, exp]) => {
            result[name] = {
                mobile: Math.round(baseMob * Math.pow(scale, exp) * 100) / 100,
                desktop: Math.round(baseDesk * Math.pow(scale, exp) * 100) / 100
            };
        });
        return result;
    }

    function getColorShades(hex, isNeutral) {
        return {
            'ultra-dark': isNeutral ? lightenColor(hex, 10) : darkenColor(hex, 40),
            'dark': isNeutral ? lightenColor(hex, 25) : darkenColor(hex, 20),
            'base': hex,
            'light': lightenColor(hex, 85),
            'ultra-light': lightenColor(hex, 95)
        };
    }

    // --- Preview Renderers ---

    function renderColorPreview() {
        const container = document.getElementById('bwm-ds-color-preview');
        if (!container) return;

        const colors = config.colors || {};
        const families = [
            { name: 'Primary', hex: colors.primary || '#3b82f6', neutral: false, enabled: true },
            { name: 'Secondary', hex: (colors.secondary || {}).hex || '#f59e0b', neutral: false, enabled: (colors.secondary || {}).enabled !== false },
            { name: 'Accent', hex: (colors.accent || {}).hex || '#10b981', neutral: false, enabled: (colors.accent || {}).enabled !== false },
            { name: 'Base', hex: colors.base || '#374151', neutral: true, enabled: true }
        ];

        let html = '<div class="bwm-ds-preview-label">Generated Shades</div>';
        families.forEach(f => {
            if (!f.enabled) return;
            const shades = getColorShades(f.hex, f.neutral);
            html += `<div class="bwm-ds-shade-row"><div class="bwm-ds-shade-label">${f.name}</div><div class="bwm-ds-shade-strip">`;
            Object.entries(shades).forEach(([key, color]) => {
                const isBorder = (key === 'light' || key === 'ultra-light');
                const isBase = key === 'base';
                html += `<div class="bwm-ds-shade" style="background:${color};${isBorder ? 'border:1px solid #ddd;' : ''}${isBase ? 'border:2px solid #333;' : ''}" title="${key}: ${color}"></div>`;
            });
            html += '</div></div>';
        });

        container.innerHTML = html;
    }

    function renderSpacingPreview() {
        const container = document.getElementById('bwm-ds-spacing-preview');
        if (!container) return;

        const s = config.spacing || {};
        const bm = parseFloat(s.base_mobile) || 20;
        const bd = parseFloat(s.base_desktop) || 24;
        const sc = parseFloat(s.scale) || 1.5;

        const steps = computeScale(bm, bd, sc, [
            ['space-xs', -2], ['space-s', -1], ['space-m', 0],
            ['space-l', 1], ['space-xl', 2], ['space-xxl', 3]
        ]);
        steps['space-section'] = {
            mobile: Math.round(bm * Math.pow(sc, 3) * 1.25 * 100) / 100,
            desktop: Math.round(bd * Math.pow(sc, 3) * 1.25 * 100) / 100
        };

        let html = '<div class="bwm-ds-preview-label">Computed Values</div><table class="bwm-ds-table"><thead><tr><th>Name</th><th>Mobile</th><th>Desktop</th><th>Clamp</th></tr></thead><tbody>';
        Object.entries(steps).forEach(([name, vals]) => {
            html += `<tr><td><code>--${name}</code></td><td>${vals.mobile}px</td><td>${vals.desktop}px</td><td class="bwm-ds-clamp"><code>${generateClamp(vals.mobile, vals.desktop)}</code></td></tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderTextPreview() {
        const container = document.getElementById('bwm-ds-text-preview');
        if (!container) return;

        const t = config.typography_text || {};
        const bm = parseFloat(t.base_mobile) || 16;
        const bd = parseFloat(t.base_desktop) || 18;
        const sc = parseFloat(t.scale) || 1.25;

        const steps = computeScale(bm, bd, sc, [
            ['text-xs', -2], ['text-s', -1], ['text-m', 0], ['text-mm', 0.5],
            ['text-l', 1], ['text-xl', 2], ['text-xxl', 3]
        ]);

        let html = '<table class="bwm-ds-table"><thead><tr><th>Name</th><th>Mobile</th><th>Desktop</th></tr></thead><tbody>';
        Object.entries(steps).forEach(([name, vals]) => {
            html += `<tr><td><code>--${name}</code></td><td>${vals.mobile}px</td><td>${vals.desktop}px</td></tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderHeadingsPreview() {
        const container = document.getElementById('bwm-ds-headings-preview');
        if (!container) return;

        const h = config.typography_headings || {};
        const bm = parseFloat(h.base_mobile) || 28;
        const bd = parseFloat(h.base_desktop) || 35;
        const sc = parseFloat(h.scale) || 1.25;

        // h3 = base (exp 0), h2 = exp 1, h1 = exp 2, h4 = exp -1, h5 = exp -2, h6 = exp -3
        const steps = computeScale(bm, bd, sc, [
            ['h1', 2], ['h2', 1], ['h3', 0], ['h4', -1], ['h5', -2], ['h6', -3]
        ]);

        let html = '<table class="bwm-ds-table"><thead><tr><th>Name</th><th>Mobile</th><th>Desktop</th></tr></thead><tbody>';
        Object.entries(steps).forEach(([name, vals]) => {
            html += `<tr><td><code>--${name}</code></td><td>${vals.mobile}px</td><td>${vals.desktop}px</td></tr>`;
        });
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderRadiusPreview() {
        const container = document.getElementById('bwm-ds-radius-preview');
        if (!container) return;

        const base = parseInt(config.radius) || 8;
        const variants = [
            { name: 'radius', value: base + 'px', px: base },
            { name: 'radius-inside', value: Math.floor(base * 0.5) + 'px', px: Math.floor(base * 0.5) },
            { name: 'radius-outside', value: Math.floor(base * 1.4) + 'px', px: Math.floor(base * 1.4) },
            { name: 'radius-btn', value: '.3em', px: 5 },
            { name: 'radius-pill', value: '9999px', px: 30 },
            { name: 'radius-circle', value: '50%', px: 25 },
            { name: 'radius-s', value: Math.floor(base * 0.7) + 'px', px: Math.floor(base * 0.7) },
            { name: 'radius-m', value: base + 'px', px: base },
            { name: 'radius-l', value: Math.floor(base * 1.5) + 'px', px: Math.floor(base * 1.5) },
            { name: 'radius-xl', value: Math.floor(base * 2.25) + 'px', px: Math.floor(base * 2.25) }
        ];

        let html = '<div class="bwm-ds-preview-label">Computed Variants</div><div class="bwm-ds-radius-grid">';
        variants.forEach(v => {
            const r = v.name === 'radius-circle' ? '50%' : Math.min(v.px, 30) + 'px';
            html += `<div class="bwm-ds-radius-item"><div class="bwm-ds-radius-box" style="border-radius:${r}"></div><div class="bwm-ds-radius-name"><code>--${v.name}</code></div><div class="bwm-ds-radius-value">${v.value}</div></div>`;
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function renderSizesPreview() {
        const container = document.getElementById('bwm-ds-sizes-preview');
        if (!container) return;

        const cw = parseInt(config.container_width) || 1280;
        const cm = parseInt(config.container_min) || 380;
        const sizes = [
            ['container-width', cw + 'px'],
            ['container-min-width', cm + 'px'],
            ['max-width', Math.floor(cw * 0.766) + 'px'],
            ['max-width-m', Math.floor(cw * 0.656) + 'px'],
            ['max-width-s', Math.floor(cw * 0.5) + 'px'],
            ['min-height', '340px'],
            ['min-height-section', '540px'],
            ['content-width', 'var(--container-width)']
        ];

        let html = '<div class="bwm-ds-preview-label">Computed Sizes</div><table class="bwm-ds-table"><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody>';
        sizes.forEach(([name, val]) => {
            html += `<tr><td><code>--${name}</code></td><td>${val}</td></tr>`;
        });
        // width-10 through width-90.
        for (let i = 10; i <= 90; i += 10) {
            html += `<tr><td><code>--width-${i}</code></td><td>calc(var(--content-width) * 0.${i / 10})</td></tr>`;
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    function renderAllPreviews() {
        renderColorPreview();
        renderSpacingPreview();
        renderTextPreview();
        renderHeadingsPreview();
        renderRadiusPreview();
        renderSizesPreview();
    }

    // --- Config Management ---

    function setNestedValue(obj, path, value) {
        const keys = path.split('.');
        let current = obj;
        for (let i = 0; i < keys.length - 1; i++) {
            if (!(keys[i] in current) || typeof current[keys[i]] !== 'object') {
                current[keys[i]] = {};
            }
            current = current[keys[i]];
        }
        current[keys[keys.length - 1]] = value;
    }

    function debouncedSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            const formData = new FormData();
            formData.append('action', 'bricks_mcp_ds_save_config');
            formData.append('nonce', DS.nonce);
            formData.append('config', JSON.stringify(config));
            fetch(DS.ajaxUrl, { method: 'POST', body: formData });
        }, 500);
    }

    // --- Event Handlers ---

    function handleInputChange(e) {
        const field = e.target.dataset.field;
        if (!field) return;

        let value;
        if (e.target.type === 'checkbox') {
            value = e.target.checked;
        } else if (e.target.type === 'number') {
            value = parseFloat(e.target.value) || 0;
        } else {
            value = e.target.value;
        }

        setNestedValue(config, field, value);

        // Sync color picker ↔ hex input.
        if (e.target.type === 'color') {
            const hexInput = e.target.parentElement.querySelector('.bwm-ds-hex');
            if (hexInput) hexInput.value = value;
        } else if (e.target.classList.contains('bwm-ds-hex')) {
            const colorInput = e.target.parentElement.querySelector('input[type="color"]');
            if (colorInput && /^#[0-9a-f]{6}$/i.test(value)) colorInput.value = value;
        }

        renderAllPreviews();
        debouncedSave();
    }

    function handleAccordion(e) {
        const header = e.target.closest('.bwm-ds-section-header');
        if (!header) return;

        const section = header.parentElement;
        const body = section.querySelector('.bwm-ds-section-body');
        const toggle = header.querySelector('.bwm-ds-toggle');
        const isOpen = header.classList.contains('bwm-ds-section-open');

        // Close all.
        document.querySelectorAll('.bwm-ds-section-header').forEach(h => {
            h.classList.remove('bwm-ds-section-open');
            h.querySelector('.bwm-ds-toggle').innerHTML = '&#9654;';
            h.parentElement.querySelector('.bwm-ds-section-body').style.display = 'none';
        });

        // Open clicked (if was closed).
        if (!isOpen) {
            header.classList.add('bwm-ds-section-open');
            toggle.innerHTML = '&#9660;';
            body.style.display = 'block';
        }
    }

    function handleApply() {
        const btn = document.getElementById('bwm-ds-apply');
        const status = document.getElementById('bwm-ds-status');
        btn.disabled = true;
        btn.textContent = 'Applying...';

        const formData = new FormData();
        formData.append('action', 'bricks_mcp_ds_apply');
        formData.append('nonce', DS.nonce);
        formData.append('config', JSON.stringify(config));

        fetch(DS.ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.textContent = 'Apply to Site';
                if (res.success) {
                    const d = res.data;
                    status.className = 'bwm-ds-status bwm-ds-status-success';
                    status.textContent = `Applied: ${d.variables_count} variables in ${d.categories} categories, ${d.palette_colors} palette colors, CSS ${d.css_applied ? '\u2713' : '\u2717'}. Last applied: ${d.last_applied}`;
                } else {
                    status.className = 'bwm-ds-status bwm-ds-status-error';
                    status.textContent = 'Error: ' + (res.data || 'Unknown error');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.textContent = 'Apply to Site';
                status.className = 'bwm-ds-status bwm-ds-status-error';
                status.textContent = 'Network error';
            });
    }

    function handleReset(e) {
        e.preventDefault();
        if (!confirm('Reset all design system settings to defaults?')) return;

        const formData = new FormData();
        formData.append('action', 'bricks_mcp_ds_reset');
        formData.append('nonce', DS.nonce);

        fetch(DS.ajaxUrl, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(res => {
                if (res.success) {
                    config = res.data.config;
                    // Update all inputs.
                    document.querySelectorAll('[data-field]').forEach(input => {
                        const path = input.dataset.field;
                        const keys = path.split('.');
                        let val = config;
                        for (const k of keys) {
                            if (val && typeof val === 'object') val = val[k];
                            else { val = undefined; break; }
                        }
                        if (val !== undefined) {
                            if (input.type === 'checkbox') input.checked = !!val;
                            else input.value = val;
                        }
                    });
                    renderAllPreviews();
                }
            });
    }

    // --- Init ---

    function init() {
        // Accordion clicks.
        document.querySelectorAll('.bwm-ds-section-header').forEach(header => {
            header.addEventListener('click', handleAccordion);
        });

        // Input changes (delegated).
        document.querySelector('.bwm-design-system-wrap')?.addEventListener('input', handleInputChange);
        document.querySelector('.bwm-design-system-wrap')?.addEventListener('change', handleInputChange);

        // Apply button.
        document.getElementById('bwm-ds-apply')?.addEventListener('click', handleApply);

        // Reset link.
        document.getElementById('bwm-ds-reset')?.addEventListener('click', handleReset);

        // Show last-applied status.
        if (DS.lastApplied) {
            const status = document.getElementById('bwm-ds-status');
            if (status) {
                status.className = 'bwm-ds-status';
                status.textContent = 'Last applied: ' + DS.lastApplied;
            }
        }

        // Initial render.
        renderAllPreviews();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
