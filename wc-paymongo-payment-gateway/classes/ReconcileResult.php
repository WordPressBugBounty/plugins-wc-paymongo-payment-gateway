<?php

namespace Cynder\PayMongo;

/**
 * Outcome of Utils::reconcileOrderAgainstPayMongoDetailed(). A bare bool|null return
 * can't tell a caller *why* a scan didn't reach a definitive answer, and
 * ReconciliationSweep needs that distinction: a deadline cutting a scan
 * short before anything was checked should not spend any retry budget or
 * record a cooldown (the order is simply reconsidered next run), while a
 * transient error, a capped intent list, or a deadline that cut the scan
 * short after some progress was made should back off with a cooldown but
 * must not count toward abandonment. Those are different meta writes, so
 * the reason has to be explicit rather than re-derived by the caller after
 * the fact.
 */
class ReconcileResult {
	const REASON_PAID       = 'paid';
	const REASON_MISS       = 'miss';
	const REASON_DEADLINE   = 'deadline';
	const REASON_INCOMPLETE = 'incomplete';

	/**
	 * Intended immutable: set only by the private constructor via the
	 * factories below. `readonly` needs PHP 8.1; this plugin supports down
	 * to 7.2.5, so there's no enforced way to stop a caller reassigning
	 * this after construction -- treat it as read-only by convention.
	 *
	 * @var bool
	 */
	public $paid;

	/**
	 * Intended immutable; see $paid.
	 *
	 * @var string One of the REASON_* constants.
	 */
	public $reason;

	/**
	 * Intended immutable; see $paid. Null unless $paid.
	 *
	 * Deliberately not run before this object is returned: a caller of
	 * reconcileOrderAgainstPayMongoDetailed() (ReconciliationSweep,
	 * Utils::reconcileOrderAgainstPayMongoLocked(), the checkout pre-emptive
	 * and rotation-commit checks) typically holds a time-limited per-order
	 * lock across that call. PostHog tracking and the third-party
	 * cynder_paymongo_successful_payment action neither read nor write
	 * anything about the order, so neither needs that lock -- and
	 * PostHog's own request can itself run long enough (see
	 * Utils::trackPaymentResolution()) to have outlived a lock sized for
	 * the rest of that method's work. The caller is expected to invoke
	 * this only after releasing its own per-order lock.
	 *
	 * Callable as `($heartbeat = null)`: a caller still holding some
	 * *other* lock across the whole call this result came from (the
	 * reconciliation sweep's own sweep-level lock, held across every
	 * order it processes, not released alongside the per-order one) should
	 * pass a callback that renews it, so this deferred work can't let that
	 * lock lapse and a concurrent trigger start an overlapping run while
	 * this one is still going.
	 *
	 * @var callable|null
	 */
	public $after_completion;

	/**
	 * Intended immutable; see $paid. Only meaningful when $reason is
	 * REASON_INCOMPLETE -- true specifically when a PayMongo call itself
	 * failed with a non-permanent error (network fault, 5xx, rate limit),
	 * as opposed to the other, unrelated causes REASON_INCOMPLETE also
	 * covers (a capped intent list, an intent still processing or
	 * awaiting the customer's next action, a malformed succeeded
	 * response, or the deadline cutting a scan short after some
	 * progress). A caller deciding whether a run's inconclusive results
	 * look like a PayMongo outage needs this distinction: an order stuck
	 * in awaiting_next_action is a normal, expected state, not a sign
	 * anything is wrong with PayMongo's API.
	 *
	 * @var bool
	 */
	public $transient_error;

	/**
	 * Intended immutable; see $paid. Only meaningful when $reason is
	 * REASON_INCOMPLETE -- true specifically when every intent this scan
	 * found incomplete was stuck in awaiting_next_action (a customer
	 * mid-3DS/bank-authorization redirect who hasn't returned, or
	 * abandoned that redirect) and nothing else in the scan was
	 * ambiguous. Distinct from "we couldn't tell" ($transient_error, a
	 * malformed succeeded response, or a capped/deadline-truncated scan):
	 * this is the customer's own payment genuinely not having gone
	 * through yet on this specific attempt, which a caller recovering a
	 * customer-facing redirect (reconcileOrderAgainstPayMongoLocked())
	 * needs to tell apart from genuine uncertainty -- see that method's
	 * own doc comment for why conflating the two routed declined/
	 * abandoned payments to "payment is processing" instead of back to
	 * checkout.
	 *
	 * @var bool
	 */
	public $awaiting_customer;

	private function __construct( $paid, $reason, $after_completion = null, $transient_error = false, $awaiting_customer = false ) {
		$this->paid              = $paid;
		$this->reason            = $reason;
		$this->after_completion  = $after_completion;
		$this->transient_error   = $transient_error;
		$this->awaiting_customer = $awaiting_customer;
	}

	/**
	 * A succeeded intent was found and the order was completed.
	 *
	 * @param callable|null $after_completion See $after_completion above.
	 * @return self
	 */
	public static function paid( $after_completion = null ) {
		return new self( true, self::REASON_PAID, $after_completion );
	}

	/**
	 * Every selected intent was checked -- cleanly, or permanently failed on
	 * PayMongo's side (e.g. a 404 on an intent that no longer exists) -- and
	 * none was paid. A definitive miss: safe to spend retry budget on.
	 *
	 * @return self
	 */
	public static function miss() {
		return new self( false, self::REASON_MISS );
	}

	/**
	 * The caller's deadline had already passed before a single intent could
	 * be checked -- nothing about this order was examined at all, so a
	 * caller tracking retry budget must not spend it, and should not record
	 * a cooldown either, since the run itself is stopping and the order will
	 * simply be reconsidered on the next one.
	 *
	 * Reserved specifically for zero progress. If at least one intent was
	 * checked before the deadline cut the rest short, that's incomplete()
	 * instead -- the progress made (and, for a capped scan, the rotation
	 * offset it earned) needs to be persisted, not discarded.
	 *
	 * @return self
	 */
	public static function deadline() {
		return new self( false, self::REASON_DEADLINE );
	}

	/**
	 * The scan didn't reach a definitive answer, but at least one intent was
	 * actually checked. Covers a transient PayMongo error (network fault,
	 * 5xx, rate limit, or any error this method can't confidently classify
	 * as permanent), a capped intent list, and the deadline cutting the
	 * scan short after some progress was already made. The order was
	 * attempted, so a cooldown is warranted, but this must not count
	 * against the abandonment budget.
	 *
	 * @param bool $transient_error   See $transient_error's own doc comment.
	 * @param bool $awaiting_customer See $awaiting_customer's own doc comment.
	 * @return self
	 */
	public static function incomplete( $transient_error = false, $awaiting_customer = false ) {
		return new self( false, self::REASON_INCOMPLETE, null, $transient_error, $awaiting_customer );
	}
}
