<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class JFB_Event_Paystack_Failed extends \Jet_Form_Builder\Actions\Events\Base_Event {

    public function get_id(): string {
        return 'PAYSTACK.FAILED';
    }

    public function get_label(): string {
        return 'Paystack: On Payment Failed';
    }

    public function get_help(): string {
        return 'Executes if the Paystack Inline Checkout failed or was closed by the user without completing.';
    }

    public function executors(): array {
        if ( class_exists( '\Jet_Form_Builder\Actions\Events\Default_Process\Default_Process_Executor' ) ) {
            return [ new \Jet_Form_Builder\Actions\Events\Default_Process\Default_Process_Executor() ];
        }
        return [];
    }
}
