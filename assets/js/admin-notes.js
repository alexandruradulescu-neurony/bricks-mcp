/**
 * Bricks MCP AI Notes management.
 *
 * Handles add/delete of persistent AI correction notes via AJAX.
 * Data passed via bricksMcpNotes global from wp_localize_script.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

(function() {
	'use strict';

	var nonce = bricksMcpNotes.nonce;

	function escHtml(s) {
		var d = document.createElement('div');
		d.appendChild(document.createTextNode(s));
		return d.innerHTML;
	}

	function attachDeleteHandler(btn) {
		btn.addEventListener('click', function() {
			var noteId = this.getAttribute('data-id');
			var row = this.closest('tr');
			if (!confirm('Delete this note?')) return;
			btn.disabled = true;
			btn.textContent = '...';
			fetch(ajaxurl, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=bricks_mcp_delete_note&note_id=' + encodeURIComponent(noteId) + '&_wpnonce=' + encodeURIComponent(nonce)
			}).then(function(r) { return r.json(); }).then(function(data) {
				if (data.success) row.remove();
				else { btn.disabled = false; btn.textContent = 'Delete'; alert(data.data || 'Error'); }
			});
		});
	}

	document.querySelectorAll('.bricks-mcp-delete-note').forEach(attachDeleteHandler);

	var addBtn = document.getElementById('bricks-mcp-add-note-btn');
	if (addBtn) {
		addBtn.addEventListener('click', function() {
			var input = document.getElementById('bricks-mcp-note-text');
			var text = input.value.trim();
			if (!text) return;
			addBtn.disabled = true;
			fetch(ajaxurl, {
				method: 'POST',
				headers: {'Content-Type': 'application/x-www-form-urlencoded'},
				body: 'action=bricks_mcp_add_note&text=' + encodeURIComponent(text) + '&_wpnonce=' + encodeURIComponent(nonce)
			}).then(function(r) { return r.json(); }).then(function(data) {
				addBtn.disabled = false;
				if (data.success) {
					var note = data.data;
					var tbody = document.querySelector('#bricks-mcp-notes-table tbody');
					var noNotes = tbody.querySelector('.bricks-mcp-no-notes');
					if (noNotes) noNotes.remove();
					var tr = document.createElement('tr');
					tr.setAttribute('data-note-id', note.id);
					tr.innerHTML = '<td>' + escHtml(note.text) + '</td><td>' + escHtml(note.created_at) + '</td><td><button type="button" class="button button-small bricks-mcp-delete-note" data-id="' + escHtml(note.id) + '">Delete</button></td>';
					tbody.appendChild(tr);
					attachDeleteHandler(tr.querySelector('.bricks-mcp-delete-note'));
					input.value = '';
				} else { alert(data.data || 'Error'); }
			});
		});
	}
})();
