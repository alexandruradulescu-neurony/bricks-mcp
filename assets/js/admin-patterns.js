/**
 * Bricks MCP — Patterns admin tab.
 *
 * View-only detail panel, export/import, delete, category filter.
 */
(function () {
  'use strict';

  if (typeof bricksMcpPatterns === 'undefined') return;
  var nonce = bricksMcpPatterns.nonce;

  // -- Helpers -------------------------------------------------

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
    var cat = catEl ? catEl.value : '';
    var visible = 0;

    qsa('#bricks-mcp-patterns-table tbody tr').forEach(function (row) {
      var matchCat = !cat || row.dataset.category === cat;
      row.style.display = matchCat ? '' : 'none';
      if (matchCat) visible++;
    });

    var countEl = qs('.bwm-patterns-count');
    if (countEl) countEl.textContent = visible + ' patterns shown';
  }

  document.addEventListener('change', function (e) {
    if (e.target.matches('#bricks-mcp-pattern-filter-category')) {
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

  // -- Delete pattern ------------------------------------------

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.bricks-mcp-delete-pattern');
    if (!btn) return;

    var id = btn.dataset.id;
    if (!confirm('Delete pattern "' + id + '"? This cannot be undone.')) return;

    post({ action: 'bricks_mcp_delete_pattern', nonce: nonce, pattern_id: id }).then(function (res) {
      if (res.success) {
        var row = qs('tr[data-pattern-id="' + id + '"]');
        if (row) {
          row.style.display = 'none';
          row.remove();
        }
        showNotice('Pattern deleted.', 'success');
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
      if (!confirm('Import ' + parsed.length + ' pattern(s)?')) return;

      post({ action: 'bricks_mcp_import_patterns', nonce: nonce, patterns_json: json }).then(function (res) {
        if (res.success) {
          var msg = 'Imported ' + (res.data.imported || []).length + ' pattern(s).';
          showNotice(msg, 'success');
          location.reload();
        } else {
          showNotice('Import failed: ' + (res.data ? res.data.message : 'Unknown'), 'error');
        }
      });
    };
    reader.readAsText(file);
    e.target.value = '';
  });

  // =============================================================
  // VIEW-ONLY DETAIL PANEL
  // =============================================================

  var panel = qs('#bricks-mcp-pattern-detail');

  function renderTree(node, depth) {
    depth = depth || 0;
    if (!node || typeof node !== 'object') return '';
    var label = node.role || node.type || 'node';
    var repeat = node.repeat ? ' × N' : '';
    var indent = new Array(depth + 1).join('  ');
    var out = indent + (depth > 0 ? '├ ' : '') + label + repeat;
    if (Array.isArray(node.class_refs) && node.class_refs.length) {
      out += ' [' + node.class_refs.join(', ') + ']';
    }
    if (Array.isArray(node.children)) {
      for (var i = 0; i < node.children.length; i++) {
        out += '\n' + renderTree(node.children[i], depth + 1);
      }
    }
    return out;
  }

  function openDetail(patternId) {
    var formData = new FormData();
    formData.append('action', 'bricks_mcp_get_pattern');
    formData.append('nonce', nonce);
    formData.append('pattern_id', patternId);

    fetch(ajaxurl, { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          alert(data.data && data.data.message ? data.data.message : 'Failed to load pattern.');
          return;
        }
        var p = data.data;

        var nameEl = qs('#bricks-mcp-detail-name');
        if (nameEl) nameEl.textContent = p.name || p.id;

        var catEl = qs('#bricks-mcp-detail-category');
        if (catEl) catEl.textContent = p.category || '';

        var layoutEl = qs('#bricks-mcp-detail-layout');
        if (layoutEl) layoutEl.textContent = p.layout || '';

        var capturedLink = qs('#bricks-mcp-detail-captured-link');
        if (capturedLink) {
          if (p.captured_from && p.captured_from.page_id) {
            capturedLink.textContent = 'Page ' + p.captured_from.page_id;
            capturedLink.href = '/?p=' + encodeURIComponent(p.captured_from.page_id);
          } else {
            capturedLink.textContent = '—';
            capturedLink.href = '#';
          }
        }

        var classesEl = qs('#bricks-mcp-detail-classes');
        if (classesEl) classesEl.textContent = Object.keys(p.classes || {}).join(', ') || '—';

        var varsEl = qs('#bricks-mcp-detail-variables');
        if (varsEl) varsEl.textContent = Object.keys(p.variables || {}).join(', ') || '—';

        var treeEl = qs('#bricks-mcp-detail-structure-tree');
        if (treeEl) treeEl.textContent = renderTree(p.structure);

        var jsonEl = qs('#bricks-mcp-detail-json');
        if (jsonEl) jsonEl.value = JSON.stringify(p, null, 2);

        var bemPurityEl = qs('#bricks-mcp-detail-bem-purity');
        if (bemPurityEl) {
          var purity = typeof p.bem_purity === 'number' ? (p.bem_purity * 100).toFixed(0) + '%' : '—';
          bemPurityEl.textContent = purity;
        }

        var nonBemEl = qs('#bricks-mcp-detail-non-bem');
        if (nonBemEl) {
          nonBemEl.textContent = (p.non_bem_classes && p.non_bem_classes.length) ? p.non_bem_classes.join(', ') : '—';
        }

        var hintsUl = qs('#bricks-mcp-detail-migration-hints');
        if (hintsUl) {
          hintsUl.innerHTML = '';
          for (var legacy in (p.bem_migration_hints || {})) {
            if (Object.prototype.hasOwnProperty.call(p.bem_migration_hints, legacy)) {
              var li = document.createElement('li');
              li.textContent = legacy + ' → ' + p.bem_migration_hints[legacy];
              hintsUl.appendChild(li);
            }
          }
        }

        if (panel) panel.style.display = 'block';
      })
      .catch(function (err) { alert('Request failed: ' + err.message); });
  }

  document.addEventListener('click', function (e) {
    var viewBtn = e.target.closest('.bricks-mcp-view-pattern');
    if (viewBtn) {
      e.preventDefault();
      openDetail(viewBtn.getAttribute('data-id'));
      return;
    }

    if (e.target.classList.contains('bwm-modal-close') ||
        e.target.classList.contains('bwm-modal-backdrop')) {
      if (panel && panel.contains(e.target)) {
        panel.style.display = 'none';
        return;
      }
    }
  });

  // Bulk delete selected patterns.
  document.addEventListener('click', function (ev) {
    var bulk = ev.target.closest('#bricks-mcp-bulk-delete-patterns');
    if (!bulk) return;
    var checked = Array.from(document.querySelectorAll('.bricks-mcp-pattern-select:checked'));
    if (checked.length === 0) {
      alert('Select at least one pattern first.');
      return;
    }
    if (!confirm('Delete ' + checked.length + ' pattern(s)? This cannot be undone.')) return;
    var body = new FormData();
    body.append('action', 'bricks_mcp_bulk_delete_patterns');
    body.append('nonce', (window.bricksMcpPatterns || {}).nonce || '');
    checked.forEach(function (cb) { body.append('pattern_ids[]', cb.value); });
    fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.success) {
          alert(data.data && data.data.message ? data.data.message : 'Bulk delete failed.');
          return;
        }
        location.reload();
      })
      .catch(function (err) { alert('Request failed: ' + err.message); });
  });

})();
