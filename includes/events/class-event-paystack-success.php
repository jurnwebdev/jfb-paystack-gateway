<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JFB_Event_Paystack_Success extends \Jet_Form_Builder\Actions\Events\Base_Event {

    public function get_id(): string {
        return 'PAYSTACK.SUCCESS';
    }

    public function get_label(): string {
        return 'Paystack: On Payment Success';
    }

    public function get_help(): string {
        return 'Executes only if the Paystack Inline Checkout was completed successfully and verified server-side.';
    }

    public function executors(): array {
        if ( class_exists( '\Jet_Form_Builder\Actions\Events\Default_Process\Default_Process_Executor' ) ) {
            return [ new \Jet_Form_Builder\Actions\Events\Default_Process\Default_Process_Executor() ];
        }
        return [];
    }
}
