/**
 * Bricks MCP — Patterns admin tab.
 *
 * Full CRUD for patterns + categories. Structured creator form,
 * inline category editing, export/import, WP Media integration.
 */
(function ($) {
  'use strict';

  if (typeof bricksMcpPatterns === 'undefined') return;
  var nonce = bricksMcpPatterns.nonce;

  // ── Helpers ──────────────────────────────────────

  function slugify(str) {
    return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  function showNotice(msg, type) {
    var cls = type === 'error' ? 'notice-error' : 'notice-success';
    var $n = $('<div class="notice ' + cls + ' is-dismissible"><p>' + msg + '</p></div>');
    $('.bricks-mcp-config-section').first().before($n);
    setTimeout(function () { $n.fadeOut(300, function () { $(this).remove(); }); }, 4000);
  }

  // ── Pattern Filters ──────────────────────────────

  function applyFilters() {
    var cat = $('#bricks-mcp-pattern-filter-category').val();
    var src = $('#bricks-mcp-pattern-filter-source').val();
    var visible = 0;
    $('#bricks-mcp-patterns-table tbody tr').each(function () {
      var row = $(this);
      var matchCat = !cat || row.data('category') === cat;
      var matchSrc = !src || row.data('source') === src;
      row.toggle(matchCat && matchSrc);
      if (matchCat && matchSrc) visible++;
    });
    $('.bwm-patterns-count').text(visible + ' patterns shown');
  }

  $(document).on('change', '#bricks-mcp-pattern-filter-category, #bricks-mcp-pattern-filter-source', applyFilters);

  // ── Select all ───────────────────────────────────

  $(document).on('change', '#bricks-mcp-patterns-select-all', function () {
    $('.bricks-mcp-pattern-select:visible').prop('checked', $(this).prop('checked'));
  });

  // ── Close modals ─────────────────────────────────

  $(document).on('click', '.bwm-modal-close, .bwm-modal-backdrop', function () {
    $(this).closest('[id$="-modal"]').fadeOut(150);
  });

  // ── View pattern (JSON modal, read-only for plugin/user) ────

  $(document).on('click', '.bricks-mcp-view-pattern', function () {
    var id = $(this).data('id');
    var source = $(this).closest('tr').data('source');

    $.post(ajaxurl, { action: 'bricks_mcp_list_patterns', nonce: nonce }, function (res) {
      if (!res.success) return;
      var pattern = res.data.find(function (p) { return p.id === id; });
      if (!pattern) return showNotice('Pattern not found.', 'error');

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

  // ── Save edited pattern (JSON modal) ─────────────

  $(document).on('click', '#bricks-mcp-save-pattern', function () {
    var btn = $(this);
    var json = $('#bricks-mcp-pattern-json').val();
    try { JSON.parse(json); } catch (e) { return showNotice('Invalid JSON: ' + e.message, 'error'); }

    btn.prop('disabled', true).text('Saving...');
    var id = btn.data('id');

    $.post(ajaxurl, { action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }, function () {
      $.post(ajaxurl, { action: 'bricks_mcp_create_pattern', nonce: nonce, pattern_json: json }, function (res) {
        btn.prop('disabled', false).text('Save Changes');
        if (res.success) { location.reload(); }
        else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
      });
    });
  });

  // ── Delete / Hide pattern ────────────────────────

  $(document).on('click', '.bricks-mcp-delete-pattern, .bricks-mcp-hide-pattern', function () {
    var id = $(this).data('id');
    var isHide = $(this).hasClass('bricks-mcp-hide-pattern');
    var msg = isHide
      ? 'Hide pattern "' + id + '" from all lists?'
      : 'Delete pattern "' + id + '"? This cannot be undone.';
    if (!confirm(msg)) return;

    $.post(ajaxurl, { action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }, function (res) {
      if (res.success) {
        $('tr[data-pattern-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
        showNotice('Pattern ' + (isHide ? 'hidden' : 'deleted') + '.', 'success');
      } else {
        showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error');
      }
    });
  });

  // ── Export patterns ──────────────────────────────

  $(document).on('click', '#bricks-mcp-export-patterns', function () {
    var selected = [];
    $('.bricks-mcp-pattern-select:checked').each(function () { selected.push($(this).val()); });

    var data = { action: 'bricks_mcp_export_patterns', nonce: nonce };
    if (selected.length) data.pattern_ids = selected;

    $.post(ajaxurl, data, function (res) {
      if (!res.success) return showNotice('Export failed.', 'error');
      var blob = new Blob([JSON.stringify(res.data.patterns, null, 2)], { type: 'application/json' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'bricks-mcp-patterns-' + new Date().toISOString().slice(0, 10) + '.json';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
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
      try { var parsed = JSON.parse(json); if (!Array.isArray(parsed)) throw new Error('not array'); }
      catch (err) { return showNotice('Invalid JSON: must be an array of pattern objects.', 'error'); }
      if (!confirm('Import ' + parsed.length + ' pattern(s)?')) return;
      $.post(ajaxurl, { action: 'bricks_mcp_import_patterns', nonce: nonce, patterns_json: json }, function (res) {
        if (res.success) { showNotice('Imported ' + (res.data.imported || []).length + ' pattern(s).', 'success'); location.reload(); }
        else { showNotice('Import failed: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
      });
    };
    reader.readAsText(file);
    $(this).val('');
  });

  // ══════════════════════════════════════════════════
  // PATTERN CREATOR FORM
  // ══════════════════════════════════════════════════

  // Open creator modal.
  $(document).on('click', '#bricks-mcp-add-pattern-btn', function () {
    // Reset form.
    $('#bricks-mcp-creator-id, #bricks-mcp-creator-name, #bricks-mcp-creator-tags, #bricks-mcp-creator-ai-desc, #bricks-mcp-creator-ai-hints, #bricks-mcp-creator-composition').val('');
    $('#bricks-mcp-creator-category, #bricks-mcp-creator-layout, #bricks-mcp-creator-bg').prop('selectedIndex', 0);
    $('#bricks-mcp-creator-image-preview').attr('src', '').hide();
    $('#bricks-mcp-creator-image-id').val('');
    $('#bricks-mcp-creator-modal-title').text('Add New Pattern');
    $('#bricks-mcp-creator-save').data('mode', 'create').data('original-id', '');
    $('#bricks-mcp-creator-modal').fadeIn(150);
  });

  // Auto-slug ID from name.
  $(document).on('input', '#bricks-mcp-creator-name', function () {
    var $id = $('#bricks-mcp-creator-id');
    if (!$id.data('manual')) {
      $id.val(slugify($(this).val()));
    }
  });
  $(document).on('input', '#bricks-mcp-creator-id', function () {
    $(this).data('manual', $(this).val() !== '');
  });

  // AI description char counter.
  $(document).on('input', '#bricks-mcp-creator-ai-desc', function () {
    var len = $(this).val().length;
    $('#bricks-mcp-creator-char-count').text(len + '/300');
  });

  // WP Media Library for reference image.
  $(document).on('click', '#bricks-mcp-creator-upload-image', function (e) {
    e.preventDefault();
    if (typeof wp === 'undefined' || !wp.media) return showNotice('Media library not available.', 'error');

    var frame = wp.media({ title: 'Select Pattern Reference Image', multiple: false, library: { type: 'image' } });
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      $('#bricks-mcp-creator-image-id').val(attachment.id);
      $('#bricks-mcp-creator-image-preview').attr('src', attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url).show();
    });
    frame.open();
  });

  // Save pattern from creator form.
  $(document).on('click', '#bricks-mcp-creator-save', function () {
    var btn = $(this);
    var mode = btn.data('mode') || 'create';
    var originalId = btn.data('original-id') || '';

    var pattern = {
      id: $('#bricks-mcp-creator-id').val().trim(),
      name: $('#bricks-mcp-creator-name').val().trim(),
      category: $('#bricks-mcp-creator-category').val(),
      tags: $('#bricks-mcp-creator-tags').val().split(',').map(function (t) { return t.trim(); }).filter(Boolean),
      layout: $('#bricks-mcp-creator-layout').val(),
      background: $('#bricks-mcp-creator-bg').val() || 'light',
      ai_description: $('#bricks-mcp-creator-ai-desc').val().trim(),
      ai_usage_hints: $('#bricks-mcp-creator-ai-hints').val().split('\n').map(function (h) { return h.trim(); }).filter(Boolean),
    };

    // Image ID.
    var imageId = $('#bricks-mcp-creator-image-id').val();
    if (imageId) pattern.image_id = parseInt(imageId, 10);

    // Composition JSON.
    var compJson = $('#bricks-mcp-creator-composition').val().trim();
    if (compJson) {
      try { var comp = JSON.parse(compJson); pattern.composition = comp; }
      catch (e) { return showNotice('Invalid Composition JSON: ' + e.message, 'error'); }
    }

    if (!pattern.id || !pattern.name || !pattern.category) {
      return showNotice('ID, Name, and Category are required.', 'error');
    }

    btn.prop('disabled', true).text('Saving...');

    var doCreate = function () {
      $.post(ajaxurl, { action: 'bricks_mcp_create_pattern', nonce: nonce, pattern_json: JSON.stringify(pattern) }, function (res) {
        btn.prop('disabled', false).text('Save Pattern');
        if (res.success) { showNotice('Pattern saved!', 'success'); location.reload(); }
        else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
      });
    };

    if (mode === 'edit' && originalId) {
      // Delete old, create new.
      $.post(ajaxurl, { action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: originalId }, function () { doCreate(); });
    } else {
      doCreate();
    }
  });

  // ── Edit pattern via creator form ────────────────

  $(document).on('click', '.bricks-mcp-edit-pattern', function () {
    var id = $(this).data('id');

    $.post(ajaxurl, { action: 'bricks_mcp_list_patterns', nonce: nonce }, function (res) {
      if (!res.success) return;
      var p = res.data.find(function (x) { return x.id === id; });
      if (!p) return showNotice('Pattern not found.', 'error');

      $('#bricks-mcp-creator-id').val(p.id).data('manual', true);
      $('#bricks-mcp-creator-name').val(p.name || '');
      $('#bricks-mcp-creator-category').val(p.category || '');
      $('#bricks-mcp-creator-tags').val((p.tags || []).join(', '));
      $('#bricks-mcp-creator-layout').val(p.layout || '');
      $('#bricks-mcp-creator-bg').val(p.background || 'light');
      $('#bricks-mcp-creator-ai-desc').val(p.ai_description || '');
      $('#bricks-mcp-creator-ai-hints').val((p.ai_usage_hints || []).join('\n'));
      $('#bricks-mcp-creator-char-count').text((p.ai_description || '').length + '/300');

      // Composition.
      var comp = {};
      ['composition', 'columns', 'patterns', 'rows', 'section_overrides', 'container_overrides', 'gradient_overlay', 'has_two_rows'].forEach(function (k) {
        if (p[k] !== undefined) comp[k] = p[k];
      });
      if (Object.keys(comp).length) {
        $('#bricks-mcp-creator-composition').val(JSON.stringify(comp, null, 2));
      } else {
        $('#bricks-mcp-creator-composition').val('');
      }

      $('#bricks-mcp-creator-modal-title').text('Edit Pattern: ' + p.name);
      $('#bricks-mcp-creator-save').data('mode', 'edit').data('original-id', id);
      $('#bricks-mcp-creator-modal').fadeIn(150);
    });
  });

  // ══════════════════════════════════════════════════
  // CATEGORY MANAGEMENT
  // ══════════════════════════════════════════════════

  // Add category.
  $(document).on('click', '#bricks-mcp-add-category-btn', function () {
    var name = $('#bricks-mcp-new-category-name').val().trim();
    var desc = $('#bricks-mcp-new-category-desc').val().trim();
    if (!name) return showNotice('Category name is required.', 'error');

    $.post(ajaxurl, { action: 'bricks_mcp_create_category', nonce: nonce, name: name, description: desc }, function (res) {
      if (res.success) { showNotice('Category created.', 'success'); location.reload(); }
      else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
    });
  });

  // Delete category.
  $(document).on('click', '.bricks-mcp-delete-category', function () {
    var id = $(this).data('id');
    var count = $(this).data('count') || 0;
    var msg = 'Delete category "' + id + '"?';
    if (count > 0) msg += ' (' + count + ' pattern(s) will become uncategorized)';
    if (!confirm(msg)) return;

    $.post(ajaxurl, { action: 'bricks_mcp_delete_category', nonce: nonce, category_id: id }, function (res) {
      if (res.success) {
        $('tr[data-category-id="' + id + '"]').fadeOut(300, function () { $(this).remove(); });
        showNotice('Category deleted.', 'success');
      } else {
        showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error');
      }
    });
  });

  // Inline edit category.
  $(document).on('click', '.bricks-mcp-edit-category', function () {
    var $row = $(this).closest('tr');
    var id = $row.data('category-id');
    $row.find('.bwm-cat-display').hide();
    $row.find('.bwm-cat-edit').show();
    $(this).hide();
    $row.find('.bricks-mcp-save-category, .bricks-mcp-cancel-edit-category').show();
  });

  $(document).on('click', '.bricks-mcp-cancel-edit-category', function () {
    var $row = $(this).closest('tr');
    $row.find('.bwm-cat-display').show();
    $row.find('.bwm-cat-edit').hide();
    $row.find('.bricks-mcp-edit-category').show();
    $row.find('.bricks-mcp-save-category, .bricks-mcp-cancel-edit-category').hide();
  });

  $(document).on('click', '.bricks-mcp-save-category', function () {
    var $row = $(this).closest('tr');
    var id = $row.data('category-id');
    var name = $row.find('.bwm-cat-edit-name').val().trim();
    var desc = $row.find('.bwm-cat-edit-desc').val().trim();

    $.post(ajaxurl, { action: 'bricks_mcp_update_category', nonce: nonce, category_id: id, name: name, description: desc }, function (res) {
      if (res.success) { location.reload(); }
      else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
    });
  });

})(jQuery);
