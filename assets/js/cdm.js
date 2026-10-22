/**
 * Carpetas de Medios — interfaz.
 * Un único script para: ventana emergente de medios (Divi, Elementor, Gutenberg…),
 * rejilla de Medios y vista de lista.
 */
(function ($) {
	'use strict';

	var C = window.CDM;
	if (!C) { return; }
	var T = C.i18n || {};
	var Z = 2147483000;
	var sidebars = [];
	var drag = null;

	function beginDrag(d) {
		drag = d;
		$('body').addClass('cdm-dragging'); // oculta el aviso «Suelta archivos» de WordPress durante nuestro arrastre.
	}

	function endDrag() {
		drag = null;
		$('body').removeClass('cdm-dragging');
		$('.cdm-row.is-drop').removeClass('is-drop');
	}

	C.folders = C.folders || [];
	C.cats = C.cats || [];
	C.counts = C.counts || { all: 0, none: 0 };
	C.active = C.current || 'all';

	/* ------------------------------------------------------------ utilidades */

	function ls(key, val) {
		try {
			if (val === undefined) { return window.localStorage.getItem(key); }
			window.localStorage.setItem(key, val);
		} catch (e) { /* almacenamiento no disponible */ }
		return null;
	}

	function fmt(str, val) { return String(str).replace(/%[sd]/, val); }

	function norm(s) {
		s = String(s || '').toLowerCase();
		return s.normalize ? s.normalize('NFD').replace(/[̀-ͯ]/g, '') : s;
	}

	function cmp(a, b) { return a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }); }

	function byId(id) {
		for (var i = 0; i < C.folders.length; i++) { if (C.folders[i].id === id) { return C.folders[i]; } }
		return null;
	}

	function childrenOf(pid) {
		return C.folders.filter(function (f) { return f.parent === pid; }).sort(cmp);
	}

	function descendants(id) {
		var out = [], stack = [id];
		while (stack.length) {
			var p = stack.pop();
			for (var i = 0; i < C.folders.length; i++) {
				if (C.folders[i].parent === p) { out.push(C.folders[i].id); stack.push(C.folders[i].id); }
			}
		}
		return out;
	}

	function ancestors(f) {
		var out = [];
		while (f && f.parent) { f = byId(f.parent); if (f) { out.push(f.id); } }
		return out;
	}

	function catById(id) {
		for (var i = 0; i < C.cats.length; i++) { if (C.cats[i].id === id) { return C.cats[i]; } }
		return null;
	}

	function folderOptions(excludeId) {
		var ex = excludeId ? [excludeId].concat(descendants(excludeId)) : [];
		var opts = [{ v: 0, l: T.root }];
		(function walk(pid, depth) {
			childrenOf(pid).forEach(function (f) {
				if (ex.indexOf(f.id) > -1) { return; }
				var pad = new Array(depth + 1).join('   ');
				opts.push({ v: f.id, l: pad + (depth ? '↳ ' : '') + f.name });
				walk(f.id, depth + 1);
			});
		})(0, 0);
		return opts;
	}

	function catOptions() {
		var o = [{ v: 0, l: T.noCategory }];
		C.cats.forEach(function (c) { o.push({ v: c.id, l: c.name }); });
		if (C.canManage) { o.push({ v: 'new', l: T.newCategory }); }
		return o;
	}

	function uploadFolder() {
		return (typeof C.active === 'number') ? C.active : 0;
	}

	/* ------------------------------------------------------------ API */

	function applyTree(t) {
		if (!t) { return; }
		C.folders = t.folders || [];
		C.cats = t.cats || [];
		C.counts = t.counts || C.counts;
		sidebars.forEach(function (s) { s.render(); });
	}

	function api(action, data) {
		var d = $.Deferred();
		$.post(C.ajaxUrl, $.extend({ action: 'cdm_' + action, nonce: C.nonce }, data || {}))
			.done(function (r) {
				if (r && r.success) {
					if (r.data && r.data.tree) { applyTree(r.data.tree); }
					d.resolve(r.data || {});
				} else {
					d.reject((r && r.data && r.data.message) || T.error);
				}
			})
			.fail(function (x) {
				var m = T.error;
				try { m = x.responseJSON.data.message || m; } catch (e) { /* sin mensaje */ }
				d.reject(m);
			});
		return d.promise();
	}

	var toastTimer;
	function toast(msg, isError) {
		var $t = $('.cdm-toast');
		if (!$t.length) { $t = $('<div class="cdm-toast" role="status">').appendTo(document.body); }
		$t.text(msg).toggleClass('is-error', !!isError).addClass('is-on');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { $t.removeClass('is-on'); }, isError ? 4500 : 2200);
	}

	/* ------------------------------------------------------------ diálogo y menú */

	function dialog(opts) {
		var d = $.Deferred();
		var $host = $(opts.container && opts.container.length ? opts.container : document.body);
		var $ov = $('<div class="cdm-overlay">').css('z-index', Z).appendTo($host);
		var $f = $('<form class="cdm-dialog" role="dialog" aria-modal="true">').appendTo($ov);
		$('<h3>').text(opts.title).appendTo($f);
		if (opts.message) { $('<p class="cdm-dlg-msg">').text(opts.message).appendTo($f); }
		(opts.fields || []).forEach(function (fd) {
			var $row = $('<label class="cdm-field">').append($('<span>').text(fd.label));
			var $in;
			if (fd.type === 'select') {
				$in = $('<select>');
				fd.options.forEach(function (o) {
					$('<option>').val(o.v).text(o.l).prop('selected', String(o.v) === String(fd.value)).appendTo($in);
				});
			} else if (fd.type === 'color') {
				$in = $('<input type="color">').val(fd.value || '#2563eb');
			} else {
				$in = $('<input type="text" maxlength="120" autocomplete="off">').val(fd.value || '');
			}
			$in.attr('name', fd.name).appendTo($row);
			$row.appendTo($f);
		});
		var $btns = $('<div class="cdm-dlg-btns">').appendTo($f);
		$('<button type="button" class="button cdm-cancel">').text(T.cancel).appendTo($btns);
		$('<button type="submit" class="button button-primary">').toggleClass('cdm-danger', !!opts.danger).text(opts.ok || T.save).appendTo($btns);

		function close() { $ov.remove(); $(document).off('keydown.cdmdlg'); }
		function stop(e) {
			if (e.type === 'keydown' && e.which === 27) { e.preventDefault(); close(); d.reject(); }
			e.stopPropagation();
		}
		$f.on('click keydown keyup', stop);
		$f.on('submit', function (e) {
			e.preventDefault();
			var vals = {}, bad = false;
			$f.find('[name]').each(function () { vals[this.name] = $(this).val(); });
			(opts.fields || []).forEach(function (fd) {
				if (fd.type !== 'select' && fd.type !== 'color' && !$.trim(vals[fd.name])) { bad = true; $f.find('[name="' + fd.name + '"]').trigger('focus'); }
			});
			if (bad) { return; }
			close();
			d.resolve(vals);
		});
		$f.on('click', '.cdm-cancel', function () { close(); d.reject(); });
		$ov.on('mousedown', function (e) { if (e.target === $ov[0]) { close(); d.reject(); } });
		$(document).on('keydown.cdmdlg', function (e) {
			if (e.which === 27) { e.stopImmediatePropagation(); e.preventDefault(); close(); d.reject(); }
		});
		setTimeout(function () {
			var $first = $f.find('input[type=text], select').first();
			$first.trigger('focus');
			if ($first.is('input')) { $first[0].select(); }
		}, 20);
		return d.promise();
	}

	function closeMenu() { $('.cdm-menu').remove(); $(document).off('.cdmmenu'); }

	function showMenu(x, y, items, $host) {
		closeMenu();
		var $m = $('<ul class="cdm-menu" role="menu">').css('z-index', Z);
		items.forEach(function (it) {
			if (it === '-') { $('<li class="cdm-menu-sep">').appendTo($m); return; }
			$('<li role="menuitem" tabindex="0">').toggleClass('is-danger', !!it.danger).text(it.label).on('click keydown', function (e) {
				if (e.type === 'keydown' && e.which !== 13) { return; }
				closeMenu();
				it.fn();
			}).appendTo($m);
		});
		$m.appendTo($host && $host.length ? $host : document.body);
		var w = $m.outerWidth(), h = $m.outerHeight();
		$m.css({ left: Math.max(4, Math.min(x, $(window).width() - w - 6)), top: Math.max(4, Math.min(y, $(window).height() - h - 6)) });
		setTimeout(function () {
			$(document).on('mousedown.cdmmenu keydown.cdmmenu', function (e) {
				if (e.type === 'keydown' && e.which !== 27) { return; }
				if (e.type === 'mousedown' && $(e.target).closest('.cdm-menu').length) { return; }
				closeMenu();
			});
			$(window).one('scroll.cdmmenu resize.cdmmenu', closeMenu);
		}, 0);
	}

	/** Resuelve el ID de categoría; si se eligió «Nueva categoría…» la crea primero. */
	function ensureCat(sb, val) {
		var d = $.Deferred();
		if (val !== 'new') { return d.resolve(parseInt(val, 10) || 0).promise(); }
		dialog({
			container: sb.container(),
			title: T.newCategory,
			ok: T.create,
			fields: [{ name: 'name', label: T.catName, type: 'text' }, { name: 'color', label: T.color, type: 'color', value: '#2563eb' }]
		}).done(function (v) {
			api('cat_save', { id: 0, name: v.name, color: v.color })
				.done(function (r) { d.resolve(r.id); })
				.fail(function (m) { toast(m, true); d.reject(); });
		}).fail(function () { d.reject(); });
		return d.promise();
	}

	/* ------------------------------------------------------------ iconos */

	var ICONS = {
		folder: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M2 5.5A1.5 1.5 0 0 1 3.5 4h4l1.6 1.8h7.4A1.5 1.5 0 0 1 18 7.3v7.2a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 2 14.5z"/></svg>',
		all: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M3 4h6v6H3zm8 0h6v6h-6zM3 12h6v4H3zm8 0h6v4h-6z"/></svg>',
		none: '<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.5" stroke-dasharray="2.2 2" d="M2.75 5.5a.75.75 0 0 1 .75-.75h3.7l1.6 1.8h7.7a.75.75 0 0 1 .75.75v7.2a.75.75 0 0 1-.75.75h-13a.75.75 0 0 1-.75-.75z"/></svg>'
	};

	/* ------------------------------------------------------------ barra lateral */

	function Sidebar(opts) {
		this.opts = opts;
		this.current = opts.current || 'all';
		this.q = '';
		this.cat = 0;
		this.open = {};
		try { this.open = JSON.parse(ls('cdmOpen') || '{}') || {}; } catch (e) { this.open = {}; }
		if (typeof this.current === 'number') {
			var self = this;
			ancestors(byId(this.current)).forEach(function (a) { self.open[a] = true; });
		}
		C.active = this.current;
		this.build();
		sidebars.push(this);
		this.render();
	}

	Sidebar.prototype.container = function () {
		var $m = this.$el.closest('.media-modal');
		return $m.length ? $m : $(document.body);
	};

	Sidebar.prototype.saveOpen = function () { ls('cdmOpen', JSON.stringify(this.open)); };

	Sidebar.prototype.destroy = function () {
		var i = sidebars.indexOf(this);
		if (i > -1) { sidebars.splice(i, 1); }
		C.active = sidebars.length ? sidebars[sidebars.length - 1].current : 'all';
		this.$el.remove();
	};

	Sidebar.prototype.build = function () {
		var self = this;
		this.$el = $('<aside class="cdm-sidebar" aria-label="' + T.folders + '">');
		var $head = $('<div class="cdm-head">').appendTo(this.$el);
		$('<span class="cdm-title">').text(T.folders).appendTo($head);
		var $b = $('<span class="cdm-head-btns">').appendTo($head);
		if (C.canManage) {
			$('<button type="button" class="cdm-ibtn cdm-new" aria-label="' + T.newFolder + '" title="' + T.newFolder + '">').text('+').appendTo($b);
		}
		$('<button type="button" class="cdm-ibtn cdm-expand" aria-label="' + T.expandAll + '" title="' + T.expandAll + '">').html('<svg viewBox="0 0 20 20" width="16" height="16"><path fill="currentColor" d="M5 7l5 5 5-5z"/></svg>').appendTo($b);
		if (this.opts.collapsible) {
			$('<button type="button" class="cdm-ibtn cdm-collapse" aria-label="' + T.collapse + '" title="' + T.collapse + '">').html('<svg viewBox="0 0 20 20" width="16" height="16"><path fill="currentColor" d="M12.5 4.5L7 10l5.5 5.5-1.4 1.4L4.2 10l6.9-6.9z"/></svg>').appendTo($b);
		}
		this.$rail = $('<button type="button" class="cdm-rail" aria-label="' + T.expand + '" title="' + T.expand + '">').html(ICONS.folder).appendTo(this.$el);
		this.$search = $('<input type="search" class="cdm-search" autocomplete="off">').attr('placeholder', T.search).appendTo($('<div class="cdm-searchwrap">').appendTo(this.$el));
		this.$chips = $('<div class="cdm-chips" role="group" aria-label="' + T.filterCat + '">').appendTo(this.$el);
		this.$tree = $('<div class="cdm-tree">').appendTo(this.$el);
		this.$hint = $('<div class="cdm-hint">').appendTo(this.$el);

		this.$search.on('input', function () { self.q = this.value; self.render(); });
		this.$el.on('keydown keyup', function (e) { e.stopPropagation(); });
		this.$el.on('click', '.cdm-new', function () { self.askNew(typeof self.current === 'number' ? self.current : 0); });
		this.$el.on('click', '.cdm-expand', function () {
			var allOpen = C.folders.every(function (f) { return self.open[f.id] || !childrenOf(f.id).length; });
			C.folders.forEach(function (f) { if (allOpen) { delete self.open[f.id]; } else { self.open[f.id] = true; } });
			self.saveOpen();
			self.render();
		});
		this.$el.on('click', '.cdm-collapse, .cdm-rail', function () { if (self.opts.onToggle) { self.opts.onToggle(); } });
		this.$el.on('click', '.cdm-chip', function () {
			var id = parseInt($(this).attr('data-cat'), 10) || 0;
			self.cat = (self.cat === id) ? 0 : id;
			self.render();
		});

		this.$tree.on('click', '.cdm-toggle', function (e) {
			e.stopPropagation();
			var id = parseInt($(this).closest('.cdm-node').attr('data-id'), 10);
			self.open[id] = !self.open[id];
			self.saveOpen();
			self.render();
		});
		this.$tree.on('click', '.cdm-row', function (e) {
			if ($(e.target).closest('.cdm-more').length) { return; }
			var raw = $(this).closest('.cdm-node').attr('data-id');
			self.select(/^\d+$/.test(raw) ? parseInt(raw, 10) : raw);
		});
		this.$tree.on('keydown', '.cdm-row', function (e) {
			var raw = $(this).closest('.cdm-node').attr('data-id');
			if (e.which === 13 || e.which === 32) { e.preventDefault(); self.select(/^\d+$/.test(raw) ? parseInt(raw, 10) : raw); }
			if (e.which === 113 && C.canManage && /^\d+$/.test(raw)) { e.preventDefault(); self.askRename(parseInt(raw, 10)); }
		});
		this.$tree.on('click', '.cdm-more', function (e) {
			e.stopPropagation();
			var id = parseInt($(this).closest('.cdm-node').attr('data-id'), 10);
			var off = $(this).offset();
			self.menu(id, off.left - $(window).scrollLeft(), off.top - $(window).scrollTop() + 22);
		});
		this.$tree.on('contextmenu', '.cdm-row', function (e) {
			var raw = $(this).closest('.cdm-node').attr('data-id');
			if (!C.canManage || !/^\d+$/.test(raw)) { return; }
			e.preventDefault();
			self.menu(parseInt(raw, 10), e.clientX, e.clientY);
		});

		/* arrastrar y soltar */
		this.$tree.on('dragstart', '.cdm-row[draggable="true"]', function (e) {
			var id = parseInt($(this).closest('.cdm-node').attr('data-id'), 10);
			beginDrag({ type: 'folder', id: id });
			e.originalEvent.dataTransfer.effectAllowed = 'move';
			e.originalEvent.dataTransfer.setData('text/plain', 'cdm-folder');
		});
		this.$tree.on('dragover', '.cdm-row', function (e) {
			if (!drag) { return; }
			var raw = $(this).closest('.cdm-node').attr('data-id');
			if (!self.canDrop(raw)) { return; }
			e.preventDefault();
			e.originalEvent.dataTransfer.dropEffect = 'move';
			$(this).addClass('is-drop');
			var $n = $(this).closest('.cdm-node');
			if ($n.hasClass('has-children') && !$n.hasClass('is-open') && !self.hoverTimer) {
				self.hoverTimer = setTimeout(function () {
					self.hoverTimer = null;
					self.open[parseInt(raw, 10)] = true;
					self.render();
				}, 700);
			}
		});
		this.$tree.on('dragleave', '.cdm-row', function () {
			$(this).removeClass('is-drop');
			clearTimeout(self.hoverTimer);
			self.hoverTimer = null;
		});
		this.$tree.on('drop', '.cdm-row', function (e) {
			if (!drag) { return; }
			e.preventDefault();
			clearTimeout(self.hoverTimer);
			self.hoverTimer = null;
			var raw = $(this).closest('.cdm-node').attr('data-id');
			$(this).removeClass('is-drop');
			if (self.canDrop(raw)) { self.drop(raw); }
			endDrag();
		});
		$(document).on('dragend.cdm drop.cdm', endDrag);
	};

	Sidebar.prototype.canDrop = function (raw) {
		if (!drag) { return false; }
		if (drag.type === 'files') { return raw !== 'all'; }
		if (!C.canManage) { return false; }
		if (raw === 'none') { return false; }
		if (raw === 'all') { return !!(byId(drag.id) && byId(drag.id).parent); }
		var t = parseInt(raw, 10);
		var f = byId(drag.id);
		if (!f || t === drag.id || t === f.parent) { return false; }
		return descendants(drag.id).indexOf(t) === -1;
	};

	Sidebar.prototype.drop = function (raw) {
		var self = this;
		if (drag.type === 'files') {
			var ids = drag.ids;
			api('assign', { ids: ids, folder: raw === 'none' ? 0 : parseInt(raw, 10) })
				.done(function (r) {
					toast(T.moved + ' · ' + (r.moved === 1 ? T.dragFile : fmt(T.dragFiles, r.moved)));
					sidebars.forEach(function (s) { if (s.opts.onMoved) { s.opts.onMoved(); } });
				})
				.fail(function (m) { toast(m, true); });
		} else {
			var parent = raw === 'all' ? 0 : parseInt(raw, 10);
			api('move_folder', { id: drag.id, parent: parent })
				.done(function () { if (parent) { self.open[parent] = true; self.saveOpen(); self.render(); } })
				.fail(function (m) { toast(m, true); });
		}
	};

	Sidebar.prototype.select = function (id, silent) {
		this.current = id;
		C.active = id;
		this.render();
		if (!silent && this.opts.onSelect) { this.opts.onSelect(id); }
	};

	/* ---- acciones de carpeta ---- */

	Sidebar.prototype.menu = function (id, x, y) {
		var self = this;
		showMenu(x, y, [
			{ label: T.newSub, fn: function () { self.askNew(id); } },
			{ label: T.rename, fn: function () { self.askRename(id); } },
			{ label: T.setCat, fn: function () { self.askCat(id); } },
			{ label: T.moveTo, fn: function () { self.askMove(id); } },
			'-',
			{ label: T.del, danger: true, fn: function () { self.askDelete(id); } }
		], this.container());
	};

	Sidebar.prototype.askNew = function (parentId) {
		var self = this;
		dialog({
			container: this.container(),
			title: parentId ? T.newSub : T.newFolder,
			ok: T.create,
			fields: [
				{ name: 'name', label: T.name, type: 'text' },
				{ name: 'parent', label: T.parent, type: 'select', value: parentId || 0, options: folderOptions(0) },
				{ name: 'cat', label: T.category, type: 'select', value: 0, options: catOptions() }
			]
		}).done(function (v) {
			ensureCat(self, v.cat).done(function (cat) {
				var parent = parseInt(v.parent, 10) || 0;
				api('create', { name: v.name, parent: parent, cat: cat })
					.done(function (r) {
						if (parent) { self.open[parent] = true; self.saveOpen(); }
						self.select(r.id);
					})
					.fail(function (m) { toast(m, true); });
			});
		});
	};

	Sidebar.prototype.askRename = function (id) {
		var f = byId(id);
		if (!f) { return; }
		dialog({ container: this.container(), title: T.rename, fields: [{ name: 'name', label: T.name, type: 'text', value: f.name }] })
			.done(function (v) { api('rename', { id: id, name: v.name }).fail(function (m) { toast(m, true); }); });
	};

	Sidebar.prototype.askCat = function (id) {
		var f = byId(id), self = this;
		if (!f) { return; }
		dialog({ container: this.container(), title: T.setCat, fields: [{ name: 'cat', label: T.category, type: 'select', value: f.cat, options: catOptions() }] })
			.done(function (v) {
				ensureCat(self, v.cat).done(function (cat) {
					api('set_cat', { id: id, cat: cat }).fail(function (m) { toast(m, true); });
				});
			});
	};

	Sidebar.prototype.askMove = function (id) {
		var f = byId(id), self = this;
		if (!f) { return; }
		dialog({ container: this.container(), title: T.moveTo, fields: [{ name: 'parent', label: T.parent, type: 'select', value: f.parent, options: folderOptions(id) }] })
			.done(function (v) {
				var parent = parseInt(v.parent, 10) || 0;
				api('move_folder', { id: id, parent: parent })
					.done(function () { if (parent) { self.open[parent] = true; self.saveOpen(); self.render(); } })
					.fail(function (m) { toast(m, true); });
			});
	};

	Sidebar.prototype.askDelete = function (id) {
		var f = byId(id), self = this;
		if (!f) { return; }
		dialog({ container: this.container(), title: T.del, message: fmt(T.confirmDelete, f.name), ok: T.delete, danger: true })
			.done(function () {
				api('delete', { id: id })
					.done(function () { if (self.current === id) { self.select('all'); } })
					.fail(function (m) { toast(m, true); });
			});
	};

	/* ---- render ---- */

	Sidebar.prototype.renderChips = function () {
		var self = this;
		this.$chips.empty();
		if (!C.cats.length && !C.settings) { this.$chips.hide(); return; }
		this.$chips.show();
		C.cats.forEach(function (c) {
			var $c = $('<button type="button" class="cdm-chip">').attr('data-cat', c.id).toggleClass('is-on', self.cat === c.id);
			$('<span class="cdm-dot">').css('background', c.color).appendTo($c);
			$('<span>').text(c.name).appendTo($c);
			$c.appendTo(self.$chips);
		});
		if (C.settings && C.canManage) {
			$('<a class="cdm-chip cdm-chip-gear" target="_blank" rel="noopener">').attr({ href: C.settings, title: T.manageCats }).text('⚙').appendTo(this.$chips);
		}
		if (!C.cats.length) { this.$chips.hide(); if (C.settings && C.canManage) { this.$chips.show(); } }
	};

	Sidebar.prototype.node = function (id, label, count, icon, color) {
		var $li = $('<li class="cdm-node" role="treeitem">').attr('data-id', id).toggleClass('is-active', this.current === id);
		var $row = $('<div class="cdm-row" tabindex="0">').css('padding-left', 8).appendTo($li);
		$('<span class="cdm-toggle cdm-toggle--empty">').appendTo($row);
		$('<span class="cdm-ico">').html(icon).css('color', color || '').appendTo($row);
		$('<span class="cdm-name">').text(label).attr('title', label).appendTo($row);
		$('<span class="cdm-count">').text(count).appendTo($row);
		return $li;
	};

	Sidebar.prototype.folderNode = function (f, depth, filtering, match, show) {
		var self = this;
		var kids = childrenOf(f.id).filter(function (k) { return !show || show[k.id]; });
		var isOpen = !!kids.length && (filtering || !!this.open[f.id]);
		var cat = f.cat ? catById(f.cat) : null;
		var $li = $('<li class="cdm-node" role="treeitem">').attr('data-id', f.id)
			.toggleClass('is-active', this.current === f.id)
			.toggleClass('has-children', !!kids.length)
			.toggleClass('is-open', isOpen)
			.toggleClass('is-dim', filtering && !match[f.id]);
		var $row = $('<div class="cdm-row" tabindex="0">').css('padding-left', 8 + depth * 14).appendTo($li);
		if (C.canManage) { $row.attr('draggable', 'true'); }
		if (kids.length) {
			$('<button type="button" class="cdm-toggle" tabindex="-1" aria-label="">').html('<svg viewBox="0 0 20 20" width="14" height="14"><path fill="currentColor" d="M7 4.5l6 5.5-6 5.5z"/></svg>').appendTo($row);
		} else {
			$('<span class="cdm-toggle cdm-toggle--empty">').appendTo($row);
		}
		$('<span class="cdm-ico">').html(ICONS.folder).css('color', cat ? cat.color : '').toggleClass('has-cat', !!cat).appendTo($row);
		$('<span class="cdm-name">').text(f.name).attr('title', f.name + (cat ? ' · ' + cat.name : '')).appendTo($row);
		$('<span class="cdm-count">').text(f.count).appendTo($row);
		if (C.canManage) {
			$('<button type="button" class="cdm-more" tabindex="-1" aria-label="⋯">').text('⋯').appendTo($row);
		}
		if (isOpen) {
			var $ul = $('<ul class="cdm-sub" role="group">').appendTo($li);
			kids.forEach(function (k) { $ul.append(self.folderNode(k, depth + 1, filtering, match, show)); });
		}
		return $li;
	};

	Sidebar.prototype.render = function () {
		var self = this;
		var scroll = this.$tree.scrollTop();
		this.renderChips();
		this.$tree.empty();

		var filtering = !!(this.q || this.cat);
		var show = null, match = {};
		if (filtering) {
			show = {};
			var q = norm(this.q);
			C.folders.forEach(function (f) {
				if ((!q || norm(f.name).indexOf(q) > -1) && (!self.cat || f.cat === self.cat)) {
					match[f.id] = true;
					show[f.id] = true;
					ancestors(f).forEach(function (a) { show[a] = true; });
				}
			});
		}

		var $ul = $('<ul class="cdm-list" role="tree">').appendTo(this.$tree);
		if (!filtering) {
			$ul.append(this.node('all', T.all, C.counts.all, ICONS.all));
			$ul.append(this.node('none', T.none, C.counts.none, ICONS.none));
		}
		var roots = childrenOf(0).filter(function (f) { return !show || show[f.id]; });
		roots.forEach(function (f) { $ul.append(self.folderNode(f, 0, filtering, match, show)); });
		if (filtering && !roots.length) {
			$('<p class="cdm-empty">').text(T.noResults).appendTo(this.$tree);
		} else if (!filtering && !C.folders.length) {
			$('<p class="cdm-empty">').text(T.empty).appendTo(this.$tree);
		}
		this.$tree.scrollTop(scroll);

		if (this.opts.mode === 'media') {
			var cur = typeof this.current === 'number' ? byId(this.current) : null;
			this.$hint.html('').append($('<span>').text(T.uploadTo + ' ')).append($('<b>').text(cur ? cur.name : T.none)).show();
		} else {
			this.$hint.hide();
		}
	};

	/* ------------------------------------------------------------ ventana de medios (wp.media) */

	function refreshCollection(coll) {
		coll.props.set({ cdm_ts: Date.now() });
	}

	function dragIds(ids) {
		if (!ids.length) { return; }
		beginDrag({ type: 'files', ids: ids });
	}

	function setDragImage(e, n) {
		var dt = e.originalEvent.dataTransfer;
		dt.effectAllowed = 'move';
		dt.setData('text/plain', 'cdm-files');
		if (dt.setDragImage) {
			var $g = $('<div class="cdm-dragimg">').text(n === 1 ? T.dragFile : fmt(T.dragFiles, n)).appendTo(document.body);
			dt.setDragImage($g[0], 10, 10);
			setTimeout(function () { $g.remove(); }, 0);
		}
	}

	function attachToBrowser(view) {
		var coll = view.collection;
		if (!coll || !coll.props || view.$el.data('cdm')) { return; }
		view.$el.data('cdm', true);

		var cur = coll.props.get('cdm_folder');
		var current = (cur === undefined || cur === null || cur === '' || cur === 'all') ? 'all' : (cur === 'none' ? 'none' : (parseInt(cur, 10) || 'all'));
		var saved = ls('cdmCollapsed');
		var collapsed = saved === null ? (window.innerWidth < 1100) : saved === '1';

		var sb = new Sidebar({
			mode: 'media',
			current: current,
			collapsible: true,
			onSelect: function (id) {
				// En «Todos» se conserva la consulta estándar (WordPress sigue añadiendo las subidas nuevas).
				// En una carpeta se fuerza una consulta nueva para evitar resultados en caché.
				coll.props.set({ cdm_folder: id === 'all' ? null : String(id), cdm_ts: id === 'all' ? null : Date.now() });
			},
			onMoved: function () { if (sb.current !== 'all') { refreshCollection(coll); } },
			onImported: function () { refreshCollection(coll); },
			onUploaded: function (att, folder) {
				// WordPress no añade las subidas a consultas con filtros propios: lo hacemos aquí.
				if (!att || sb.current === 'all') { return; }
				if ((folder && sb.current === folder) || (!folder && sb.current === 'none')) { coll.add(att); }
			},
			onToggle: function () {
				collapsed = !collapsed;
				ls('cdmCollapsed', collapsed ? '1' : '0');
				view.$el.toggleClass('cdm-collapsed', collapsed);
			}
		});
		// Subvista real: así sobrevive a los render() de la ventana de medios.
		var host = new wp.Backbone.View({ el: sb.$el[0] });
		view.views.add(host);
		initFolderImport();
		view.$el.addClass('cdm-has-sidebar').toggleClass('cdm-collapsed', collapsed);

		// Arrastrar adjuntos hacia el árbol.
		view.$el.on('dragstart.cdm', 'li.attachment', function (e) {
			var id = parseInt($(this).attr('data-id'), 10);
			if (!id) { return; }
			var ids = [id];
			try {
				var sel = view.controller.state().get('selection');
				if (sel && sel.get(id)) { ids = sel.pluck('id'); }
			} catch (err) { /* sin selección */ }
			dragIds(ids);
			setDragImage(e, ids.length);
		});

		// Actualizar con las carpetas más recientes (otra pestaña, otro usuario…).
		api('tree');

		var remove = view.remove;
		view.remove = function () {
			var i = sidebars.indexOf(sb);
			if (i > -1) { sb.destroy(); }
			return remove.apply(this, arguments);
		};
	}

	function patchMedia() {
		if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.AttachmentsBrowser) { return; }
		var V = wp.media.view;

		var init = V.AttachmentsBrowser.prototype.initialize;
		V.AttachmentsBrowser.prototype.initialize = function () {
			init.apply(this, arguments);
			try { attachToBrowser(this); } catch (e) { if (window.console) { console.error('Carpetas de Medios:', e); } }
		};

		// Elementos arrastrables.
		var attrs = V.Attachment.prototype.attributes;
		V.Attachment.prototype.attributes = function () {
			var a = (typeof attrs === 'function') ? attrs.apply(this, arguments) : $.extend({}, attrs);
			if (this.model && this.model.get('id')) { a.draggable = 'true'; }
			return a;
		};

		// Las subidas nuevas se guardan en la carpeta activa.
		if (wp.Uploader) {
			var oldInit = wp.Uploader.prototype.init;
			wp.Uploader.prototype.init = function () {
				if (oldInit) { oldInit.apply(this, arguments); }
				if (!this.uploader) { return; }
				this.uploader.bind('BeforeUpload', function (up, file) {
					up.settings.multipart_params = up.settings.multipart_params || {};
					var f = uploadFolder();
					file.cdmFolder = f;
					if (f) { up.settings.multipart_params.cdm_folder = f; } else { delete up.settings.multipart_params.cdm_folder; }
				});
				this.uploader.bind('FileUploaded', function (up, file) {
					sidebars.forEach(function (s) { if (s.opts.onUploaded) { s.opts.onUploaded(file.attachment, file.cdmFolder || 0); } });
				});
				this.uploader.bind('UploadComplete', function () { api('tree'); });
			};
		}
	}

	/* ------------------------------------------------------------ importar carpetas del equipo (arrastrar y soltar) */

	var importing = false;

	function uploadSettings() {
		var s = window._wpPluploadSettings;
		return (s && s.defaults && s.defaults.url) ? s.defaults : null;
	}

	function allowedExts() {
		var u = uploadSettings();
		try {
			var ext = u.filters.mime_types[0].extensions;
			return ext ? ext.toLowerCase().split(',') : null;
		} catch (e) { return null; }
	}

	function maxBytes() {
		var u = uploadSettings();
		try { return parseInt(u.filters.max_file_size, 10) || 0; } catch (e) { return 0; }
	}

	function readAllEntries(reader) {
		var d = $.Deferred(), all = [];
		(function next() {
			reader.readEntries(function (batch) {
				if (!batch.length) { d.resolve(all); return; }
				all = all.concat(Array.prototype.slice.call(batch));
				next();
			}, function () { d.resolve(all); });
		})();
		return d.promise();
	}

	// Recorre las entradas soltadas: llena out.files [{dir, file}] y out.dirs [ruta].
	function walk(entry, segs, out) {
		var d = $.Deferred();
		if (entry.isFile) {
			entry.file(function (f) { out.files.push({ dir: segs.join('/'), file: f }); d.resolve(); }, function () { d.resolve(); });
		} else if (entry.isDirectory) {
			var mine = segs.concat(entry.name);
			out.dirs.push(mine.join('/'));
			readAllEntries(entry.createReader()).done(function (list) {
				var chain = $.Deferred().resolve();
				list.forEach(function (child) { chain = chain.then(function () { return walk(child, mine, out); }); });
				chain.always(function () { d.resolve(); });
			});
		} else { d.resolve(); }
		return d.promise();
	}

	function progressPanel() {
		var $p = $('.cdm-imp');
		if ($p.length) { return $p; }
		$p = $('<div class="cdm-imp" role="status">').css('z-index', Z)
			.append('<div class="cdm-imp-head"><strong></strong><button type="button" class="cdm-ibtn cdm-imp-x" aria-label="' + $('<i>').text(T.impClose || '').html() + '">×</button></div>')
			.append('<div class="cdm-imp-bar"><span></span></div><div class="cdm-imp-txt"></div><ul class="cdm-imp-list"></ul>')
			.appendTo(document.body);
		$p.on('click', '.cdm-imp-x', function () { if (!importing) { $p.remove(); } });
		return $p;
	}

	function uploadOne(item, folderId, up) {
		var d = $.Deferred(), fd = new FormData();
		fd.append('name', item.file.name);
		fd.append('action', 'upload-attachment');
		fd.append('_wpnonce', up.multipart_params._wpnonce);
		if (folderId) { fd.append('cdm_folder', folderId); }
		fd.append('async-upload', item.file, item.file.name);
		$.ajax({ url: up.url, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
			.done(function (r) {
				if (r && r.success) { d.resolve(); } else { d.reject((r && r.data && r.data.message) || T.error); }
			})
			.fail(function (x) { d.reject((x.responseJSON && x.responseJSON.data && x.responseJSON.data.message) || x.statusText || T.error); });
		return d.promise();
	}

	function runImport(parentId, entries) {
		if (importing) { return; }
		var up = uploadSettings();
		if (!up || !up.multipart_params || !up.multipart_params._wpnonce) { toast(T.impNoUploader, true); return; }
		importing = true;
		var $p = progressPanel();
		$p.find('.cdm-imp-head strong').text(T.impReading);
		$p.find('.cdm-imp-bar span').css('width', '0');
		$p.find('.cdm-imp-txt, .cdm-imp-list').empty();

		var out = { files: [], dirs: [] }, chain = $.Deferred().resolve();
		entries.forEach(function (en) { chain = chain.then(function () { return walk(en, [], out); }); });

		chain.always(function () {
			// Filtrado: ocultos, tipos no permitidos, tamaño.
			var exts = allowedExts(), max = maxBytes(), skipped = 0;
			var files = out.files.filter(function (it) {
				var n = it.file.name, ext = (n.split('.').pop() || '').toLowerCase();
				var ok = n.charAt(0) !== '.' && n.indexOf('.') > -1 && (!exts || exts.indexOf(ext) > -1) && (!max || it.file.size <= max);
				if (!ok) { skipped++; }
				return ok;
			});
			var dirs = out.dirs;

			function finish(msg, isErr) {
				importing = false;
				$p.find('.cdm-imp-head strong').text(T.impDone);
				if (msg) { $p.find('.cdm-imp-txt').text(msg); }
				if (isErr) { $p.addClass('is-error'); }
			}

			if (!files.length && !dirs.length) { importing = false; $p.remove(); toast(T.impNothing, true); return; }
			if (dirs.length && !C.canManage) { importing = false; $p.remove(); toast(T.impNoManage, true); return; }

			var treeDone = dirs.length ? api('import_tree', { parent: parentId, paths: dirs }) : $.Deferred().resolve({ map: {}, created: 0 }).promise();
			treeDone.fail(function (m) { finish(m, true); }).done(function (r) {
				var map = r.map || {}, created = r.created || 0;
				var total = files.length, done = 0, ok = 0, failed = [], idx = 0, running = 0, LIMIT = 3;

				function paint() {
					$p.find('.cdm-imp-head strong').text(fmt2(T.impUploading, done, total));
					$p.find('.cdm-imp-bar span').css('width', (total ? Math.round(done / total * 100) : 100) + '%');
				}
				function end() {
					var txt = fmt2(T.impSummary, ok, created);
					if (skipped) { txt += ' · ' + fmt(T.impSkipped, skipped); }
					finish(txt, false);
					$p.find('.cdm-imp-bar span').css('width', '100%');
					if (failed.length) {
						$p.find('.cdm-imp-list').before($('<div class="cdm-imp-txt">').text(fmt(T.impFailed, failed.length)));
						failed.slice(0, 15).forEach(function (f) { $('<li>').text(f).appendTo($p.find('.cdm-imp-list')); });
					}
					api('tree');
					sidebars.forEach(function (s) { if (s.opts.onImported) { s.opts.onImported(); } });
				}
				function pump() {
					while (running < LIMIT && idx < total) {
						(function (it) {
							running++;
							var fid = it.dir ? (map[it.dir] || parentId) : parentId;
							uploadOne(it, fid, up)
								.done(function () { ok++; })
								.fail(function (m) { failed.push(it.file.name + ' — ' + m); })
								.always(function () { running--; done++; paint(); if (done >= total) { end(); } else { pump(); } });
						})(files[idx++]);
					}
				}
				paint();
				if (!total) { end(); } else { pump(); }
			});
		});
	}

	function fmt2(str, a, b) { return String(str).replace('%1$d', a).replace('%2$d', b); }

	var overTimer = null;

	function sidebarHit(e) {
		var els = document.elementsFromPoint ? document.elementsFromPoint(e.clientX, e.clientY) : [];
		for (var i = 0; i < els.length; i++) {
			var $t = $(els[i]).closest('.cdm-tree');
			if ($t.length) {
				var $row = $(els[i]).closest('.cdm-row');
				var raw = $row.length ? $row.closest('.cdm-node').attr('data-id') : 'all';
				return { $row: $row, parent: (raw === 'all' || raw === 'none') ? 0 : (parseInt(raw, 10) || 0) };
			}
		}
		return null;
	}

	function externalFiles(e) {
		var dt = e.dataTransfer;
		if (!dt || drag || !dt.types) { return false; }
		return Array.prototype.indexOf.call(dt.types, 'Files') > -1;
	}

	function hasDirectory(dt) {
		if (!dt.items) { return false; }
		for (var i = 0; i < dt.items.length; i++) {
			var en = dt.items[i].webkitGetAsEntry ? dt.items[i].webkitGetAsEntry() : null;
			if (en && en.isDirectory) { return true; }
		}
		return false;
	}

	function initFolderImport() {
		if (window.cdmImportBound) { return; }
		window.cdmImportBound = true;

		window.addEventListener('dragover', function (e) {
			if (!sidebars.length || !externalFiles(e)) { return; }
			var hit = sidebarHit(e);
			$('.cdm-row.is-drop').removeClass('is-drop');
			clearTimeout(overTimer);
			if (!hit) { $('body').removeClass('cdm-dragging'); return; }
			$('body').addClass('cdm-dragging'); // oculta el aviso de WordPress mientras se está sobre el árbol.
			e.preventDefault();
			e.stopPropagation();
			e.dataTransfer.dropEffect = 'copy';
			if (hit.$row.length) { hit.$row.addClass('is-drop'); }
			overTimer = setTimeout(function () { $('.cdm-row.is-drop').removeClass('is-drop'); $('body').removeClass('cdm-dragging'); }, 400);
		}, true);

		window.addEventListener('drop', function (e) {
			if (!sidebars.length || !externalFiles(e)) { return; }
			var hit = sidebarHit(e);
			var dirs = hasDirectory(e.dataTransfer);
			// Sobre el árbol: se importa en esa carpeta. Fuera del árbol: sólo se intercepta si se sueltan carpetas.
			if (!hit && !dirs) { return; }
			e.preventDefault();
			e.stopPropagation();
			clearTimeout(overTimer);
			$('.cdm-row.is-drop').removeClass('is-drop');
			$('body').removeClass('cdm-dragging');
			var parent = hit ? hit.parent : uploadFolder();
			var entries = [], items = e.dataTransfer.items;
			for (var i = 0; items && i < items.length; i++) {
				if (items[i].kind !== 'file') { continue; }
				var en = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
				if (en) { entries.push(en); }
			}
			if (!entries.length) { return; }
			runImport(parent, entries);
		}, true);
	}

	/* ------------------------------------------------------------ vista de lista */

	function initList() {
		var $wrap = $('#wpbody-content .wrap').first();
		var $end = $wrap.find('.wp-header-end').first();
		if (!$end.length) { $end = $wrap.find('h1').first(); }
		if (!$wrap.length || !$end.length) { return; }

		var $rest = $end.nextAll();
		var $layout = $('<div class="cdm-list-layout">');
		var $side = $('<div class="cdm-list-side">');
		var $main = $('<div class="cdm-list-main">');
		$end.after($layout);
		$layout.append($side, $main);
		$main.append($rest);

		var current = C.current === 'all' ? 'all' : (C.current === 'none' ? 'none' : (parseInt(C.current, 10) || 'all'));
		var sb = new Sidebar({
			mode: 'list',
			current: current,
			onSelect: function (id) {
				var u = new URL(window.location.href);
				['paged', 'cdm_notice', 'cdm_n'].forEach(function (k) { u.searchParams.delete(k); });
				if (id === 'all') { u.searchParams.delete('cdm_folder'); } else { u.searchParams.set('cdm_folder', String(id)); }
				window.location.href = u.toString();
			},
			onMoved: function () { window.location.reload(); }
		});
		$side.append(sb.$el);

		// Filas arrastrables.
		$main.find('#the-list tr').attr('draggable', 'true');
		$main.on('dragstart', '#the-list tr', function (e) {
			var m = /post-(\d+)/.exec(this.id || '');
			if (!m) { return; }
			var id = parseInt(m[1], 10);
			var ids = [];
			var $chk = $main.find('#the-list input[name="media[]"]:checked');
			$chk.each(function () { ids.push(parseInt(this.value, 10)); });
			if (ids.indexOf(id) === -1) { ids = [id]; }
			dragIds(ids);
			setDragImage(e, ids.length);
		});

		// Selector «Mover a carpeta…» sólo cuando se elige esa acción masiva.
		var $target = $main.find('.cdm-bulk-target');
		$target.insertAfter($main.find('#doaction').first()); // junto al desplegable de acciones masivas.
		function toggleTarget() {
			var on = $main.find('select[name="action"], select[name="action2"]').filter(function () { return $(this).val() === 'cdm_move'; }).length > 0;
			$target.toggle(on);
		}
		$main.on('change', 'select[name="action"], select[name="action2"]', toggleTarget);
		toggleTarget();
	}

	/* ------------------------------------------------------------ campo «Carpeta» en el detalle del adjunto */

	$(document).on('change', '.cdm-field-select', function () {
		var id = parseInt($(this).attr('data-id'), 10);
		var folder = parseInt($(this).val(), 10) || 0;
		api('assign', { ids: [id], folder: folder })
			.done(function () { sidebars.forEach(function (s) { if (s.opts.onMoved && s.opts.mode === 'media') { s.opts.onMoved(); } }); })
			.fail(function (m) { toast(m, true); });
	});

	/* ------------------------------------------------------------ arranque */

	if (C.mode === 'list') {
		$(initList);
	} else {
		patchMedia();
	}

})(jQuery);
