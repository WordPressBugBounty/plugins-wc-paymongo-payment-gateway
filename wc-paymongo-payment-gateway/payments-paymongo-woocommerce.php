<?php
/**
 * PHP version 7
 * Plugin Name: Payments via PayMongo for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/payments-via-paymongo-for-woo/
 * Description: Take credit card, GCash, GrabPay and PayMaya payments via PayMongo.
 * Author: CynderTech
 * Author URI: http://cynder.io
 * Version: 1.14.1
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
    define('CYNDER_PAYMONGO_VERSION', '1.14.1');
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

						// Bounds every PayMongo API call this sweep makes. Without an
						// explicit timeout, Guzzle waits indefinitely on a stalled
						// call, which would make the lock TTL below unenforceable no
						// matter how it's sized. \Paymongo\Phaymongo\Phaymongo
						// (constructed below) takes this 4th argument and forwards it
						// unchanged to each resource class (e.g. PaymentIntent) it
						// builds, which extends PaymongoClient and merges it into the
						// Guzzle client it constructs -- see
						// tests/PhaymongoApiTimeoutTest.php, which fails CI directly
						// against the installed micahbule/phaymongo if a library
						// update ever changes that constructor signature, rather than
						// this comment claiming a version pin it can't itself verify.
						$api_timeout = PAYMONGO_RECONCILE_API_TIMEOUT;

						// Guards against overlapping runs: WP-Cron's own overlap lock
						// (WP_CRON_LOCK_TIMEOUT) only lasts 60 seconds, shorter than a
						// full sweep can take. Utils::reconcileOrderAgainstPayMongoDetailed()
						// is itself deadline-bound (passed below), so the TTL only needs
						// margin for one in-flight API call, not the whole run --
						// ReconciliationSweep renews this lock before each order it
						// processes, precisely so a slow paid-order resolution (an email
						// send, a third-party callback -- none of it bounded by
						// $api_timeout) can't let this initial TTL lapse mid-run and have
						// a concurrent trigger reclaim the lock out from under it.
						$run_deadline_seconds  = PAYMONGO_RECONCILE_RUN_DEADLINE;
						$max_intents_per_order = PAYMONGO_RECONCILE_MAX_INTENTS;
						$lock_key              = 'reconcile_running';
						$min_lock_ttl          = $run_deadline_seconds + $api_timeout + PAYMONGO_RECONCILE_LOCK_TTL_MARGIN;
						// Clamped, not used as-is: a filter returning something
						// below $min_lock_ttl (zero, negative, or just too small)
						// would size this lock shorter than the run it's meant to
						// guard, letting a concurrent trigger reclaim it mid-run.
						// See ReconciliationSweep::resolveLockTtl() for why this
						// one line of arithmetic is a static method rather than
						// staying inline here.
						$lock_ttl = \Cynder\PayMongo\ReconciliationSweep::resolveLockTtl(
							apply_filters( 'cynder_paymongo_reconcile_lock_ttl', $min_lock_ttl ),
							$min_lock_ttl
						);

						$lock_token = \Cynder\PayMongo\Lock::acquire( $lock_key, $lock_ttl );

						if ( false === $lock_token ) {
							wc_get_logger()->debug( 'Reconciliation sweep skipped: lock already held.', array( 'source' => 'cynder-paymongo' ) );
							return;
						}

						// Measured from acquisition, not from after the query below,
						// so the query's own cost is inside the same budget the TTL
						// above accounts for.
						$deadline = microtime( true ) + $run_deadline_seconds;
						$result   = array(
							'considered'       => 0,
							'reconciled'       => 0,
							'abandoned'        => 0,
							'inconclusive'     => 0,
							'transient_errors' => 0,
							'skipped'          => 0,
							'errored'          => 0,
							'deadline_hit'     => false,
						);

						try {
							$client = new \Paymongo\Phaymongo\Phaymongo(
								$public_key,
								$secret_key,
								array(),
								array(
									'timeout'         => $api_timeout,
									'connect_timeout' => 5,
								)
							);

							// Query stranded orders, filtered to only the ones actually
							// worth an API call this run -- see
							// ReconciliationSweep::buildOrderQueryArgs() for why
							// that's now a query-level exclusion (max attempts and
							// cooldown both), not just run()'s own in-loop skip
							// check filtering an already-fetched batch. Hydrated order
							// objects (the default) are cheaper per order in bulk
							// under HPOS than a lazy per-ID reload, and the limit
							// keeps this bulk cost bounded.
							$orders = wc_get_orders( \Cynder\PayMongo\ReconciliationSweep::buildOrderQueryArgs() );

							// $lock_token is passed by reference: run() updates it in
							// place on every successful renewal, not just once at
							// return time. That matters here specifically because
							// run() can throw (see catch below) -- if it does after
							// renewing, $lock_token must already hold that renewed
							// value by the time we reach the release() in finally,
							// or that release's own compare-and-delete would silently
							// no-op against a token the database no longer has,
							// leaving the lock orphaned until it expires on its own.
							$sweep  = new \Cynder\PayMongo\ReconciliationSweep( new \Cynder\PayMongo\Utils() );
							$result = $sweep->run( $orders, $client, $deadline, $max_intents_per_order, $lock_key, $lock_token, $lock_ttl );
						} catch ( \Throwable $e ) {
							// $result is still all zeros at this point -- without this,
							// the summary log below would read identically to a clean,
							// genuinely empty run and hide that the sweep actually threw.
							// Class, file, and line alongside the message -- the message
							// alone is often not enough to tell a PayMongo-side failure
							// (already logged with its own context above the throw) apart
							// from a genuine bug in this plugin's own code.
							wc_get_logger()->error(
								sprintf(
									'Reconciliation sweep aborted: %s (%s at %s:%d)',
									$e->getMessage(),
									get_class( $e ),
									$e->getFile(),
									$e->getLine()
								),
								array( 'source' => 'cynder-paymongo' )
							);
							// Deliberately not rethrown: wp-cron.php runs every due
							// event in one request, not just this plugin's -- an
							// uncaught exception here would be fatal for that whole
							// request, skipping every other plugin's due cron events
							// too, not merely other callbacks on this one hook.
							// Logged at error level above and the lock is already
							// released by the finally block below, so nothing about
							// this failure is silently lost; it just doesn't take
							// down other sites' scheduled work with it.
						} finally {
							\Cynder\PayMongo\Lock::release( $lock_key, $lock_token );

							$summary = sprintf(
								'Reconciliation sweep finished: %d considered, %d reconciled, %d abandoned, %d inconclusive (%d transient errors, %d errored), %d skipped in-loop (should always be 0 -- see ReconciliationSweep::run()), deadline hit: %s.',
								$result['considered'],
								$result['reconciled'],
								$result['abandoned'],
								$result['inconclusive'],
								$result['transient_errors'],
								$result['errored'],
								$result['skipped'],
								$result['deadline_hit'] ? 'yes' : 'no'
							);

							// See ReconciliationSweep::summaryLogLevel() for why this
							// condition is a tested static method rather than
							// staying inline here.
							if ( 'warning' === \Cynder\PayMongo\ReconciliationSweep::summaryLogLevel( $result ) ) {
								wc_get_logger()->warning( $summary, array( 'source' => 'cynder-paymongo' ) );
							} else {
								wc_get_logger()->debug( $summary, array( 'source' => 'cynder-paymongo' ) );
							}
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
    \Cynder\PayMongo\Lock::clear();
}
register_deactivation_hook( __FILE__, 'cynder_paymongo_deactivate' );
