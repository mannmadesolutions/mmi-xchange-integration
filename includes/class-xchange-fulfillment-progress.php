<?php
/**
 * MMI_Xchange_Fulfillment_Progress
 *
 * Where each Fulfillment Queue order stands, step by step, read back from the
 * records every step leaves behind — never from what the browser assumes
 * happened:
 *
 *   Payment   WC status, paid date, Stripe Radar review (mmi-admin's
 *             _mmi_stripe_review)
 *   Purchase  per-item MMI_Software_Fulfillment record: po_number, placed_at
 *   License   record license_key; pending MMI_Xchange_Auto_Fulfillment retries
 *   Email     record status 'fulfilled' + the queue row's email_history
 *   Complete  WC completed date
 *   Reverb    MMI_Reverb_Fulfillment_Sync's notice/shipped/pending/error meta
 *             (Reverb-imported orders only)
 *
 * `next` is the one thing the order needs now, with a sortable rank. `live`
 * is true while an automated step is still running (license retries, the
 * Reverb close-out), so the Orders tab knows to keep checking.
 *
 * Pending Action Scheduler jobs are loaded once per request for every order
 * on the page (preload()), never once per order.
 *
 * @package MannMade\Xchange
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Xchange_Fulfillment_Progress {

    const DONE      = 'done';
    const ACTIVE    = 'active';
    const WAITING   = 'waiting';
    const ATTENTION = 'attention';
    const TODO      = 'todo';

    /** Matches MMI_Reverb_Fulfillment_Sync (mmi-reverb-integration). */
    const REVERB_HOOK           = 'mmi_reverb_fulfillment_sync';
    const REVERB_META_PENDING   = '_mmi_reverb_fulfillment_sync_pending';
    const REVERB_META_NOTICE_AT = '_mmi_reverb_delivery_notice_sent_at';
    const REVERB_META_SHIPPED   = '_mmi_reverb_marked_shipped_at';
    const REVERB_META_ERROR     = '_mmi_reverb_fulfillment_sync_error';

    /** Sort order of `next.key` — what needs the admin first. */
    const RANK = [
        'needs_you'        => 10,
        'ready'            => 20,
        'stripe_check'     => 30,
        'stripe_review'    => 40,
        'waiting_license'  => 50,
        'reverb'           => 55,
        'awaiting_payment' => 60,
        'on_hold'          => 65,
        'partly_done'      => 70,
        'done'             => 80,
        'completed_no_po'  => 85,
        'closed'           => 90,
    ];

    /** @var array<int,array<string,int>> order_id => sku => next retry timestamp */
    private static $retries = [];

    /** @var array<int,int> order_id => next Reverb sync timestamp */
    private static $reverb_runs = [];

    /**
     * Loads pending license retries and Reverb close-out runs for every
     * order on the page in two Action Scheduler queries.
     *
     * @param int[] $order_ids
     */
    public static function preload( array $order_ids ): void {
        self::$retries     = [];
        self::$reverb_runs = [];
        if ( ! $order_ids || ! function_exists( 'as_get_scheduled_actions' ) ) {
            return;
        }
        $wanted = array_flip( array_map( 'intval', $order_ids ) );

        $hooks = [ MMI_Xchange_Auto_Fulfillment::RETRY_HOOK, self::REVERB_HOOK ];
        foreach ( $hooks as $hook ) {
            $actions = as_get_scheduled_actions( [
                'hook'     => $hook,
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => 200,
            ] );
            foreach ( $actions as $action ) {
                $args     = $action->get_args();
                $order_id = (int) ( $args[0] ?? 0 );
                if ( ! isset( $wanted[ $order_id ] ) ) {
                    continue;
                }
                $date = $action->get_schedule() ? $action->get_schedule()->get_date() : null;
                $when = $date ? $date->getTimestamp() : time();
                if ( $hook === self::REVERB_HOOK ) {
                    self::$reverb_runs[ $order_id ] = $when;
                } else {
                    self::$retries[ $order_id ][ (string) ( $args[1] ?? '' ) ] = $when;
                }
            }
        }
    }

    /**
     * @param array $row One Fulfillment Queue row (items, email_history,
     *                   fulfilled_po, xchange_license, stripe_review).
     * @return array{next:array, steps:array<int,array>, live:bool}
     */
    public static function build( WC_Order $order, array $row ): array {
        $status = $order->get_status();
        $items  = $row['items'] ?? [];
        $count  = count( $items );

        $placed    = array_filter( $items, static fn( $i ) => ( $i['placed_po'] ?? '' ) !== '' );
        $fulfilled = array_filter( $items, static fn( $i ) => ( $i['fulfillment_status'] ?? '' ) === 'fulfilled' );
        $licensed  = array_filter( $items, static fn( $i ) => ! empty( $i['has_license'] ) || ( $i['fulfillment_status'] ?? '' ) === 'fulfilled' );

        $retry_at = self::$retries[ $order->get_id() ] ?? [];
        $emails   = $row['email_history'] ?? [];
        $first_ok = null;
        $last_ok  = null;
        $sends    = 0;
        $last     = $emails ? end( $emails ) : null;
        foreach ( $emails as $entry ) {
            if ( ! empty( $entry['success'] ) ) {
                $first_ok = $first_ok ?? $entry;
                $last_ok  = $entry;
                $sends++;
            }
        }
        $sent_text = $first_ok ? 'Sent to ' . $first_ok['to'] . ( $sends > 1 ? " · sent {$sends} times, last " . self::short_time( (string) $last_ok['sent_at'] ) : '' ) : '';

        $steps = [];

        // ── 1. Payment ───────────────────────────────────────────────────────
        $paid_at = $order->get_date_paid();
        $method  = $order->get_payment_method_title();
        $review  = (string) ( $row['stripe_review'] ?? '' );
        if ( in_array( $status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
            $steps[] = self::step( 'payment', 'Payment', self::ATTENTION, sprintf( 'Order is %s', $status ) );
        } elseif ( $status === 'pending' ) {
            $steps[] = self::step( 'payment', 'Payment', self::WAITING, 'Awaiting payment' . ( $method ? " ({$method})" : '' ) );
        } elseif ( $status === 'on-hold' && $review === 'open' ) {
            $steps[] = self::step( 'payment', 'Payment', self::WAITING, 'Paid, held for Stripe review: approve or refuse it in Stripe', '', self::stripe_link( $order ) );
        } elseif ( $status === 'on-hold' && $review === 'check' ) {
            $steps[] = self::step( 'payment', 'Payment', self::ATTENTION, 'Stripe review closed: check the payment in Stripe, then set the order to Processing', '', self::stripe_link( $order ) );
        } elseif ( $status === 'on-hold' ) {
            $steps[] = self::step( 'payment', 'Payment', self::WAITING, 'On hold: confirm payment and set the order to Processing' );
        } else {
            $text = 'Paid' . ( $method ? " · {$method}" : '' );
            if ( $review === 'approved' ) {
                $text .= ' · Stripe review approved';
            }
            $steps[] = self::step( 'payment', 'Payment', self::DONE, $text, $paid_at ? $paid_at->date_i18n( 'Y-m-d H:i' ) : '' );
        }
        $paid = $steps[0]['state'] === self::DONE;

        // ── 2. Purchase on XChange ───────────────────────────────────────────
        if ( $count > 0 && count( $placed ) === $count ) {
            $first   = reset( $placed );
            $pos     = array_unique( array_column( $placed, 'placed_po' ) );
            $steps[] = self::step( 'purchase', 'Bought on XChange', self::DONE, 'PO ' . implode( ', ', $pos ), (string) ( $first['placed_at'] ?? '' ) );
        } elseif ( $placed ) {
            $steps[] = self::step( 'purchase', 'Bought on XChange', self::TODO, sprintf( '%d of %d items bought', count( $placed ), $count ) );
        } elseif ( $status === 'completed' && ( $row['fulfilled_po'] ?? '' ) !== '' ) {
            $steps[] = self::step( 'purchase', 'Bought on XChange', self::DONE, 'PO ' . $row['fulfilled_po'] );
        } else {
            $steps[] = self::step( 'purchase', 'Bought on XChange', self::TODO, $paid ? 'Not bought yet' : 'Waits for payment' );
        }

        // ── 3. License from XChange ──────────────────────────────────────────
        $waiting_license = array_filter( $placed, static fn( $i ) => empty( $i['has_license'] ) && ( $i['fulfillment_status'] ?? '' ) !== 'fulfilled' );
        if ( $count > 0 && count( $licensed ) === $count ) {
            $steps[] = self::step( 'license', 'License from XChange', self::DONE, 'License received' );
        } elseif ( $waiting_license ) {
            $next_check = null;
            foreach ( $waiting_license as $item ) {
                if ( isset( $retry_at[ $item['sku'] ] ) ) {
                    $next_check = $next_check === null ? $retry_at[ $item['sku'] ] : min( $next_check, $retry_at[ $item['sku'] ] );
                }
            }
            $steps[] = $next_check !== null
                ? self::step( 'license', 'License from XChange', self::ACTIVE, 'Waiting for XChange to post the license. Next check ' . wp_date( 'H:i', $next_check ) . '; the email goes out on its own when it arrives' )
                : self::step( 'license', 'License from XChange', self::ATTENTION, 'XChange hasn\'t posted the license and automatic checks have stopped. Open the email window to fetch it or paste it' );
        } elseif ( $status === 'completed' && $row['xchange_license'] !== '' ) {
            $steps[] = self::step( 'license', 'License from XChange', self::DONE, 'License received' );
        } else {
            $steps[] = self::step( 'license', 'License from XChange', self::TODO, 'Arrives after the purchase' );
        }

        // ── 4. Customer email ────────────────────────────────────────────────
        if ( $count > 0 && count( $fulfilled ) === $count && $first_ok ) {
            $steps[] = self::step( 'email', 'Customer emailed', self::DONE, $sent_text, (string) $first_ok['sent_at'] );
        } elseif ( $last && empty( $last['success'] ) ) {
            $steps[] = self::step( 'email', 'Customer emailed', self::ATTENTION, 'Last send failed: ' . (string) ( $last['message'] ?? '' ), (string) $last['sent_at'] );
        } elseif ( $placed && ! empty( $row['looks_relayed'] ) ) {
            $steps[] = self::step( 'email', 'Customer emailed', self::ATTENTION, 'Needs the buyer\'s real email address first' );
        } elseif ( $fulfilled ) {
            $steps[] = self::step( 'email', 'Customer emailed', self::TODO, sprintf( '%d of %d items emailed', count( $fulfilled ), $count ), $last_ok ? (string) $last_ok['sent_at'] : '' );
        } elseif ( $status === 'completed' && $first_ok ) {
            $steps[] = self::step( 'email', 'Customer emailed', self::DONE, $sent_text, (string) $first_ok['sent_at'] );
        } else {
            $steps[] = self::step( 'email', 'Customer emailed', self::TODO, 'Sent automatically once the license is in' );
        }

        // ── 5. Order completed ───────────────────────────────────────────────
        $completed_at = $order->get_date_completed();
        $steps[] = $status === 'completed'
            ? self::step( 'complete', 'Order completed', self::DONE, 'Completed', $completed_at ? $completed_at->date_i18n( 'Y-m-d H:i' ) : '' )
            : self::step( 'complete', 'Order completed', self::TODO, 'Completed once every item is emailed' );

        // ── 6. Reverb close-out (Reverb orders only) ─────────────────────────
        $reverb_live = false;
        if ( (string) $order->get_meta( '_mmi_reverb_order_number' ) !== '' ) {
            $notice_at  = (string) $order->get_meta( self::REVERB_META_NOTICE_AT );
            $shipped_at = (string) $order->get_meta( self::REVERB_META_SHIPPED );
            $error      = (string) $order->get_meta( self::REVERB_META_ERROR );
            $run_at     = self::$reverb_runs[ $order->get_id() ] ?? null;
            if ( $notice_at !== '' && $shipped_at !== '' ) {
                $steps[] = self::step( 'reverb', 'Reverb close-out', self::DONE, 'Buyer messaged and marked shipped', $shipped_at );
            } elseif ( $run_at !== null || (string) $order->get_meta( self::REVERB_META_PENDING ) === '1' ) {
                $reverb_live = true;
                $steps[]     = self::step( 'reverb', 'Reverb close-out', self::ACTIVE, 'Messaging the buyer and marking shipped' . ( $run_at ? ' at ' . wp_date( 'H:i', $run_at ) : '' ) . ( $error !== '' ? " (last try: {$error})" : '' ) );
            } elseif ( $error !== '' ) {
                $steps[] = self::step( 'reverb', 'Reverb close-out', self::ATTENTION, $error );
            } elseif ( $status === 'completed' ) {
                $steps[] = self::step( 'reverb', 'Reverb close-out', self::TODO, 'No Reverb message or shipping recorded for this order' );
            } else {
                $steps[] = self::step( 'reverb', 'Reverb close-out', self::TODO, 'Runs after the order completes' );
            }
        }

        $license_live = (bool) array_filter( $steps, static fn( $s ) => $s['key'] === 'license' && $s['state'] === self::ACTIVE );

        return [
            'next'  => self::next_step( $status, $review, $steps, $count, count( $placed ), count( $fulfilled ), $last_ok, $row ),
            'steps' => $steps,
            'live'  => $license_live || $reverb_live,
        ];
    }

    /** The one thing this order needs now. */
    private static function next_step( string $status, string $review, array $steps, int $count, int $placed, int $fulfilled, ?array $last_ok, array $row ): array {
        $by = array_column( $steps, null, 'key' );

        if ( in_array( $status, [ 'cancelled', 'refunded', 'failed' ], true ) ) {
            return self::next( 'closed', ucfirst( $status ), 'neutral' );
        }
        if ( $status === 'pending' ) {
            return self::next( 'awaiting_payment', 'Awaiting payment', 'warning' );
        }
        if ( $status === 'on-hold' ) {
            if ( $review === 'open' ) {
                return self::next( 'stripe_review', 'Stripe review', 'warning' );
            }
            if ( $review === 'check' ) {
                return self::next( 'stripe_check', 'Check payment in Stripe', 'error' );
            }
            return self::next( 'on_hold', 'On hold', 'warning' );
        }
        if ( ( $by['license']['state'] ?? '' ) === self::ATTENTION || ( $by['email']['state'] ?? '' ) === self::ATTENTION ) {
            return self::next( 'needs_you', 'Needs you: review & send', 'error', 'send' );
        }
        if ( ( $by['license']['state'] ?? '' ) === self::ACTIVE ) {
            return self::next( 'waiting_license', 'Waiting for license', 'info' );
        }
        if ( $count > 0 && $placed > $fulfilled && $placed === $count ) {
            // Bought, license known, but not emailed and nothing running.
            return self::next( 'needs_you', 'Needs you: review & send', 'error', 'send' );
        }
        if ( $status === 'processing' && $placed < $count ) {
            return self::next( 'ready', 'Ready to fulfill', 'success', 'fulfill' );
        }
        if ( ( $by['reverb']['state'] ?? '' ) === self::ACTIVE ) {
            return self::next( 'reverb', 'Closing out on Reverb', 'info' );
        }
        if ( $count > 0 && $fulfilled === $count && $status !== 'completed' ) {
            return self::next( 'partly_done', 'Emailed; order has other items', 'info' );
        }
        if ( $status === 'completed' && $fulfilled === 0 && ( $row['fulfilled_po'] ?? '' ) === '' ) {
            return self::next( 'completed_no_po', 'Completed, no PO recorded', 'neutral' );
        }
        return self::next( 'done', $last_ok ? 'Done · emailed ' . self::short_time( (string) $last_ok['sent_at'] ) : 'Done', 'success' );
    }

    private static function next( string $key, string $label, string $tone, string $action = '' ): array {
        return [
            'key'    => $key,
            'label'  => $label,
            'tone'   => $tone,
            'action' => $action,
            'rank'   => self::RANK[ $key ] ?? 99,
        ];
    }

    private static function step( string $key, string $label, string $state, string $text, string $at = '', string $link = '' ): array {
        return compact( 'key', 'label', 'state', 'text', 'at', 'link' );
    }

    private static function short_time( string $mysql ): string {
        $ts = strtotime( $mysql );
        if ( ! $ts ) {
            return $mysql;
        }
        return gmdate( 'Y-m-d', $ts ) === current_time( 'Y-m-d' ) ? gmdate( 'H:i', $ts ) : gmdate( 'M j', $ts );
    }

    private static function stripe_link( WC_Order $order ): string {
        $intent = (string) $order->get_meta( '_stripe_intent_id' );
        $id     = $intent !== '' ? $intent : (string) $order->get_transaction_id();
        return $id !== '' ? 'https://dashboard.stripe.com/payments/' . rawurlencode( $id ) : '';
    }
}
