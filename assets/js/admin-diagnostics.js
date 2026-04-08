/**
 * Bricks MCP Diagnostics panel.
 *
 * Handles running diagnostics and copying results via AJAX.
 * Data passed via bricksMcpUpdates global from wp_localize_script.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

(function() {
	'use strict';

	var iconMap = {
		pass:    'dashicons-yes-alt',
		warn:    'dashicons-warning',
		fail:    'dashicons-dismiss',
		skipped: 'dashicons-minus'
	};

	var diagnosticsData = null;

	function escHtml(s) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(s));
		return d.innerHTML;
	}

	var runBtn = document.getElementById('bricks-mcp-run-diagnostics');
	if (runBtn) {
		runBtn.addEventListener('click', function() {
			var btn      = this;
			var spinner  = document.getElementById('bricks-mcp-diagnostics-spinner');
			var results  = document.getElementById('bricks-mcp-diagnostics-results');
			var copyBtn  = document.getElementById('bricks-mcp-copy-results');

			btn.disabled = true;
			spinner.classList.add('is-active');
			results.innerHTML = '';
			copyBtn.style.display = 'none';

			var data = new FormData();
			data.append('action', 'bricks_mcp_run_diagnostics');
			data.append('nonce', bricksMcpUpdates.nonce);

			fetch(bricksMcpUpdates.ajaxUrl, { method: 'POST', body: data })
				.then(function(r) { return r.json(); })
				.then(function(response) {
					btn.disabled = false;
					spinner.classList.remove('is-active');

					if (!response.success) {
						results.innerHTML = '<p style="color:#d63638;">' + (response.data && response.data.message ? escHtml(response.data.message) : bricksMcpDiagnostics.errorText) + '</p>';
						return;
					}

					diagnosticsData = response.data;
					var html = '<p class="bricks-mcp-diagnostics-summary"><strong>' + escHtml(response.data.summary) + '</strong></p>';

					response.data.checks.forEach(function(check) {
						var icon = iconMap[check.status] || 'dashicons-minus';
						var fixHtml = '';
						if (check.fix_steps && check.fix_steps.length > 0) {
							fixHtml = '<div class="bricks-mcp-check-fixes"><strong>' + bricksMcpDiagnostics.howToFixText + '</strong><ul>';
							check.fix_steps.forEach(function(step) {
								fixHtml += '<li>' + escHtml(step) + '</li>';
							});
							fixHtml += '</ul></div>';
						}
						html += '<div class="bricks-mcp-check bricks-mcp-check--' + check.status + '" role="listitem">';
						html += '<span class="dashicons ' + icon + '" role="img" aria-label="' + escHtml(check.status) + '"></span>';
						html += '<div class="bricks-mcp-check-content">';
						html += '<strong>' + escHtml(check.label) + '</strong>';
						html += '<p>' + escHtml(check.message) + '</p>';
						html += fixHtml;
						html += '</div></div>';
					});

					results.innerHTML = html;
					copyBtn.style.display = 'inline-block';
				})
				.catch(function() {
					btn.disabled = false;
					spinner.classList.remove('is-active');
					results.innerHTML = '<p style="color:#d63638;">' + bricksMcpDiagnostics.requestFailedText + '</p>';
				});
		});
	}

	var copyBtn = document.getElementById('bricks-mcp-copy-results');
	if (copyBtn) {
		copyBtn.addEventListener('click', function() {
			if (!diagnosticsData) return;
			var btn = this;
			var text = '';
			diagnosticsData.checks.forEach(function(check) {
				text += '[' + check.status.toUpperCase() + '] ' + check.label + ': ' + check.message + '\n';
				if (check.fix_steps && check.fix_steps.length > 0) {
					check.fix_steps.forEach(function(step) {
						text += '  Fix: ' + step + '\n';
					});
				}
			});
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(function() {
					btn.textContent = bricksMcpDiagnostics.copiedText;
					setTimeout(function() {
						btn.textContent = bricksMcpDiagnostics.copyResultsText;
					}, 2000);
				}).catch(function() {
					fallbackCopy(text, btn);
				});
			} else {
				fallbackCopy(text, btn);
			}
		});
	}
	function fallbackCopy(text, btn) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
			btn.textContent = bricksMcpDiagnostics.copiedText;
		} catch (e) {
			btn.textContent = 'Copy failed';
		}
		document.body.removeChild(ta);
		setTimeout(function() {
			btn.textContent = bricksMcpDiagnostics.copyResultsText;
		}, 2000);
	}
}());
