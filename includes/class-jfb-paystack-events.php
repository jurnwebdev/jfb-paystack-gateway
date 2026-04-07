<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers custom Paystack payment events into JetFormBuilder's event registry.
 * These events appear as conditions in the JFB action editor (e.g. "Paystack: On Payment Success").
 */
class JFB_Paystack_Events {

    public function __construct() {
        add_filter( 'jet-form-builder/event-types', [ $this, 'register_events' ] );
    }

    public function register_events( $events ) {
        if ( class_exists( '\Jet_Form_Builder\Actions\Events\Base_Event' ) ) {
            require_once JFB_PAYSTACK_DIR . 'includes/events/class-event-paystack-success.php';
            require_once JFB_PAYSTACK_DIR . 'includes/events/class-event-paystack-failed.php';

            $events[] = new JFB_Event_Paystack_Success();
            $events[] = new JFB_Event_Paystack_Failed();
        }
        return $events;
    }
}
