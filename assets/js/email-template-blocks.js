( function ( blocks, element, blockEditor, i18n, components ) {
	const el = element.createElement;
	const InnerBlocks = blockEditor.InnerBlocks;
	const InspectorControls = blockEditor.InspectorControls;
	const useBlockProps = blockEditor.useBlockProps;
	const PanelColorSettings = blockEditor.PanelColorSettings || blockEditor.__experimentalPanelColorSettings;
	const PanelBody = components.PanelBody;
	const RangeControl = components.RangeControl;
	const __ = i18n.__;

	const colorAttributes = {
		textColorHex: { type: 'string', default: '' },
		textColor: { type: 'string' },
		backgroundColor: { type: 'string' },
		customTextColor: { type: 'string' },
		customBackgroundColor: { type: 'string' },
		style: { type: 'object' },
	};

	function setTextColor( props, color ) {
		const nextStyle = Object.assign( {}, props.attributes.style || {} );
		const nextColor = Object.assign( {}, nextStyle.color || {} );

		if ( color ) {
			nextColor.text = color;
		} else {
			delete nextColor.text;
		}

		if ( Object.keys( nextColor ).length > 0 ) {
			nextStyle.color = nextColor;
		} else {
			delete nextStyle.color;
		}

		props.setAttributes( {
			textColorHex: color || '',
			style: Object.keys( nextStyle ).length > 0 ? nextStyle : undefined,
		} );
	}

	function renderTextColorPanel( props, title ) {
		if ( ! PanelColorSettings ) {
			return null;
		}

		return el( PanelColorSettings, {
			title: title || __( 'Text color', 'we-subscribe-to-posts' ),
			initialOpen: true,
			colorSettings: [
				{
					value: props.attributes.textColorHex || '',
					onChange: function ( color ) {
						setTextColor( props, color );
					},
					label: __( 'Text', 'we-subscribe-to-posts' ),
				},
			],
		} );
	}

	function renderColoredPlaceholder( props, text ) {
		const blockProps = useBlockProps();
		return el(
			'div',
			{},
			el(
				InspectorControls,
				{},
				renderTextColorPanel( props )
			),
			el( 'p', blockProps, text )
		);
	}

	blocks.registerBlockType( 'wstp/greeting', {
		apiVersion: 2,
		title: __( 'Digest Greeting', 'we-subscribe-to-posts' ),
		icon: 'admin-users',
		category: 'widgets',
		description: __( 'Dynamic personalized greeting for the subscriber.', 'we-subscribe-to-posts' ),
		attributes: colorAttributes,
		supports: {
			typography: {
				fontSize: true,
				lineHeight: true,
			},
			color: {
				text: true,
			},
		},
		edit: function ( props ) {
			return renderColoredPlaceholder(
				props,
				__( 'Greeting is rendered dynamically in the email.', 'we-subscribe-to-posts' )
			);
		},
		save: function () {
			return null;
		},
	} );

	blocks.registerBlockType( 'wstp/unsubscribe-link', {
		apiVersion: 2,
		title: __( 'Unsubscribe Link', 'we-subscribe-to-posts' ),
		icon: 'dismiss',
		category: 'widgets',
		description: __( 'Required one-click unsubscribe link.', 'we-subscribe-to-posts' ),
		attributes: colorAttributes,
		supports: {
			typography: {
				fontSize: true,
				lineHeight: true,
			},
			color: {
				text: true,
				background: true,
			},
		},
		edit: function ( props ) {
			return renderColoredPlaceholder(
				props,
				__( 'Unsubscribe link is rendered dynamically in the email.', 'we-subscribe-to-posts' )
			);
		},
		save: function () {
			return null;
		},
	} );

	blocks.registerBlockType( 'wstp/posts-loop', {
		apiVersion: 2,
		title: __( 'Digest Posts Loop', 'we-subscribe-to-posts' ),
		icon: 'index-card',
		category: 'widgets',
		description: __( 'Loop layout for digest posts. Add columns and post field blocks inside.', 'we-subscribe-to-posts' ),
		edit: function () {
			const template = [
				[
					'core/columns',
					{ isStackedOnMobile: true },
					[
						[
							'core/column',
							{ width: '30%' },
							[
								[ 'wstp/post-image', {} ],
							],
						],
						[
							'core/column',
							{ width: '70%' },
							[
								[ 'wstp/post-title', {} ],
								[ 'wstp/post-excerpt', {} ],
								[ 'wstp/post-read-more', {} ],
							],
						],
					],
				],
			];

			return el(
				'div',
				{},
				el(
					'p',
					{ style: { marginBottom: '8px', opacity: 0.8 } },
					__( 'This layout is repeated for each digest post.', 'we-subscribe-to-posts' )
				),
				el( InnerBlocks, { template: template } )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

	function registerLoopChildBlock( name, title, icon, text ) {
		blocks.registerBlockType( name, {
			apiVersion: 2,
			title: title,
			icon: icon,
			category: 'widgets',
			ancestor: [ 'wstp/posts-loop' ],
			attributes: colorAttributes,
			supports: {
				typography: {
					fontSize: true,
					lineHeight: true,
				},
				color: {
					text: true,
					background: true,
				},
			},
			edit: function ( props ) {
				return renderColoredPlaceholder( props, text );
			},
			save: function () {
				return null;
			},
		} );
	}

	blocks.registerBlockType( 'wstp/post-image', {
		apiVersion: 2,
		title: __( 'Digest Post Image', 'we-subscribe-to-posts' ),
		icon: 'format-image',
		category: 'widgets',
		ancestor: [ 'wstp/posts-loop' ],
		attributes: {
			maxWidth: {
				type: 'number',
				default: 180,
			},
			...colorAttributes,
		},
		edit: function ( props ) {
			const blockProps = useBlockProps();
			return el(
				'div',
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{
							title: __( 'Image size', 'we-subscribe-to-posts' ),
							initialOpen: true,
						},
						el( RangeControl, {
							label: __( 'Max width (px)', 'we-subscribe-to-posts' ),
							value: props.attributes.maxWidth || 180,
							onChange: function ( value ) {
								props.setAttributes( { maxWidth: value || 180 } );
							},
							min: 80,
							max: 600,
							step: 10,
						} )
					),
					renderTextColorPanel( props, __( 'Image block color', 'we-subscribe-to-posts' ) )
				),
				el(
					'p',
					blockProps,
					__( 'Post image renders here.', 'we-subscribe-to-posts' ) +
						' (' +
						( props.attributes.maxWidth || 180 ) +
						'px max)'
				)
			);
		},
		save: function () {
			return null;
		},
	} );

	registerLoopChildBlock(
		'wstp/post-title',
		__( 'Digest Post Title', 'we-subscribe-to-posts' ),
		'heading',
		__( 'Post title renders here.', 'we-subscribe-to-posts' )
	);

	registerLoopChildBlock(
		'wstp/post-excerpt',
		__( 'Digest Post Excerpt', 'we-subscribe-to-posts' ),
		'editor-paragraph',
		__( 'Post excerpt renders here.', 'we-subscribe-to-posts' )
	);

	registerLoopChildBlock(
		'wstp/post-read-more',
		__( 'Digest Post Read More Link', 'we-subscribe-to-posts' ),
		'admin-links',
		__( 'Read more link renders here.', 'we-subscribe-to-posts' )
	);
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.i18n, window.wp.components );
