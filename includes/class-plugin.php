<?php
/**
 * Main plugin class.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP;

use WSTP\Admin\Mjml_Template;
use WSTP\Admin\Preview_Controller;
use WSTP\Admin\Settings_Page;
use WSTP\Admin\Subscribers_Page;
use WSTP\Admin\Dashboard_Widget;
use WSTP\DB\Event_Log_Repository;
use WSTP\DB\Subscriber_Repository;
use WSTP\Frontend\Subscription_Form;
use WSTP\Mailer\Digest_Builder;
use WSTP\Mailer\Digest_Scheduler;
use WSTP\Mailer\Mailer;
use WSTP\Subscription\Double_Optin_Service;
use WSTP\Subscription\Admin_Notification_Service;
use WSTP\Subscription\Pending_Cleanup;
use WSTP\Subscription\Unsubscribe_Service;
use WSTP\Core\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps plugin modules.
 */
final class Plugin {
	/**
	 * Run plugin hooks.
	 */
	public function run(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$subscriber_repository = new Subscriber_Repository();
		$event_repository      = new Event_Log_Repository();
		$mailer                = new Mailer();
		$digest_builder        = new Digest_Builder( $event_repository );
		$admin_notification_service = new Admin_Notification_Service( $mailer );

		$double_optin_service = new Double_Optin_Service( $subscriber_repository, $mailer, $admin_notification_service );
		$unsubscribe_service  = new Unsubscribe_Service( $subscriber_repository );
		$pending_cleanup      = new Pending_Cleanup( $subscriber_repository );
		$subscription_form    = new Subscription_Form( $subscriber_repository, $double_optin_service );
		$digest_scheduler     = new Digest_Scheduler( $subscriber_repository, $event_repository, $digest_builder, $mailer );
		$settings_page        = new Settings_Page();
		$subscribers_page     = new Subscribers_Page( $subscriber_repository, $event_repository );
		$dashboard_widget     = new Dashboard_Widget( $subscriber_repository, $event_repository );
		$preview_controller   = new Preview_Controller( $digest_builder, $mailer );
		$mjml_template      = new Mjml_Template();
		if ( ( is_admin() || wp_doing_cron() ) && class_exists( Updater::class ) ) {
			new Updater( WSTP_FILE );
		}

		$mailer->register();
		$admin_notification_service->register();
		$double_optin_service->register();
		$unsubscribe_service->register();
		$pending_cleanup->register();
		$subscription_form->register();
		$digest_scheduler->register();
		$settings_page->register();
		$subscribers_page->register();
		$dashboard_widget->register();
		$mjml_template->register();
		$preview_controller->register();
	}

	/**
	 * Plugin activation callback.
	 */
	public static function activate(): void {
		$activator = new Activator();
		$activator->activate();
	}

	/**
	 * Plugin deactivation callback.
	 */
	public static function deactivate(): void {
		$deactivator = new Deactivator();
		$deactivator->deactivate();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'we-subscribe-to-posts', false, dirname( plugin_basename( WSTP_FILE ) ) . '/languages' );
	}
}
