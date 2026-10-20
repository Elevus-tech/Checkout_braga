<?php
/**
 * Plugin Name: Checkout Braga
 * Version: 1.0.3
 * Description: Plugin customizado para processamento de checkout.
 * Author: Elevus Tech
 */

// Bloqueia o acesso direto ao arquivo por questões de segurança
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Requer o arquivo principal do Plugin Update Checker
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

/**
 * Inicializa o verificador de atualizações.
 */
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Elevus-tech/Checkout_braga',
    __FILE__,
    'checkout-braga'
);
$myUpdateChecker->setBranch( 'main' );

// Carrega os arquivos internos
require_once plugin_dir_path( __FILE__ ) . 'includes/class-braga-config.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-api.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/gateways/class-pix-integration.php';

class Custom_Checkout_Core {
    public function __construct() {
        add_shortcode( 'websbraga_checkout', [ $this, 'render_checkout_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );
    }

    public function enqueue_checkout_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'websbraga_checkout' ) ) {
            // Assets futuros
        }
    }

    public function render_checkout_shortcode() {
        ob_start();
        $template_path = plugin_dir_path( __FILE__ ) . 'templates/custom-checkout-page.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            echo '<p>Erro: Template não encontrado.</p>';
        }
        return ob_get_clean();
    }
}
new Custom_Checkout_Core();

// Elementor Widget
add_action( 'elementor/widgets/register', function( $widgets_manager ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-elementor-widget.php';
    $widgets_manager->register( new Custom_Checkout_Elementor_Widget() );
});

// Tela de Obrigado (Exibir QR Code)
add_action( 'woocommerce_thankyou', 'exibir_pix_na_tela_de_obrigado', 10, 1 );
function exibir_pix_na_tela_de_obrigado( $order_id ) {
    if ( ! $order_id ) return;
    $order = wc_get_order( $order_id );
    if ( $order->has_status( 'pending' ) ) {
        $pix_base64     = $order->get_meta( '_mp_pix_base64' );
        $pix_copia_cola = $order->get_meta( '_mp_pix_copia_cola' );

        if ( $pix_base64 && $pix_copia_cola ) {
            echo '<div style="background: #f4f5f7; border: 1px solid #ccc; padding: 30px; text-align: center; border-radius: 8px; margin-bottom: 30px;">';
            echo '<h2 style="color: #2ecc71;">Pague seu PIX para liberar o pedido!</h2>';
            echo '<img src="data:image/jpeg;base64,' . esc_attr( $pix_base64 ) . '" style="width: 200px; height: 200px; border-radius: 8px; border: 1px solid #ddd;" />';
            echo '<p style="margin-top: 20px;"><strong>Ou use o código Copia e Cola:</strong></p>';
            echo '<input type="text" value="' . esc_attr( $pix_copia_cola ) . '" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; text-align: center;" onclick="this.select();" readonly />';
            echo '</div>';
        }
    }
}

// Registra o Gateway Nativo no WooCommerce
add_filter( 'woocommerce_payment_gateways', 'add_checkout1_gateway' );
function add_checkout1_gateway( $gateways ) {
    require_once plugin_dir_path( __FILE__ ) . 'includes/gateways/class-wc-gateway-custom-pix.php';
    $gateways[] = 'WC_Gateway_Custom_MP_Pix';
    return $gateways;
}

// TRAVA DE AGÊNCIA: Esconde o gateway do cliente
add_action( 'admin_head', 'esconder_gateway_do_cliente' );
function esconder_gateway_do_cliente() {
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'wc-settings' || ! isset( $_GET['tab'] ) || $_GET['tab'] !== 'checkout' ) return;

    $current_user = wp_get_current_user();
    $email_do_patrao = 'mikael.r.joaquim@gmail.com'; 

    if ( $current_user->user_email !== $email_do_patrao ) {
        echo '<style>table.wc_gateways tbody tr[data-gateway_id="checkout1_mp_pix"] { display: none !important; }</style>';
    }
}

/**
 * ==============================================================================
 * INTEGRAÇÃO LOGÍSTICA: MELHOR ENVIO (CARRINHO) COM RESGATE DINÂMICO
 * ==============================================================================
 */
