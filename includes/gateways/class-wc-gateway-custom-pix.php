<?php
/**
 * Gateway class for Custom Checkout Braga.
 * Integrates natively with WooCommerce but delegates credential management to the SaaS Panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Gateway_Custom_MP_Pix extends WC_Payment_Gateway {
    
    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'checkout1_mp_pix';
        $this->icon               = '';
        $this->has_fields         = false;
        $this->method_title       = 'Pix e Cartão (Braga Checkout)';
        $this->method_description = 'Integração centralizada via SaaS. A gestão de credenciais do Mercado Pago é realizada através do Painel Braga.';

        $this->init_form_fields();
        $this->init_settings();

        $this->title   = 'Mercado Pago (Braga)';
        $this->enabled = $this->get_option( 'enabled' ); 

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    /**
     * Initialize Gateway Settings Form Fields.
     * Removed manual credential inputs (Access Token/Public Key) to enforce OAuth flow via SaaS.
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Habilitar/Desabilitar',
                'type'    => 'checkbox',
                'label'   => 'Habilitar pagamentos via Braga Checkout',
                'default' => 'yes',
            ],
            'info_msg' => [
                'title'       => 'Configuração de Credenciais',
                'type'        => 'title',
                'description' => 'A autenticação com o Mercado Pago agora é feita via OAuth. Por favor, acesse o menu <strong>Braga Checkout</strong> na barra lateral para conectar sua conta.',
            ],
        ];
    }
}