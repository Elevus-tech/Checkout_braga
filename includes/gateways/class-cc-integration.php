<?php
/**
 * Integração de Cartão de Crédito roteada através do Painel Central Braga.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Custom_Checkout_MP_CC {

    public static function process_payment( $order, $payload ) {
        $contract_token = get_option( 'braga_checkout_contract_token', '' );
        $panel_url      = get_option( 'braga_checkout_panel_url', '' );

        if ( empty( $contract_token ) || empty( $panel_url ) ) {
            throw new Exception( 'Configuração do Painel Braga ausente. Verifique o licenciamento.' );
        }

        $cpf_limpo = preg_replace( '/[^0-9]/', '', $payload['cpf'] );

        $request_payload = [
            'transaction_amount' => (float) $order->get_total(),
            'description'        => 'Pedido #' . $order->get_id() . ' - ' . get_bloginfo( 'name' ),
            'payment_method'     => 'credit_card',
            'email'              => $payload['email'],
            'nome'               => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'cpf'                => $cpf_limpo,
            'order_key'          => $order->get_order_key(),
            'token'              => sanitize_text_field( $payload['token'] ),
            'installments'       => (int) $payload['installments'],
            'payment_method_id'  => sanitize_text_field( $payload['payment_method_id'] )
        ];

        if ( ! empty( $payload['issuer_id'] ) ) {
            $request_payload['issuer_id'] = sanitize_text_field( $payload['issuer_id'] );
        }

        $endpoint = trailingslashit( $panel_url ) . 'api/checkout/process';

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Content-Type'           => 'application/json',
                'X-Braga-Checkout-Token' => $contract_token
            ],
            'body'    => wp_json_encode( $request_payload ),
            'timeout' => 20
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Erro de conectividade com o servidor central.' );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status_code !== 200 || empty( $body['success'] ) ) {
            $error_msg = isset( $body['error'] ) ? $body['error'] : 'Falha na comunicação com o gateway.';
            throw new Exception( 'Transação recusada: ' . $error_msg );
        }

        return [
            'id'     => $body['id'] ?? '',
            'status' => $body['status'] ?? 'pending'
        ];
    }
}