function braga_exportar_pedido_melhor_envio_dinamico( $order_id ) {
    $order = wc_get_order( $order_id );

    if ( ! $order ) {
        error_log( '[Melhor Envio] Falha na integração: Pedido não localizado.' );
        return;
    }

    if ( $order->get_meta( '_melhor_envio_cart_id' ) ) {
        return;
    }

    // 1. EXTRAÇÃO DINÂMICA DO TOKEN
    $me_settings = get_option( 'woocommerce_melhor-envio_settings', [] );
    $me_access_token = isset( $me_settings['token'] ) ? $me_settings['token'] : '';

    if ( empty( $me_access_token ) ) {
        $me_settings = get_option( 'wcmp_settings', [] );
        $me_access_token = isset( $me_settings['token'] ) ? $me_settings['token'] : '';
    }

    if ( empty( $me_access_token ) ) {
        error_log( '[Melhor Envio] Falha: Token de acesso não localizado nas opções do plugin oficial.' );
        return;
    }

    // 2. EXTRAÇÃO DINÂMICA DO SERVIÇO DE FRETE
    $service_id = 1; 
    $shipping_methods = $order->get_shipping_methods();

    foreach ( $shipping_methods as $method ) {
        $method_id = $method->get_method_id();
        $instance_id = $method->get_instance_id();

        if ( strpos( $method_id, 'melhor_envio' ) !== false || strpos( $method_id, 'wcmp' ) !== false ) {
            $parts = explode( ':', $method_id );
            if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
                $service_id = (int) $parts[1];
            } elseif ( is_numeric( $instance_id ) ) {
                $service_id = (int) $instance_id;
            }
            break;
        }
    }

    // 3. MONTAGEM DOS PRODUTOS E VOLUMES
    $total_weight     = 0;
    $max_height       = 0;
    $max_width        = 0;
    $max_length       = 0;
    $products_payload = [];

    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        
        if ( $product && ! $product->is_virtual() ) {
            $qty = (int) $item->get_quantity();
            
            // Popula o array de produtos para a API
            $products_payload[] = [
                'name'          => $product->get_name(),
                'quantity'      => $qty,
                'unitary_value' => (float) $order->get_item_subtotal( $item, false )
            ];

            // Acúmulo de peso e dimensões
            $weight = (float) $product->get_weight();
            $total_weight += ( $weight > 0 ? $weight : 0.3 ) * $qty;

            $height = (float) $product->get_height();
            $width  = (float) $product->get_width();
            $length = (float) $product->get_length();

            $max_height = max( $max_height, $height > 0 ? $height : 4 );
            $max_width  = max( $max_width, $width > 0 ? $width : 11 );
            $max_length = max( $max_length, $length > 0 ? $length : 16 );
        }
    }

    $total_weight = max( $total_weight, 0.3 );
    $max_height   = max( $max_height, 4 );
    $max_width    = max( $max_width, 11 );
    $max_length   = max( $max_length, 16 );

    // 4. ESTRUTURAÇÃO DO PAYLOAD FINAL
    // ATENÇÃO: Os dados do remetente ('from') devem ser os da sua loja.
    $payload = [
        'service'  => $service_id,
        'from'     => [
            'name'          => get_option('blogname'),
            'phone'         => '11999999999', // Substitua pelo telefone da Elevus Tech/Loja
            'email'         => get_option('admin_email'),
            'document'      => '', // CNPJ da loja se necessário
            'postal_code'   => get_option('woocommerce_store_postcode', '01001000'), // Puxa do Woo
            'address'       => get_option('woocommerce_store_address', 'Rua Padrão'),
            'number'        => 'S/N'
        ],
        'to'       => [
            'name'          => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'phone'         => $order->get_billing_phone(),
            'email'         => $order->get_billing_email(),
            'document'      => $order->get_meta('_billing_cpf'), // Ajuste a meta key conforme seu checkout
            'address'       => $order->get_shipping_address_1(),
            'number'        => $order->get_shipping_address_2() ?: 'S/N',
            'postal_code'   => preg_replace('/[^0-9]/', '', $order->get_shipping_postcode()),
            'city'          => $order->get_shipping_city(),
            'state_abbr'    => $order->get_shipping_state(),
        ],
        'products' => $products_payload,
        'volumes'  => [
            [
                'height' => $max_height,
                'width'  => $max_width,
                'length' => $max_length,
                'weight' => $total_weight
            ]
        ],
        'options' => [
            'insurance_value' => (float) $order->get_total(),
            'receipt'         => false,
            'own_hand'        => false,
            'non_commercial'  => true,
            'invoice'         => [ 'key' => '' ]
        ]
    ];

    // 5. SUBMISSÃO À API DO MELHOR ENVIO
    $response = wp_remote_post( 'https://www.melhorenvio.com.br/api/v2/me/cart', [
        'headers' => [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $me_access_token,
            'User-Agent'    => 'BragaCheckout_System/1.0 (dev@websbraga.com)'
        ],
        'body'    => wp_json_encode( $payload ),
        'timeout' => 20
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[Melhor Envio] Falha crítica de conectividade HTTP: ' . $response->get_error_message() );
        return;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    $body_json = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $http_code >= 200 && $http_code < 300 ) {
        $order->update_meta_data( '_melhor_envio_cart_id', $body_json['id'] ?? 'gerado' );
        $order->add_order_note( 'Integração Logística: Etiqueta exportada para o carrinho do Melhor Envio dinamicamente.' );
        $order->save();
    } else {
        error_log( '[Melhor Envio] Rejeição da API (HTTP ' . $http_code . '): ' . wp_json_encode( $body_json ) );
    }
}

add_action( 'woocommerce_payment_complete', 'braga_exportar_pedido_melhor_envio_dinamico', 10, 1 );