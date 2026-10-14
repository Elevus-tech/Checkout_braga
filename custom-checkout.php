<?php
/**
 * Plugin Name: Checkout Braga
 * Version: 1.0.1
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
 * Como o repositório é público, não é necessária a injeção de tokens de autenticação.
 */
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/Elevus-tech/Checkout_braga', // URL do repositório público
    __FILE__, // Arquivo principal do plugin
    'checkout-braga' // Slug do plugin (deve ser idêntico ao nome da pasta do plugin)
);

// Define explicitamente qual branch o PUC deve monitorar para atualizações
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
            // Assets virão aqui futuramente
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

// TRAVA DE AGÊNCIA: Esconde o gateway do cliente fofoqueiro
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
 * Exporta os dados do pedido para o carrinho do Melhor Envio de forma dinâmica,
 * reaproveitando o Token do plugin oficial e o ID do serviço escolhido no checkout.
 *
 * @param int $order_id Identificador numérico do pedido no WooCommerce.
 * @return void
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

    // ==========================================================================
    // 1. EXTRAÇÃO DINÂMICA DO TOKEN (PLUGIN OFICIAL DO MELHOR ENVIO)
    // ==========================================================================
    $me_settings = get_option( 'woocommerce_melhor-envio_settings', [] );
    $me_access_token = isset( $me_settings['token'] ) ? $me_settings['token'] : '';

    // Fallback genérico caso o plugin antigo use a nomenclatura legada
    if ( empty( $me_access_token ) ) {
        $me_settings = get_option( 'wcmp_settings', [] );
        $me_access_token = isset( $me_settings['token'] ) ? $me_settings['token'] : '';
    }

    if ( empty( $me_access_token ) ) {
        error_log( '[Melhor Envio] Falha: Token de acesso não localizado nas opções do plugin oficial.' );
        return;
    }

    // ==========================================================================
    // 2. EXTRAÇÃO DINÂMICA DO SERVIÇO DE FRETE (PAC, SEDEX, JADLOG, ETC)
    // ==========================================================================
    $service_id = 1; // Fallback estrutural para PAC
    $shipping_methods = $order->get_shipping_methods();

    foreach ( $shipping_methods as $method ) {
        $method_id = $method->get_method_id();
        $instance_id = $method->get_instance_id();

        // O provedor costuma atrelar o ID da transportadora no instance_id ou method_id
        if ( strpos( $method_id, 'melhor_envio' ) !== false || strpos( $method_id, 'wcmp' ) !== false ) {
            // Tenta extrair o identificador numérico da string (ex: "melhor_envio:2")
            $parts = explode( ':', $method_id );
            if ( isset( $parts[1] ) && is_numeric( $parts[1] ) ) {
                $service_id = (int) $parts[1];
            } elseif ( is_numeric( $instance_id ) ) {
                $service_id = (int) $instance_id;
            }
            break;
        }
    }

    // ==========================================================================
    // 3. MONTAGEM DO PAYLOAD E SUBMISSÃO À API
    // ==========================================================================
    $products_payload = [];
    foreach ( $order->get_items() as $item ) {
        $products_payload[] = [
            'name'          => sanitize_text_field( $item->get_name() ),
            'quantity'      => (int) $item->get_quantity(),
            'unitary_value' => (float) $order->get_item_subtotal( $item, false )
        ];
    }

    $payload = [
        'service' => $service_id,
        // A chave 'agency' é requerida para transportadoras privadas (ex: JadLog).
        // A implementação completa exigiria mapeamento da transportadora escolhida.
        'from'    => [
            'name'          => get_bloginfo( 'name' ),
            'document'      => '00000000000000', // Recomenda-se resgatar das configurações da loja
            'address'       => 'Endereço Padrão',
            'number'        => 'S/N',
            'district'      => 'Centro',
            'city'          => 'São Paulo',
            'state_abbr'    => 'SP',
            'postal_code'   => get_option( 'woocommerce_store_postcode', '01001000' )
        ],
        'to'      => [
            'name'          => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'phone'         => preg_replace( '/[^0-9]/', '', $order->get_billing_phone() ),
            'email'         => $order->get_billing_email(),
            'document'      => $order->get_meta( '_billing_cpf' ),
            'address'       => $order->get_shipping_address_1(),
            'number'        => $order->get_meta( '_shipping_number' ) ?: 'S/N',
            'district'      => $order->get_meta( '_shipping_neighborhood' ) ?: 'Bairro',
            'city'          => $order->get_shipping_city(),
            'state_abbr'    => $order->get_shipping_state(),
            'postal_code'   => preg_replace( '/[^0-9]/', '', $order->get_shipping_postcode() )
        ],
        'products' => $products_payload,
        'volumes'  => [
            [
                'height' => 10,
                'width'  => 20,
                'length' => 30,
                'weight' => 1.5
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