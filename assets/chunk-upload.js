/* global jQuery, ai1wm_uploader, s3bsChunk */
/**
 * Caricamento a blocchi per la pagina "Importa" di All-in-One WP Migration.
 * Intercetta solo la richiesta di upload del file .wpress: tutto il resto dell'import
 * (validazione, conferma, ripristino) resta quello originale di AI1WM.
 */
(function ($) {
	'use strict';

	if (!$ || typeof window.ai1wm_uploader !== 'object' || typeof window.s3bsChunk !== 'object' || $.s3bsChunkPatched) {
		return;
	}
	$.s3bsChunkPatched = true;

	var originalAjax = $.ajax;

	$.ajax = function (url, options) {
		var settings = (typeof url === 'object') ? url : $.extend({}, options, { url: url });

		if (settings && settings.url === ai1wm_uploader.url && typeof FormData !== 'undefined' && settings.data instanceof FormData) {
			var file = settings.data.get('upload_file');
			if (file && typeof file.size === 'number') {
				return chunkedUpload(settings, file);
			}
		}

		return originalAjax.apply(this, arguments);
	};

	function chunkedUpload(settings, file) {
		var deferred = $.Deferred();
		var chunk = parseInt(s3bsChunk.chunk, 10) || 1048576;
		var storage = settings.data.get('storage');
		var archive = settings.data.get('archive') || file.name;
		var aborted = false;
		var current = null;

		// AI1WM registra il listener di avanzamento su xhr.upload e usa xhr.abort() per "Stop".
		var xhr = typeof settings.xhr === 'function' ? settings.xhr() : null;
		if (xhr) {
			xhr.abort = function () {
				aborted = true;
				if (current) {
					current.abort();
				}
			};
		}

		function progress(loaded) {
			if (xhr && xhr.upload && typeof ProgressEvent === 'function') {
				try {
					xhr.upload.dispatchEvent(new ProgressEvent('progress', { lengthComputable: true, loaded: loaded, total: file.size }));
				} catch (e) { /* ignore */ }
			}
		}

		function fail(message) {
			var response = {
				status: 500,
				responseText: JSON.stringify({ errors: [{ code: 500, message: message || s3bsChunk.failed }] })
			};
			if (typeof settings.error === 'function') {
				settings.error(response, 'error');
			}
			deferred.reject(response, 'error');
		}

		function send(offset, attempt) {
			if (aborted) {
				return;
			}

			var data = new FormData();
			data.append('action', 's3bs_upload_chunk');
			data.append('nonce', s3bsChunk.nonce);
			data.append('storage', storage);
			data.append('archive', archive);
			data.append('offset', offset);
			data.append('total', file.size);
			data.append('chunk', file.slice(offset, offset + chunk), 'chunk');

			current = originalAjax({
				url: s3bsChunk.url,
				type: 'POST',
				data: data,
				cache: false,
				processData: false,
				contentType: false,
				dataType: 'json'
			});

			current.done(function (response) {
				if (aborted) {
					return;
				}
				if (!response || !response.success) {
					fail(response && response.data && response.data.message);
					return;
				}

				var next = Math.min(offset + chunk, file.size);
				progress(next);

				if (next >= file.size) {
					if (typeof settings.success === 'function') {
						settings.success({});
					}
					deferred.resolve({});
				} else {
					send(next, 0);
				}
			}).fail(function (jq) {
				if (aborted) {
					return;
				}
				// Errori di rete temporanei: ritenta lo stesso blocco.
				if (attempt < 3 && (!jq || jq.status === 0 || jq.status >= 500)) {
					setTimeout(function () { send(offset, attempt + 1); }, 2000 * (attempt + 1));
					return;
				}
				var message = jq && jq.responseJSON && jq.responseJSON.data && jq.responseJSON.data.message;
				fail(message);
			});
		}

		send(0, 0);

		return deferred.promise({
			abort: function () {
				if (xhr) {
					xhr.abort();
				}
			}
		});
	}
})(jQuery);
