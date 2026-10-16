<?php

namespace Cynder\PayMongo;

/**
 * Atomic named lock used to guard against concurrent access to the same
 * resource -- an overlapping sweep cron run, a webhook delivery racing that
 * same sweep over one order, or a checkout request racing an intent
 * rotation.
 *
 * A transient is a plain get-then-set, which races under concurrent
 * triggers: two requests can both observe no lock before either writes
 * one. This uses the UNIQUE index on wp_options.option_name as a
 * compare-and-set instead, so only one request can ever win it.
 */
class Lock {
	public const OPTION_PREFIX = 'cynder_paymongo_lock_';

	/**
	 * Lock key guarding one order's reconciliation-meta writes against a
	 * concurrent intent rotation. Shared between
	 * cynder_paymongo_create_intent() and ReconciliationSweep so both
	 * agree on the same name for the same order.
	 *
	 * @param int $order_id
	 * @return string
	 */
	public static function orderIntentKey( $order_id ) {
		return 'order_intent_' . $order_id;
	}

	/**
	 * @param string $key Lock key.
	 * @param int    $ttl Seconds after which an unreleased lock is abandoned.
	 *
	 * @return string|false Owner token to pass to release(), or false if held elsewhere.
	 */
	public static function acquire( $key, $ttl ) {
		global $wpdb;

		$name  = self::OPTION_PREFIX . $key;
		$token = ( time() + $ttl ) . '|' . uniqid( 'lock_', true );

		// 'no', not 'off': this writes the options table directly rather
		// than through the Options API, so it has to use a value every
		// supported WP version's own autoload handling understands. 'off'
		// is only meaningful to WP 6.6+'s newer autoload logic; 'no' has
		// worked the same way since autoloading existed at all.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$token
			)
		);

		if ( 1 === (int) $wpdb->rows_affected ) {
			return $token;
		}

		// Held elsewhere -- reclaim only if its TTL has actually passed, and
		// only by deleting the exact stale value, so a still-live lock can
		// never be stolen out from under its owner.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

		if ( is_null( $existing ) ) {
			return false;
		}

		$expires = (int) explode( '|', $existing, 2 )[0];

		// $expires was computed by time() + ttl on whichever process (and,
		// on multi-node hosting, possibly whichever web node) last
		// acquired or renewed this lock -- not necessarily this one, so
		// clock skew between nodes is a real, if typically small,
		// possibility here, not something this compare already rules out.
		// Accepted as-is: a lock being reclaimed slightly early or late by
		// a few seconds of drift is far cheaper than coordinating clocks
		// across nodes just for this, and the TTL itself already carries
		// margin for the guarded work's own duration on top of whatever
		// skew exists.
		if ( $expires > time() ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $existing )
		);

		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$name,
				$token
			)
		);
		// phpcs:enable

		return 1 === (int) $wpdb->rows_affected ? $token : false;
	}

	/**
	 * Extend a lock's expiry, but only if $token still matches what's
	 * currently stored -- if it doesn't, the lock has already expired and
	 * been reclaimed by someone else, and this run no longer safely owns it.
	 *
	 * For a long-running holder: acquire() alone bounds how long a lock can
	 * be held, but not how long the work behind it actually takes. A single
	 * fixed TTL sized for the expected case can still expire mid-run if a
	 * particular unit of work runs long (an email send, a webhook to a
	 * third party, anything not under this process's control). Renewing
	 * periodically during the run, instead of once upfront, means only the
	 * gap *between* renewals needs to fit inside the TTL, not the run's
	 * entire duration.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Token currently held, as returned by acquire() or a previous renew().
	 * @param int    $ttl   Seconds from now the renewed lock should expire.
	 *
	 * @return string|false New token to use for the next renew()/release() call, or false if
	 *                       $token no longer matches (the lock was lost).
	 */
	public static function renew( $key, $token, $ttl ) {
		global $wpdb;

		$name      = self::OPTION_PREFIX . $key;
		$new_token = ( time() + $ttl ) . '|' . uniqid( 'lock_', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$new_token,
				$name,
				$token
			)
		);
		// phpcs:enable

		return 1 === (int) $wpdb->rows_affected ? $new_token : false;
	}

	/**
	 * Builds a no-argument closure that renews $key via renew(), updating
	 * $token (captured by reference) in place on success, or logging
	 * $message and throwing LockLostException( $message ) on failure.
	 * Every per-order and sweep-level heartbeat callback threaded through
	 * Utils, ReconciliationSweep, the webhook handler, and checkout's
	 * intent rotation follows exactly this renew/log/throw sequence --
	 * this exists so each of those sites only has to supply the
	 * lock-specific key, token, TTL, and message, not repeat the sequence
	 * itself.
	 *
	 * Deliberately not called combineHeartbeats() or similar for a caller
	 * renewing more than one lock (e.g. ReconciliationSweep::run(), which
	 * renews both its own sweep-level lock and the current order's lock
	 * from one heartbeat) -- such a caller just calls each heartbeat in
	 * sequence itself; the first one to throw aborts before the second is
	 * even attempted, same as today.
	 *
	 * @param string   $key     Lock key.
	 * @param string   &$token  Token currently held; updated in place by
	 *                          each successful renewal, exactly as a caller
	 *                          managing its own renew() call would update
	 *                          its own local copy.
	 * @param int      $ttl     Seconds from now the renewed lock should expire.
	 * @param string   $message Logged as a warning and used as the thrown
	 *                          LockLostException's message when renewal fails.
	 *
	 * @return callable
	 */
	public static function heartbeat( $key, &$token, $ttl, $message ) {
		return function () use ( $key, &$token, $ttl, $message ) {
			$renewed = self::renew( $key, $token, $ttl );

			if ( false === $renewed ) {
				wc_get_logger()->warning( $message, array( 'source' => 'cynder-paymongo' ) );

				throw new LockLostException( $message );
			}

			$token = $renewed;
		};
	}

	/**
	 * acquire(), retrying a bounded number of times before giving up.
	 * Factored out of withLock() so a caller that needs the token itself
	 * -- to renew it mid-callback, e.g. around unbounded work withLock()
	 * has no way to renew for (see cynder_paymongo_create_intent()'s
	 * pre-emptive succeeded-intent check) -- can reuse the same retry
	 * behavior instead of managing its own acquire/release around
	 * withLock()'s all-or-nothing callback.
	 *
	 * @param string $key              Lock key.
	 * @param int    $ttl              Seconds after which an unreleased lock is abandoned.
	 * @param int    $retries          Acquisition attempts before giving up (1 = no retry).
	 * @param int    $retry_delay_usec Microseconds to wait between attempts.
	 *
	 * @return string|false Owner token to pass to renew()/release(), or false if never acquired.
	 */
	public static function acquireWithRetry( $key, $ttl, $retries = 1, $retry_delay_usec = 0 ) {
		$token = false;

		for ( $attempt = 0; $attempt < $retries; $attempt++ ) {
			$token = self::acquire( $key, $ttl );

			if ( false !== $token ) {
				break;
			}

			if ( $attempt + 1 < $retries ) {
				usleep( $retry_delay_usec );
			}
		}

		return $token;
	}

	/**
	 * Runs $on_acquired only while holding the named lock, retrying
	 * acquisition a bounded number of times first. If the lock still can't
	 * be acquired, $on_acquired is never called -- this exists specifically
	 * for callers where entering the guarded section unprotected would be
	 * worse than skipping it (see cynder_paymongo_create_intent()'s intent
	 * rotation plus reconciliation-meta reset, and ReconciliationSweep's
	 * per-order writes, both of which must never run outside this lock or
	 * they'd recreate the exact race it exists to prevent).
	 *
	 * Suited to callback work bounded well within $ttl: $on_acquired has no
	 * way to renew the lock it's running under (its token is never exposed
	 * to it), so unbounded work -- an external API call, arbitrary hooks --
	 * belongs behind acquireWithRetry() plus a caller-managed renew/release
	 * instead, the way ReconciliationSweep::run() and the pre-emptive check
	 * in cynder_paymongo_create_intent() do.
	 *
	 * @param string   $key              Lock key.
	 * @param int      $ttl              Seconds after which an unreleased lock is abandoned.
	 * @param callable $on_acquired      Called with no arguments once the lock is held.
	 * @param int      $retries          Acquisition attempts before giving up (1 = no retry).
	 * @param int      $retry_delay_usec Microseconds to wait between attempts.
	 *
	 * @return bool Whether $on_acquired ran.
	 */
	public static function withLock( $key, $ttl, callable $on_acquired, $retries = 1, $retry_delay_usec = 0 ) {
		$token = self::acquireWithRetry( $key, $ttl, $retries, $retry_delay_usec );

		if ( false === $token ) {
			return false;
		}

		try {
			$on_acquired();
		} finally {
			self::release( $key, $token );
		}

		return true;
	}

	/**
	 * Release a lock acquired by acquire(), but only if still owned by this
	 * run -- deleting by key alone could remove a lock that a later request
	 * has since (correctly) reclaimed.
	 *
	 * @param string $key   Lock key.
	 * @param string $token Token returned by acquire().
	 *
	 * @return void
	 */
	public static function release( $key, $token ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_PREFIX . $key,
				$token
			)
		);
		// phpcs:enable
	}

	/**
	 * Remove stale (already-expired) lock rows, e.g. on plugin deactivation,
	 * so an abandoned lock from a fatal mid-run doesn't linger until the
	 * next run reclaims it.
	 *
	 * Deliberately leaves any lock whose TTL hasn't passed yet alone.
	 * wp_clear_scheduled_hook() (called alongside this on deactivation)
	 * only stops *future* cron runs from being scheduled -- it can't stop
	 * one already in flight in a separate PHP process, which may still be
	 * relying on a lock it currently owns. Deleting that live lock here
	 * would let a concurrent trigger acquire the same key while the
	 * original holder is still running, the exact overlap this lock
	 * exists to prevent. A live lock is left to expire and be reclaimed
	 * the normal way (see acquire()), the same as it would be if this
	 * method were never called at all.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$now = time();

		foreach ( $rows as $row ) {
			$expires = (int) explode( '|', $row->option_value, 2 )[0];

			if ( $expires > $now ) {
				continue;
			}

			// Compare-and-delete, not by name alone -- if this row was
			// renewed between the SELECT above and here, its value no
			// longer matches what was just read as stale, and this
			// simply no-ops instead of deleting a lock that's live again.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$row->option_name,
					$row->option_value
				)
			);
		}
		// phpcs:enable
	}
}
