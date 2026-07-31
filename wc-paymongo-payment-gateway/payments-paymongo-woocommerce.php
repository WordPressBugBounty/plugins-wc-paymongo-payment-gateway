<?php
/**
 * PHP version 7
 * Plugin Name: Payments via PayMongo for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/payments-via-paymongo-for-woo/
 * Description: Take credit card, GCash, GrabPay and PayMaya payments via PayMongo.
 * Author: CynderTech
 * Author URI: http://cynder.io
 * Version: 1.14.0
 * Requires at least: 5.3.2
 * Tested up to: 7.0
 * WC requires at least: 3.9.3
 * WC tested up to: 10.9.3
 *
 * @category Plugin
 * @package  CynderTech
 * @author   CynderTech <hello@cynder.io>
 * @license  GPLv3 (https://www.gnu.org/licenses/gpl-3.0.html)
 * @link     n/a
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

include_once 'paymongo-constants.php';
require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';

use PostHog\PostHog;

/**
 * WooCommerce fallback notice.
 *
 * @return string
 */
function Woocommerce_Missing_Cynder_notice()
{
    /* translators: 1. URL link. */
    echo '<div class="error"><p><strong>' . sprintf(
        esc_html__(
            'PayMongo requires WooCommerce to be '
            . 'installed and active. You can download %s here.',
            'woocommerce-gateway-paymongo'
        ),
        '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>'
    ) . '</strong></p></div>';
}

/**
 * Initialize PayMongo Gateway Class
 *
 * @return string
 */
