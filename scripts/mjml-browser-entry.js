const mjml2html = require('mjml-browser');

/**
 * Compile MJML source to HTML for admin-side template saving.
 *
 * @param {string} source MJML markup.
 * @returns {{html:string,errors:Array<{formattedMessage?:string,message?:string}>}}
 */
function compileMjml(source) {
	const result = mjml2html(source, {
		validationLevel: 'soft',
		minify: false,
	});

	return {
		html: result && result.html ? result.html : '',
		errors: Array.isArray(result && result.errors) ? result.errors : [],
	};
}

if (typeof window !== 'undefined') {
	window.wstpMjmlCompile = compileMjml;
}

module.exports = compileMjml;
