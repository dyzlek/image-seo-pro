/* Image SEO Pro — page d'administration. Sans dépendance. */
(function () {
	'use strict';

	var cfg = window.ISP || {};
	var t = cfg.i18n || {};

	function format(str) {
		var args = Array.prototype.slice.call(arguments, 1);
		return String(str).replace(/%(\d+)\$[sd]/g, function (m, n) {
			return args[n - 1] !== undefined ? args[n - 1] : m;
		});
	}

	/**
	 * Appelle une action AJAX. Résout avec `data`, rejette avec un message lisible.
	 */
	function call(action, params) {
		var body = new FormData();
		body.append('action', 'isp_' + action);
		body.append('nonce', cfg.nonce);
		Object.keys(params || {}).forEach(function (key) {
			body.append(key, params[key]);
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (res) {
				return res.json().catch(function () {
					throw new Error(t.requestFailed);
				});
			})
			.then(function (json) {
				if (!json || !json.success) {
					throw new Error((json && json.data && json.data.message) || t.error);
				}
				return json.data || {};
			}, function (err) {
				throw new Error(err && err.message ? err.message : t.requestFailed);
			});
	}

	function busy(button, on) {
		if (on) {
			button.dataset.label = button.textContent;
			button.textContent = t.working;
			button.disabled = true;
		} else {
			button.textContent = button.dataset.label || button.textContent;
			button.disabled = false;
		}
	}

	function replaceRow(row, html) {
		var tpl = document.createElement('template');
		tpl.innerHTML = html.trim();
		row.replaceWith(tpl.content.firstElementChild);
	}

	/* ---------------------------------------------------------------- */
	/* Traitement par lots avec barre de progression                    */
	/* ---------------------------------------------------------------- */

	function runBulk(box, ids, task) {
		var progress = box.querySelector('.isp-progress');
		var bar = progress.querySelector('.isp-progress-bar');
		var text = progress.querySelector('.isp-progress-text');
		var log = progress.querySelector('.isp-progress-log');
		var stop = progress.querySelector('.isp-bulk-stop');
		var starts = box.querySelectorAll('.isp-bulk-start');
		var done = 0, ok = 0, failed = 0, stopped = false;

		function addLog(message, isError) {
			var li = document.createElement('li');
			li.textContent = message;
			if (isError) {
				li.className = 'is-error';
			}
			log.insertBefore(li, log.firstChild);
		}

		function update() {
			var pct = ids.length ? Math.round((100 * done) / ids.length) : 100;
			bar.setAttribute('aria-valuenow', pct);
			bar.firstElementChild.style.width = pct + '%';
			text.textContent = format(t.progress, done, ids.length);
		}

		function finish(message) {
			text.textContent = message;
			stop.hidden = true;
			starts.forEach(function (b) { b.disabled = false; });
		}

		progress.hidden = false;
		stop.hidden = false;
		log.textContent = '';
		starts.forEach(function (b) { b.disabled = true; });
		stop.onclick = function () { stopped = true; };
		update();

		if (!ids.length) {
			finish(t.nothingToDo);
			return;
		}

		// Une image après l'autre : le serveur n'est jamais saturé et on peut arrêter à tout moment.
		(function next(i) {
			if (stopped) {
				finish(t.stopped + ' ' + format(t.done, ok, failed));
				return;
			}
			if (i >= ids.length) {
				finish(format(t.done, ok, failed));
				return;
			}
			task(ids[i]).then(function (data) {
				ok++;
				if (data && data.message) {
					addLog(data.message, false);
				}
			}, function (err) {
				failed++;
				addLog('#' + ids[i] + ' — ' + err.message, true);
			}).then(function () {
				done++;
				update();
				next(i + 1);
			});
		})(0);
	}

	document.querySelectorAll('[data-isp-bulk]').forEach(function (box) {
		box.addEventListener('click', function (e) {
			var start = e.target.closest('.isp-bulk-start');
			if (!start) {
				return;
			}
			var kind = box.dataset.ispBulk;
			var mode = start.dataset.mode;

			if (kind === 'alt' && !window.confirm(mode === 'ai' ? t.confirmAi : t.confirmName)) {
				return;
			}

			var list = kind === 'optimize'
				? call('pending', { force: document.getElementById('isp-force') && document.getElementById('isp-force').checked ? 1 : 0 })
				: call('missing_alt');

			start.disabled = true;
			list.then(function (data) {
				runBulk(box, data.ids || [], function (id) {
					return kind === 'optimize'
						? call('optimize', { id: id })
						: call('suggest_alt', { id: id, mode: mode, save: 1 });
				});
			}, function (err) {
				start.disabled = false;
				window.alert(err.message);
			});
		});
	});

	/* ---------------------------------------------------------------- */
	/* Onglet Images                                                    */
	/* ---------------------------------------------------------------- */

	document.addEventListener('click', function (e) {
		var button = e.target.closest('.isp-optimize, .isp-restore');
		if (!button) {
			return;
		}
		var row = button.closest('tr');
		var restore = button.classList.contains('isp-restore');
		if (restore && !window.confirm(t.confirmRestore)) {
			return;
		}
		busy(button, true);
		call(restore ? 'restore' : 'optimize', { id: row.dataset.id }).then(function (data) {
			replaceRow(row, data.row);
		}, function (err) {
			busy(button, false);
			window.alert(err.message);
		});
	});

	/* ---------------------------------------------------------------- */
	/* Onglet Textes alternatifs                                        */
	/* ---------------------------------------------------------------- */

	function setStatus(row, message, isError) {
		var status = row.querySelector('.isp-alt-status');
		status.textContent = message;
		status.className = 'isp-alt-status' + (isError ? ' is-error' : ' is-ok');
	}

	function refreshCount(input) {
		var row = input.closest('tr');
		var count = row.querySelector('.isp-alt-count');
		count.textContent = input.value.length;
		count.parentNode.classList.toggle('is-long', input.value.length > 125);
		row.classList.toggle('is-dirty', input.value !== input.dataset.saved);
	}

	function saveAlt(row) {
		var input = row.querySelector('.isp-alt-input');
		var button = row.querySelector('.isp-alt-save');
		busy(button, true);
		return call('save_alt', { id: row.dataset.id, alt: input.value }).then(function (data) {
			input.value = data.alt;
			input.dataset.saved = data.alt;
			refreshCount(input);
			busy(button, false);
			setStatus(row, t.saved, false);
		}, function (err) {
			busy(button, false);
			setStatus(row, err.message, true);
		});
	}

	document.addEventListener('input', function (e) {
		if (e.target.classList.contains('isp-alt-input')) {
			refreshCount(e.target);
			setStatus(e.target.closest('tr'), '', false);
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.classList.contains('isp-alt-input')) {
			e.preventDefault();
			saveAlt(e.target.closest('tr'));
		}
	});

	document.addEventListener('click', function (e) {
		var save = e.target.closest('.isp-alt-save');
		if (save) {
			saveAlt(save.closest('tr'));
			return;
		}
		var suggest = e.target.closest('.isp-alt-suggest');
		if (!suggest) {
			return;
		}
		var row = suggest.closest('tr');
		var input = row.querySelector('.isp-alt-input');
		busy(suggest, true);
		setStatus(row, '', false);
		// La suggestion remplit le champ sans l'enregistrer : on relit, puis « Enregistrer ».
		call('suggest_alt', { id: row.dataset.id, mode: suggest.dataset.mode }).then(function (data) {
			busy(suggest, false);
			input.value = data.alt;
			refreshCount(input);
			input.focus();
		}, function (err) {
			busy(suggest, false);
			setStatus(row, err.message, true);
		});
	});

	/* ---------------------------------------------------------------- */
	/* Réglages                                                         */
	/* ---------------------------------------------------------------- */

	var test = document.querySelector('.isp-test-ai');
	if (test) {
		test.addEventListener('click', function () {
			var result = document.querySelector('.isp-test-result');
			result.textContent = '';
			result.className = 'isp-test-result';
			busy(test, true);
			call('test_ai').then(function () {
				busy(test, false);
				result.textContent = t.connectionOk;
				result.classList.add('is-ok');
			}, function (err) {
				busy(test, false);
				result.textContent = err.message;
				result.classList.add('is-error');
			});
		});
	}
})();
