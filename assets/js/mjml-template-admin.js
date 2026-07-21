(function () {
	var mjmlCodeEditor = null;

	function getCompileFn() {
		if (typeof window.wstpMjmlCompile === 'function') {
			return window.wstpMjmlCompile;
		}
		if (window.wstpMjml && typeof window.wstpMjml.compileMjml === 'function') {
			return window.wstpMjml.compileMjml;
		}
		return null;
	}

	function getTextarea() {
		return document.getElementById('wstp-mjml-template');
	}

	function isMjmlPanelVisible() {
		var textarea = getTextarea();
		if (!textarea) {
			return false;
		}
		var panel = textarea.closest('.wstp-template-tab-panel');
		if (!panel) {
			return true;
		}
		return window.getComputedStyle(panel).display !== 'none';
	}

	function compileCurrentTemplate() {
		var textarea = getTextarea();
		var compileFn = getCompileFn();
		if (!textarea || !compileFn) {
			return {
				ok: false,
				message: 'MJML compiler is not loaded.',
			};
		}

		// Keep textarea in sync if CodeMirror is active.
		if (mjmlCodeEditor && mjmlCodeEditor.codemirror) {
			textarea.value = mjmlCodeEditor.codemirror.getValue();
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

	function updateEditorButton() {
		var btn = document.getElementById('wstp-mjml-open-editor');
		var i18n = (window.wstpMjmlTemplateAdmin && window.wstpMjmlTemplateAdmin.i18n) || {};
		if (!btn) {
			return;
		}
		if (mjmlCodeEditor) {
			btn.textContent = i18n.refreshEditor || 'Refresh code editor';
		} else {
			btn.textContent = i18n.showEditor || 'Show code editor';
		}
	}

	/**
	 * Initialize CodeMirror only when the MJML tab is visible.
	 * Init while display:none yields a blank/broken gutter (overlapping line numbers).
	 *
	 * @return {boolean} Whether an editor instance exists after this call.
	 */
	function ensureMjmlCodeEditor() {
		var textarea = getTextarea();
		if (!textarea || !window.wp || !wp.codeEditor) {
			return false;
		}

		if (!isMjmlPanelVisible()) {
			return false;
		}

		if (mjmlCodeEditor && mjmlCodeEditor.codemirror) {
			mjmlCodeEditor.codemirror.refresh();
			window.setTimeout(function () {
				if (mjmlCodeEditor && mjmlCodeEditor.codemirror) {
					mjmlCodeEditor.codemirror.refresh();
				}
			}, 50);
			updateEditorButton();
			return true;
		}

		var editorSettings = wp.codeEditor.defaultSettings
			? Object.assign({}, wp.codeEditor.defaultSettings)
			: {};
		editorSettings.codemirror = Object.assign({}, editorSettings.codemirror, {
			mode: 'xml',
			lineNumbers: true,
			lineWrapping: true,
		});

		mjmlCodeEditor = wp.codeEditor.initialize(textarea, editorSettings);
		window.wstpMjmlCodeEditor = mjmlCodeEditor;

		if (mjmlCodeEditor && mjmlCodeEditor.codemirror) {
			window.setTimeout(function () {
				if (mjmlCodeEditor && mjmlCodeEditor.codemirror) {
					mjmlCodeEditor.codemirror.refresh();
					mjmlCodeEditor.codemirror.focus();
				}
			}, 50);
		}

		updateEditorButton();
		return !!mjmlCodeEditor;
	}

	window.wstpEnsureMjmlCodeEditor = ensureMjmlCodeEditor;

	document.addEventListener('DOMContentLoaded', function () {
		var saveForm = document.getElementById('wstp-mjml-save-form');
		var previewBtn = document.getElementById('wstp-preview-mjml');
		var previewForm = document.getElementById('wstp-mjml-preview-form');
		var previewInput = document.getElementById('wstp-mjml-preview-input');
		var htmlInput = document.getElementById('wstp-html-template');
		var openBtn = document.getElementById('wstp-mjml-open-editor');

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

		if (openBtn) {
			openBtn.addEventListener('click', function () {
				ensureMjmlCodeEditor();
			});
		}

		updateEditorButton();

		// If MJML is the active tab on load, open the editor immediately.
		if (isMjmlPanelVisible()) {
			ensureMjmlCodeEditor();
		}
	});
})();
