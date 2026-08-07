<?php
/**
 * Plugin Name: Custom checkout Braga
 * Description: Checkout transparente e 100% customizável focado em alta conversão via Shortcode.
 * Version: 1.0.0
 * Author: Websbraga
 * Text Domain: custom-checkout
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$puc_filepath = plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $puc_filepath ) ) {
    require_once $puc_filepath;

    if ( class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        
        /**
         * Inicialização do mecanismo de atualização autônoma apontando para o repositório remoto.
         * Substitua o URI abaixo pelo endereço HTTPS oficial do seu repositório GitHub.
         */
        $braga_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/Elevus-tech/Checkout_braga.git',
            __FILE__,
            'custom-checkout'
        );

        // Definição da branch principal de onde as atualizações estáveis serão originadas.
        $braga_update_checker->setBranch( 'main' );

        /**
         * Configuração de Autenticação para Repositórios Privados.
         * Insira o Personal Access Token (PAT) gerado no painel de desenvolvedor do GitHub.
         */
        $github_token = 'ghp_COLE_SEU_TOKEN_AQUI';
        $braga_update_checker->setAuthentication( $github_token );
    }
} else {
    // Registro de falha arquitetural para fins de auditoria
    error_log( '[Custom Checkout Braga] Falha crítica: Biblioteca Plugin Update Checker não localizada no diretório esperado.' );
}

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