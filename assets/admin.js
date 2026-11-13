/* global jQuery, s3bs */
(function ($) {
	'use strict';

	var i18n = s3bs.i18n;
	var $panel = $('#s3bs-job');
	var pollTimer = null;
	var lastMessage = '';
	var lastChange = Date.now();
	var restFailures = 0;

	// ------------------------------------------------------------------
	// Utility
	// ------------------------------------------------------------------

	function ajax(action, data) {
		return $.ajax({
			url: s3bs.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: $.extend({ action: 's3bs_' + action, nonce: s3bs.nonce }, data || {})
		}).then(function (response) {
			if (response && response.success) {
				return response.data || {};
			}
			return $.Deferred().reject(response && response.data && response.data.message ? response.data.message : i18n.error).promise();
		}, function (xhr) {
			var message = i18n.error;
			if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
				message = xhr.responseJSON.data.message;
			}
			return $.Deferred().reject(message).promise();
		});
	}

	/** Converte l'HTML dei messaggi di AI1WM in testo semplice (mantiene gli a capo). */
	function toText(html) {
		if (!html) {
			return '';
		}
		var withBreaks = String(html).replace(/<br\s*\/?>/gi, '\n');
		return $('<div>').html(withBreaks).text().replace(/[ \t]+/g, ' ').trim();
	}

	function setBusy(busy) {
		$('.s3bs-wrap').toggleClass('s3bs-busy', busy);
		$('.s3bs-actions .button, #s3bs-backup-now button[type=submit]').prop('disabled', busy);
	}

	function showPanel(state, title, message) {
		$panel.prop('hidden', false)
			.removeClass('is-running is-done is-error is-confirm')
			.addClass('is-' + state);
		$panel.find('.spinner').toggleClass('is-active', state === 'running');
		$panel.find('.s3bs-job-title').text(title || '');
		$panel.find('.s3bs-job-message').text(message || '');
		$panel.find('.s3bs-job-actions').empty();

		if (message !== lastMessage) {
			lastMessage = message;
			lastChange = Date.now();
		}

		$('html, body').animate({ scrollTop: $panel.offset().top - 60 }, 200);
	}

	function addButton(label, cls, handler) {
		$('<button type="button" class="button">').addClass(cls || '').text(label).on('click', handler).appendTo($panel.find('.s3bs-job-actions'));
	}

	function addReloadButtons(withLogin) {
		addButton(i18n.reload, 'button-primary', function () {
			window.location.reload();
		});
		if (withLogin) {
			addButton(i18n.login, '', function () {
				window.location.href = s3bs.loginUrl;
			});
		}
	}

	function stopPolling() {
		if (pollTimer) {
			clearTimeout(pollTimer);
			pollTimer = null;
		}
	}

	function stalledSuffix() {
		return (Date.now() - lastChange > 5 * 60 * 1000) ? '\n\n' + i18n.stalled : '';
	}

	// ------------------------------------------------------------------
	// Polling: backup e caricamenti
	// ------------------------------------------------------------------

	function pollBackup(job) {
		ajax('job_status', { job: job }).then(function (data) {
			var history = data.history || {};
			var status = data.ai1wm || {};

			if (history.status === 'ok') {
				stopPolling();
				setBusy(false);
				showPanel('done', i18n.done, toText(history.message));
				addReloadButtons(false);
				return;
			}

			if (history.status === 'error' || status.type === 'error') {
				stopPolling();
				setBusy(false);
				var text = history.status === 'error' ? toText(history.message) : toText((status.title ? status.title + ': ' : '') + (status.message || ''));
				showPanel('error', i18n.error, text);
				addReloadButtons(false);
				return;
			}

			var message = toText(status.message) || toText(history.message) || i18n.working;
			if (status.type === 'progress' && typeof status.percent !== 'undefined') {
				message += ' ' + status.percent + '%';
			}
			showPanel('running', i18n.working, message + stalledSuffix());
			pollTimer = setTimeout(function () { pollBackup(job); }, 3000);
		}, function () {
			pollTimer = setTimeout(function () { pollBackup(job); }, 5000);
		});
	}

	// ------------------------------------------------------------------
	// Polling: ripristino
	// Dopo l'import del database la sessione di WordPress non è più valida, quindi
	// lo stato si legge dall'API REST di All-in-One WP Migration con la secret key.
	// ------------------------------------------------------------------

	function readRestoreStatus(job) {
		if (restFailures < 3) {
			return $.ajax({
				url: s3bs.restUrl + encodeURIComponent(job),
				method: 'GET',
				dataType: 'json',
				cache: false,
				data: { secret_key: s3bs.secretKey }
			}).then(function (data) {
				restFailures = 0;
				return data;
			}, function () {
				restFailures++;
				return fallbackStatus(job);
			});
		}
		return fallbackStatus(job);
	}

	function fallbackStatus(job) {
		return ajax('job_status', { job: job }).then(function (data) {
			var status = $.extend({}, data.ai1wm || {});
			var history = data.history || {};
			if (history.status === 'ok') {
				status.status = 'complete';
			} else if (history.status === 'error') {
				status.status = 'error';
				status.message = status.message || history.message;
			} else if (status.type === 'done') {
				status.status = 'complete';
			} else if (status.type === 'error' || status.type === 'server_cannot_decrypt') {
				status.status = 'error';
			} else if (status.type === 'confirm' || status.type === 'disk_space_confirm' || status.type === 'backup_is_encrypted') {
				status.status = 'confirm';
			} else if (status.type === 'canceled') {
				status.status = 'canceled';
			} else {
				status.status = 'running';
				status.message = status.message || history.message;
			}
			return status;
		});
	}

	function pollRestore(job) {
		readRestoreStatus(job).then(function (data) {
			var state = data.status || 'running';
			var message = toText(data.message);

			if (state === 'complete') {
				stopPolling();
				setBusy(false);
				showPanel('done', i18n.done, (message ? message + '\n\n' : '') + i18n.restoreDone);
				addReloadButtons(true);
				return;
			}

			if (state === 'error' || state === 'canceled') {
				stopPolling();
				setBusy(false);
				showPanel('error', i18n.error, toText((data.title ? data.title + ': ' : '') + (data.message || '')));
				addReloadButtons(false);
				return;
			}

			if (state === 'confirm') {
				stopPolling();
				askConfirmation(job, data);
				return;
			}

			var text = message || i18n.working;
			if (data.type === 'progress' && typeof data.percent !== 'undefined') {
				text += ' ' + data.percent + '%';
			}
			showPanel('running', i18n.working, text + stalledSuffix());
			pollTimer = setTimeout(function () { pollRestore(job); }, 3000);
		}, function () {
			showPanel('running', i18n.working, (lastMessage || i18n.working) + stalledSuffix());
			pollTimer = setTimeout(function () { pollRestore(job); }, 5000);
		});
	}

	function askConfirmation(job, data) {
		var encrypted = data.type === 'backup_is_encrypted';
		var text = encrypted ? i18n.passwordPrompt : toText(data.message);
		if (encrypted && data.error) {
			text = toText(data.error) + '\n\n' + text;
		}

		showPanel('confirm', i18n.working, text);

		var $input = null;
		if (encrypted) {
			$input = $('<input type="password" class="regular-text" autocomplete="off">').appendTo($panel.find('.s3bs-job-actions'));
		}

		addButton(i18n.proceed, 'button-primary', function () {
			var password = $input ? $input.val() : '';
			showPanel('running', i18n.working, i18n.working);
			ajax('resume', { job: job, password: password }).then(function () {
				pollTimer = setTimeout(function () { pollRestore(job); }, 2000);
			}, function (message) {
				showPanel('error', i18n.error, message);
				addReloadButtons(false);
				setBusy(false);
			});
		});

		addButton(i18n.cancel, '', function () {
			ajax('cancel', { job: job }).always(function () {
				setBusy(false);
				showPanel('error', i18n.error, i18n.cancel);
				addReloadButtons(false);
			});
		});
	}

	function startJob(job, type, extraNotice) {
		stopPolling();
		setBusy(true);
		lastChange = Date.now();
		showPanel('running', i18n.working, (extraNotice ? extraNotice + '\n\n' : '') + i18n.working);

		if (type === 'restore') {
			pollTimer = setTimeout(function () { pollRestore(job); }, 2000);
		} else {
			pollTimer = setTimeout(function () { pollBackup(job); }, 2000);
		}
	}

	function fail(message) {
		setBusy(false);
		showPanel('error', i18n.error, message);
		addButton(i18n.close, '', function () {
			$panel.prop('hidden', true);
		});
	}

	// ------------------------------------------------------------------
	// Azioni
	// ------------------------------------------------------------------

	$('#s3bs-backup-now').on('submit', function (event) {
		event.preventDefault();
		var data = {};
		$(this).serializeArray().forEach(function (field) {
			data[field.name] = field.value;
		});
		ajax('backup_now', data).then(function (result) {
			startJob(result.job, result.type);
		}, fail);
	});

	$(document).on('click', '.s3bs-run-schedule', function () {
		var id = $(this).closest('tr').data('schedule');
		ajax('run_schedule', { id: id }).then(function (result) {
			startJob(result.job, result.type);
		}, fail);
	});

	$(document).on('click', '.s3bs-upload-local', function () {
		var archive = $(this).closest('tr').data('archive');
		ajax('upload_local', { archive: archive }).then(function (result) {
			startJob(result.job, result.type);
		}, fail);
	});

	$(document).on('click', '.s3bs-delete-local', function () {
		var $row = $(this).closest('tr');
		if (!window.confirm(i18n.confirmDelLocal)) {
			return;
		}
		ajax('delete_local', { archive: $row.data('archive') }).then(function () {
			$row.fadeOut(200, function () { $row.remove(); });
		}, fail);
	});

	$(document).on('click', '.s3bs-delete-s3', function () {
		var $row = $(this).closest('tr');
		if (!window.confirm(i18n.confirmDelete)) {
			return;
		}
		ajax('delete_s3', { key: $row.data('key') }).then(function () {
			$row.fadeOut(200, function () { $row.remove(); });
		}, fail);
	});

	$(document).on('change', '.s3bs-protect-toggle', function () {
		var $checkbox = $(this);
		var $row = $checkbox.closest('tr');
		var protect = $checkbox.is(':checked');
		$checkbox.prop('disabled', true);
		ajax('protect', { key: $row.data('key'), protected: protect ? 1 : 0 }).then(function () {
			$row.find('.s3bs-delete-s3').prop('disabled', protect);
		}, function (message) {
			$checkbox.prop('checked', !protect);
			fail(message);
		}).always(function () {
			$checkbox.prop('disabled', false);
		});
	});

	$(document).on('click', '.s3bs-download', function () {
		ajax('download_url', { key: $(this).closest('tr').data('key') }).then(function (result) {
			window.location.href = result.url;
		}, fail);
	});

	$(document).on('click', '.s3bs-restore', function () {
		var key = $(this).closest('tr').data('key');
		var answer = window.prompt(i18n.confirmRestore + '\n\n' + key);
		if (answer === null || $.trim(answer).toUpperCase() !== i18n.restoreWord.toUpperCase()) {
			return;
		}
		ajax('restore', { key: key }).then(function (result) {
			startJob(result.job, 'restore', result.guard ? '' : i18n.restoreGuardOff);
		}, fail);
	});

	$(document).on('click', '.s3bs-confirm-link', function (event) {
		if (!window.confirm($(this).data('confirm'))) {
			event.preventDefault();
		}
	});

	// Verifica connessione con i valori attualmente nel form (anche se non ancora salvati).
	$('#s3bs-test').on('click', function () {
		var $result = $('#s3bs-test-result').removeClass('is-ok is-error').text(i18n.working);
		var data = {};
		$('#s3bs-settings-form').serializeArray().forEach(function (field) {
			if (field.name.indexOf('s3bs[') === 0) {
				data[field.name] = field.value;
			}
		});
		ajax('test', data).then(function (result) {
			$result.addClass('is-ok').text(result.message);
		}, function (message) {
			$result.addClass('is-error').text(message);
		});
	});

	// Campi della frequenza nelle pianificazioni.
	function syncFrequency() {
		var value = $('#s3bs-frequency').val();
		$('.s3bs-when-weekly').toggle(value === 'weekly');
		$('.s3bs-when-monthly').toggle(value === 'monthly');
	}
	$('#s3bs-frequency').on('change', syncFrequency);
	syncFrequency();

	// Riprende il monitoraggio di un'operazione già in corso.
	if (s3bs.activeJob && s3bs.activeJob.job) {
		startJob(s3bs.activeJob.job, s3bs.activeJob.type);
	}
})(jQuery);
