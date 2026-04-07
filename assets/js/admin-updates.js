/**
 * Bricks MCP Admin Updates & Onboarding JS.
 *
 * Handles: tab switching, copy to clipboard, Check Now AJAX, Test Connection AJAX.
 * Data passed via bricksMcpUpdates global from wp_localize_script.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

(function() {
	'use strict';

	// -------------------------------------------------------------------------
	// Tab switching (event delegation)
	// -------------------------------------------------------------------------

	document.addEventListener('click', function(e) {
		var tab = e.target.closest('[data-tab]');
		if (!tab) {
			return;
		}

		var container = tab.closest('.bricks-mcp-tabs');
		if (!container) {
			return;
		}

		var target = tab.getAttribute('data-tab');

		// Toggle active state on tab buttons.
		container.querySelectorAll('[data-tab]').forEach(function(t) {
			if (t === tab) {
				t.classList.add('active');
				t.style.borderBottomColor = '#2271b1';
				t.style.color = '';
				t.setAttribute('aria-selected', 'true');
				t.setAttribute('tabindex', '0');
			} else {
				t.classList.remove('active');
				t.style.borderBottomColor = 'transparent';
				t.style.color = '#666';
				t.setAttribute('aria-selected', 'false');
				t.setAttribute('tabindex', '-1');
			}
		});

		// Show/hide panels.
		container.querySelectorAll('[data-panel]').forEach(function(p) {
			p.style.display = p.getAttribute('data-panel') === target ? '' : 'none';
		});
	});

	// -------------------------------------------------------------------------
	// Tab keyboard navigation (WAI-ARIA pattern)
	// -------------------------------------------------------------------------

	document.addEventListener('keydown', function(e) {
		var tab = e.target.closest('[data-tab]');
		if (!tab) {
			return;
		}

		var container = tab.closest('.bricks-mcp-tabs');
		if (!container) {
			return;
		}

		var tabs = Array.from(container.querySelectorAll('[data-tab]'));
		var currentIndex = tabs.indexOf(tab);
		var targetIndex = -1;

		switch (e.key) {
			case 'ArrowRight':
				targetIndex = (currentIndex + 1) % tabs.length;
				break;
			case 'ArrowLeft':
				targetIndex = (currentIndex - 1 + tabs.length) % tabs.length;
				break;
			case 'Home':
				targetIndex = 0;
				break;
			case 'End':
				targetIndex = tabs.length - 1;
				break;
			default:
				return;
		}

		e.preventDefault();
		var targetTab = tabs[targetIndex];
		targetTab.focus();
		targetTab.click();
	});

	// -------------------------------------------------------------------------
	// Copy to clipboard (event delegation on .bricks-mcp-copy-btn)
	// -------------------------------------------------------------------------

	/**
	 * Copy text to clipboard with button feedback.
	 *
	 * @param {string}      text   Text to copy.
	 * @param {HTMLElement}  button Button element for feedback.
	 */
	function copyToClipboard(text, button) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function() {
				showCopyFeedback(button);
			}).catch(function() {
				fallbackCopy(text, button);
			});
		} else {
			fallbackCopy(text, button);
		}
	}

	/**
	 * Fallback copy using hidden textarea.
	 *
	 * @param {string}      text   Text to copy.
	 * @param {HTMLElement}  button Button element for feedback.
	 */
	function fallbackCopy(text, button) {
		var textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild(textarea);
		textarea.select();
		document.execCommand('copy');
		document.body.removeChild(textarea);
		showCopyFeedback(button);
	}

	/**
	 * Show "Copied!" feedback on button for 2 seconds.
	 *
	 * @param {HTMLElement} button Button element.
	 */
	function showCopyFeedback(button) {
		var original = button.textContent;
		button.textContent = 'Copied!';
		setTimeout(function() {
			button.textContent = original;
		}, 2000);
	}

	document.addEventListener('click', function(e) {
		var btn = e.target.closest('.bricks-mcp-copy-btn');
		if (!btn) {
			return;
		}

		var targetId = btn.getAttribute('data-target');
		var targetClass = btn.getAttribute('data-target-class');
		var codeEl;

		if (targetId) {
			codeEl = document.getElementById(targetId);
		} else if (targetClass) {
			// Find the element within the same parent code-wrap container.
			codeEl = btn.closest('.bricks-mcp-code-wrap').querySelector('.' + targetClass);
		}

		if (!codeEl) {
			return;
		}

		copyToClipboard(codeEl.textContent, btn);
	});

	// -------------------------------------------------------------------------
	// Check Now button
	// -------------------------------------------------------------------------

	function initCheckNow() {
		var btn = document.getElementById('bricks-mcp-check-update-btn');
		var spinner = document.getElementById('bricks-mcp-check-update-spinner');
		var versionText = document.getElementById('bricks-mcp-version-text');
		var versionCard = document.querySelector('.bricks-mcp-version-card');

		if (!btn) {
			return;
		}

		btn.addEventListener('click', function() {
			btn.disabled = true;
			if (spinner) {
				spinner.classList.add('is-active');
			}

			var formData = new FormData();
			formData.append('action', 'bricks_mcp_check_update');
			formData.append('nonce', bricksMcpUpdates.nonce);

			fetch(bricksMcpUpdates.ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(response) {
				return response.json();
			})
			.then(function(data) {
				if (data.success && data.data && versionText) {
					var remoteVersion = data.data.version || '';
					var currentVersion = bricksMcpUpdates.currentVersion;
					var html = '<strong>v' + escapeHtml(currentVersion) + '</strong>';

					if (remoteVersion && isNewer(currentVersion, remoteVersion)) {
						html += ' &mdash; <span style="color:#dba617;font-weight:600;">v' +
							escapeHtml(remoteVersion) + ' available</span> ' +
							'<a href="' + escapeAttr(bricksMcpUpdates.updateCoreUrl) + '">Update</a>';
						if (versionCard) {
							versionCard.style.borderLeftColor = '#dba617';
						}
					} else {
						html += ' &mdash; <span style="color:#00a32a;">up to date</span>';
						if (versionCard) {
							versionCard.style.borderLeftColor = '#2271b1';
						}
					}

					versionText.innerHTML = html;
				}
			})
			.catch(function() {
				// Silent fail — button re-enables regardless.
			})
			.finally(function() {
				btn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
			});
		});
	}

	// -------------------------------------------------------------------------
	// Test Connection button
	// -------------------------------------------------------------------------

	function initTestConnection() {
		var btn = document.getElementById('bricks-mcp-test-connection-btn');
		var spinner = document.getElementById('bricks-mcp-test-spinner');
		var resultDiv = document.getElementById('bricks-mcp-test-result');

		if (!btn) {
			return;
		}

		btn.addEventListener('click', function() {
			var usernameInput = document.getElementById('bricks-mcp-test-username');
			var passwordInput = document.getElementById('bricks-mcp-test-app-password');

			var username = usernameInput ? usernameInput.value.trim() : '';
			var appPassword = passwordInput ? passwordInput.value.trim() : '';

			if (!appPassword) {
				if (resultDiv) {
					resultDiv.innerHTML = '<span style="color:#d63638;">Please enter an Application Password.</span>';
				}
				return;
			}

			btn.disabled = true;
			if (spinner) {
				spinner.classList.add('is-active');
			}
			if (resultDiv) {
				resultDiv.innerHTML = '';
			}

			var formData = new FormData();
			formData.append('action', 'bricks_mcp_test_connection');
			formData.append('nonce', bricksMcpUpdates.nonce);
			formData.append('username', username);
			formData.append('app_password', appPassword);

			fetch(bricksMcpUpdates.ajaxUrl, {
				method: 'POST',
				body: formData
			})
			.then(function(response) {
				return response.json();
			})
			.then(function(data) {
				if (resultDiv) {
					if (data.success) {
						resultDiv.innerHTML = '<span style="color:#00a32a;font-weight:600;">' +
							escapeHtml(data.data.message) + '</span>';
					} else {
						var message = (data.data && data.data.message) ? data.data.message : 'Connection test failed.';
						resultDiv.innerHTML = '<span style="color:#d63638;">' +
							escapeHtml(message) + '</span>';
					}
				}
			})
			.catch(function() {
				if (resultDiv) {
					resultDiv.innerHTML = '<span style="color:#d63638;">Network error. Please try again.</span>';
				}
			})
			.finally(function() {
				btn.disabled = false;
				if (spinner) {
					spinner.classList.remove('is-active');
				}
			});
		});
	}

	// -------------------------------------------------------------------------
	// Generate Setup Command button
	// -------------------------------------------------------------------------

	function initGenerateCommand() {
		var buttons = document.querySelectorAll('.bricks-mcp-generate-for-client');

		if (!buttons.length) {
			return;
		}

		// Map client keys to their AJAX response data keys.
		var configMap = {
			'claude':         { command: 'claude_command',  config: 'claude_config' },
			'claude-desktop': { config: 'claude_desktop_config' },
			'gemini':         { command: 'gemini_command',  config: 'gemini_config' },
			'cursor':         { config: 'cursor_config' },
			'vscode':         { config: 'vscode_config' },
			'augment':        { config: 'augment_config' },
			'qwen':           { config: 'qwen_config' }
		};

		buttons.forEach(function(btn) {
			btn.addEventListener('click', function() {
				var client = btn.getAttribute('data-client');
				var tabPanel = btn.closest('[role="tabpanel"]');
				var spinner = tabPanel.querySelector('.bricks-mcp-tab-generate .spinner');
				var resultDiv = tabPanel.querySelector('.bricks-mcp-generated-for-client');

				btn.disabled = true;
				if (spinner) {
					spinner.classList.add('is-active');
				}

				var formData = new FormData();
				formData.append('action', 'bricks_mcp_generate_app_password');
				formData.append('nonce', bricksMcpUpdates.nonce);

				fetch(bricksMcpUpdates.ajaxUrl, {
					method: 'POST',
					body: formData
				})
				.then(function(response) {
					return response.json();
				})
				.then(function(data) {
					if (spinner) {
						spinner.classList.remove('is-active');
					}

					if (data.success && data.data) {
						var clientMap = configMap[client];
						if (!clientMap) {
							btn.disabled = false;
							return;
						}

						// Populate one-liner command (Claude Code & Gemini only).
						var commandEl = resultDiv.querySelector('.bricks-mcp-gen-command');
						if (commandEl) {
							var commandWrap = commandEl.closest('.bricks-mcp-code-wrap');
							var commandHeading = commandWrap ? commandWrap.previousElementSibling : null;
							if (clientMap.command && data.data[clientMap.command]) {
								commandEl.textContent = data.data[clientMap.command];
								if (commandWrap) {
									commandWrap.style.display = '';
								}
								if (commandHeading && commandHeading.tagName === 'H4') {
									commandHeading.style.display = '';
								}
							} else {
								if (commandWrap) {
									commandWrap.style.display = 'none';
								}
								if (commandHeading && commandHeading.tagName === 'H4') {
									commandHeading.style.display = 'none';
								}
							}
						}

						// Populate JSON config.
						var configEl = resultDiv.querySelector('.bricks-mcp-gen-config');
						if (configEl && clientMap.config && data.data[clientMap.config]) {
							configEl.textContent = data.data[clientMap.config];
						}

						// Show the result container.
						resultDiv.style.display = 'block';
						resultDiv.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

						// Disable the button permanently (password is one-time).
						btn.textContent = 'Generated';
						btn.disabled = true;
						btn.classList.remove('button-primary');
					} else {
						var message = (data.data && data.data.message) ? data.data.message : 'Failed to generate Application Password.';
						alert(escapeHtml(message));
						btn.disabled = false;
					}
				})
				.catch(function() {
					if (spinner) {
						spinner.classList.remove('is-active');
					}
					alert('Network error. Please try again.');
					btn.disabled = false;
				});
			});
		});
	}

	// -------------------------------------------------------------------------
	// Utility functions
	// -------------------------------------------------------------------------

	/**
	 * Compare two version strings. Returns true if remote is newer.
	 *
	 * @param {string} current Current version string.
	 * @param {string} remote  Remote version string.
	 * @returns {boolean}
	 */
	function isNewer(current, remote) {
		var a = current.split('.').map(Number);
		var b = remote.split('.').map(Number);
		var len = Math.max(a.length, b.length);

		for (var i = 0; i < len; i++) {
			var av = a[i] || 0;
			var bv = b[i] || 0;
			if (bv > av) {
				return true;
			}
			if (bv < av) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Escape HTML entities.
	 *
	 * @param {string} str String to escape.
	 * @returns {string}
	 */
	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

	/**
	 * Escape a string for use in an HTML attribute.
	 *
	 * @param {string} str String to escape.
	 * @returns {string}
	 */
	function escapeAttr(str) {
		return str
			.replace(/&/g, '&amp;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;');
	}

	// -------------------------------------------------------------------------
	// Initialize
	// -------------------------------------------------------------------------

	function init() {
		initCheckNow();
		initTestConnection();
		initGenerateCommand();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
