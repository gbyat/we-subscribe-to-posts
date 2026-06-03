( function ( blocks, element, i18n, blockEditor, components ) {
	const el = element.createElement;
	const InspectorControls = blockEditor.InspectorControls;
	const PanelColorSettings = blockEditor.PanelColorSettings;
	const PanelBody = components.PanelBody;
	const SelectControl = components.SelectControl;
	const ToggleControl = components.ToggleControl;
	const TextControl = components.TextControl;
	const RangeControl = components.RangeControl;
	const __ = i18n.__;

	blocks.registerBlockType( 'wstp/subscription-form', {
		apiVersion: 2,
		title: __( 'Post Subscription Form', 'we-subscribe-to-posts' ),
		icon: 'email',
		category: 'widgets',
		description: __( 'Signup form for post update notifications.', 'we-subscribe-to-posts' ),
		attributes: {
			compact: { type: 'boolean', default: false },
			default_frequency: { type: 'string', default: 'daily' },
			button_label: { type: 'string', default: __( 'Subscribe', 'we-subscribe-to-posts' ) },
			button_bg_color: { type: 'string', default: '#1d4ed8' },
			button_text_color: { type: 'string', default: '#ffffff' },
			button_radius: { type: 'number', default: 6 },
		},
		edit: function ( props ) {
			return el(
				'div',
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{
							title: __( 'Form options', 'we-subscribe-to-posts' ),
							initialOpen: true,
						},
						el( ToggleControl, {
							label: __( 'Compact layout', 'we-subscribe-to-posts' ),
							checked: !!props.attributes.compact,
							onChange: function ( value ) {
								props.setAttributes( { compact: value } );
							},
						} ),
						el( SelectControl, {
							label: __( 'Default frequency', 'we-subscribe-to-posts' ),
							value: props.attributes.default_frequency || 'daily',
							options: [
								{ label: __( 'Daily', 'we-subscribe-to-posts' ), value: 'daily' },
								{ label: __( 'Weekly', 'we-subscribe-to-posts' ), value: 'weekly' },
								{ label: __( 'Monthly', 'we-subscribe-to-posts' ), value: 'monthly' },
							],
							onChange: function ( value ) {
								props.setAttributes( { default_frequency: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Button label', 'we-subscribe-to-posts' ),
							value: props.attributes.button_label || __( 'Subscribe', 'we-subscribe-to-posts' ),
							onChange: function ( value ) {
								props.setAttributes( { button_label: value } );
							},
						} ),
						el( RangeControl, {
							label: __( 'Button radius (px)', 'we-subscribe-to-posts' ),
							value: props.attributes.button_radius || 6,
							min: 0,
							max: 24,
							step: 1,
							onChange: function ( value ) {
								props.setAttributes( { button_radius: value || 0 } );
							},
						} )
					),
					el(
						PanelColorSettings,
						{
							title: __( 'Button colors', 'we-subscribe-to-posts' ),
							initialOpen: false,
							colorSettings: [
								{
									label: __( 'Background color', 'we-subscribe-to-posts' ),
									value: props.attributes.button_bg_color || '#1d4ed8',
									onChange: function ( value ) {
										props.setAttributes( { button_bg_color: value || '#1d4ed8' } );
									},
								},
								{
									label: __( 'Text color', 'we-subscribe-to-posts' ),
									value: props.attributes.button_text_color || '#ffffff',
									onChange: function ( value ) {
										props.setAttributes( { button_text_color: value || '#ffffff' } );
									},
								},
							],
						}
					)
				),
				el(
					'div',
					{ style: { border: '1px dashed #b6b6b6', padding: '12px' } },
					el( 'strong', {}, __( 'Post Subscription Form', 'we-subscribe-to-posts' ) ),
					el( 'p', { style: { margin: '8px 0 0 0' } }, __( 'The form renders on the frontend.', 'we-subscribe-to-posts' ) ),
					el( 'p', { style: { margin: '8px 0 0 0', opacity: 0.75 } }, __( 'Use the sidebar to set compact layout and button style.', 'we-subscribe-to-posts' ) )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.i18n, window.wp.blockEditor, window.wp.components );
