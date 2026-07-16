(function () {
	function getCompileFn() {
		if (typeof window.wstpMjmlCompile === 'function') {
			return window.wstpMjmlCompile;
		}
		if (window.wstpMjml && typeof window.wstpMjml.compileMjml === 'function') {
			return window.wstpMjml.compileMjml;
		}
		return null;
	}

	function compileCurrentTemplate() {
		var textarea = document.getElementById('wstp-mjml-template');
		var compileFn = getCompileFn();
		if (!textarea || !compileFn) {
			return {
				ok: false,
				message: 'MJML compiler is not loaded.',
			};
		}

		var result = compileFn(textarea.value || '');
		var html = result && result.html ? result.html : '';
		var errors = result && Array.isArray(result.errors) ? result.errors : [];

		if (!html) {
			var message = errors.length
				? errors.map(function (error) {
					return error.formattedMessage || error.message || '';
				}).filter(Boolean).join(' ')
				: 'MJML compilation failed.';
			return {
				ok: false,
				message: message,
			};
		}

		return {
			ok: true,
			html: html,
		};
	}

	function showCompileError(message) {
		var box = document.getElementById('wstp-mjml-compile-error');
		if (!box) {
			window.alert(message);
			return;
		}
		box.style.display = 'block';
		box.textContent = message;
	}

	function clearCompileError() {
		var box = document.getElementById('wstp-mjml-compile-error');
		if (box) {
			box.style.display = 'none';
			box.textContent = '';
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var saveForm = document.getElementById('wstp-mjml-save-form');
		var previewBtn = document.getElementById('wstp-preview-mjml');
		var previewForm = document.getElementById('wstp-mjml-preview-form');
		var previewInput = document.getElementById('wstp-mjml-preview-input');
		var htmlInput = document.getElementById('wstp-html-template');
		var textarea = document.getElementById('wstp-mjml-template');

		if (saveForm) {
			saveForm.addEventListener('submit', function (event) {
				var compiled = compileCurrentTemplate();
				if (!compiled.ok) {
					event.preventDefault();
					showCompileError(compiled.message);
					return;
				}
				clearCompileError();
				if (htmlInput) {
					htmlInput.value = compiled.html;
				}
			});
		}

		if (previewBtn && previewForm && previewInput) {
			previewBtn.addEventListener('click', function () {
				var compiled = compileCurrentTemplate();
				if (!compiled.ok) {
					showCompileError(compiled.message);
					return;
				}
				clearCompileError();
				previewInput.value = compiled.html;
				previewForm.submit();
			});
		}

		if (window.wp && wp.codeEditor && textarea) {
			var editorSettings = wp.codeEditor.defaultSettings ? Object.assign({}, wp.codeEditor.defaultSettings) : {};
			editorSettings.codemirror = Object.assign({}, editorSettings.codemirror, {
				mode: 'xml',
				lineNumbers: true,
				lineWrapping: true,
			});
			wp.codeEditor.initialize('wstp-mjml-template', editorSettings);
		}
	});
})();
