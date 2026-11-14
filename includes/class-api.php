<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Custom_Checkout_API {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'rest_api_init', [ $this, 'load_wc_session_for_rest' ], 5 );
    }

    public function load_wc_session_for_rest() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( strpos( $request_uri, 'checkout1' ) === false ) return;

        if ( class_exists( 'WooCommerce' ) ) {
            if ( ! function_exists( 'wc_get_cart_item_data_hash' ) && defined( 'WC_ABSPATH' ) ) {
                include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
                include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
            }
            if ( is_null( WC()->session ) ) {
                $session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
                WC()->session  = new $session_class();
                WC()->session->init();
            }
            if ( is_null( WC()->cart ) ) {
                WC()->cart = new WC_Cart();
                WC()->cart->get_cart_from_session();
            }
        }
    }

    public function register_routes() {
        register_rest_route( 'checkout1/v1', '/payment-notification', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_checkout1_payment_notification' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'checkout1/v1', '/cart', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_cart_data' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'checkout1/v1', '/process', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'process_checkout' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'checkout1/v1', '/webhook/mp', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'mp_webhook_handler' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'checkout1/v1', '/check-status', [
            'methods'             => WP_REST_Server::READABLE, // GET
            'callback'            => [ $this, 'check_order_status' ],
            'permission_callback' => '__return_true',
        ] );
        register_rest_route( 'checkout1/v1', '/calculate-shipping', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'calculate_shipping' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function get_cart_data( $request ) {
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) return new WP_REST_Response( [ 'error' => 'Carrinho vazio' ], 404 );

        $cart_data = [];
        $needs_shipping = false;

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
            if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 ) {
                if ( ! $_product->is_virtual() ) $needs_shipping = true;
                $cart_data[] = [
                    'key'      => $cart_item_key,
                    'id'       => $_product->get_id(),
                    'name'     => $_product->get_name(),
                    'price'    => (float) $_product->get_price(),
                    'quantity' => $cart_item['quantity'],
                    'image'    => wp_get_attachment_image_url( $_product->get_image_id(), 'thumbnail' ),
                ];
            }
        }

        return new WP_REST_Response( [
            'items'          => $cart_data,
            'subtotal'       => (float) $cart->get_subtotal(),
            'total'          => (float) $cart->get_total( 'edit' ),
            'needs_shipping' => $needs_shipping,
        ], 200 );
    }

    /**
     * Processa a criação do pedido, atribui o endereço de cobrança/entrega
     * e incorpora os custos logísticos antes de invocar o gateway de pagamento.
     */
    public function process_checkout( $request ) {
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return new WP_REST_Response( [ 'error' => 'Carrinho vazio.' ], 400 );
        }

        $params = $request->get_json_params();       
        $payment_method = sanitize_text_field(
            $params['payment_method'] ?? 'pix'
        );

        if ( ! Custom_Checkout_Braga_Config::is_connected() ) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Checkout não conectado ao painel Braga.'
            ], 403);
        }

        if ( empty( $params['email'] ) || empty( $params['nome'] ) ) {
            return new WP_REST_Response( [ 'error' => 'Dados incompletos.' ], 400 );
        }

        try {
            // Instancia o pedido do WooCommerce de forma antecipada para validação de valores
            $order = wc_create_order();
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                $order->add_product( $cart_item['data'], $cart_item['quantity'] );
            }

            $nome_partes = explode( ' ', sanitize_text_field( $params['nome'] ), 2 );
            $telefone    = sanitize_text_field( $params['telefone'] );
            
            // Construção do endereço de cobrança
            $address = [
                'first_name' => $nome_partes[0],
                'last_name'  => $nome_partes[1] ?? '',
                'email'      => sanitize_email( $params['email'] ),
                'phone'      => $telefone,
            ];
            $order->set_address( $address, 'billing' );

            // Consolida os valores matemáticos iniciais
            $order->calculate_totals();

            /**
             * Tratamento específico para pedidos de valor zerado (cortesia de assinatura).
             * Ignora o gateway de pagamento externo e conclui o pedido diretamente.
             */
            $total_pedido = (float) $order->get_total();

            if ( $total_pedido <= 0.00 ) {
                $order->payment_complete();
                $order->update_status( 'processing', 'Pedido gratuito processado automaticamente via assinatura.' );
                
                WC()->cart->empty_cart();

                return new WP_REST_Response([
                    'success'   => true,
                    'order_id'  => $order->get_id(),
                    'status'    => 'approved',
                    'redirect'  => $order->get_checkout_order_received_url()
                ], 200);
            }
            
            // Garantia de persistência no banco antes da chamada externa
            $order->save(); 

            // =================================================================
            // ROTEAMENTO DE PAGAMENTO - ENVIO DIRETO PARA O SAAS (PYTHON)
            // =================================================================
            
            // ATENÇÃO: Ajuste a URL abaixo se o seu SaaS rodar em outro domínio
            $panel_url = Custom_Checkout_Braga_Config::get_panel_url();

            if ( empty( $panel_url ) ) {
                throw new Exception(
                    'URL do painel Braga Checkout não configurada.'
                );
            }

            $saas_url = trailingslashit( $panel_url ) . 'api/checkout/process';           
            $token_loja = get_option( 'braga_checkout_contract_token', '' ); 

            // --- INÍCIO DO FIX ---
            // Montagem antecipada da referência externa para envio ao SaaS
            $config = Custom_Checkout_Braga_Config::get_config();
            $contrato_id = absint( $config['contrato']['id'] ?? 0 );

            $expected_reference = sprintf(
                'braga:%d:%d',
                $contrato_id,
                $order->get_id()
            );
            error_log(
                '[Braga Checkout][SAAS_TARGET] ' . wp_json_encode([
                    'panel_url' => $panel_url,
                    'saas_url' => $saas_url,
                    'contract_id' => $contrato_id,
                    'order_id' => (int) $order->get_id()
                ])
            );

            $saas_payload = $params;
            $saas_payload['order_key']          = $order->get_order_key();
            $saas_payload['transaction_amount'] = (float) $order->get_total();
            $saas_payload['order_id']           = $order->get_id();
            
            // Injeta a referência no payload para o Python enviar ao Mercado Pago
            $saas_payload['external_reference'] = $expected_reference; 
            
            // Pega a URL do webhook direto do WordPress (já vem com HTTPS certinho) apontando para a rota nova
            $saas_payload['notification_url']   = get_rest_url( null, 'checkout1/v1/payment-notification' );
            // --- FIM DO FIX ---

            // Dispara a requisição HTTP POST pro backend em Python
            $response = wp_remote_post( $saas_url, [
                'headers' => [
                    'Content-Type'           => 'application/json',
                    'X-Braga-Checkout-Token' => $token_loja
                ],
                'body'    => wp_json_encode( $saas_payload ),
                'timeout' => 30,
            ]);

            if ( is_wp_error( $response ) ) {
                throw new Exception( 'Falha de rede ao contatar o servidor de pagamentos: ' . $response->get_error_message() );
            }

            $http_code = wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );
            error_log(
                '[Braga Checkout][SAAS_RESPONSE] ' . wp_json_encode([
                    'http_code' => (int) $http_code,
                    'response_keys' => is_array($data)
                        ? array_keys($data)
                        : [],
                    'external_reference' => is_array($data)
                        ? ($data['external_reference'] ?? null)
                        : null,
                    'body_sha256' => hash('sha256', $body)
                ])
            );

            if (
                $http_code !== 200
                || ! is_array( $data )
                || empty( $data['success'] )
                || empty( $data['id'] )
            ) {
                $erro_mp = is_array( $data )
                    ? ( $data['error'] ?? 'Falha ao iniciar o pagamento.' )
                    : 'Resposta inválida do painel de pagamentos.';

                throw new Exception( $erro_mp );
            }

            $payment_id = sanitize_text_field( (string) $data['id'] );
            $payment_status = sanitize_text_field(
                (string) ( $data['status'] ?? 'unknown' )
            );

            /**
             * Extrai a referência devolvida pelo servidor Flask.
             */
            $mp_reference = (string) ( $data['external_reference'] ?? '' );
            
            /**
             * O WooCommerce precisa garantir apenas que o pagamento gerado 
             * pertence ao identificador do pedido atual.
             */
            $expected_suffix = ':' . $order->get_id();

            if (
                empty($mp_reference)
                || !str_ends_with($mp_reference, $expected_suffix)
            ) {

                error_log(
                    '[Braga Checkout][REF] ' . wp_json_encode([
                        'order_id' => (int) $order->get_id(),
                        'contract_id_config' => (int) $contrato_id,
                        'expected_reference' => $expected_reference,
                        'received_reference' => $mp_reference ?: '(vazia)',
                        'response_keys' => array_keys($data),
                        'http_code' => (int) $http_code
                    ])
                );

                throw new Exception(
                    'Não foi possível validar a referência do pagamento.'
                );
            }

            // Salva a transação antes de qualquer atualização de status.
            $order->update_meta_data( '_mp_transaction_id', $payment_id );
            $order->save();

            // Tratamento dos estados retornados pelo Mercado Pago.
            if ( $payment_status === 'approved' ) {

                $order->payment_complete( $payment_id );

            } elseif ( in_array( $payment_status, [ 'pending', 'in_process' ], true ) ) {

                if ( $payment_method === 'pix' && $payment_status === 'pending' ) {
                    $order->update_status(
                        'pending',
                        'Aguardando pagamento PIX. MP ID: ' . $payment_id
                    );
                } else {
                    $order->update_status(
                        'on-hold',
                        'Pagamento aguardando confirmação. MP ID: ' . $payment_id
                    );
                }

            } elseif ( $payment_status === 'rejected' ) {

                $order->update_status(
                    'failed',
                    'Pagamento recusado pelo Mercado Pago.'
                );

                return new WP_REST_Response([
                    'success' => false,
                    'status' => $payment_status,
                    'error' => 'O pagamento foi recusado. Confira os dados e tente novamente.'
                ], 402);

            } elseif ( $payment_status === 'cancelled' ) {

                $order->update_status(
                    'cancelled',
                    'Pagamento cancelado pelo Mercado Pago.'
                );

                return new WP_REST_Response([
                    'success' => false,
                    'status' => $payment_status,
                    'error' => 'O pagamento foi cancelado.'
                ], 402);

            } else {

                $order->update_status(
                    'on-hold',
                    'Status de pagamento aguardando verificação: ' . $payment_status
                );
            }

            // Se for PIX pendente, precisamos ter o código para apresentar ao comprador.
            $pix_copia_cola = $data['pix_copia_cola'] ?? '';
            $pix_base64 = $data['pix_base64'] ?? '';

            if (
                $payment_method === 'pix'
                && $payment_status !== 'approved'
                && ( ! $pix_copia_cola || ! $pix_base64 )
            ) {
                $order->add_order_note(
                    'Pagamento PIX criado, mas o QR Code não foi retornado pelo painel.'
                );

                return new WP_REST_Response([
                    'success' => false,
                    'error' => 'Não foi possível exibir o QR Code. Entre em contato com a loja.'
                ], 502);
            }

            // A partir daqui temos uma transação válida e registrada.
            $order->save();

            WC()->cart->empty_cart();

            $result = [
                'success' => true,
                'order_id' => $order->get_id(),
                'payment_id' => $payment_id,
                'id' => $payment_id, // Compatibilidade com o JS antigo.
                'order_key' => $order->get_order_key(),
                'status' => $payment_status,
                'redirect' => $this->get_order_redirect_url( $order )
            ];

            if ( $payment_method === 'pix' ) {
                $result['pix_copia_cola'] = $pix_copia_cola;
                $result['pix_base64'] = $pix_base64;
            }

            return new WP_REST_Response( $result, 200 );


        } catch ( Exception $e ) {
            // Registra no arquivo debug.log a exceção disparada para facilitar auditorias
            error_log( '[Braga Checkout] Falha no processamento da transação: ' . $e->getMessage() );
            return new WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }
    /**
     * Handles the incoming payment notification from the SaaS backend.
     *
     * @param WP_REST_Request $request The incoming API request containing payment data.
     * @return WP_REST_Response|WP_Error Response object or error on failure.
     */
    public function handle_checkout1_payment_notification( WP_REST_Request $request ) {
        $parameters = $request->get_json_params();

        $order_id   = isset( $parameters['order_id'] ) ? intval( $parameters['order_id'] ) : 0;
        $payment_id = isset( $parameters['payment_id'] ) ? sanitize_text_field( $parameters['payment_id'] ) : '';
        $status     = isset( $parameters['status'] ) ? sanitize_text_field( $parameters['status'] ) : '';
        $saas_token = isset( $parameters['saas_token'] ) ? sanitize_text_field( $parameters['saas_token'] ) : '';

        // Validate the presence of all required fields.
        if ( ! $order_id || ! $payment_id || ! $status || ! $saas_token ) {
            return new WP_Error( 'missing_fields', 'Missing required fields in payload.', [ 'status' => 400 ] );
        }

        // Validate the security token.
        $saved_token = get_option( 'checkout1_saas_token' );
        
        if ( empty( $saved_token ) || ! hash_equals( $saved_token, $saas_token ) ) {
            return new WP_Error( 'unauthorized', 'Invalid or missing authentication token.', [ 'status' => 401 ] );
        }

        // Load the WooCommerce order object.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_Error( 'invalid_order', 'Order not found.', [ 'status' => 404 ] );
        }

        // Process the WooCommerce order based on the received Mercado Pago status.
        if ( $status === 'approved' ) {
            // Prevent double processing if the order is already marked as paid.
            if ( ! $order->has_status( [ 'processing', 'completed' ] ) ) {
                $order->payment_complete( $payment_id );
                $order->add_order_note( sprintf( 'Payment approved via Checkout SaaS. Transaction ID: %s', $payment_id ) );
            }
        } else {
            // Log other statuses (e.g., 'rejected', 'in_process') as order notes.
            $order->add_order_note( sprintf( 'Payment status updated to: %s. Transaction ID: %s', $status, $payment_id ) );
        }

        return rest_ensure_response( [ 'message' => 'Payment notification processed successfully.' ] );
    }
    
    private function query_payment_status_from_flask( $order, $payment_id ) {

        $panel_url = Custom_Checkout_Braga_Config::get_panel_url();
        $token = Custom_Checkout_Braga_Config::get_contract_token();
        $config = Custom_Checkout_Braga_Config::get_config();

        $contrato_id = absint(
            $config['contrato']['id'] ?? 0
        );

        if ( ! $panel_url || ! $token || ! $contrato_id ) {
            return new WP_Error(
                'braga_config_missing',
                'Configuração do painel indisponível.'
            );
        }

        $endpoint = trailingslashit( $panel_url ) . 'api/checkout/status';

        $expected_amount = (float) $order->get_total();

        $response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
                'X-Braga-Checkout-Token' => $token,
            ],
            'body' => wp_json_encode([
                'order_id'        => $order->get_id(),
                'payment_id'      => (string) $payment_id,
                'expected_amount' => number_format(
                    $expected_amount,
                    2,
                    '.',
                    ''
                ),
            ]),
        ]);

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'braga_status_connection',
                'Falha ao consultar o painel de pagamentos.'
            );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode(
            wp_remote_retrieve_body( $response ),
            true
        );

        if (
            $http_code !== 200
            || ! is_array( $data )
            || empty( $data['success'] )
        ) {
            return new WP_Error(
                'braga_status_invalid',
                'O painel não conseguiu validar o pagamento.'
            );
        }

        // Confere novamente a referência esperada.
        $expected_reference = sprintf(
            'braga:%d:%d',
            $contrato_id,
            $order->get_id()
        );

        if (
            ! hash_equals(
                $expected_reference,
                (string) ( $data['external_reference'] ?? '' )
            )
        ) {
            return new WP_Error(
                'braga_reference_mismatch',
                'A referência da transação não corresponde ao pedido.'
            );
        }

        if (
            (string) ( $data['payment_id'] ?? '' ) !== (string) $payment_id
            || ( $data['currency_id'] ?? '' ) !== 'BRL'
            || abs(
                round( (float) ( $data['transaction_amount'] ?? 0 ), 2 )
                - round( $expected_amount, 2 )
            ) > 0.001
        ) {
            return new WP_Error(
                'braga_payment_integrity',
                'Os dados da transação não correspondem ao pedido.'
            );
        }

        return $data;
    }
    

    
    private function verify_mp_webhook_signature( $request, $data_id ) {

        if (
            ! defined( 'BRAGA_MP_WEBHOOK_SECRET' )
            || ! BRAGA_MP_WEBHOOK_SECRET
        ) {
            return false;
        }

        $signature = (string) $request->get_header( 'x-signature' );
        $request_id = (string) $request->get_header( 'x-request-id' );

        $parts = [];

        foreach ( explode( ',', $signature ) as $part ) {
            $pair = array_map( 'trim', explode( '=', $part, 2 ) );

            if ( count( $pair ) === 2 ) {
                $parts[ $pair[0] ] = $pair[1];
            }
        }

        $ts = $parts['ts'] ?? '';
        $v1 = $parts['v1'] ?? '';

        if ( ! $ts || ! $v1 || ! $request_id || ! $data_id ) {
            return false;
        }

        // Normaliza segundos ou milissegundos para validar a janela temporal.
        $timestamp = (int) $ts;

        if ( $timestamp > 9999999999 ) {
            $timestamp = (int) floor( $timestamp / 1000 );
        }

        if ( abs( time() - $timestamp ) > 300 ) {
            return false;
        }

        $manifest = sprintf(
            'id:%s;request-id:%s;ts:%s;',
            strtolower( (string) $data_id ),
            $request_id,
            $ts
        );

        $expected = hash_hmac(
            'sha256',
            $manifest,
            BRAGA_MP_WEBHOOK_SECRET
        );

        return hash_equals(
            strtolower( $expected ),
            strtolower( $v1 )
        );
    }

    
    public function mp_webhook_handler( WP_REST_Request $request ) {

        $params = $request->get_json_params();
        $params = is_array( $params ) ? $params : [];

        if ( ( $params['type'] ?? '' ) !== 'payment' ) {
            return new WP_REST_Response( 'OK', 200 );
        }

        // O Mercado Pago envia data.id na query string.
        $query_params = $request->get_query_params();

        $payment_id = (string) (
            $query_params['data.id']
            ?? $params['data']['id']
            ?? ''
        );

        if ( ! preg_match( '/^\d{1,30}$/', $payment_id ) ) {
            return new WP_REST_Response(
                'Identificador inválido.',
                400
            );
        }

        if (
            ! defined( 'BRAGA_MP_WEBHOOK_SECRET' )
            || ! BRAGA_MP_WEBHOOK_SECRET
        ) {
            error_log(
                '[Braga Checkout] Segredo do webhook não configurado.'
            );

            return new WP_REST_Response(
                'Webhook não configurado.',
                503
            );
        }

        if ( ! $this->verify_mp_webhook_signature( $request, $payment_id ) ) {
            error_log(
                '[Braga Checkout] Assinatura de webhook inválida.'
            );

            return new WP_REST_Response(
                'Assinatura inválida.',
                401
            );
        }

        // Localiza o pedido pela transação já registrada.
        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'meta_key' => '_mp_transaction_id',
            'meta_value' => $payment_id
        ]);

        if ( empty( $orders ) ) {
            // Pedido não localizado: não alterar nada.
            return new WP_REST_Response( 'OK', 200 );
        }

        $order = $orders[0];

        // Consulta o status real, sem confiar apenas no corpo da notificação.
        $payment = $this->query_payment_status_from_flask(
            $order,
            $payment_id
        );

        if ( is_wp_error( $payment ) ) {
            // 503 permite que o Mercado Pago tente novamente.
            return new WP_REST_Response(
                'Falha na validação do pagamento.',
                503
            );
        }

        $status = $payment['status'] ?? '';

        if (
            $status === 'approved'
            && ! $order->has_status( [ 'processing', 'completed' ] )
        ) {
            $order->payment_complete( $payment_id );

            $order->add_order_note(
                'Pagamento aprovado após validação da notificação do Mercado Pago.'
            );

        } elseif ( $status === 'rejected' ) {

            if ( ! $order->is_paid() ) {
                $order->update_status(
                    'failed',
                    'Pagamento recusado pelo Mercado Pago.'
                );
            }

        } elseif ( $status === 'cancelled' ) {

            if ( ! $order->is_paid() ) {
                $order->update_status(
                    'cancelled',
                    'Pagamento cancelado pelo Mercado Pago.'
                );
            }
        }

        return new WP_REST_Response( 'OK', 200 );
    }


    /**
     * Trata as requisições recebidas pelo frontend para consultar o status de um pagamento em tempo real.
     * Assegura que o solicitante possui autorização (order_key) para consultar o respectivo pedido.
     * 
     * @param WP_REST_Request $request Objeto contendo os parâmetros da requisição REST.
     * @return WP_REST_Response Resposta estruturada com o status atualizado ou erro de permissão.
     */
    
    public function check_order_status( WP_REST_Request $request ) {

        $order_id = absint( $request->get_param( 'order_id' ) );
        $payment_id = sanitize_text_field(
            (string) $request->get_param( 'payment_id' )
        );
        $order_key = sanitize_text_field(
            (string) $request->get_param( 'order_key' )
        );

        if ( ! $order_id || ! $payment_id || ! $order_key ) {
            return new WP_REST_Response([
                'success' => false,
                'paid' => false,
                'message' => 'Parâmetros insuficientes.'
            ], 400);
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_REST_Response([
                'success' => false,
                'paid' => false,
                'message' => 'Pedido não localizado.'
            ], 404);
        }

        // 1. Confere a chave de acesso ao pedido.
        if ( ! hash_equals( (string) $order->get_order_key(), $order_key ) ) {
            return new WP_REST_Response([
                'success' => false,
                'paid' => false,
                'message' => 'Chave do pedido inválida.'
            ], 403);
        }

        // 2. Confere se a transação pertence ao pedido.
        $saved_payment_id = (string) $order->get_meta(
            '_mp_transaction_id'
        );

        if (
            ! $saved_payment_id
            || ! hash_equals( $saved_payment_id, $payment_id )
        ) {
            return new WP_REST_Response([
                'success' => false,
                'paid' => false,
                'message' => 'Transação não corresponde ao pedido.'
            ], 403);
        }

        // 3. Se o webhook já confirmou, não precisamos consultar novamente.
        if ( $order->has_status( [ 'processing', 'completed' ] ) ) {
            return new WP_REST_Response([
                'success' => true,
                'paid' => true,
                'status' => 'approved',
                'redirect' => $this->get_order_redirect_url( $order )
            ], 200);
        }

        // 4. Consulta centralizada no Flask.
        $payment = $this->query_payment_status_from_flask(
            $order,
            $payment_id
        );

        if ( is_wp_error( $payment ) ) {
            return new WP_REST_Response([
                'success' => false,
                'paid' => false,
                'status' => 'unknown',
                'message' => 'Não foi possível confirmar o pagamento.'
            ], 502);
        }

        $status = sanitize_text_field(
            $payment['status'] ?? 'unknown'
        );

        // 5. Atualiza o WooCommerce somente com o status validado.
        if ( $status === 'approved' ) {

            $order->payment_complete( $payment_id );

            $order->add_order_note(
                'Pagamento aprovado e validado pelo painel Braga.'
            );

            return new WP_REST_Response([
                'success' => true,
                'paid' => true,
                'status' => 'approved',
                'redirect' => $order->get_checkout_order_received_url()
            ], 200);
        }

        if ( $status === 'rejected' ) {
            $order->update_status(
                'failed',
                'Pagamento recusado pelo Mercado Pago.'
            );
        } elseif ( $status === 'cancelled' ) {
            $order->update_status(
                'cancelled',
                'Pagamento cancelado pelo Mercado Pago.'
            );
        }

        return new WP_REST_Response([
            'success' => true,
            'paid' => false,
            'status' => $status
        ], 200);
    }
    private function get_order_redirect_url( $order ) {

        $page_id = absint(
            get_option( 'braga_checkout_thankyou_page_id', 0 )
        );

        if (
            $page_id
            && get_post_type( $page_id ) === 'page'
            && get_post_status( $page_id ) === 'publish'
        ) {
            return add_query_arg(
                [
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ],
                get_permalink( $page_id )
            );
        }

        return $order->get_checkout_order_received_url();
    }


    /**
     * Calcula o frete baseado no CEP informado e nas taxas nativas do WooCommerce.
     * Inclui a formatação obrigatória de CEP (com traço) exigida por plugins
     * terceiros (como o Melhor Envio) para evitar Undefined property: stdClass::$postal_code.
     */
    public function calculate_shipping( $request ) {
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) {
            return new WP_REST_Response( [ 'error' => 'Carrinho vazio.' ], 400 );
        }

        $params  = $request->get_json_params();
        
        // 1. Limpa tudo que não é número do CEP recebido
        $raw_zip = isset( $params['zipcode'] ) ? preg_replace( '/[^0-9]/', '', $params['zipcode'] ) : '';

        if ( empty( $raw_zip ) || strlen( $raw_zip ) !== 8 ) {
            return new WP_REST_Response( [ 'error' => 'CEP inválido.' ], 400 );
        }

        // 2. A MÁGICA: Força a formatação com o traço (Ex: 03621-020). 
        // A API do Melhor Envio recusa a chamada se não tiver o traço.
        $formatted_zip = substr( $raw_zip, 0, 5 ) . '-' . substr( $raw_zip, 5, 3 );

        try {
            // Instancia o customer na sessão REST pra evitar erro on null
            if ( is_null( WC()->customer ) ) {
                WC()->customer = new WC_Customer( get_current_user_id(), true );
            }

            WC()->customer->set_shipping_country( 'BR' );
            WC()->customer->set_shipping_postcode( $formatted_zip );
            
            WC()->customer->set_billing_country( 'BR' );
            WC()->customer->set_billing_postcode( $formatted_zip );
            
            $city = '';
            $state = '';

            if ( ! empty( $params['state'] ) ) {
                $state = sanitize_text_field( $params['state'] );
                WC()->customer->set_shipping_state( $state );
                WC()->customer->set_billing_state( $state );
            }
            if ( ! empty( $params['city'] ) ) {
                $city = sanitize_text_field( $params['city'] );
                WC()->customer->set_shipping_city( $city );
                WC()->customer->set_billing_city( $city );
            }

            // 3. Filtro marreta: injeta o destino formatado direto no pacote de entrega do Woo
            add_filter( 'woocommerce_cart_shipping_packages', function( $packages ) use ( $formatted_zip, $city, $state ) {
                if ( ! empty( $packages ) ) {
                    foreach ( $packages as &$package ) {
                        $package['destination']['country']  = 'BR';
                        $package['destination']['postcode'] = $formatted_zip;
                        if ( ! empty( $city ) ) $package['destination']['city'] = $city;
                        if ( ! empty( $state ) ) $package['destination']['state'] = $state;
                    }
                }
                return $packages;
            });
            
            // Força o cálculo das taxas
            $cart->calculate_shipping();
            $cart->calculate_totals();

            $shipping_packages = WC()->shipping()->get_packages();
            $available_methods = [];

            foreach ( $shipping_packages as $i => $package ) {
                if ( isset( $package['rates'] ) && ! empty( $package['rates'] ) ) {
                    foreach ( $package['rates'] as $rate_id => $rate ) {
                        
                        $label_original = $rate->get_label();
                        
                        /**
                         * ========================================================================
                         * INTERCEPTAÇÃO E CÁLCULO DE PRAZO DE ENTREGA (SLA DE PRODUÇÃO)
                         * ========================================================================
                         * Realiza a extração do prazo logístico original retornado pela transportadora
                         * e adiciona o lead time fixo (7 dias) necessário para suprimento do estoque.
                         */
                        if ( preg_match( '/(\d+)\s*(dia|dias)/i', $label_original, $matches ) ) {
                            $prazo_logistico = (int) $matches[1];
                            $prazo_total     = $prazo_logistico + 7;
                            
                            // Substitui apenas o valor numérico preservando a formatação original da string
                            $label_final = str_replace( $matches[0], $prazo_total . ' ' . $matches[2], $label_original );
                        } else {
                            // Fallback de segurança caso a transportadora não padronize a string de retorno
                            $label_final = $label_original . ' (+ 7 dias de produção)';
                        }

                        $available_methods[] = [
                            'id'    => $rate_id,
                            'label' => $label_final,
                            'cost'  => (float) $rate->get_cost(),
                        ];
                    }
                }
            }

            return new WP_REST_Response( [
                'success'  => true,
                'methods'  => $available_methods,
                'subtotal' => (float) $cart->get_subtotal(),
                'total'    => (float) $cart->get_total( 'edit' ),
            ], 200 );

        } catch ( Exception $e ) {
            return new WP_REST_Response( [ 'error' => 'Falha ao calcular o frete: ' . $e->getMessage() ], 500 );
        }
    }
}
new Custom_Checkout_API();