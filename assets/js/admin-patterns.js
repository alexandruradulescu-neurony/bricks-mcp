/**
 * Bricks MCP — Patterns admin tab.
 *
 * AJAX interactions for listing, creating, deleting, exporting, importing patterns.
 */
(function ($) {
  'use strict';

  if (typeof bricksMcpPatterns === 'undefined') return;
  var nonce = bricksMcpPatterns.nonce;

  // ── Filters ──────────────────────────────────────

  function applyFilters() {
    var cat = $('#bricks-mcp-pattern-filter-category').val();
    var src = $('#bricks-mcp-pattern-filter-source').val();
    var visible = 0;

    $('#bricks-mcp-patterns-table tbody tr').each(function () {
      var row = $(this);
      var matchCat = !cat || row.data('category') === cat;
      var matchSrc = !src || row.data('source') === src;
      if (matchCat && matchSrc) {
        row.show();
        visible++;
      } else {
        row.hide();
      }
    });

    $('.bwm-patterns-count').text(visible + ' patterns shown');
  }

  $(document).on('change', '#bricks-mcp-pattern-filter-category, #bricks-mcp-pattern-filter-source', applyFilters);

  // ── Select all ───────────────────────────────────

  $(document).on('change', '#bricks-mcp-patterns-select-all', function () {
    var checked = $(this).prop('checked');
    $('.bricks-mcp-pattern-select:visible').prop('checked', checked);
  });

  // ── View pattern (modal) ─────────────────────────

  $(document).on('click', '.bricks-mcp-view-pattern', function () {
    var id = $(this).data('id');
    var source = $(this).closest('tr').data('source');

    $.post(ajaxurl, { action: 'bricks_mcp_list_patterns', nonce: nonce }, function (res) {
      if (!res.success) return;
      var pattern = res.data.find(function (p) { return p.id === id; });
      if (!pattern) return alert('Pattern not found.');

      $('#bricks-mcp-modal-title').text(pattern.name + ' (' + (pattern.source || 'plugin') + ')');
      $('#bricks-mcp-pattern-json').val(JSON.stringify(pattern, null, 2));

      if (source === 'database') {
        $('#bricks-mcp-pattern-json').prop('readonly', false);
        $('#bricks-mcp-save-pattern').show().data('id', id);
      } else {
        $('#bricks-mcp-pattern-json').prop('readonly', true);
        $('#bricks-mcp-save-pattern').hide();
      }

      $('#bricks-mcp-pattern-modal').fadeIn(150);
    });
  });

  // ── Save edited pattern ──────────────────────────

  $(document).on('click', '#bricks-mcp-save-pattern', function () {
    var btn = $(this);
    var json = $('#bricks-mcp-pattern-json').val();
    try { JSON.parse(json); } catch (e) { return alert('Invalid JSON: ' + e.message); }

    btn.prop('disabled', true).text('Saving...');

    // Delete old, create new (simple replace for DB patterns).
    var id = btn.data('id');
    $.post(ajaxurl, { action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }, function () {
      $.post(ajaxurl, { action: 'bricks_mcp_create_pattern', nonce: nonce, pattern_json: json }, function (res) {
        btn.prop('disabled', false).text('Save Changes');
        if (res.success) {
          alert('Pattern saved.');
          location.reload();
        } else {
          alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown'));
        }
      });
    });
  });

  // ── Close modals ─────────────────────────────────

  $(document).on('click', '.bwm-modal-close, .bwm-modal-backdrop', function () {
    $(this).closest('[id$="-modal"]').fadeOut(150);
  });

  // ── Delete / Hide pattern ────────────────────────

  $(document).on('click', '.bricks-mcp-delete-pattern, .bricks-mcp-hide-pattern', function () {
    var id = $(this).data('id');
    var isHide = $(this).hasClass('bricks-mcp-hide-pattern');
    var msg = isHide
      ? 'Hide pattern "' + id + '" from all lists? (It can be restored later.)'
      : 'Delete pattern "' + id + '"? This cannot be undone.';

    if (!confirm(msg)) return;

    $.post(ajaxurl, { action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }, function (res) {
      if (res.success) {
        $('tr[data-pattern-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
      } else {
        alert('Error: ' + (res.data && res.data.message ? res.data.message : 'Unknown'));
      }
    });
  });

  // ── Export patterns ──────────────────────────────

  $(document).on('click', '#bricks-mcp-export-patterns', function () {
    var selected = [];
    $('.bricks-mcp-pattern-select:checked').each(function () {
      selected.push($(this).val());
    });

    var data = { action: 'bricks_mcp_export_patterns', nonce: nonce };
    if (selected.length) {
      data.pattern_ids = selected;
    }

    $.post(ajaxurl, data, function (res) {
      if (!res.success) return alert('Export failed.');

      var blob = new Blob([JSON.stringify(res.data.patterns, null, 2)], { type: 'application/json' });
      var url = URL.createObjectURL(blob);
      var a = document.createElement('a');
      a.href = url;
      a.download = 'bricks-mcp-patterns-' + new Date().toISOString().slice(0, 10) + '.json';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });
  });

  // ── Import patterns ──────────────────────────────

  $(document).on('click', '#bricks-mcp-import-patterns-btn', function () {
    $('#bricks-mcp-import-file').trigger('click');
  });

  $(document).on('change', '#bricks-mcp-import-file', function () {
    var file = this.files[0];
    if (!file) return;

    var reader = new FileReader();
    reader.onload = function (e) {
      var json = e.target.result;
      try {
        var parsed = JSON.parse(json);
        if (!Array.isArray(parsed)) {
          alert('JSON must be an array of pattern objects.');
          return;
        }
      } catch (err) {
        alert('Invalid JSON file: ' + err.message);
        return;
      }

      if (!confirm('Import ' + parsed.length + ' pattern(s)?')) return;

      $.post(ajaxurl, { action: 'bricks_mcp_import_patterns', nonce: nonce, patterns_json: json }, function (res) {
        if (res.success) {
          var imported = res.data.imported || [];
          var errors = res.data.errors || [];
          alert('Imported: ' + imported.length + ', Errors: ' + errors.length);
          location.reload();
        } else {
          alert('Import failed: ' + (res.data && res.data.message ? res.data.message : 'Unknown'));
        }
      });
    };
    reader.readAsText(file);

    // Reset file input so the same file can be re-imported.
    $(this).val('');
  });

})(jQuery);
