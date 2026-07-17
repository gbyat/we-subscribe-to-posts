( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.element || ! wp.blocks || ! wp.blockEditor || ! window.wstpEmailVisualEditor ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var createRoot = wp.element.createRoot;
	var render = wp.element.render;
	var registerBlockType = wp.blocks.registerBlockType;
	var parse = wp.blocks.parse;
	var serialize = wp.blocks.serialize;
	var BlockEditorProvider = wp.blockEditor.BlockEditorProvider;
	var BlockList = wp.blockEditor.BlockList;
	var WritingFlow = wp.blockEditor.WritingFlow;
	var ObserveTyping = wp.blockEditor.ObserveTyping;
	var BlockInspector = wp.blockEditor.BlockInspector;
	var BlockTools = wp.blockEditor.BlockTools;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var RichText = wp.blockEditor.RichText;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	var Inserter = wp.blockEditor.Inserter;
	var CopyHandler = wp.blockEditor.CopyHandler || Fragment;
	var createBlock = wp.blocks.createBlock;
	var useSelect = wp.data && wp.data.useSelect ? wp.data.useSelect : null;
	var useDispatch = wp.data && wp.data.useDispatch ? wp.data.useDispatch : null;
	var Button = wp.components.Button;
	var RangeControl = wp.components.RangeControl;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var ToggleControl = wp.components.ToggleControl;
	var ColorPalette = wp.components.ColorPalette;
	var BaseControl = wp.components.BaseControl;
	var SlotFillProvider = wp.components.SlotFillProvider;
	var Popover = wp.components.Popover;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var BlockControls = wp.blockEditor.BlockControls;
	var BlockBreadcrumb = wp.blockEditor.BlockBreadcrumb || null;
	var ListView = wp.blockEditor.ListView || wp.blockEditor.__experimentalListView || null;
	var BlockAlignmentToolbar = wp.blockEditor.BlockAlignmentToolbar || null;
	var AlignmentToolbar = wp.blockEditor.AlignmentToolbar;
	var AlignmentControl = wp.blockEditor.AlignmentControl;
	var ToolbarGroup = wp.components.ToolbarGroup;
	var ToolbarButton = wp.components.ToolbarButton;
	var TabPanel = wp.components.TabPanel || null;
	var PanelBody = wp.components.PanelBody;
	var ShortcutProvider =
		wp.keyboardShortcuts && wp.keyboardShortcuts.ShortcutProvider
			? wp.keyboardShortcuts.ShortcutProvider
			: Fragment;
	var hooks = wp.hooks;
	var config = window.wstpEmailVisualEditor;
	var i18n = config.i18n || {};
	var palette = Array.isArray( config.palette ) ? config.palette : [];
	var branding = config.branding || {};
	var homeUrl = config.homeUrl || '/';
	var blocksRef = { current: [] };

	var LOOP_ALLOWED = [
		'wstp/post-title',
		'wstp/post-excerpt',
		'wstp/post-meta',
		'wstp/post-image',
		'wstp/post-image-side',
		'wstp/post-read-more',
		'core/columns',
		'core/column',
		'core/separator',
		'core/paragraph',
		'core/heading',
	];

	var FIELD_ATTRS = {
		textColor: { type: 'string', default: '' },
		backgroundColor: { type: 'string', default: '' },
		fontSize: { type: 'number', default: 0 },
		align: { type: 'string', default: 'left' },
		paddingTop: { type: 'number', default: 0 },
		paddingBottom: { type: 'number', default: 0 },
		paddingX: { type: 'number', default: 0 },
		borderRadius: { type: 'number', default: 4 },
	};

	var EXCERPT_ATTRS = Object.assign( {}, FIELD_ATTRS, {
		wordCount: { type: 'number', default: 42 },
	} );

	var META_ATTRS = Object.assign( {}, FIELD_ATTRS, {
		showDate: { type: 'boolean', default: true },
		showAuthor: { type: 'boolean', default: true },
		separator: { type: 'string', default: ' · ' },
	} );

	var EMAIL_FONTS = [
		{ label: 'Arial', value: 'Arial, Helvetica, sans-serif' },
		{ label: 'Helvetica', value: 'Helvetica, Arial, sans-serif' },
		{ label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
		{ label: 'Trebuchet MS', value: "'Trebuchet MS', Helvetica, sans-serif" },
		{ label: 'Georgia', value: "Georgia, 'Times New Roman', serif" },
		{ label: 'Times New Roman', value: "'Times New Roman', Times, serif" },
		{ label: 'Courier New', value: "'Courier New', Courier, monospace" },
	];

	var DEFAULT_EMAIL_FONT = 'Arial, Helvetica, sans-serif';

	var SPACING_ATTRS = {
		paddingTop: { type: 'number', default: 0 },
		paddingBottom: { type: 'number', default: 0 },
		paddingX: { type: 'number', default: 0 },
	};

	var SECTION_ATTRS = Object.assign( {}, SPACING_ATTRS, {
		paddingX: { type: 'number', default: 0 },
		backgroundColor: { type: 'string', default: 'base-two' },
		textColor: { type: 'string', default: '' },
		mutedColor: { type: 'string', default: '' },
		linkColor: { type: 'string', default: '' },
		borderTop: { type: 'number', default: 0 },
		borderRight: { type: 'number', default: 0 },
		borderBottom: { type: 'number', default: 0 },
		borderLeft: { type: 'number', default: 0 },
		borderColor: { type: 'string', default: '' },
		align: { type: 'string', default: 'left' },
		fontSize: { type: 'number', default: 0 },
	} );

	// Editable header — section chrome + optional logo, or InnerBlocks (heading/text/button).
	var HEADER_ATTRS = Object.assign( {}, SECTION_ATTRS, {
		paddingTop: { type: 'number', default: 24 },
		paddingBottom: { type: 'number', default: 12 },
		paddingX: { type: 'number', default: 24 },
		align: { type: 'string', default: 'center' },
		textAlign: { type: 'string', default: 'center' },
		logoId: { type: 'number', default: 0 },
		logoUrl: { type: 'string', default: '' },
		logoAlt: { type: 'string', default: '' },
		logoWidth: { type: 'number', default: 280 },
		logoLink: { type: 'string', default: '' },
		// Legacy single-richtext fields (migrated to InnerBlocks on edit).
		content: { type: 'string', default: '' },
		contentLink: { type: 'string', default: '' },
		textColor: { type: 'string', default: 'accent-three' },
		fontSize: { type: 'number', default: 22 },
		tagName: { type: 'string', default: 'h2' },
	} );

	var HEADER_INNER_ALLOWED = [ 'core/heading', 'core/paragraph', 'core/buttons', 'core/button' ];
	var INTRO_INNER_ALLOWED = [ 'core/paragraph', 'core/heading' ];

	var FOOTER_ATTRS = Object.assign( {}, SECTION_ATTRS, {
		paddingTop: { type: 'number', default: 16 },
		paddingBottom: { type: 'number', default: 28 },
		paddingX: { type: 'number', default: 24 },
	} );

	var SHELL_ALLOWED = [
		'wstp/email-header',
		'wstp/email-footer',
		'wstp/intro',
		'wstp/truncation-notice',
		'wstp/posts-loop',
		'core/paragraph',
		'core/heading',
		'core/image',
		'core/buttons',
		'core/button',
		'core/columns',
		'core/column',
		'core/separator',
	];

	function resolveFontFamily( value ) {
		var raw = value ? String( value ) : '';
		if ( ! raw ) {
			return DEFAULT_EMAIL_FONT;
		}
		for ( var i = 0; i < EMAIL_FONTS.length; i++ ) {
			if ( EMAIL_FONTS[ i ].value === raw ) {
				return raw;
			}
		}
		return DEFAULT_EMAIL_FONT;
	}

	/**
	 * Native block text color (palette slug or style.color.text hex).
	 *
	 * @param {Object} attrs Block attributes.
	 * @param {string} [fallback]
	 * @return {string|undefined}
	 */
	function resolveBlockTextColor( attrs, fallback ) {
		if ( attrs && attrs.style && attrs.style.color && attrs.style.color.text ) {
			return String( attrs.style.color.text );
		}
		return storeToColor( attrs && attrs.textColor, fallback );
	}

	/**
	 * Native block background (palette slug or style.color.background hex).
	 *
	 * @param {Object} attrs Block attributes.
	 * @param {string} [fallback]
	 * @return {string|undefined}
	 */
	function resolveBlockBackgroundColor( attrs, fallback ) {
		if ( attrs && attrs.style && attrs.style.color && attrs.style.color.background ) {
			return String( attrs.style.color.background );
		}
		return storeToColor( attrs && attrs.backgroundColor, fallback );
	}

	/**
	 * Native font size in px (style.typography.fontSize or numeric attr).
	 *
	 * @param {Object} attrs Block attributes.
	 * @param {number} fallback Default px.
	 * @return {number}
	 */
	function resolveBlockFontSizePx( attrs, fallback ) {
		if ( attrs && attrs.style && attrs.style.typography && attrs.style.typography.fontSize ) {
			var fromStyle = parseInt( String( attrs.style.typography.fontSize ), 10 );
			if ( isFinite( fromStyle ) && fromStyle > 0 ) {
				return fromStyle;
			}
		}
		if ( typeof ( attrs && attrs.fontSize ) === 'number' && attrs.fontSize > 0 ) {
			return attrs.fontSize;
		}
		if ( attrs && typeof attrs.fontSize === 'string' && /^\d/.test( attrs.fontSize ) ) {
			var fromAttr = parseInt( attrs.fontSize, 10 );
			if ( isFinite( fromAttr ) && fromAttr > 0 ) {
				return fromAttr;
			}
		}
		return fallback || 15;
	}

	function resolveTextAlign( attrs ) {
		// Prefer Gutenberg `textAlign` (core/heading, core/paragraph). A leftover custom
		// `align: left` must not override an explicit textAlign (center/right).
		var raw = '';
		if ( attrs ) {
			if ( attrs.textAlign ) {
				raw = String( attrs.textAlign );
			} else if (
				attrs.align === 'left' ||
				attrs.align === 'center' ||
				attrs.align === 'right'
			) {
				raw = String( attrs.align );
			}
		}
		return raw === 'center' || raw === 'right' ? raw : 'left';
	}

	/**
	 * Persist text alignment on free-text blocks (keeps textAlign + legacy align in sync).
	 *
	 * @param {Function} setAttributes Block setAttributes.
	 * @param {string}   value         left|center|right.
	 */
	function setTextAlignAttributes( setAttributes, value ) {
		var next = value === 'center' || value === 'right' ? value : 'left';
		setAttributes( { textAlign: next, align: next } );
	}

	function paletteColors() {
		return palette.map( function ( item ) {
			return {
				name: item.name || item.slug,
				slug: item.slug,
				color: item.color,
			};
		} );
	}

	function colorValueToStore( hex ) {
		if ( ! hex ) {
			return '';
		}
		var lower = String( hex ).toLowerCase();
		for ( var i = 0; i < palette.length; i++ ) {
			if ( String( palette[ i ].color || '' ).toLowerCase() === lower ) {
				return palette[ i ].slug;
			}
		}
		return hex;
	}

	function storeToColor( value, fallbackHex ) {
		if ( ! value ) {
			return fallbackHex || undefined;
		}
		for ( var i = 0; i < palette.length; i++ ) {
			if ( palette[ i ].slug === value ) {
				return palette[ i ].color;
			}
		}
		return value;
	}

	function ColorField( props ) {
		return el(
			BaseControl,
			{ label: props.label, id: props.id },
			el( ColorPalette, {
				colors: paletteColors(),
				value: storeToColor( props.value, props.fallback ),
				onChange: function ( hex ) {
					props.onChange( colorValueToStore( hex ) );
				},
				clearable: true,
			} )
		);
	}

	function SpacingPanel( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var defaults = props.spacingDefaults || {};
		var topDefault = typeof defaults.paddingTop === 'number' ? defaults.paddingTop : 0;
		var bottomDefault = typeof defaults.paddingBottom === 'number' ? defaults.paddingBottom : 0;
		var xDefault = typeof defaults.paddingX === 'number' ? defaults.paddingX : 0;
		return el(
			PanelBody,
			{ title: i18n.spacing || 'Spacing', initialOpen: false },
			el( RangeControl, {
				label: i18n.paddingTop || 'Padding top (px)',
				value: typeof attrs.paddingTop === 'number' ? attrs.paddingTop : topDefault,
				min: 0,
				max: 80,
				onChange: function ( value ) {
					setAttributes( { paddingTop: value } );
				},
			} ),
			el( RangeControl, {
				label: i18n.paddingBottom || 'Padding bottom (px)',
				value: typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : bottomDefault,
				min: 0,
				max: 80,
				onChange: function ( value ) {
					setAttributes( { paddingBottom: value } );
				},
			} ),
			el( RangeControl, {
				label: i18n.paddingX || 'Padding left/right (px)',
				value: typeof attrs.paddingX === 'number' ? attrs.paddingX : xDefault,
				min: 0,
				max: 80,
				onChange: function ( value ) {
					setAttributes( { paddingX: value } );
				},
			} )
		);
	}

	function BorderPanel( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		function sideControl( side, label ) {
			var key = 'border' + side;
			return el( RangeControl, {
				label: label,
				value: typeof attrs[ key ] === 'number' ? attrs[ key ] : 0,
				min: 0,
				max: 12,
				onChange: function ( value ) {
					var next = {};
					next[ key ] = value;
					setAttributes( next );
				},
			} );
		}
		return el(
			PanelBody,
			{ title: i18n.borders || 'Borders', initialOpen: false },
			sideControl( 'Top', i18n.borderTop || 'Top (px)' ),
			sideControl( 'Right', i18n.borderRight || 'Right (px)' ),
			sideControl( 'Bottom', i18n.borderBottom || 'Bottom (px)' ),
			sideControl( 'Left', i18n.borderLeft || 'Left (px)' ),
			el( ColorField, {
				id: 'wstp-border-color',
				label: i18n.borderColor || 'Border color',
				value: attrs.borderColor,
				fallback: storeToColor( 'accent', '#94a3b8' ),
				onChange: function ( value ) {
					setAttributes( { borderColor: value } );
				},
			} )
		);
	}

	function SectionStylePanels( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var showColors = props.showColors !== false;
		var showText = !! props.showText;
		var showMuted = !! props.showMuted;
		var showLink = !! props.showLink;
		var showFont = !! props.showFont;
		var showAlign = !! props.showAlign;
		var showFontSize = !! props.showFontSize;
		var showTypography = showFont || showAlign || showFontSize;

		return el(
			Fragment,
			null,
			el( SpacingPanel, props ),
			el( BorderPanel, props ),
			showColors
				? el(
						PanelBody,
						{ title: i18n.colors || 'Colors', initialOpen: true },
						el( ColorField, {
							id: 'wstp-bg',
							label: i18n.background || 'Background',
							value: attrs.backgroundColor,
							fallback: storeToColor( 'base-two' ),
							onChange: function ( value ) {
								setAttributes( { backgroundColor: value } );
							},
						} ),
						showText
							? el( ColorField, {
									id: 'wstp-text',
									label: i18n.textColor || 'Text',
									value: attrs.textColor,
									fallback: storeToColor( 'accent-three' ),
									onChange: function ( value ) {
										setAttributes( { textColor: value } );
									},
							  } )
							: null,
						showMuted
							? el( ColorField, {
									id: 'wstp-muted',
									label: i18n.mutedColor || 'Secondary text',
									value: attrs.mutedColor,
									fallback: storeToColor( 'accent' ),
									onChange: function ( value ) {
										setAttributes( { mutedColor: value } );
									},
							  } )
							: null,
						showLink
							? el( ColorField, {
									id: 'wstp-link',
									label: i18n.linkColor || 'Links',
									value: attrs.linkColor,
									fallback: storeToColor( 'accent-two' ),
									onChange: function ( value ) {
										setAttributes( { linkColor: value } );
									},
							  } )
							: null
				  )
				: null,
			showTypography
				? el(
						PanelBody,
						{ title: i18n.typography || 'Typography', initialOpen: true },
						showFont
							? el( SelectControl, {
									label: i18n.fontFamily || 'Font',
									help:
										i18n.emailFontHelp ||
										'Email-safe font stack (Outlook and most clients).',
									value: resolveFontFamily( attrs.fontFamily ),
									options: EMAIL_FONTS,
									onChange: function ( value ) {
										setAttributes( { fontFamily: value } );
									},
							  } )
							: null,
						showAlign
							? el( SelectControl, {
									label: i18n.align || 'Align',
									value: resolveTextAlign( attrs ),
									options: [
										{ label: i18n.alignLeft || 'Left', value: 'left' },
										{ label: i18n.alignCenter || 'Center', value: 'center' },
										{ label: i18n.alignRight || 'Right', value: 'right' },
									],
									onChange: function ( value ) {
										setTextAlignAttributes( setAttributes, value );
									},
							  } )
							: null,
						showFontSize
							? el( RangeControl, {
									label: i18n.fontSize || 'Font size (px)',
									value: attrs.fontSize || ( props.defaultFontSize || 15 ),
									min: 10,
									max: 48,
									onChange: function ( value ) {
										setAttributes( { fontSize: value } );
									},
							  } )
							: null
				  )
				: null
		);
	}

	function FieldStylePanels( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var isButton = !! props.isButton;

		return el(
			Fragment,
			null,
			el(
				PanelBody,
				{ title: i18n.spacing || 'Spacing', initialOpen: true },
				el( RangeControl, {
					label: i18n.gapAfter || 'Gap after (px)',
					help:
						i18n.gapAfterHelp ||
						'Space below this field in the email. Shown in the editor canvas as you change it.',
					value: typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : 0,
					min: 0,
					max: 40,
					onChange: function ( value ) {
						setAttributes( { paddingBottom: value } );
					},
				} )
			),
			el(
				PanelBody,
				{ title: i18n.colors || 'Colors', initialOpen: true },
				isButton
					? el( ColorField, {
							id: 'wstp-btn-bg',
							label: i18n.background || 'Background',
							value: attrs.backgroundColor,
							fallback: storeToColor( 'accent-two' ),
							onChange: function ( value ) {
								setAttributes( { backgroundColor: value } );
							},
					  } )
					: null,
				el( ColorField, {
					id: 'wstp-field-text',
					label: i18n.textColor || 'Text',
					value: attrs.textColor,
					fallback: storeToColor( props.defaultTextToken || 'accent-three' ),
					onChange: function ( value ) {
						setAttributes( { textColor: value } );
					},
				} )
			),
			el(
				PanelBody,
				{ title: i18n.typography || 'Typography', initialOpen: false },
				el( RangeControl, {
					label: i18n.fontSize || 'Font size (px)',
					value: attrs.fontSize || props.defaultFontSize || 15,
					min: 10,
					max: 48,
					onChange: function ( value ) {
						setAttributes( { fontSize: value } );
					},
				} ),
				el( SelectControl, {
					label: i18n.align || 'Align',
					value: attrs.align || 'left',
					options: [
						{ label: i18n.alignLeft || 'Left', value: 'left' },
						{ label: i18n.alignCenter || 'Center', value: 'center' },
						{ label: i18n.alignRight || 'Right', value: 'right' },
					],
					onChange: function ( value ) {
						setAttributes( { align: value } );
					},
				} ),
				isButton
					? el( RangeControl, {
							label: i18n.borderRadius || 'Border radius (px)',
							value: typeof attrs.borderRadius === 'number' ? attrs.borderRadius : 4,
							min: 0,
							max: 24,
							onChange: function ( value ) {
								setAttributes( { borderRadius: value } );
							},
					  } )
					: null
			)
		);
	}

	function previewBg( attrs ) {
		return storeToColor( attrs.backgroundColor, storeToColor( 'base-two', '#ffffff' ) );
	}

	function previewPad( attrs, defaults ) {
		defaults = defaults || {};
		var top = typeof attrs.paddingTop === 'number' ? attrs.paddingTop : ( typeof defaults.paddingTop === 'number' ? defaults.paddingTop : 0 );
		var bottom = typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : ( typeof defaults.paddingBottom === 'number' ? defaults.paddingBottom : 0 );
		var x = typeof attrs.paddingX === 'number' ? attrs.paddingX : ( typeof defaults.paddingX === 'number' ? defaults.paddingX : 0 );
		var borderColor = storeToColor( attrs.borderColor, '#e5e7eb' );
		var style = {
			paddingTop: top + 'px',
			paddingBottom: bottom + 'px',
			paddingLeft: x + 'px',
			paddingRight: x + 'px',
			backgroundColor: previewBg( attrs ),
			boxSizing: 'border-box',
		};
		[ 'Top', 'Right', 'Bottom', 'Left' ].forEach( function ( side ) {
			var width = typeof attrs[ 'border' + side ] === 'number' ? attrs[ 'border' + side ] : 0;
			if ( width > 0 ) {
				style[ 'border' + side ] = width + 'px solid ' + borderColor;
			}
		} );
		return style;
	}

	function ShellEdit( props ) {
		var bg = storeToColor( props.attributes.backgroundColor, storeToColor( 'base', '#f3f4f6' ) );
		var blockProps = useBlockProps( {
			className: 'wstp-email-block wstp-email-block--shell',
			style: { backgroundColor: bg, minHeight: '280px' },
		} );

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: i18n.emailCanvas || 'Email canvas', initialOpen: true },
					el( ColorField, {
						id: 'wstp-shell-bg',
						label: i18n.outerBackground || 'Outer background',
						value: props.attributes.backgroundColor || 'base',
						fallback: storeToColor( 'base' ),
						onChange: function ( value ) {
							props.setAttributes( { backgroundColor: value || 'base' } );
						},
					} )
				)
			),
			el(
				'div',
				blockProps,
				el( InnerBlocks, {
					allowedBlocks: SHELL_ALLOWED,
					templateLock: false,
				} )
			)
		);
	}

	function stripHtmlToText( html ) {
		var div = document.createElement( 'div' );
		div.innerHTML = html || '';
		return ( div.textContent || div.innerText || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function buildHeaderInnerFromLegacy( attrs ) {
		var raw = attrs.content ? String( attrs.content ) : '';
		var link = attrs.contentLink ? String( attrs.contentLink ).trim() : '';
		var text = stripHtmlToText( raw );
		if ( ! text ) {
			text = i18n.headerPlaceholder || 'Your brand';
		}
		var content = text;
		if ( link ) {
			content = '<a href="' + link.replace( /"/g, '&quot;' ) + '">' + text + '</a>';
		} else if ( /<a\b/i.test( raw ) ) {
			content = raw;
		}
		return [
			createBlock( 'core/heading', {
				level: 2,
				content: content,
				textAlign: 'center',
				paddingTop: 0,
				paddingBottom: 4,
				paddingX: 0,
			} ),
		];
	}

	function escapeHtml( text ) {
		return String( text || '' )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function defaultHeaderInnerTemplate() {
		var title = ( branding.title && String( branding.title ).trim() ) || i18n.headerPlaceholder || 'Your brand';
		var tagline = branding.tagline ? String( branding.tagline ).trim() : '';
		var link = ( branding.logoLink && String( branding.logoLink ).trim() ) || homeUrl;
		var headingContent = '<a href="' + String( link ).replace( /"/g, '&quot;' ) + '">' + escapeHtml( title ) + '</a>';
		var template = [
			[
				'core/heading',
				{
					level: 2,
					content: headingContent,
					textAlign: 'center',
					paddingTop: 0,
					paddingBottom: 4,
					paddingX: 0,
				},
			],
		];
		if ( tagline ) {
			template.push( [
				'core/paragraph',
				{
					content: escapeHtml( tagline ),
					align: 'center',
					paddingTop: 0,
					paddingBottom: 0,
					paddingX: 0,
				},
			] );
		}
		return template;
	}

	function HeaderEdit( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var clientId = props.clientId;
		var align = resolveTextAlign( attrs );
		var hasLogo = !! ( attrs.logoUrl && String( attrs.logoUrl ).trim() );
		// Do not set textAlign on the header wrapper when using InnerBlocks — that
		// inherits onto headings and makes them look centered while attrs stay left.
		var headerStyle = previewPad( attrs, { paddingTop: 24, paddingBottom: 12, paddingX: 24 } );
		if ( hasLogo ) {
			headerStyle = Object.assign( {}, headerStyle, { textAlign: align } );
		}
		var blockProps = useBlockProps( {
			className: 'wstp-email-block wstp-email-block--header',
			style: headerStyle,
		} );

		var innerCount = 0;
		if ( useSelect ) {
			innerCount = useSelect(
				function ( select ) {
					return select( 'core/block-editor' ).getBlockCount( clientId );
				},
				[ clientId ]
			);
		}

		var replaceInnerBlocks = null;
		if ( useDispatch ) {
			replaceInnerBlocks = useDispatch( 'core/block-editor' ).replaceInnerBlocks;
		}

		useEffect(
			function () {
				if ( hasLogo || ! replaceInnerBlocks || innerCount > 0 ) {
					return;
				}
				if ( ! attrs.content && ! attrs.contentLink ) {
					return;
				}
				replaceInnerBlocks( clientId, buildHeaderInnerFromLegacy( attrs ), false );
				setAttributes( { content: '', contentLink: '' } );
			},
			[ clientId, hasLogo, innerCount ]
		);

		var logoPanel = el(
			PanelBody,
			{ title: i18n.headerLogo || 'Logo (optional)', initialOpen: false },
			el(
				MediaUploadCheck,
				null,
				el( MediaUpload, {
					onSelect: function ( media ) {
						var url = '';
						if ( media.sizes && media.sizes.full && media.sizes.full.url ) {
							url = media.sizes.full.url;
						} else if ( media.url ) {
							url = media.url;
						}
						setAttributes( {
							logoId: media.id || 0,
							logoUrl: url || '',
							logoAlt: media.alt || attrs.logoAlt || '',
						} );
					},
					allowedTypes: [ 'image' ],
					value: attrs.logoId || undefined,
					render: function ( obj ) {
						return el(
							Button,
							{
								variant: 'secondary',
								onClick: obj.open,
								style: { marginBottom: '8px' },
							},
							hasLogo ? i18n.replaceLogo || 'Replace logo' : i18n.selectLogo || 'Select logo'
						);
					},
				} )
			),
			hasLogo
				? el(
						Button,
						{
							variant: 'link',
							isDestructive: true,
							onClick: function () {
								setAttributes( { logoId: 0, logoUrl: '', logoAlt: '' } );
							},
						},
						i18n.removeLogo || 'Remove logo'
				  )
				: null,
			hasLogo
				? el( TextControl, {
						label: i18n.logoLink || 'Logo link URL',
						value: attrs.logoLink || '',
						onChange: function ( value ) {
							setAttributes( { logoLink: value } );
						},
				  } )
				: null,
			hasLogo
				? el( RangeControl, {
						label: i18n.logoWidth || 'Logo max width (px)',
						value: attrs.logoWidth || 280,
						min: 80,
						max: 560,
						onChange: function ( value ) {
							setAttributes( { logoWidth: value } );
						},
				  } )
				: null,
			hasLogo
				? el( TextControl, {
						label: i18n.logoAlt || 'Logo alt text',
						value: attrs.logoAlt || '',
						onChange: function ( value ) {
							setAttributes( { logoAlt: value } );
						},
				  } )
				: null,
			! hasLogo
				? el(
						'p',
						{ className: 'description', style: { marginTop: 0 } },
						i18n.headerLogoHelp ||
							'Leave empty to add a heading, text, or button in the header — each with its own colors.'
				  )
				: null
		);

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el( SectionStylePanels, Object.assign( {}, props, {
					showText: false,
					showLink: false,
					showAlign: false,
					spacingDefaults: { paddingTop: 24, paddingBottom: 12, paddingX: 24 },
				} ) ),
				logoPanel
			),
			el(
				'div',
				blockProps,
				hasLogo
					? el(
							'div',
							{
								className: 'wstp-email-preview-header',
								style: {
									justifyContent:
										align === 'left' ? 'flex-start' : align === 'right' ? 'flex-end' : 'center',
								},
							},
							el( 'img', {
								className: 'wstp-email-preview-logo-img',
								src: attrs.logoUrl,
								alt: attrs.logoAlt || '',
								style: {
									maxWidth: Math.min( attrs.logoWidth || 280, 560 ) + 'px',
									width: '100%',
									height: 'auto',
									display: 'block',
								},
							} )
					  )
					: el(
							'div',
							{ className: 'wstp-email-header-inners' },
							el( InnerBlocks, {
								allowedBlocks: HEADER_INNER_ALLOWED,
								template: defaultHeaderInnerTemplate(),
								templateLock: false,
								renderAppender: InnerBlocks.ButtonBlockAppender,
							} )
					  )
			)
		);
	}

	function FooterEdit( props ) {
		var blockProps = useBlockProps( {
			className: 'wstp-email-block wstp-email-block--footer',
			style: previewPad( props.attributes ),
		} );
		return el(
			Fragment,
			null,
			el( InspectorControls, null, el( SectionStylePanels, Object.assign( {}, props, { showText: true, showMuted: true, showLink: true } ) ) ),
			el(
				'div',
				blockProps,
				el(
					'div',
					{ className: 'wstp-email-preview-footer' },
					el(
						'strong',
						{ style: { color: storeToColor( props.attributes.textColor, storeToColor( 'accent-three' ) ) } },
						i18n.footer || 'Email footer'
					),
					el(
						'span',
						{ style: { color: storeToColor( props.attributes.linkColor || props.attributes.mutedColor, storeToColor( 'accent-two' ) ) } },
						i18n.footerHelp || 'From Branding tab'
					)
				)
			)
		);
	}

	function IntroEdit( props ) {
		var attrs = props.attributes;
		var sample = config.samplePost || {};
		var greetName = sample.name || 'Alex';
		var greetingPreview = ( i18n.greetingSample || 'Hi %s,' ).replace( '%s', greetName );
		var blockProps = useBlockProps( {
			className: 'wstp-email-block wstp-email-block--intro',
			style: previewPad( attrs ),
		} );

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el( SectionStylePanels, Object.assign( {}, props, { showText: true, showMuted: false, showAlign: true } ) ),
				el(
					'p',
					{ className: 'description', style: { marginTop: 0, padding: '0 16px 12px' } },
					i18n.introHelp ||
						'The greeting is personalized at send time. Add paragraph or heading blocks below for an optional intro or announcement.'
				)
			),
			el(
				'div',
				blockProps,
				el(
					'div',
					{
						className: 'wstp-email-preview-intro',
						style: { textAlign: attrs.align || 'left' },
					},
					el(
						'div',
						{
							className: 'wstp-email-preview-greeting',
							style: {
								color: storeToColor( attrs.textColor, storeToColor( 'accent-three' ) ),
								fontSize: '22px',
								fontWeight: 700,
								marginBottom: '8px',
							},
						},
						el( 'span', { className: 'wstp-email-greeting-label' }, greetingPreview ),
						el(
							'span',
							{ className: 'wstp-email-placeholder-hint', style: { display: 'block', fontWeight: 400, fontSize: '11px', marginTop: '2px' } },
							i18n.greetingLabel || 'Greeting (personalized)'
						)
					),
					el(
						'div',
						{ className: 'wstp-email-intro-inners' },
						el( InnerBlocks, {
							allowedBlocks: INTRO_INNER_ALLOWED,
							templateLock: false,
							renderAppender: InnerBlocks.ButtonBlockAppender,
						} )
					)
				)
			)
		);
	}

	function TruncationEdit( props ) {
		var attrs = props.attributes;
		var borderPreview = previewPad(
			Object.assign( {}, attrs, {
				paddingTop: 8,
				paddingBottom: 8,
			} )
		);
		var blockProps = useBlockProps( {
			className: 'wstp-email-block wstp-email-block--truncation wstp-email-block--truncation-placeholder',
			style: borderPreview,
		} );
		return el(
			Fragment,
			null,
			el( InspectorControls, null, el( SectionStylePanels, props ) ),
			el(
				'div',
				blockProps,
				el( 'strong', null, i18n.truncation || 'Truncation notice' ),
				el(
					'span',
					{ className: 'wstp-email-placeholder-hint' },
					i18n.truncationHelp ||
						'Appears only when the digest post limit hides extra posts. Omitted from the email when unused; spacing applies only then.'
				)
			)
		);
	}

	function PostsLoopEdit( props ) {
		var blockProps = useBlockProps( {
			className: 'wstp-email-block wstp-email-block--posts-loop',
			style: previewPad( props.attributes ),
		} );

		return el(
			Fragment,
			null,
			el( InspectorControls, null, el( SectionStylePanels, props ) ),
			el(
				'div',
				blockProps,
				el( 'div', { className: 'wstp-email-preview-loop-label' }, i18n.postsLoop || 'Posts loop' ),
				el( 'div', { className: 'wstp-email-posts-loop-inner' }, el( InnerBlocks, { allowedBlocks: LOOP_ALLOWED } ) )
			)
		);
	}

	function FieldCard( props ) {
		var attrs = props.attributes || {};
		var padTop = typeof attrs.paddingTop === 'number' ? attrs.paddingTop : 0;
		var padBottom = typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : 0;
		var padX = typeof attrs.paddingX === 'number' ? attrs.paddingX : 0;
		var blockProps = useBlockProps( {
			className: 'wstp-email-field-block ' + ( props.className || '' ),
			style: {
				color: storeToColor( attrs.textColor, props.fallbackColor ),
				textAlign: attrs.align || 'left',
				fontSize: ( attrs.fontSize || props.defaultFontSize || 15 ) + 'px',
				// Mirror MJML gap-after / padding so the canvas matches the preview.
				paddingTop: padTop + 'px',
				paddingBottom: padBottom + 'px',
				paddingLeft: padX + 'px',
				paddingRight: padX + 'px',
				boxSizing: 'border-box',
			},
		} );
		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el( FieldStylePanels, {
					attributes: props.attributes,
					setAttributes: props.setAttributes,
					defaultTextToken: props.defaultTextToken,
					defaultFontSize: props.defaultFontSize,
					isButton: props.isButton,
				} ),
				props.extraInspector || null
			),
			el( 'div', blockProps, props.children )
		);
	}

	/**
	 * Trim text to a word count for excerpt preview (matches wp_trim_words).
	 *
	 * @param {string} text Source text.
	 * @param {number} count Max words.
	 * @return {string}
	 */
	function trimWords( text, count ) {
		var source = String( text || '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! source ) {
			return '';
		}
		var max = Math.max( 1, Math.min( 200, parseInt( count, 10 ) || 42 ) );
		var words = source.split( ' ' );
		if ( words.length <= max ) {
			return source;
		}
		return words.slice( 0, max ).join( ' ' ) + '…';
	}

	function PostTitleEdit( props ) {
		var sample = config.samplePost || {};
		return el(
			FieldCard,
			{
				attributes: props.attributes,
				setAttributes: props.setAttributes,
				defaultTextToken: 'accent-three',
				defaultFontSize: 20,
				fallbackColor: storeToColor( 'accent-three' ),
				className: 'is-title',
				extraInspector: el(
					PanelBody,
					{ title: i18n.postField || 'Post field', initialOpen: true },
					el(
						'p',
						{ className: 'description', style: { marginTop: 0 } },
						i18n.postTitleHelp || 'Shows each post title (linked). Style with color, size, and spacing.'
					)
				),
			},
			el( 'strong', null, sample.title || i18n.sampleTitle || 'Sample post title' ),
			el(
				'span',
				{ className: 'wstp-email-placeholder-hint', style: { display: 'block', fontSize: '11px', marginTop: '4px' } },
				i18n.postTitle || 'Post title'
			)
		);
	}

	function PostExcerptEdit( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var sample = config.samplePost || {};
		var source =
			sample.excerptSource || sample.excerpt || i18n.sampleExcerpt || 'Short excerpt of the post…';
		var words = typeof attrs.wordCount === 'number' ? attrs.wordCount : 42;
		var preview = trimWords( source, words );

		return el(
			FieldCard,
			{
				attributes: attrs,
				setAttributes: setAttributes,
				defaultTextToken: 'accent',
				defaultFontSize: 15,
				fallbackColor: storeToColor( 'accent' ),
				className: 'is-excerpt',
				extraInspector: el(
					PanelBody,
					{ title: i18n.postExcerpt || 'Post excerpt', initialOpen: true },
					el( RangeControl, {
						label: i18n.wordCount || 'Word count',
						help:
							i18n.wordCountHelp ||
							'Maximum words per post. Uses the post excerpt when set, otherwise the content.',
						value: words,
						min: 5,
						max: 100,
						step: 1,
						onChange: function ( value ) {
							setAttributes( { wordCount: value } );
						},
					} )
				),
			},
			preview,
			el(
				'span',
				{ className: 'wstp-email-placeholder-hint', style: { display: 'block', fontSize: '11px', marginTop: '4px' } },
				( i18n.wordCountLabel || '%d words' ).replace( '%d', String( words ) )
			)
		);
	}

	function PostMetaEdit( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		var sample = config.samplePost || {};
		var showDate = attrs.showDate !== false;
		var showAuthor = attrs.showAuthor !== false;
		var separator = typeof attrs.separator === 'string' ? attrs.separator : ' · ';
		var parts = [];
		if ( showDate ) {
			parts.push( sample.date || i18n.sampleDate || 'March 15, 2026' );
		}
		if ( showAuthor ) {
			parts.push( sample.author || sample.name || i18n.sampleAuthor || 'Alex' );
		}
		var preview =
			parts.length > 0
				? parts.join( separator )
				: i18n.postMetaEmpty || 'Enable date and/or author in the sidebar.';

		return el(
			FieldCard,
			{
				attributes: attrs,
				setAttributes: setAttributes,
				defaultTextToken: 'accent',
				defaultFontSize: 13,
				fallbackColor: storeToColor( 'accent' ),
				className: 'is-meta',
				extraInspector: el(
					PanelBody,
					{ title: i18n.postMeta || 'Post meta', initialOpen: true },
					el( ToggleControl, {
						label: i18n.showDate || 'Show date',
						checked: showDate,
						onChange: function ( value ) {
							setAttributes( { showDate: !! value } );
						},
					} ),
					el( ToggleControl, {
						label: i18n.showAuthor || 'Show author',
						checked: showAuthor,
						onChange: function ( value ) {
							setAttributes( { showAuthor: !! value } );
						},
					} ),
					showDate && showAuthor
						? el( TextControl, {
								label: i18n.metaSeparator || 'Separator',
								value: separator,
								onChange: function ( value ) {
									setAttributes( { separator: value } );
								},
						  } )
						: null
				),
			},
			preview,
			el(
				'span',
				{ className: 'wstp-email-placeholder-hint', style: { display: 'block', fontSize: '11px', marginTop: '4px' } },
				i18n.postMeta || 'Post meta'
			)
		);
	}

	var IMAGE_ATTRS = {
		widthPercent: { type: 'number', default: 100 },
		// align comes from supports.align (native Gutenberg toolbar, like core/image).
		borderRadius: { type: 'number', default: 4 },
		paddingTop: { type: 'number', default: 0 },
		paddingBottom: { type: 'number', default: 0 },
		paddingX: { type: 'number', default: 0 },
	};

	function ImageAlignToolbar( props ) {
		var align = props.attributes.align || 'center';
		var setAttributes = props.setAttributes;
		// Prefer the same toolbar Gutenberg uses for core/image.
		if ( BlockAlignmentToolbar ) {
			return el(
				BlockControls,
				{ group: 'block' },
				el( BlockAlignmentToolbar, {
					value: align,
					controls: [ 'left', 'center', 'right' ],
					onChange: function ( next ) {
						setAttributes( { align: next || 'center' } );
					},
				} )
			);
		}
		if ( AlignmentToolbar ) {
			return el(
				BlockControls,
				{ group: 'block' },
				el( AlignmentToolbar, {
					value: align,
					onChange: function ( next ) {
						setAttributes( { align: next || 'center' } );
					},
				} )
			);
		}
		if ( AlignmentControl && ToolbarGroup ) {
			return el(
				BlockControls,
				{ group: 'block' },
				el(
					ToolbarGroup,
					null,
					el( AlignmentControl, {
						value: align,
						onChange: function ( next ) {
							setAttributes( { align: next || 'center' } );
						},
					} )
				)
			);
		}
		return null;
	}

	function ImageStylePanels( props ) {
		var attrs = props.attributes;
		var setAttributes = props.setAttributes;
		return el(
			Fragment,
			null,
			el(
				PanelBody,
				{ title: i18n.imageSettings || 'Image', initialOpen: true },
				el( RangeControl, {
					label: i18n.widthPercent || 'Width (% of column)',
					help:
						i18n.widthPercentHelp ||
						'Percent of the column. Email clients compile this to pixels for that column; on mobile the column is full width (minus padding).',
					value: typeof attrs.widthPercent === 'number' ? attrs.widthPercent : 100,
					min: 20,
					max: 100,
					step: 5,
					onChange: function ( value ) {
						setAttributes( { widthPercent: value } );
					},
				} ),
				el( RangeControl, {
					label: i18n.borderRadius || 'Border radius (px)',
					value: typeof attrs.borderRadius === 'number' ? attrs.borderRadius : 4,
					min: 0,
					max: 24,
					onChange: function ( value ) {
						setAttributes( { borderRadius: value } );
					},
				} )
			),
			el( SpacingPanel, props )
		);
	}

	function PreviewPostImage( props ) {
		var url = props.src || '';
		var side = !! props.side;
		var style = props.style || {};
		var alt = props.alt || '';
		var state = useState( { failed: false } );
		var failed = state[ 0 ].failed;
		var setState = state[ 1 ];

		if ( ! url || failed ) {
			return el( 'div', {
				className: side ? 'wstp-email-preview-thumb' : 'wstp-email-preview-image',
				'aria-hidden': true,
				style: style,
			} );
		}

		return el( 'img', {
			className: side ? 'wstp-email-preview-thumb-img' : 'wstp-email-preview-image-img',
			src: url,
			alt: alt,
			style: Object.assign( {}, style, {
				display: 'block',
				height: 'auto',
				maxWidth: '100%',
			} ),
			onError: function () {
				setState( { failed: true } );
			},
		} );
	}

	function PostImageEdit( props ) {
		var attrs = props.attributes;
		var width = Math.max( 20, Math.min( 100, attrs.widthPercent || 100 ) );
		var align = attrs.align || 'center';
		var sample = config.samplePost || {};
		var imageUrl = sample.image || '';
		var blockProps = useBlockProps( {
			className: 'wstp-email-field-block is-image',
			style: {
				paddingTop: ( attrs.paddingTop || 0 ) + 'px',
				paddingBottom: ( typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : 0 ) + 'px',
				paddingLeft: ( attrs.paddingX || 0 ) + 'px',
				paddingRight: ( attrs.paddingX || 0 ) + 'px',
				textAlign: align,
			},
		} );
		var imageStyle = {
			width: width + '%',
			marginLeft: align === 'right' || align === 'center' ? 'auto' : 0,
			marginRight: align === 'left' || align === 'center' ? 'auto' : 0,
			borderRadius: ( attrs.borderRadius || 0 ) + 'px',
		};
		return el(
			Fragment,
			null,
			el( ImageAlignToolbar, props ),
			el( InspectorControls, null, el( ImageStylePanels, props ) ),
			el( 'div', blockProps, el( PreviewPostImage, { src: imageUrl, alt: sample.title || '', style: imageStyle } ) )
		);
	}

	function PostImageSideEdit( props ) {
		var attrs = props.attributes;
		var width = Math.max( 20, Math.min( 100, attrs.widthPercent || 100 ) );
		var align = attrs.align || 'center';
		var sample = config.samplePost || {};
		var imageUrl = sample.image || '';
		var blockProps = useBlockProps( {
			className: 'wstp-email-field-block is-image-side',
			style: {
				paddingTop: ( attrs.paddingTop || 0 ) + 'px',
				paddingBottom: ( typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : 0 ) + 'px',
				paddingLeft: ( attrs.paddingX || 0 ) + 'px',
				paddingRight: ( attrs.paddingX || 0 ) + 'px',
				textAlign: align,
			},
		} );
		var imageStyle = {
			width: width + '%',
			marginLeft: align === 'right' || align === 'center' ? 'auto' : 0,
			marginRight: align === 'left' || align === 'center' ? 'auto' : 0,
			borderRadius: ( attrs.borderRadius || 0 ) + 'px',
		};
		return el(
			Fragment,
			null,
			el( ImageAlignToolbar, props ),
			el( InspectorControls, null, el( ImageStylePanels, props ) ),
			el(
				'div',
				blockProps,
				el( PreviewPostImage, { src: imageUrl, alt: sample.title || '', side: true, style: imageStyle } )
			)
		);
	}

	function PostReadMoreEdit( props ) {
		var style = props.attributes.style || 'button';
		var isButton = style === 'button';
		var bg = storeToColor( props.attributes.backgroundColor, storeToColor( 'accent-two' ) );
		var color = storeToColor(
			props.attributes.textColor,
			isButton ? '#ffffff' : storeToColor( 'accent-two' )
		);

		return el(
			FieldCard,
			{
				attributes: props.attributes,
				setAttributes: props.setAttributes,
				defaultTextToken: isButton ? '' : 'accent-two',
				defaultFontSize: 14,
				fallbackColor: color,
				isButton: isButton,
				extraInspector: el(
					PanelBody,
					{ title: i18n.buttonStyle || 'Button style', initialOpen: true },
					el( SelectControl, {
						label: i18n.readMoreStyle || 'Style',
						value: style,
						options: [
							{ label: i18n.styleButton || 'Button', value: 'button' },
							{ label: i18n.styleLink || 'Link', value: 'link' },
						],
						onChange: function ( value ) {
							props.setAttributes( { style: value } );
						},
					} )
				),
			},
			isButton
				? el(
						'span',
						{
							className: 'wstp-email-preview-btn',
							style: {
								background: bg,
								color: color,
								borderRadius: ( props.attributes.borderRadius || 4 ) + 'px',
							},
						},
						i18n.readMore || 'Read more'
				  )
				: el( 'span', { className: 'wstp-email-preview-link', style: { color: color } }, i18n.readMore || 'Read more' )
		);
	}

	function registerColumnSpacing() {
		if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
			return;
		}
		if ( window.wstpColumnSpacingRegistered ) {
			return;
		}
		window.wstpColumnSpacingRegistered = true;

		hooks.addFilter(
			'blocks.registerBlockType',
			'wstp/column-spacing-attrs',
			function ( settings, name ) {
				// Native "Block spacing" (blockGap) does not map to MJML — use Gap after on fields.
				if ( name === 'core/columns' || name === 'core/column' ) {
					var supports = Object.assign( {}, settings.supports || {} );
					var spacing = Object.assign( {}, supports.spacing || {}, {
						blockGap: false,
						margin: false,
					} );
					// Column padding is our own Spacing panel (maps to mj-column padding).
					if ( name === 'core/column' ) {
						spacing.padding = false;
					}
					supports.spacing = spacing;
					settings.supports = supports;
				}
				if ( name !== 'core/column' ) {
					return settings;
				}
				settings.attributes = Object.assign( {}, settings.attributes, {
					paddingTop: { type: 'number', default: 0 },
					paddingBottom: { type: 'number', default: 0 },
					paddingX: { type: 'number', default: 0 },
				} );
				return settings;
			}
		);

		hooks.addFilter(
			'editor.BlockEdit',
			'wstp/column-spacing-controls',
			function ( BlockEdit ) {
				return function ( props ) {
					if ( props.name !== 'core/column' ) {
						return el( BlockEdit, props );
					}
					var widthPct = parseColumnWidthPercent( props.attributes.width );
					return el(
						Fragment,
						null,
						el( BlockEdit, props ),
						el(
							InspectorControls,
							null,
							el(
								PanelBody,
								{ title: i18n.columnWidth || 'Column width (%)', initialOpen: true },
								el( RangeControl, {
									label: i18n.columnWidth || 'Column width (%)',
									help:
										i18n.columnWidthHelp ||
										'Share of the email width (600px). Example: 34% ≈ 204px in Outlook. Prefer this over dragging — drag can store pixel widths.',
									value: widthPct,
									min: 20,
									max: 80,
									onChange: function ( value ) {
										var pct = Math.max( 20, Math.min( 80, parseInt( value, 10 ) || 50 ) );
										props.setAttributes( { width: String( pct ) + '%' } );
									},
								} )
							),
							el( SpacingPanel, {
								attributes: props.attributes,
								setAttributes: props.setAttributes,
							} )
						)
					);
				};
			}
		);

		hooks.addFilter(
			'editor.BlockListBlock',
			'wstp/column-padding-preview',
			function ( BlockListBlock ) {
				return function ( props ) {
					var name = props.name || ( props.block && props.block.name );
					if ( name !== 'core/column' ) {
						return el( BlockListBlock, props );
					}
					var attrs = props.attributes || ( props.block && props.block.attributes ) || {};
					var top = typeof attrs.paddingTop === 'number' ? attrs.paddingTop : 0;
					var bottom = typeof attrs.paddingBottom === 'number' ? attrs.paddingBottom : 0;
					var x = typeof attrs.paddingX === 'number' ? attrs.paddingX : 0;
					var wrapperProps = Object.assign( {}, props.wrapperProps || {}, {
						style: Object.assign(
							{},
							( props.wrapperProps && props.wrapperProps.style ) || {},
							{
								paddingTop: top + 'px',
								paddingBottom: bottom + 'px',
								paddingLeft: x + 'px',
								paddingRight: x + 'px',
								boxSizing: 'border-box',
								// Kill leftover native blockGap from saved layouts.
								gap: '0px',
								rowGap: '0px',
								columnGap: '0px',
							}
						),
					} );
					return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
				};
			}
		);
	}

	/**
	 * Read core/column width as a percent of the email body.
	 * Handles "34%", "220px", and bare numbers ( >100 treated as px ).
	 *
	 * @param {string|number|undefined} raw Width attribute.
	 * @return {number}
	 */
	function parseColumnWidthPercent( raw ) {
		if ( typeof raw === 'number' && isFinite( raw ) ) {
			if ( raw > 100 ) {
				return Math.max( 20, Math.min( 80, Math.round( ( raw / 600 ) * 100 ) ) );
			}
			return Math.max( 20, Math.min( 80, Math.round( raw ) ) );
		}
		var s = raw ? String( raw ).trim() : '';
		if ( ! s ) {
			return 50;
		}
		var pct = s.match( /^([\d.]+)\s*%$/ );
		if ( pct ) {
			return Math.max( 20, Math.min( 80, Math.round( parseFloat( pct[1] ) ) ) );
		}
		var px = s.match( /^([\d.]+)\s*px$/i );
		if ( px ) {
			return Math.max( 20, Math.min( 80, Math.round( ( parseFloat( px[1] ) / 600 ) * 100 ) ) );
		}
		var n = parseFloat( s );
		if ( ! isFinite( n ) ) {
			return 50;
		}
		if ( n > 100 ) {
			return Math.max( 20, Math.min( 80, Math.round( ( n / 600 ) * 100 ) ) );
		}
		return Math.max( 20, Math.min( 80, Math.round( n ) ) );
	}

	function registerSeparatorColor() {
		if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
			return;
		}
		if ( window.wstpSeparatorColorRegistered ) {
			return;
		}
		window.wstpSeparatorColorRegistered = true;

		hooks.addFilter(
			'blocks.registerBlockType',
			'wstp/separator-color-attrs',
			function ( settings, name ) {
				if ( name !== 'core/separator' ) {
					return settings;
				}
				settings.attributes = Object.assign( {}, settings.attributes, {
					wstpColor: { type: 'string', default: '' },
					paddingTop: { type: 'number', default: 0 },
					paddingBottom: { type: 'number', default: 0 },
					paddingX: { type: 'number', default: 0 },
				} );
				var supports = Object.assign( {}, settings.supports || {} );
				var spacing = Object.assign( {}, supports.spacing || {}, {
					blockGap: false,
					margin: false,
				} );
				supports.spacing = spacing;
				settings.supports = supports;
				return settings;
			}
		);

		hooks.addFilter(
			'editor.BlockEdit',
			'wstp/separator-color-controls',
			function ( BlockEdit ) {
				return function ( props ) {
					if ( props.name !== 'core/separator' ) {
						return el( BlockEdit, props );
					}
					var color = storeToColor( props.attributes.wstpColor, storeToColor( 'accent', '#94a3b8' ) );
					return el(
						Fragment,
						null,
						el( BlockEdit, props ),
						el(
							InspectorControls,
							null,
							el( SpacingPanel, {
								attributes: props.attributes,
								setAttributes: props.setAttributes,
							} ),
							el(
								'p',
								{ className: 'description', style: { marginTop: '-4px' } },
								i18n.separatorSpacingHelp ||
									'Add top padding so the line does not sit against the button above.'
							),
							el(
								PanelBody,
								{ title: i18n.colors || 'Colors', initialOpen: true },
								el( ColorField, {
									id: 'wstp-separator-color',
									label: i18n.separatorColor || 'Line color',
									value: props.attributes.wstpColor,
									fallback: storeToColor( 'accent', '#94a3b8' ),
									onChange: function ( value ) {
										props.setAttributes( { wstpColor: value || '' } );
									},
								} )
							)
						),
						// Tint the preview line so the choice is visible in the canvas.
						el( 'style', {
							dangerouslySetInnerHTML: {
								__html:
									'.block-editor-block-list__block[data-block="' +
									props.clientId +
									'"] .wp-block-separator{border-color:' +
									color +
									'!important;color:' +
									color +
									'!important;}' +
									'.block-editor-block-list__block[data-block="' +
									props.clientId +
									'"] .wp-block-separator.is-style-dots:before{color:' +
									color +
									'!important;background-image:none!important;}',
							},
						} )
					);
				};
			}
		);

		hooks.addFilter(
			'editor.BlockListBlock',
			'wstp/separator-spacing-preview',
			function ( BlockListBlock ) {
				return function ( props ) {
					var name = props.name || ( props.block && props.block.name );
					if ( name !== 'core/separator' ) {
						return el( BlockListBlock, props );
					}
					var attrs = props.attributes || ( props.block && props.block.attributes ) || {};
					var wrapperProps = Object.assign( {}, props.wrapperProps || {}, {
						className: [
							( props.wrapperProps && props.wrapperProps.className ) || '',
							'wstp-email-separator-wrap',
						]
							.join( ' ' )
							.trim(),
						style: Object.assign(
							{},
							( props.wrapperProps && props.wrapperProps.style ) || {},
							previewPad( attrs )
						),
					} );
					return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
				};
			}
		);
	}

	function registerFreeTextBlocks() {
		if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
			return;
		}
		if ( window.wstpFreeTextRegistered ) {
			return;
		}
		window.wstpFreeTextRegistered = true;

		// Email-only extras. Do NOT register textColor / backgroundColor / fontSize —
		// those belong to core (native Color + Typography panels). Overwriting them
		// duplicated the UI and broke font-size sync (custom 20 vs native 38).
		var FREE_TEXT_ATTRS = {
			paddingTop: { type: 'number', default: 0 },
			paddingBottom: { type: 'number', default: 0 },
			paddingX: { type: 'number', default: 0 },
			borderTop: { type: 'number', default: 0 },
			borderRight: { type: 'number', default: 0 },
			borderBottom: { type: 'number', default: 0 },
			borderLeft: { type: 'number', default: 0 },
			borderColor: { type: 'string', default: '' },
			fontFamily: { type: 'string', default: DEFAULT_EMAIL_FONT },
		};

		var BUTTON_ATTRS = {
			backgroundColor: { type: 'string', default: 'accent-two' },
			textColor: { type: 'string', default: '#ffffff' },
			paddingTop: { type: 'number', default: 8 },
			paddingBottom: { type: 'number', default: 8 },
			paddingX: { type: 'number', default: 0 },
			align: { type: 'string', default: 'center' },
			textAlign: { type: 'string', default: 'center' },
		};

		hooks.addFilter(
			'blocks.registerBlockType',
			'wstp/free-text-attrs',
			function ( settings, name ) {
				if ( name === 'core/button' ) {
					settings.attributes = Object.assign( {}, settings.attributes, BUTTON_ATTRS );
					return settings;
				}
				if ( name !== 'core/paragraph' && name !== 'core/heading' ) {
					return settings;
				}
				settings.attributes = Object.assign( {}, settings.attributes, FREE_TEXT_ATTRS );
				settings.title =
					name === 'core/heading'
						? i18n.customHeading || 'Custom heading'
						: i18n.customParagraph || 'Custom text';
				settings.description =
					i18n.customTextHelp ||
					'Your own static copy in the digest (links, bold, and italic are supported).';
				// Do not disable core textAlign support — that changes save() HTML and
				// marks seeded blocks (has-text-align-*) as invalid on parse.
				var supports = Object.assign( {}, settings.supports || {} );
				var typography = Object.assign( {}, supports.typography || {}, {
					fontFamily: false,
					fontStyle: false,
					fontWeight: false,
					letterSpacing: false,
					textTransform: false,
					textDecoration: false,
				} );
				supports.typography = typography;
				settings.supports = supports;
				return settings;
			}
		);

		hooks.addFilter(
			'editor.BlockEdit',
			'wstp/free-text-controls',
			function ( BlockEdit ) {
				return function ( props ) {
					if ( props.name === 'core/button' ) {
						return el(
							Fragment,
							null,
							el( BlockEdit, props ),
							el(
								InspectorControls,
								{ group: 'styles' },
								el(
									PanelBody,
									{ title: i18n.buttonStyle || 'Button style', initialOpen: true },
									el( ColorField, {
										id: 'wstp-btn-bg',
										label: i18n.background || 'Background',
										value: props.attributes.backgroundColor,
										fallback: storeToColor( 'accent-two' ),
										onChange: function ( value ) {
											props.setAttributes( { backgroundColor: value } );
										},
									} ),
									el( ColorField, {
										id: 'wstp-btn-text',
										label: i18n.textColor || 'Text',
										value: props.attributes.textColor,
										fallback: '#ffffff',
										onChange: function ( value ) {
											props.setAttributes( { textColor: value } );
										},
									} ),
									el( SelectControl, {
										label: i18n.align || 'Align',
										value: resolveTextAlign( props.attributes ),
										options: [
											{ label: i18n.alignLeft || 'Left', value: 'left' },
											{ label: i18n.alignCenter || 'Center', value: 'center' },
											{ label: i18n.alignRight || 'Right', value: 'right' },
										],
										onChange: function ( value ) {
											setTextAlignAttributes( props.setAttributes, value );
										},
									} )
								)
							)
						);
					}
					if ( props.name !== 'core/paragraph' && props.name !== 'core/heading' ) {
						return el( BlockEdit, props );
					}
					// Native Color + Typography (incl. font size) + alignment toolbar.
					// We only add Spacing, Borders, and email-safe Font family.
					return el(
						Fragment,
						null,
						el( BlockEdit, props ),
						el(
							InspectorControls,
							{ group: 'styles' },
							el(
								SectionStylePanels,
								Object.assign( {}, props, {
									showColors: false,
									showText: false,
									showFont: true,
									showAlign: false,
									showFontSize: false,
								} )
							)
						)
					);
				};
			}
		);

		hooks.addFilter(
			'editor.BlockListBlock',
			'wstp/free-text-preview',
			function ( BlockListBlock ) {
				return function ( props ) {
					var name = props.name || ( props.block && props.block.name );
					if ( name !== 'core/paragraph' && name !== 'core/heading' ) {
						return el( BlockListBlock, props );
					}
					var attrs = props.attributes || ( props.block && props.block.attributes ) || {};
					var align = resolveTextAlign( attrs );
					var color = resolveBlockTextColor( attrs, storeToColor( 'accent' ) );
					var bg = resolveBlockBackgroundColor( attrs );
					var sizePx = resolveBlockFontSizePx( attrs, name === 'core/heading' ? 20 : 15 );
					var style = Object.assign( {}, previewPad( attrs ), {
						color: color,
						'--wstp-block-color': color,
						backgroundColor: bg,
						fontSize: sizePx + 'px',
						fontWeight: name === 'core/heading' ? 700 : undefined,
						fontFamily: resolveFontFamily( attrs.fontFamily ),
						textAlign: align,
						width: '100%',
					} );
					var wrapperProps = Object.assign( {}, props.wrapperProps || {}, {
						className: [
							( props.wrapperProps && props.wrapperProps.className ) || '',
							'wstp-email-free-text',
							'has-text-align-' + align,
						]
							.join( ' ' )
							.trim(),
						style: Object.assign(
							{},
							( props.wrapperProps && props.wrapperProps.style ) || {},
							style
						),
					} );
					return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
				};
			}
		);
	}

	/**
	 * Hide Advanced → Additional CSS class(es) / HTML anchor in the email editor.
	 * Those controls do not map to MJML and only clutter the sidebar.
	 */
	function registerHideEmailAdvancedFields() {
		if ( ! hooks || typeof hooks.addFilter !== 'function' ) {
			return;
		}
		if ( window.wstpHideEmailAdvancedRegistered ) {
			return;
		}
		window.wstpHideEmailAdvancedRegistered = true;

		var coreEmailBlocks = {
			'core/paragraph': true,
			'core/heading': true,
			'core/image': true,
			'core/buttons': true,
			'core/button': true,
			'core/columns': true,
			'core/column': true,
			'core/separator': true,
		};

		hooks.addFilter(
			'blocks.registerBlockType',
			'wstp/hide-email-advanced',
			function ( settings, name ) {
				if ( name.indexOf( 'wstp/' ) !== 0 && ! coreEmailBlocks[ name ] ) {
					return settings;
				}
				settings.supports = Object.assign( {}, settings.supports || {}, {
					customClassName: false,
					anchor: false,
					renaming: false,
					lock: false,
				} );
				return settings;
			}
		);
	}

	function registerEmailBlocks() {
		registerHideEmailAdvancedFields();
		registerColumnSpacing();
		registerSeparatorColor();
		registerFreeTextBlocks();

		if ( wp.blocks.getBlockType( 'wstp/email-shell' ) ) {
			return;
		}

		var emailSupports = {
			html: false,
			reusable: false,
			customClassName: false,
			anchor: false,
		};
		var emailSupportsOnce = Object.assign( {}, emailSupports, { multiple: false } );

		registerBlockType( 'wstp/email-shell', {
			apiVersion: 3,
			title: i18n.emailCanvas || 'Email canvas',
			description: i18n.emailCanvasHelp || 'Outer email background and container for all sections.',
			icon: 'email-alt',
			category: 'widgets',
			attributes: {
				backgroundColor: { type: 'string', default: 'base' },
			},
			supports: emailSupportsOnce,
			edit: ShellEdit,
			save: function () {
				return el( InnerBlocks.Content );
			},
		} );

		registerBlockType( 'wstp/email-header', {
			apiVersion: 3,
			title: i18n.header || 'Email header',
			icon: 'admin-site-alt3',
			category: 'widgets',
			attributes: HEADER_ATTRS,
			supports: emailSupportsOnce,
			edit: HeaderEdit,
			save: function () {
				return el( InnerBlocks.Content );
			},
		} );

		registerBlockType( 'wstp/email-footer', {
			apiVersion: 3,
			title: i18n.footer || 'Email footer',
			icon: 'editor-insertmore',
			category: 'widgets',
			attributes: FOOTER_ATTRS,
			supports: emailSupportsOnce,
			edit: FooterEdit,
			save: function () {
				return null;
			},
		} );

		registerBlockType( 'wstp/intro', {
			apiVersion: 3,
			title: i18n.intro || 'Greeting & intro',
			icon: 'editor-paragraph',
			category: 'widgets',
			attributes: SECTION_ATTRS,
			supports: emailSupportsOnce,
			edit: IntroEdit,
			save: function () {
				return el( InnerBlocks.Content );
			},
		} );

		registerBlockType( 'wstp/truncation-notice', {
			apiVersion: 3,
			title: i18n.truncation || 'Truncation notice',
			icon: 'warning',
			category: 'widgets',
			attributes: SECTION_ATTRS,
			supports: emailSupportsOnce,
			edit: TruncationEdit,
			save: function () {
				return null;
			},
		} );

		registerBlockType( 'wstp/posts-loop', {
			apiVersion: 3,
			title: i18n.postsLoop || 'Posts loop',
			icon: 'list-view',
			category: 'widgets',
			attributes: Object.assign( {}, SECTION_ATTRS, {
				layout: { type: 'string', default: 'stacked' },
			} ),
			supports: emailSupportsOnce,
			edit: PostsLoopEdit,
			save: function () {
				return el( InnerBlocks.Content );
			},
		} );

		registerBlockType( 'wstp/post-title', {
			apiVersion: 3,
			title: i18n.postTitle || 'Post title',
			icon: 'heading',
			category: 'widgets',
			parent: [ 'wstp/posts-loop', 'core/column' ],
			attributes: FIELD_ATTRS,
			supports: emailSupports,
			edit: PostTitleEdit,
			save: function () {
				return null;
			},
		} );

		registerBlockType( 'wstp/post-excerpt', {
			apiVersion: 3,
			title: i18n.postExcerpt || 'Post excerpt',
			icon: 'text',
			category: 'widgets',
			parent: [ 'wstp/posts-loop', 'core/column' ],
			attributes: EXCERPT_ATTRS,
			supports: emailSupports,
			edit: PostExcerptEdit,
			save: function () {
				return null;
			},
		} );

		registerBlockType( 'wstp/post-meta', {
			apiVersion: 3,
			title: i18n.postMeta || 'Post meta',
			icon: 'calendar-alt',
			category: 'widgets',
			parent: [ 'wstp/posts-loop', 'core/column' ],
			attributes: META_ATTRS,
			supports: emailSupports,
			edit: PostMetaEdit,
			save: function () {
				return null;
			},
		} );

		registerBlockType( 'wstp/post-image', {
			apiVersion: 3,
			title: i18n.postImage || 'Post image',
			icon: 'format-image',
			category: 'widgets',
			parent: [ 'wstp/posts-loop', 'core/column' ],
			attributes: Object.assign( {}, IMAGE_ATTRS, {
				align: { type: 'string', default: 'center' },
			} ),
			// No supports.align — that adds alignleft/center CSS floats which break email columns.
			// Alignment uses the same BlockAlignmentToolbar as core/image (see ImageAlignToolbar).
			supports: emailSupports,
			edit: PostImageEdit,
			save: function () {
				// Non-null save so the block reliably round-trips inside core/column.
				return el( 'div', { className: 'wstp-email-post-image', 'aria-hidden': 'true' } );
			},
		} );

		registerBlockType( 'wstp/post-image-side', {
			apiVersion: 3,
			title: i18n.postImageSide || 'Post image (side)',
			icon: 'align-pull-left',
			category: 'widgets',
			parent: [ 'wstp/posts-loop', 'core/column' ],
			attributes: Object.assign( {}, IMAGE_ATTRS, {
				align: { type: 'string', default: 'center' },
				paddingBottom: { type: 'number', default: 0 },
			} ),
			supports: emailSupports,
			edit: PostImageSideEdit,
			save: function () {
				return el( 'div', { className: 'wstp-email-post-image-side', 'aria-hidden': 'true' } );
			},
		} );

		registerBlockType( 'wstp/post-read-more', {
			apiVersion: 3,
			title: i18n.postReadMore || 'Read more',
			icon: 'button',
			category: 'widgets',
			parent: [ 'wstp/posts-loop', 'core/column' ],
			attributes: Object.assign( {}, FIELD_ATTRS, {
				style: { type: 'string', default: 'button' },
			} ),
			supports: emailSupports,
			edit: PostReadMoreEdit,
			save: function () {
				return null;
			},
		} );
	}

	function getCompileFn() {
		if ( typeof window.wstpMjmlCompile === 'function' ) {
			return window.wstpMjmlCompile;
		}
		if ( window.wstpMjml && typeof window.wstpMjml.compileMjml === 'function' ) {
			return window.wstpMjml.compileMjml;
		}
		return null;
	}

	function compileMjml( mjml ) {
		var compileFn = getCompileFn();
		if ( ! compileFn ) {
			return { ok: false, message: i18n.compileFail || 'MJML compilation failed.' };
		}
		var result = compileFn( mjml || '' );
		var html = result && result.html ? result.html : '';
		var errors = result && Array.isArray( result.errors ) ? result.errors : [];
		if ( ! html ) {
			var message = errors.length
				? errors
						.map( function ( error ) {
							return error.formattedMessage || error.message || '';
						} )
						.filter( Boolean )
						.join( ' ' )
				: i18n.compileFail || 'MJML compilation failed.';
			return { ok: false, message: message };
		}
		return { ok: true, html: html };
	}

	function mapBlocksToMjml( serialized ) {
		var body = new window.FormData();
		body.append( 'action', 'wstp_map_blocks_to_mjml' );
		body.append( 'nonce', config.mapNonce || '' );
		body.append( 'blocks', serialized );

		return window
			.fetch( config.mapAjaxUrl || window.ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data || ! payload.data.mjml ) {
					var message =
						( payload && payload.data && payload.data.message ) ||
						i18n.mapFailed ||
						'Could not convert blocks to MJML.';
					throw new Error( message );
				}
				return payload.data.mjml;
			} );
	}

	function showVisualError( message ) {
		var box = document.getElementById( 'wstp-visual-compile-error' );
		if ( ! box ) {
			window.alert( message );
			return;
		}
		box.style.display = 'block';
		box.textContent = message;
	}

	function clearVisualError() {
		var box = document.getElementById( 'wstp-visual-compile-error' );
		if ( box ) {
			box.style.display = 'none';
			box.textContent = '';
		}
	}

	function EditorHeaderBar() {
		return el(
			'div',
			{ className: 'wstp-email-visual-headerbar' },
			Inserter
				? el( Inserter, {
						renderToggle: function ( toggleProps ) {
							return el( Button, {
								icon: 'plus',
								label: i18n.addBlock || 'Add block',
								variant: 'primary',
								onClick: toggleProps.onToggle,
								'aria-expanded': toggleProps.isOpen,
							} );
						},
				  } )
				: null,
			el(
				'span',
				{ className: 'wstp-email-visual-headerbar-hint' },
				i18n.editorHint ||
					'Add placeholders and your own Paragraph / Heading text. Use the sidebar for spacing, colors, borders, and typography.'
			)
		);
	}

	function EditorSidebar() {
		var listPanel =
			ListView
				? el(
						'div',
						{ className: 'wstp-email-visual-listview' },
						el( ListView, null )
				  )
				: el(
						'p',
						{ className: 'description', style: { padding: '12px 16px' } },
						i18n.listViewUnavailable || 'List view is not available in this WordPress version.'
				  );

		var inspector = el(
			Fragment,
			null,
			BlockBreadcrumb
				? el( 'div', { className: 'wstp-email-visual-breadcrumb' }, el( BlockBreadcrumb, { rootLabelText: i18n.emailCanvas || 'Email canvas' } ) )
				: null,
			el( BlockInspector, null )
		);

		if ( TabPanel ) {
			return el( TabPanel, {
				className: 'wstp-email-visual-sidebar-tabs',
				activeClass: 'is-active',
				tabs: [
					{ name: 'block', title: i18n.blockSettings || 'Block', className: 'wstp-tab-block' },
					{ name: 'list', title: i18n.listView || 'List view', className: 'wstp-tab-list' },
				],
				children: function ( tab ) {
					return tab.name === 'list' ? listPanel : inspector;
				},
			} );
		}

		return el(
			Fragment,
			null,
			el( 'div', { className: 'wstp-email-visual-sidebar-title' }, i18n.blockSettings || 'Block' ),
			inspector,
			ListView
				? el(
						Fragment,
						null,
						el( 'div', { className: 'wstp-email-visual-sidebar-title' }, i18n.listView || 'List view' ),
						listPanel
				  )
				: null
		);
	}

	function EditorCanvas() {
		var list = el(
			CopyHandler,
			null,
			el( WritingFlow, null, el( ObserveTyping, null, el( BlockList, null ) ) )
		);
		var inner = BlockTools ? el( BlockTools, null, list ) : list;
		return el(
			'div',
			{ className: 'wstp-email-visual-editor-canvas' },
			el( 'div', { className: 'editor-styles-wrapper wstp-email-visual-canvas' }, inner )
		);
	}

	function EmailVisualEditor() {
		var initial = parse( config.blocks || '' );
		var state = useState( initial );
		var blocks = state[ 0 ];
		var setBlocks = state[ 1 ];

		useEffect(
			function () {
				blocksRef.current = blocks;
			},
			[ blocks ]
		);

		var palette = paletteColors();
		var settings = {
			allowedBlockTypes: config.allowedBlocks || true,
			hasFixedToolbar: false,
			__experimentalCanUserUseUnfilteredHTML: false,
			bodyPlaceholder: i18n.bodyPlaceholder || 'Add email content…',
			mediaUpload: wp.editor && wp.editor.mediaUpload ? wp.editor.mediaUpload : undefined,
			colors: palette,
			// No theme.json “apply globally” / design push in this standalone editor.
			__experimentalGlobalStylesBaseStyles: undefined,
			canUpdateBlockBindings: false,
			codeEditingEnabled: false,
			// Sections join flush; spacing comes from block padding only.
			__experimentalFeatures: {
				spacing: {
					blockGap: '0px',
					customSpacingSize: false,
					spacingSizes: [],
				},
				color: {
					custom: true,
					customDuotone: false,
					customGradient: false,
					defaultPalette: false,
					defaultGradients: false,
					palette: {
						theme: palette,
					},
					text: true,
					background: true,
					link: true,
				},
				typography: {
					customFontSize: true,
					fontStyle: false,
					fontWeight: false,
					letterSpacing: false,
					textDecoration: true,
					textTransform: false,
				},
			},
		};

		return el(
			ShortcutProvider,
			null,
			el(
				SlotFillProvider,
				null,
				el(
					BlockEditorProvider,
					{
						value: blocks,
						onInput: setBlocks,
						onChange: setBlocks,
						settings: settings,
					},
					el(
						'div',
						{ className: 'wstp-email-visual-shell' },
						el( EditorHeaderBar, null ),
						el(
							'div',
							{ className: 'wstp-email-visual-layout' },
							el( EditorCanvas, null ),
							el(
								'div',
								{ className: 'wstp-email-visual-sidebar interface-complementary-area' },
								el( EditorSidebar, null )
							)
						)
					),
					Popover && Popover.Slot ? el( Popover.Slot, null ) : null
				)
			)
		);
	}

	function mountEditor() {
		var rootEl = document.getElementById( 'wstp-email-visual-root' );
		if ( ! rootEl || rootEl.getAttribute( 'data-wstp-mounted' ) === '1' ) {
			return;
		}

		registerEmailBlocks();
		if ( wp.blockLibrary && typeof wp.blockLibrary.registerCoreBlocks === 'function' ) {
			wp.blockLibrary.registerCoreBlocks();
		}

		rootEl.setAttribute( 'data-wstp-mounted', '1' );
		if ( createRoot ) {
			createRoot( rootEl ).render( el( EmailVisualEditor ) );
		} else {
			render( el( EmailVisualEditor ), rootEl );
		}
	}

	function prepareCompiledPayload() {
		var serialized = serialize( blocksRef.current || [] );
		return mapBlocksToMjml( serialized ).then( function ( mjml ) {
			var compiled = compileMjml( mjml );
			if ( ! compiled.ok ) {
				throw new Error( compiled.message );
			}
			return { blocks: serialized, mjml: mjml, html: compiled.html };
		} );
	}

	function bindForms() {
		var saveForm = document.getElementById( 'wstp-visual-save-form' );
		var saveAsBtn = document.getElementById( 'wstp-save-visual-as' );
		var saveAsForm = document.getElementById( 'wstp-visual-save-as-form' );
		var previewBtn = document.getElementById( 'wstp-preview-visual' );
		var previewForm = document.getElementById( 'wstp-visual-preview-form' );
		var previewInput = document.getElementById( 'wstp-visual-preview-input' );
		var blocksInput = document.getElementById( 'wstp-blocks-template' );
		var mjmlInput = document.getElementById( 'wstp-visual-mjml-template' );
		var htmlInput = document.getElementById( 'wstp-visual-html-template' );

		if ( saveForm ) {
			saveForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				clearVisualError();
				prepareCompiledPayload()
					.then( function ( payload ) {
						if ( blocksInput ) {
							blocksInput.value = payload.blocks;
						}
						if ( mjmlInput ) {
							mjmlInput.value = payload.mjml;
						}
						if ( htmlInput ) {
							htmlInput.value = payload.html;
						}
						HTMLFormElement.prototype.submit.call( saveForm );
					} )
					.catch( function ( error ) {
						showVisualError( error && error.message ? error.message : i18n.mapFailed );
					} );
			} );
		}

		if ( saveAsBtn && saveAsForm ) {
			saveAsBtn.addEventListener( 'click', function () {
				var suggested = i18n.saveAsDefault || 'My layout';
				var name = window.prompt( i18n.saveAsPrompt || 'Name for this layout:', suggested );
				if ( name === null ) {
					return;
				}
				name = String( name ).trim();
				if ( ! name ) {
					showVisualError( i18n.saveAsEmpty || 'Please enter a layout name.' );
					return;
				}
				clearVisualError();
				prepareCompiledPayload()
					.then( function ( payload ) {
						var nameInput = document.getElementById( 'wstp-save-as-name' );
						var asBlocks = document.getElementById( 'wstp-save-as-blocks' );
						var asMjml = document.getElementById( 'wstp-save-as-mjml' );
						var asHtml = document.getElementById( 'wstp-save-as-html' );
						if ( nameInput ) {
							nameInput.value = name;
						}
						if ( asBlocks ) {
							asBlocks.value = payload.blocks;
						}
						if ( asMjml ) {
							asMjml.value = payload.mjml;
						}
						if ( asHtml ) {
							asHtml.value = payload.html;
						}
						HTMLFormElement.prototype.submit.call( saveAsForm );
					} )
					.catch( function ( error ) {
						showVisualError( error && error.message ? error.message : i18n.mapFailed );
					} );
			} );
		}

		if ( previewBtn && previewForm && previewInput ) {
			previewBtn.addEventListener( 'click', function () {
				clearVisualError();
				prepareCompiledPayload()
					.then( function ( payload ) {
						previewInput.value = payload.html;
						previewForm.submit();
					} )
					.catch( function ( error ) {
						showVisualError( error && error.message ? error.message : i18n.compileFail );
					} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		mountEditor();
		bindForms();
	} );

	window.wstpMountEmailVisualEditor = mountEditor;
}( window.wp ) );
