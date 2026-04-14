/**
 * Bricks MCP — Patterns admin tab.
 *
 * Full CRUD for patterns + categories. Structured creator form,
 * inline category editing, export/import, WP Media integration.
 */
(function () {
  'use strict';

  if (typeof bricksMcpPatterns === 'undefined') return;
  var nonce = bricksMcpPatterns.nonce;

  // -- Helpers -------------------------------------------------

  function slugify(str) {
    return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  }

  function qs(selector) {
    return document.querySelector(selector);
  }

  function qsa(selector) {
    return document.querySelectorAll(selector);
  }

  function post(data) {
    var formData = new FormData();
    Object.keys(data).forEach(function (key) {
      if (Array.isArray(data[key])) {
        data[key].forEach(function (v) { formData.append(key + '[]', v); });
      } else {
        formData.append(key, data[key]);
      }
    });
    return fetch(ajaxurl, { method: 'POST', body: formData })
      .then(function (r) { return r.json(); });
  }

  function showNotice(msg, type) {
    var cls = type === 'error' ? 'notice-error' : 'notice-success';
    var div = document.createElement('div');
    div.className = 'notice ' + cls + ' is-dismissible';
    div.innerHTML = '<p>' + msg + '</p>';
    var section = qs('.bricks-mcp-config-section');
    if (section) section.parentNode.insertBefore(div, section);
    setTimeout(function () {
      div.style.display = 'none';
      div.remove();
    }, 4000);
  }

  // -- Pattern Filters -----------------------------------------

  function applyFilters() {
    var catEl = qs('#bricks-mcp-pattern-filter-category');
    var srcEl = qs('#bricks-mcp-pattern-filter-source');
    var cat = catEl ? catEl.value : '';
    var src = srcEl ? srcEl.value : '';
    var visible = 0;

    qsa('#bricks-mcp-patterns-table tbody tr').forEach(function (row) {
      var matchCat = !cat || row.dataset.category === cat;
      var matchSrc = !src || row.dataset.source === src;
      row.style.display = (matchCat && matchSrc) ? '' : 'none';
      if (matchCat && matchSrc) visible++;
    });

    var countEl = qs('.bwm-patterns-count');
    if (countEl) countEl.textContent = visible + ' patterns shown';
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('#bricks-mcp-pattern-filter-category, #bricks-mcp-pattern-filter-source')) {
      applyFilters();
    }
  });

  // -- Select all ----------------------------------------------

  document.addEventListener('change', function (e) {
    if (e.target.matches('#bricks-mcp-patterns-select-all')) {
      var checked = e.target.checked;
      qsa('.bricks-mcp-pattern-select').forEach(function (cb) {
        if (cb.closest('tr').style.display !== 'none') {
          cb.checked = checked;
        }
      });
    }
  });

  // -- Close modals --------------------------------------------

  document.addEventListener('click', function (e) {
    if (e.target.matches('.bwm-modal-close, .bwm-modal-backdrop')) {
      var modal = e.target.closest('[id$="-modal"]');
      if (modal) modal.style.display = 'none';
    }
  });

  // -- View pattern (JSON modal, read-only for plugin/user) ----

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-view-pattern');
    if (!btn) return;

    var id = btn.dataset.id;
    var source = btn.closest('tr').dataset.source;

    post({ action: 'bricks_mcp_list_patterns', nonce: nonce }).then(function (res) {
      if (!res.success) return;
      var pattern = res.data.find(function (p) { return p.id === id; });
      if (!pattern) return showNotice('Pattern not found.', 'error');

      var titleEl = qs('#bricks-mcp-modal-title');
      var jsonEl = qs('#bricks-mcp-pattern-json');
      var saveBtn = qs('#bricks-mcp-save-pattern');

      if (titleEl) titleEl.textContent = pattern.name + ' (' + (pattern.source || 'plugin') + ')';
      if (jsonEl) jsonEl.value = JSON.stringify(pattern, null, 2);

      if (source === 'database') {
        if (jsonEl) jsonEl.readOnly = false;
        if (saveBtn) {
          saveBtn.style.display = '';
          saveBtn.dataset.id = id;
        }
      } else {
        if (jsonEl) jsonEl.readOnly = true;
        if (saveBtn) saveBtn.style.display = 'none';
      }

      var modal = qs('#bricks-mcp-pattern-modal');
      if (modal) modal.style.display = 'block';
    });
  });

  // -- Save edited pattern (JSON modal) ------------------------

  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-save-pattern')) return;

    var btn = e.target;
    var jsonEl = qs('#bricks-mcp-pattern-json');
    var json = jsonEl ? jsonEl.value : '';
    try { JSON.parse(json); } catch (err) { return showNotice('Invalid JSON: ' + err.message, 'error'); }

    btn.disabled = true;
    btn.textContent = 'Saving...';
    var id = btn.dataset.id;

    post({ action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }).then(function () {
      post({ action: 'bricks_mcp_create_pattern', nonce: nonce, pattern_json: json }).then(function (res) {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
        if (res.success) { location.reload(); }
        else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
      });
    });
  });

  // -- Delete / Hide pattern -----------------------------------

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-delete-pattern, .bricks-mcp-hide-pattern');
    if (!btn) return;

    var id = btn.dataset.id;
    var isHide = btn.classList.contains('bricks-mcp-hide-pattern');
    var msg = isHide
      ? 'Hide pattern "' + id + '" from all lists?'
      : 'Delete pattern "' + id + '"? This cannot be undone.';
    if (!confirm(msg)) return;

    post({ action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }).then(function (res) {
      if (res.success) {
        var row = qs('tr[data-pattern-id="' + id + '"]');
        if (row) {
          row.style.display = 'none';
          row.remove();
        }
        showNotice('Pattern ' + (isHide ? 'hidden' : 'deleted') + '.', 'success');
      } else {
        showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error');
      }
    });
  });

  // -- Export patterns -----------------------------------------

  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-export-patterns')) return;

    var selected = [];
    qsa('.bricks-mcp-pattern-select:checked').forEach(function (cb) {
      selected.push(cb.value);
    });

    var data = { action: 'bricks_mcp_export_patterns', nonce: nonce };
    if (selected.length) data.pattern_ids = selected;

    post(data).then(function (res) {
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

  // -- Import patterns -----------------------------------------

  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-import-patterns-btn')) return;
    var fileInput = qs('#bricks-mcp-import-file');
    if (fileInput) fileInput.click();
  });

  document.addEventListener('change', function (e) {
    if (!e.target.matches('#bricks-mcp-import-file')) return;

    var file = e.target.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (ev) {
      var json = ev.target.result;
      try { var parsed = JSON.parse(json); if (!Array.isArray(parsed)) throw new Error('not array'); }
      catch (err) { return showNotice('Invalid JSON: must be an array of pattern objects.', 'error'); }
      if (!confirm('Import ' + parsed.length + ' pattern(s)? They will be normalized to match your site\'s classes and variables.')) return;

      // Normalize each pattern before importing.
      post({ action: 'bricks_mcp_normalize_patterns', nonce: nonce, patterns_json: json }).then(function (normRes) {
        if (!normRes.success) {
          // Fallback: import without normalization.
          showNotice('Normalization skipped — importing raw.', 'error');
        }
        var patternsToImport = normRes.success ? JSON.stringify(normRes.data.patterns) : json;
        var warnings = normRes.success ? (normRes.data.warnings || []) : [];

        post({ action: 'bricks_mcp_import_patterns', nonce: nonce, patterns_json: patternsToImport }).then(function (res) {
          if (res.success) {
            var msg = 'Imported ' + (res.data.imported || []).length + ' pattern(s).';
            if (warnings.length) msg += ' Warnings: ' + warnings.join('; ');
            showNotice(msg, 'success');
            location.reload();
          } else {
            showNotice('Import failed: ' + (res.data ? res.data.message : 'Unknown'), 'error');
          }
        });
      });
    };
    reader.readAsText(file);
    e.target.value = '';
  });

  // =============================================================
  // PATTERN CREATOR FORM
  // =============================================================

  // Open creator modal.
  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-add-pattern-btn')) return;

    // Reset form.
    ['#bricks-mcp-creator-id', '#bricks-mcp-creator-name', '#bricks-mcp-creator-tags',
     '#bricks-mcp-creator-ai-desc', '#bricks-mcp-creator-ai-hints', '#bricks-mcp-creator-composition'
    ].forEach(function (sel) {
      var el = qs(sel);
      if (el) el.value = '';
    });

    ['#bricks-mcp-creator-category', '#bricks-mcp-creator-layout', '#bricks-mcp-creator-bg'].forEach(function (sel) {
      var el = qs(sel);
      if (el) el.selectedIndex = 0;
    });

    var preview = qs('#bricks-mcp-creator-image-preview');
    if (preview) { preview.src = ''; preview.style.display = 'none'; }

    var imageId = qs('#bricks-mcp-creator-image-id');
    if (imageId) imageId.value = '';

    var modalTitle = qs('#bricks-mcp-creator-modal-title');
    if (modalTitle) modalTitle.textContent = 'Add New Pattern';

    var saveBtn = qs('#bricks-mcp-creator-save');
    if (saveBtn) {
      saveBtn.dataset.mode = 'create';
      saveBtn.dataset.originalId = '';
    }

    var modal = qs('#bricks-mcp-creator-modal');
    if (modal) modal.style.display = 'block';
  });

  // Auto-slug ID from name.
  document.addEventListener('input', function (e) {
    if (e.target.matches('#bricks-mcp-creator-name')) {
      var idEl = qs('#bricks-mcp-creator-id');
      if (idEl && !idEl.dataset.manual) {
        idEl.value = slugify(e.target.value);
      }
    }
  });

  document.addEventListener('input', function (e) {
    if (e.target.matches('#bricks-mcp-creator-id')) {
      e.target.dataset.manual = e.target.value !== '' ? 'true' : '';
    }
  });

  // AI description char counter.
  document.addEventListener('input', function (e) {
    if (e.target.matches('#bricks-mcp-creator-ai-desc')) {
      var len = e.target.value.length;
      var counter = qs('#bricks-mcp-creator-char-count');
      if (counter) counter.textContent = len + '/300';
    }
  });

  // Generate AI prompt -- builds a prompt from the description field, copies to clipboard.
  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-creator-generate-ai')) return;

    var descEl = qs('#bricks-mcp-creator-ai-desc');
    var nameEl = qs('#bricks-mcp-creator-name');
    var desc = (descEl ? descEl.value.trim() : '') || (nameEl ? nameEl.value.trim() : '');
    if (!desc) return showNotice('Enter a name or AI description first.', 'error');

    var catEl = qs('#bricks-mcp-creator-category');
    var cat = catEl ? catEl.value : '';

    var btn = e.target;
    btn.disabled = true;
    btn.textContent = 'Generating...';

    post({ action: 'bricks_mcp_generate_prompt', nonce: nonce, description: desc, category: cat }).then(function (res) {
      btn.disabled = false;
      btn.textContent = 'Generate AI Prompt';
      if (!res.success) return showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error');

      var prompt = res.data.prompt || '';
      // Copy to clipboard.
      if (navigator.clipboard) {
        navigator.clipboard.writeText(prompt).then(function () {
          showNotice('AI prompt copied to clipboard! Paste it into Claude Code or any AI assistant, then paste the generated JSON into the Composition field below.', 'success');
        });
      } else {
        // Fallback: temporary textarea.
        var ta = document.createElement('textarea');
        ta.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;';
        ta.value = prompt;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        ta.remove();
        showNotice('AI prompt copied to clipboard!', 'success');
      }
    });
  });

  // WP Media Library for reference image.
  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-creator-upload-image')) return;
    e.preventDefault();
    if (typeof wp === 'undefined' || !wp.media) return showNotice('Media library not available.', 'error');

    var frame = wp.media({ title: 'Select Pattern Reference Image', multiple: false, library: { type: 'image' } });
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      var imageIdEl = qs('#bricks-mcp-creator-image-id');
      var previewEl = qs('#bricks-mcp-creator-image-preview');
      if (imageIdEl) imageIdEl.value = attachment.id;
      if (previewEl) {
        previewEl.src = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
        previewEl.style.display = '';
      }
    });
    frame.open();
  });

  // Save pattern from creator form.
  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-creator-save')) return;

    var btn = e.target;
    var mode = btn.dataset.mode || 'create';
    var originalId = btn.dataset.originalId || '';

    var idEl = qs('#bricks-mcp-creator-id');
    var nameEl = qs('#bricks-mcp-creator-name');
    var catEl = qs('#bricks-mcp-creator-category');
    var tagsEl = qs('#bricks-mcp-creator-tags');
    var layoutEl = qs('#bricks-mcp-creator-layout');
    var bgEl = qs('#bricks-mcp-creator-bg');
    var aiDescEl = qs('#bricks-mcp-creator-ai-desc');
    var aiHintsEl = qs('#bricks-mcp-creator-ai-hints');

    var pattern = {
      id: idEl ? idEl.value.trim() : '',
      name: nameEl ? nameEl.value.trim() : '',
      category: catEl ? catEl.value : '',
      tags: (tagsEl ? tagsEl.value : '').split(',').map(function (t) { return t.trim(); }).filter(Boolean),
      layout: layoutEl ? layoutEl.value : '',
      background: (bgEl ? bgEl.value : '') || 'light',
      ai_description: aiDescEl ? aiDescEl.value.trim() : '',
      ai_usage_hints: (aiHintsEl ? aiHintsEl.value : '').split('\n').map(function (h) { return h.trim(); }).filter(Boolean),
    };

    // Image ID.
    var imageIdEl = qs('#bricks-mcp-creator-image-id');
    var imageIdVal = imageIdEl ? imageIdEl.value : '';
    if (imageIdVal) pattern.image_id = parseInt(imageIdVal, 10);

    // Composition JSON.
    var compEl = qs('#bricks-mcp-creator-composition');
    var compJson = compEl ? compEl.value.trim() : '';
    if (compJson) {
      try { var comp = JSON.parse(compJson); pattern.composition = comp; }
      catch (err) { return showNotice('Invalid Composition JSON: ' + err.message, 'error'); }
    }

    if (!pattern.id || !pattern.name || !pattern.category) {
      return showNotice('ID, Name, and Category are required.', 'error');
    }

    btn.disabled = true;
    btn.textContent = 'Saving...';

    var doCreate = function () {
      post({ action: 'bricks_mcp_create_pattern', nonce: nonce, pattern_json: JSON.stringify(pattern) }).then(function (res) {
        btn.disabled = false;
        btn.textContent = 'Save Pattern';
        if (res.success) { showNotice('Pattern saved!', 'success'); location.reload(); }
        else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
      });
    };

    if (mode === 'edit' && originalId) {
      // Delete old, create new.
      post({ action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: originalId }).then(function () { doCreate(); });
    } else {
      doCreate();
    }
  });

  // -- Edit pattern via creator form ---------------------------

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-edit-pattern');
    if (!btn) return;

    var id = btn.dataset.id;

    post({ action: 'bricks_mcp_list_patterns', nonce: nonce }).then(function (res) {
      if (!res.success) return;
      var p = res.data.find(function (x) { return x.id === id; });
      if (!p) return showNotice('Pattern not found.', 'error');

      var idEl = qs('#bricks-mcp-creator-id');
      if (idEl) { idEl.value = p.id; idEl.dataset.manual = 'true'; }

      var nameEl = qs('#bricks-mcp-creator-name');
      if (nameEl) nameEl.value = p.name || '';

      var catEl = qs('#bricks-mcp-creator-category');
      if (catEl) catEl.value = p.category || '';

      var tagsEl = qs('#bricks-mcp-creator-tags');
      if (tagsEl) tagsEl.value = (p.tags || []).join(', ');

      var layoutEl = qs('#bricks-mcp-creator-layout');
      if (layoutEl) layoutEl.value = p.layout || '';

      var bgEl = qs('#bricks-mcp-creator-bg');
      if (bgEl) bgEl.value = p.background || 'light';

      var aiDescEl = qs('#bricks-mcp-creator-ai-desc');
      if (aiDescEl) aiDescEl.value = p.ai_description || '';

      var aiHintsEl = qs('#bricks-mcp-creator-ai-hints');
      if (aiHintsEl) aiHintsEl.value = (p.ai_usage_hints || []).join('\n');

      var charCount = qs('#bricks-mcp-creator-char-count');
      if (charCount) charCount.textContent = (p.ai_description || '').length + '/300';

      // Composition.
      var comp = {};
      ['composition', 'columns', 'patterns', 'rows', 'section_overrides', 'container_overrides', 'gradient_overlay', 'has_two_rows'].forEach(function (k) {
        if (p[k] !== undefined) comp[k] = p[k];
      });
      var compEl = qs('#bricks-mcp-creator-composition');
      if (compEl) {
        compEl.value = Object.keys(comp).length ? JSON.stringify(comp, null, 2) : '';
      }

      var modalTitle = qs('#bricks-mcp-creator-modal-title');
      if (modalTitle) modalTitle.textContent = 'Edit Pattern: ' + p.name;

      var saveBtn = qs('#bricks-mcp-creator-save');
      if (saveBtn) {
        saveBtn.dataset.mode = 'edit';
        saveBtn.dataset.originalId = id;
      }

      var modal = qs('#bricks-mcp-creator-modal');
      if (modal) modal.style.display = 'block';
    });
  });

  // =============================================================
  // CATEGORY MANAGEMENT
  // =============================================================

  // Add category.
  document.addEventListener('click', function (e) {
    if (!e.target.matches('#bricks-mcp-add-category-btn')) return;

    var nameEl = qs('#bricks-mcp-new-category-name');
    var descEl = qs('#bricks-mcp-new-category-desc');
    var name = nameEl ? nameEl.value.trim() : '';
    var desc = descEl ? descEl.value.trim() : '';
    if (!name) return showNotice('Category name is required.', 'error');

    post({ action: 'bricks_mcp_create_category', nonce: nonce, name: name, description: desc }).then(function (res) {
      if (res.success) { showNotice('Category created.', 'success'); location.reload(); }
      else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
    });
  });

  // Delete category.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-delete-category');
    if (!btn) return;

    var id = btn.dataset.id;
    var count = btn.dataset.count || 0;
    var msg = 'Delete category "' + id + '"?';
    if (count > 0) msg += ' (' + count + ' pattern(s) will become uncategorized)';
    if (!confirm(msg)) return;

    post({ action: 'bricks_mcp_delete_category', nonce: nonce, category_id: id }).then(function (res) {
      if (res.success) {
        var row = qs('tr[data-category-id="' + id + '"]');
        if (row) {
          row.style.display = 'none';
          row.remove();
        }
        showNotice('Category deleted.', 'success');
      } else {
        showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error');
      }
    });
  });

  // Inline edit category.
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-edit-category');
    if (!btn) return;

    var row = btn.closest('tr');
    row.querySelectorAll('.bwm-cat-display').forEach(function (el) { el.style.display = 'none'; });
    row.querySelectorAll('.bwm-cat-edit').forEach(function (el) { el.style.display = ''; });
    btn.style.display = 'none';
    row.querySelectorAll('.bricks-mcp-save-category, .bricks-mcp-cancel-edit-category').forEach(function (el) { el.style.display = ''; });
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-cancel-edit-category');
    if (!btn) return;

    var row = btn.closest('tr');
    row.querySelectorAll('.bwm-cat-display').forEach(function (el) { el.style.display = ''; });
    row.querySelectorAll('.bwm-cat-edit').forEach(function (el) { el.style.display = 'none'; });
    var editBtn = row.querySelector('.bricks-mcp-edit-category');
    if (editBtn) editBtn.style.display = '';
    row.querySelectorAll('.bricks-mcp-save-category, .bricks-mcp-cancel-edit-category').forEach(function (el) { el.style.display = 'none'; });
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-save-category');
    if (!btn) return;

    var row = btn.closest('tr');
    var id = row.dataset.categoryId;
    var nameEl = row.querySelector('.bwm-cat-edit-name');
    var descEl = row.querySelector('.bwm-cat-edit-desc');
    var name = nameEl ? nameEl.value.trim() : '';
    var desc = descEl ? descEl.value.trim() : '';

    post({ action: 'bricks_mcp_update_category', nonce: nonce, category_id: id, name: name, description: desc }).then(function (res) {
      if (res.success) { location.reload(); }
      else { showNotice('Error: ' + (res.data ? res.data.message : 'Unknown'), 'error'); }
    });
  });

})();