function Paymongo_Init_Gateway_class()
{
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'Woocommerce_Missing_Cynder_notice');
        return;
    }

    add_action('before_woocommerce_init', function () {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    });

    define('CYNDER_PAYMONGO_MAIN_FILE', __FILE__);
    define('CYNDER_PAYMONGO_VERSION', '1.14.0');
    define(
        'CYNDER_PAYMONGO_PLUGIN_URL',
        untrailingslashit(
            plugins_url(
                basename(plugin_dir_path(__FILE__)),
                basename(__FILE__)
            )
        )
    );

    PostHog::init('phc_zC7px2IrSCO7SlSVEb250VISscWfwvBPafWJOYJsUhv', array('host' => 'https://app.posthog.com'));


    if (!class_exists('Cynder_PayMongo')):
        /**
         * PayMongo Class
         * 
         * @category Class
         * @package  PayMongo
         * @author   PayMongo <devops@cynder.io>
         * @license  n/a (http://127.0.0.0)
         * @link     n/a
         * @phpcs:disable Standard.Cat.SniffName
         */
        class Cynder_PayMongo
        {
            /**
             * *Singleton* instance of this class
             * 
             * @var Singleton The reference the *Singleton* instance of this class
             */
            private static $_instance;

            /**
             * Returns the *Singleton* instance of this class.
             *
             * @return Singleton The *Singleton* instance.
             */
            public static function getInstance()
            {
                if (null === self::$_instance) {
                    self::$_instance = new self();
                }

                return self::$_instance;
            }

            /**
             * Private clone method to prevent cloning of the instance of the
             * *Singleton* instance.
             *
             * @return void
             */
            public function __clone()
            {
                // empty
            }

            /**
             * Private unserialize method to prevent unserializing of the *Singleton*
             * instance.
             *
             * @return void
             */
            public function __wakeup()
            {
                // empty
            }

            /**
             * Protected constructor to prevent creating a new instance of the
             * *Singleton* via the `new` operator from outside of this class.
             */
            private function __construct()
            {
                add_action('admin_init', array($this, 'install'));
                $this->init();
            }

			/**
			 * Initialize PayMongo plugin
			 *
			 * @return void
			 *
			 * @since 1.0.0
			 */
			public function init() {
				include_once 'paymongo-top-level-hooks.php';
				include_once __DIR__ . '/classes/Cynder_PayMongo_Webhook_Handler.php';

				add_filter(
					'woocommerce_payment_gateways',
					array( $this, 'addGateways' )
				);

				if ( version_compare( WC_VERSION, '3.4', '<' ) ) {
					add_filter(
						'woocommerce_get_sections_checkout',
						array( $this, 'filterGatewayOrderAdmin' )
					);
				}

				add_action(
					'cynder_paymongo_reconciliation_cron',
					function () {
						$test_mode  = get_option( 'woocommerce_cynder_paymongo_test_mode' ) === 'yes';
						$pk_key     = $test_mode ? 'woocommerce_cynder_paymongo_test_public_key' : 'woocommerce_cynder_paymongo_public_key';
						$sk_key     = $test_mode ? 'woocommerce_cynder_paymongo_test_secret_key' : 'woocommerce_cynder_paymongo_secret_key';
						$public_key = get_option( $pk_key );
						$secret_key = get_option( $sk_key );

						if ( empty( $public_key ) || empty( $secret_key ) ) {
							return;
						}

						$client = new \Paymongo\Phaymongo\Phaymongo( $public_key, $secret_key );
						$utils  = new \Cynder\PayMongo\Utils();

						// Find stranded orders from the last 7 days.
						$orders = wc_get_orders(
							array(
								'status'         => array( 'pending', 'failed', 'cancelled' ),
								'payment_method' => array( 'paymongo', 'paymongo_card_installment', 'paymongo_gcash', 'paymongo_grab_pay', 'paymongo_paymaya', 'paymongo_atome', 'paymongo_bpi', 'paymongo_unionbank', 'paymongo_billease' ),
								'limit'          => 30,
								'date_created'   => '>=' . strtotime( '-7 days' ),
							)
						);

						foreach ( $orders as $order ) {
							$utils->reconcileOrderAgainstPayMongo( $order, $client );
						}
					}
				);
			}

            /**
             * Registers Payment Gateways
             * 
             * @param $methods array of methods
             * 
             * @return array
             * 
             * @since 1.0.0
             */
            public function addGateways($methods)
            {
                $methods[] = 'Cynder\\PayMongo\\CynderPayMongoGateway';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_Card_Installment';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_Gcash_Gateway';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_GrabPay_Gateway';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_PayMaya';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_Atome';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_Bpi';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_UnionBank';
                $methods[] = 'Cynder\\PayMongo\\Cynder_PayMongo_BillEase';

                return $methods;
            }

            /**
             * Registers Payment Gateways
             * 
             * @param array $sections array of sections
             * 
             * @return array
             * 
             * @since 1.0.0
             */
            public function filterGatewayOrderAdmin($sections)
            {
                foreach (PAYMONGO_PAYMENT_METHODS as $method) {
                    unset($sections[$method]);
                }

                $gatewayName = 'woocommerce-gateway-paymongo';

                foreach (PAYMONGO_PAYMENT_METHOD_LABELS as $method => $label) {
                    $sections[$method] = __($label, $gatewayName);
                }

                return $sections;
            }

            /**
             * Install/Update function
             * 
             * @return void
             * 
             * @since 1.0.0
             */
            public function install()
            {
                if (!is_plugin_active(plugin_basename(__FILE__))) {
                    return;
                }

                $stored_version = get_option('cynder_paymongo_version');

                if ($stored_version !== CYNDER_PAYMONGO_VERSION) {
                    if ( ! wp_next_scheduled( 'cynder_paymongo_reconciliation_cron' ) ) {
                        wp_schedule_event( time(), 'hourly', 'cynder_paymongo_reconciliation_cron' );
                    }

                    if (!defined('IFRAME_REQUEST')) {
                        do_action('woocommerce_paymongo_updated');

                        if (!defined('CYNDER_PAYMONGO_INSTALLING')) {
                            define('CYNDER_PAYMONGO_INSTALLING', true);
                        }

                        $this->updatePluginVersion();
                    }
                }
            }

            /**
             * Updates Plugin Version
             * 
             * @return void
             * 
             * @since 1.0.0
             */
            public function updatePluginVersion()
            {
                update_option('cynder_paymongo_version', CYNDER_PAYMONGO_VERSION);
            }

        }

        Cynder_PayMongo::getInstance();
    endif;
}

add_action('plugins_loaded', 'Paymongo_Init_Gateway_class');

// Schedule cron on plugin activation
function cynder_paymongo_activate() {
    if ( ! wp_next_scheduled( 'cynder_paymongo_reconciliation_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'cynder_paymongo_reconciliation_cron' );
    }
}
register_activation_hook( __FILE__, 'cynder_paymongo_activate' );

/**
 * Clean up cron jobs on plugin deactivation.
 */
function cynder_paymongo_deactivate() {
    wp_clear_scheduled_hook( 'cynder_paymongo_reconciliation_cron' );
}
register_deactivation_hook( __FILE__, 'cynder_paymongo_deactivate' );
