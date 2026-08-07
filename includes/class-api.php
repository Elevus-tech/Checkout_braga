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
        if ( ! $cart || $cart->is_empty() ) return new WP_REST_Response( [ 'error' => 'Carrinho vazio.' ], 400 );

        $params = $request->get_json_params();
        if ( empty( $params['email'] ) || empty( $params['nome'] ) ) return new WP_REST_Response( [ 'error' => 'Dados incompletos.' ], 400 );

        try {
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

            // Construção do endereço de entrega
            if ( ! empty( $params['cep'] ) ) {
                $shipping_address = [
                    'first_name' => $nome_partes[0],
                    'last_name'  => $nome_partes[1] ?? '',
                    'postcode'   => sanitize_text_field( $params['cep'] ),
                    'address_1'  => sanitize_text_field( $params['rua'] ?? '' ),
                    'address_2'  => sanitize_text_field( $params['complemento'] ?? '' ),
                    'city'       => sanitize_text_field( $params['cidade'] ?? '' ),
                    'state'      => sanitize_text_field( $params['estado'] ?? '' ),
                    'country'    => 'BR'
                ];
                $order->set_address( $shipping_address, 'shipping' );
                
                // Mapeamento extra: número e bairro (padrão Brasil no WooCommerce)
                if ( ! empty( $params['numero'] ) ) {
                    $order->update_meta_data( '_shipping_number', sanitize_text_field( $params['numero'] ) );
                    $order->update_meta_data( '_billing_number', sanitize_text_field( $params['numero'] ) );
                }
                if ( ! empty( $params['bairro'] ) ) {
                    $order->update_meta_data( '_shipping_neighborhood', sanitize_text_field( $params['bairro'] ) );
                    $order->update_meta_data( '_billing_neighborhood', sanitize_text_field( $params['bairro'] ) );
                }
            }

            $cpf_limpo = preg_replace( '/[^0-9]/', '', $params['cpf'] );
            if ( ! empty( $cpf_limpo ) ) $order->update_meta_data( '_billing_cpf', $cpf_limpo );

            // Integração do Custo de Frete na Fatura do Pedido
            if ( ! empty( $params['shipping_method'] ) ) {
                $shipping_packages = WC()->shipping()->get_packages();
                $shipping_cost  = 0;
                $shipping_label = '';

                foreach ( $shipping_packages as $package ) {
                    if ( isset( $package['rates'][ $params['shipping_method'] ] ) ) {
                        $rate = $package['rates'][ $params['shipping_method'] ];
                        $shipping_cost  = $rate->get_cost();
                        $shipping_label = $rate->get_label();
                        break;
                    }
                }

                $item = new WC_Order_Item_Shipping();
                $item->set_method_id( sanitize_text_field( $params['shipping_method'] ) );
                
                if ( ! empty( $shipping_label ) ) {
                    $item->set_method_title( $shipping_label );
                }
                
                // Atribuição do custo para consolidação matemática no Total
                $item->set_total( $shipping_cost );
                $order->add_item( $item );
            }

            // Consolida os valores matemáticos
            $order->calculate_totals();

            // Roteamento de Pagamento
            // =================================================================
            // ROTEAMENTO DE PAGAMENTO - ENVIO DIRETO PARA O SAAS (PYTHON)
            // =================================================================
            // =================================================================
            // ROTEAMENTO DE PAGAMENTO - ENVIO DIRETO PARA O SAAS (PYTHON)
            // =================================================================
            $payment_method = isset( $params['payment_method'] ) ? sanitize_text_field( $params['payment_method'] ) : 'pix';
            
            // ATENÇÃO: Ajuste a URL abaixo se o seu SaaS rodar em outro domínio
            $saas_url = 'https://chatbiaa.websbraga.com/api/checkout/process';
            
            $token_loja = get_option( 'braga_checkout_contract_token', '' ); 

            $saas_payload = $params;
            $saas_payload['order_key']          = $order->get_order_key();
            $saas_payload['transaction_amount'] = (float) $order->get_total();
            
            // Pega a URL do webhook direto do WordPress (já vem com HTTPS certinho)
            $saas_payload['notification_url']   = get_rest_url( null, 'checkout1/v1/webhook/mp' );

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

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            // Verifica se a API do Python devolveu erro (como o caso do Sandbox)
            if ( empty( $data['success'] ) ) {
                $erro_mp = isset( $data['error'] ) ? $data['error'] : 'Transação recusada no provedor.';
                throw new Exception( $erro_mp );
            }

            // Atualiza os metadados do pedido com o ID da transação no Mercado Pago
            $order->update_meta_data( '_mp_transaction_id', $data['id'] );

            // Roteia a finalização baseada no tipo de pagamento
            if ( $payment_method === 'credit_card' ) {
                if ( $data['status'] === 'approved' ) {
                    $order->payment_complete( $data['id'] );
                } else {
                    $order->update_status( 'on-hold', 'Aguardando revisão manual do Mercado Pago (ID: ' . $data['id'] . ').' );
                }
                $order->save();
                WC()->cart->empty_cart();

                return new WP_REST_Response( [
                    'success'  => true,
                    'order_id' => $order->get_id(),
                    'redirect' => $order->get_checkout_order_received_url()
                ], 200 );
                
            } else { // Retorno do PIX
                $order->update_meta_data( '_mp_pix_copia_cola', $data['pix_copia_cola'] );
                $order->update_meta_data( '_mp_pix_base64', $data['pix_base64'] );
                $order->update_status( 'pending', 'Aguardando PIX (MP ID: ' . $data['id'] . ').' );
                $order->save();
                WC()->cart->empty_cart();

                return new WP_REST_Response( [
                    'success'        => true,
                    'order_id'       => $order->get_id(),
                    'pix_copia_cola' => $data['pix_copia_cola'],
                    'pix_base64'     => $data['pix_base64'],
                    'redirect'       => $order->get_checkout_order_received_url()
                ], 200 );
            }

        } catch ( Exception $e ) {
            // Registra no arquivo debug.log a exceção disparada para facilitar auditorias
            error_log( '[Braga Checkout] Falha no processamento da transação: ' . $e->getMessage() );
            return new WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
        }
    }

    public function mp_webhook_handler( $request ) {
        $params = $request->get_json_params();

        if ( isset( $params['type'] ) && $params['type'] === 'payment' && isset( $params['data']['id'] ) ) {
            $payment_id = $params['data']['id'];
            
            // Resgata o cache da configuração sincronizada do Painel Braga
            $braga_config = get_option( 'braga_checkout_cached_config', [] );
            $access_token = isset( $braga_config['contrato']['mp_access_token'] ) ? $braga_config['contrato']['mp_access_token'] : '';

            if ( empty( $access_token ) ) {
                error_log('[Braga Checkout] Webhook ignorado: Token de acesso do Mercado Pago ausente no cache local.');
                return new WP_REST_Response( 'OK', 200 );
            }

            // Consulta a API do Mercado Pago para atestar a veracidade do webhook
            $response = wp_remote_get( 'https://api.mercadopago.com/v1/payments/' . $payment_id, [
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ]
            ] );

            if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                $payment_info = json_decode( wp_remote_retrieve_body( $response ), true );
                
                if ( isset( $payment_info['status'] ) && $payment_info['status'] === 'approved' ) {
                    $orders = wc_get_orders( [
                        'meta_key'   => '_mp_transaction_id',
                        'meta_value' => $payment_id,
                        'status'     => 'pending',
                        'limit'      => 1,
                    ] );

                    if ( ! empty( $orders ) ) {
                        $order = $orders[0];
                        $order->payment_complete( $payment_id );
                        $order->add_order_note( 'Pagamento aprovado automaticamente via Webhook Mercado Pago.' );
                    }
                }
            }
        }
        
        return new WP_REST_Response( 'OK', 200 );
    }

    /**
     * Verifica o status do pedido via Consulta Ativa (Polling) no Mercado Pago.
     * Elimina o delay do Webhook e garante o redirecionamento instantâneo no frontend.
     */
    public function check_order_status( $request ) {
        $order_id   = absint( $request->get_param( 'order_id' ) );
        $payment_id = sanitize_text_field( $request->get_param( 'payment_id' ) );
        
        if ( ! $order_id ) {
            return new WP_REST_Response( [ 'paid' => false ], 400 );
        }

        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            return new WP_REST_Response( [ 'paid' => false ], 404 );
        }

        // 1. Verificação passiva: O Webhook já deu baixa no WooCommerce?
        if ( $order->has_status( [ 'processing', 'completed' ] ) ) {
            return new WP_REST_Response( [ 
                'paid'     => true, 
                'redirect' => $order->get_checkout_order_received_url() 
            ], 200 );
        }

        // 2. Verificação ativa: Bate direto na API do Mercado Pago
        if ( $payment_id ) {
            $braga_config = get_option( 'braga_checkout_cached_config', [] );
            $access_token = isset( $braga_config['contrato']['mp_access_token'] ) ? $braga_config['contrato']['mp_access_token'] : '';

            if ( ! empty( $access_token ) ) {
                $response = wp_remote_get( 'https://api.mercadopago.com/v1/payments/' . $payment_id, [
                    'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
                    'timeout' => 10,
                ] );

                if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                    $payment_info = json_decode( wp_remote_retrieve_body( $response ), true );
                    
                    if ( isset( $payment_info['status'] ) && $payment_info['status'] === 'approved' ) {
                        // Força a conclusão do pagamento no WooCommerce antes mesmo do Webhook chegar
                        $order->payment_complete( $payment_id );
                        $order->add_order_note( 'Pagamento aprovado e validado instantaneamente via Polling (Consulta Ativa do Frontend).' );
                        
                        return new WP_REST_Response( [ 
                            'paid'     => true, 
                            'redirect' => $order->get_checkout_order_received_url() 
                        ], 200 );
                    }
                }
            }
        }

        // Retorna falso para o JS tentar de novo no próximo ciclo
        return new WP_REST_Response( [ 'paid' => false ], 200 );
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
                        $available_methods[] = [
                            'id'    => $rate_id,
                            'label' => $rate->get_label(),
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