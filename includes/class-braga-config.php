<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Custom_Checkout_Braga_Config {

    const OPTION_PANEL_URL      = 'braga_checkout_panel_url';
    const OPTION_CONTRACT_TOKEN = 'braga_checkout_contract_token';
    const OPTION_CACHED_CONFIG = 'braga_checkout_cached_config';
    const OPTION_LAST_SYNC     = 'braga_checkout_last_sync';
    const OPTION_LAST_ERROR    = 'braga_checkout_last_error';
	/**
     * Inicializa as rotinas de agendamento (Cron) do plugin.
     * Deve ser invocado no construtor da classe.
     */
    public function setup_cron_routines() {
        // Registra o hook que será disparado pelo WP Cron
        add_action( 'braga_checkout_daily_sync_event', [ $this, 'execute_scheduled_sync' ] );

        // Verifica se a tarefa já está na fila; caso contrário, agenda para execução diária
        if ( ! wp_next_scheduled( 'braga_checkout_daily_sync_event' ) ) {
            wp_schedule_event( time(), 'daily', 'braga_checkout_daily_sync_event' );
        }
    }

    /**
     * Callback executado pelo agendador de tarefas do WordPress.
     * Atualiza as configurações locais consumindo a API central.
     */
    public function execute_scheduled_sync() {
        // Interrompe a execução caso o plugin não esteja vinculado a um contrato válido
        if ( ! self::is_connected() ) {
            return;
        }

        $result = self::sync_config();

        if ( is_wp_error( $result ) ) {
            error_log( '[Braga Checkout] Falha na sincronização diária automatizada: ' . $result->get_error_message() );
        }
    }

    public function __construct() {
        // Registra o menu do painel administrativo no WooCommerce
        add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 99 );

        // Registra os handlers para processamento dos formulários de configuração
        add_action( 'admin_post_braga_checkout_save_config', [ $this, 'handle_save_config' ] );
        add_action( 'admin_post_braga_checkout_sync_config', [ $this, 'handle_sync_config' ] );

        // Inicializa o agendamento de sincronização diária com o servidor Flask
        $this->setup_cron_routines();
    }

    public function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Braga Checkout',
            'Braga Checkout',
            'manage_woocommerce',
            'braga-checkout-config',
            [ $this, 'render_admin_page' ]
        );
    }

    public function handle_save_config() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sem permissão.' );
        }

        check_admin_referer( 'braga_checkout_save_config' );

        $panel_url = isset( $_POST['panel_url'] )
            ? sanitize_text_field( wp_unslash( $_POST['panel_url'] ) )
            : '';

        $contract_token = isset( $_POST['contract_token'] )
            ? sanitize_text_field( wp_unslash( $_POST['contract_token'] ) )
            : '';

        $panel_url = trim( $panel_url );
        $contract_token = trim( $contract_token );

        if ( $panel_url && ! preg_match( '#^https?://#i', $panel_url ) ) {
            $panel_url = 'https://' . $panel_url;
        }

        $panel_url = untrailingslashit( esc_url_raw( $panel_url ) );

        update_option( self::OPTION_PANEL_URL, $panel_url, false );
        update_option( self::OPTION_CONTRACT_TOKEN, $contract_token, false );

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'        => 'braga-checkout-config',
                    'braga_saved' => '1',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public function handle_sync_config() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sem permissão.' );
        }

        check_admin_referer( 'braga_checkout_sync_config' );

        $result = self::sync_config();

        if ( is_wp_error( $result ) ) {
            update_option( self::OPTION_LAST_ERROR, $result->get_error_message(), false );

            wp_safe_redirect(
                add_query_arg(
                    [
                        'page'        => 'braga-checkout-config',
                        'braga_error' => rawurlencode( $result->get_error_message() ),
                    ],
                    admin_url( 'admin.php' )
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'       => 'braga-checkout-config',
                    'braga_sync' => '1',
                ],
                admin_url( 'admin.php' )
            )
        );
        exit;
    }

    public static function sync_config( $panel_url = '', $contract_token = '' ) {
        $panel_url = $panel_url ? $panel_url : get_option( self::OPTION_PANEL_URL, '' );
        $contract_token = $contract_token ? $contract_token : get_option( self::OPTION_CONTRACT_TOKEN, '' );

        $panel_url = trim( $panel_url );
        $contract_token = trim( $contract_token );

        if ( empty( $panel_url ) ) {
            return new WP_Error( 'braga_missing_panel_url', 'Informe a URL do painel Braga.' );
        }

        if ( empty( $contract_token ) ) {
            return new WP_Error( 'braga_missing_token', 'Informe o token do contrato.' );
        }

        $panel_url = untrailingslashit( esc_url_raw( $panel_url ) );

        $endpoint = $panel_url . '/api/checkout/config/' . rawurlencode( $contract_token );

        $response = wp_remote_get( $endpoint, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'braga_request_error',
                'Erro ao conectar no painel Braga: ' . $response->get_error_message()
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            return new WP_Error(
                'braga_invalid_status',
                'Painel Braga retornou status HTTP ' . $status_code . '. Resposta: ' . wp_strip_all_tags( $body )
            );
        }

        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error(
                'braga_invalid_json',
                'Resposta inválida do painel Braga.'
            );
        }

        if ( empty( $data['success'] ) ) {
            $error_message = isset( $data['error'] ) ? $data['error'] : 'Painel Braga recusou a configuração.';
            return new WP_Error( 'braga_api_error', $error_message );
        }

        update_option( self::OPTION_CACHED_CONFIG, $data, false );
        update_option( self::OPTION_LAST_SYNC, current_time( 'mysql' ), false );
        update_option( self::OPTION_LAST_ERROR, '', false );

        return $data;
    }

    public static function get_config() {
        $config = get_option( self::OPTION_CACHED_CONFIG, [] );

        if ( ! is_array( $config ) ) {
            return [];
        }

        return $config;
    }

    public static function get_panel_url() {
        return get_option( self::OPTION_PANEL_URL, '' );
    }

    public static function get_contract_token() {
        return get_option( self::OPTION_CONTRACT_TOKEN, '' );
    }

    public static function get_last_sync() {
        return get_option( self::OPTION_LAST_SYNC, '' );
    }

    public static function get_last_error() {
        return get_option( self::OPTION_LAST_ERROR, '' );
    }

    public static function get_plano_nome() {
        $config = self::get_config();

        return isset( $config['plano']['nome'] )
            ? $config['plano']['nome']
            : '';
    }

    public static function get_plano_slug() {
        $config = self::get_config();

        return isset( $config['plano']['slug'] )
            ? $config['plano']['slug']
            : '';
    }

    public static function get_taxa_percentual() {
        $config = self::get_config();

        return isset( $config['plano']['taxa_percentual'] )
            ? floatval( $config['plano']['taxa_percentual'] )
            : 0;
    }

    public static function has_permission( $permission, $default = true ) {
        $config = self::get_config();

        if ( isset( $config['permissoes'][ $permission ] ) ) {
            return (bool) $config['permissoes'][ $permission ];
        }

        return $default;
    }

    public static function is_connected() {
        $config = self::get_config();

        return ! empty( $config['success'] );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $panel_url      = self::get_panel_url();
        $contract_token = self::get_contract_token();
        $config         = self::get_config();
        $last_sync      = self::get_last_sync();
        $last_error     = self::get_last_error();

        $plano      = isset( $config['plano'] ) && is_array( $config['plano'] ) ? $config['plano'] : [];
        $contrato   = isset( $config['contrato'] ) && is_array( $config['contrato'] ) ? $config['contrato'] : [];
        $cliente    = isset( $config['cliente'] ) && is_array( $config['cliente'] ) ? $config['cliente'] : [];
        $permissoes = isset( $config['permissoes'] ) && is_array( $config['permissoes'] ) ? $config['permissoes'] : [];

        ?>
        <div class="wrap">
            <h1>Braga Checkout</h1>
            <p>Conecte este WooCommerce a um contrato de checkout no painel Braga.</p>

            <?php if ( isset( $_GET['braga_saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Configurações salvas.</strong></p>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['braga_sync'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Configuração sincronizada com sucesso.</strong></p>
                </div>
            <?php endif; ?>

            <?php if ( isset( $_GET['braga_error'] ) ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><strong>Erro:</strong> <?php echo esc_html( rawurldecode( $_GET['braga_error'] ) ); ?></p>
                </div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(320px, 0.7fr); gap: 20px; max-width: 1200px; margin-top: 20px;">

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h2>Conexão com o painel Braga</h2>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'braga_checkout_save_config' ); ?>
                        <input type="hidden" name="action" value="braga_checkout_save_config">

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="panel_url">URL do Painel Braga</label>
                                </th>
                                <td>
                                    <input
                                        type="url"
                                        id="panel_url"
                                        name="panel_url"
                                        value="<?php echo esc_attr( $panel_url ); ?>"
                                        class="regular-text"
                                        placeholder="https://chatbiaa.websbraga.com"
                                    >
                                    <p class="description">
                                        Exemplo: https://chatbiaa.websbraga.com
                                    </p>
                                </td>
                            </tr>

                            <tr>
                                <th scope="row">
                                    <label for="contract_token">Token do contrato</label>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        id="contract_token"
                                        name="contract_token"
                                        value="<?php echo esc_attr( $contract_token ); ?>"
                                        class="regular-text"
                                        placeholder="Cole aqui o token gerado no painel Braga"
                                    >
                                    <p class="description">
                                        Esse token conecta esta loja ao contrato criado no painel Braga.
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button( 'Salvar conexão' ); ?>
                    </form>

                    <hr>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'braga_checkout_sync_config' ); ?>
                        <input type="hidden" name="action" value="braga_checkout_sync_config">

                        <?php submit_button( 'Sincronizar configuração agora', 'primary', 'submit', false ); ?>
                    </form>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h2>Status da configuração</h2>

                    <?php if ( self::is_connected() ) : ?>
                        <p>
                            <span style="display: inline-block; background: #d1fae5; color: #065f46; padding: 4px 10px; border-radius: 999px; font-weight: 700;">
                                Conectado
                            </span>
                        </p>
                    <?php else : ?>
                        <p>
                            <span style="display: inline-block; background: #fee2e2; color: #991b1b; padding: 4px 10px; border-radius: 999px; font-weight: 700;">
                                Não sincronizado
                            </span>
                        </p>
                    <?php endif; ?>

                    <p>
                        <strong>Último sync:</strong><br>
                        <?php echo $last_sync ? esc_html( $last_sync ) : 'Nunca sincronizado'; ?>
                    </p>

                    <?php if ( $last_error ) : ?>
                        <p style="color: #b91c1c;">
                            <strong>Último erro:</strong><br>
                            <?php echo esc_html( $last_error ); ?>
                        </p>
                    <?php endif; ?>

                    <hr>

                    <h3>Plano atual</h3>

                    <p>
                        <strong>Plano:</strong>
                        <?php echo isset( $plano['nome'] ) ? esc_html( $plano['nome'] ) : '-'; ?>
                    </p>

                    <p>
                        <strong>Slug:</strong>
                        <?php echo isset( $plano['slug'] ) ? esc_html( $plano['slug'] ) : '-'; ?>
                    </p>

                    <p>
                        <strong>Taxa:</strong>
                        <?php echo isset( $plano['taxa_percentual'] ) ? esc_html( $plano['taxa_percentual'] ) . '%' : '-'; ?>
                    </p>

                    <p>
                        <strong>Mensalidade:</strong>
                        R$ <?php echo isset( $plano['preco_mensal'] ) ? esc_html( number_format( floatval( $plano['preco_mensal'] ), 2, ',', '.' ) ) : '0,00'; ?>
                    </p>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h2>Contrato</h2>

                    <p>
                        <strong>Loja:</strong>
                        <?php echo isset( $contrato['nome_loja'] ) ? esc_html( $contrato['nome_loja'] ) : '-'; ?>
                    </p>

                    <p>
                        <strong>Cliente:</strong>
                        <?php echo isset( $cliente['nome'] ) ? esc_html( $cliente['nome'] ) : '-'; ?>
                    </p>

                    <p>
                        <strong>Status financeiro:</strong>
                        <?php echo isset( $contrato['status_financeiro'] ) ? esc_html( $contrato['status_financeiro'] ) : '-'; ?>
                    </p>

                    <p>
                        <strong>Status operacional:</strong>
                        <?php echo isset( $contrato['status_operacional'] ) ? esc_html( $contrato['status_operacional'] ) : '-'; ?>
                    </p>

                    <hr style="margin: 20px 0;">

                    <h3>Integração de Pagamentos</h3>
                    
                    <?php 
                    // ---------------------------------------------------------
                    // VERIFICAÇÃO DE INTEGRAÇÃO OAUTH2 (MERCADO PAGO)
                    // ---------------------------------------------------------
                    // A flag 'mp_vinculado' é recebida a partir da sincronização 
                    // com a API central do Painel Braga.
                    $is_mp_connected = isset( $contrato['mp_vinculado'] ) && $contrato['mp_vinculado'];
					
                    
                    if ( self::is_connected() && isset( $contrato['id'] ) ) : 
                        if ( $is_mp_connected ) :
                    ?>
                            <!-- Indicador visual de integração concluída -->
                            <p>
                                <span style="display: inline-block; background: #d1fae5; color: #065f46; padding: 6px 12px; border-radius: 999px; font-weight: 700; border: 1px solid #34d399;">
                                    <span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span> Mercado Pago Conectado
                                </span>
                            </p>
                            <p class="description" style="margin-top: 10px;">
                                A loja já está autorizada a processar pagamentos através do Painel Braga.
                            </p>
                    <?php 
                        else : 
                            // Montagem dinâmica da URL de autorização OAuth2 baseada no ID do contrato
                            $oauth_url = rtrim( $panel_url, '/' ) . '/api/mp/auth/' . intval( $contrato['id'] );
                    ?>
                            <p class="description" style="margin-bottom: 15px;">
                                Para processar pagamentos, é obrigatório vincular sua conta do Mercado Pago.
                            </p>
                            <a href="<?php echo esc_url( $oauth_url ); ?>" target="_blank" style="display: inline-block; background: #009ee3; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: bold;">
                                <span class="dashicons dashicons-lock" style="vertical-align: middle; margin-right: 5px;"></span>
                                Conectar conta do Mercado Pago
                            </a>
                    <?php 
                        endif;
                    else : 
                    ?>
                        <p style="color: #b91c1c;"><em>Sincronize a configuração primeiramente para liberar a conexão.</em></p>
                    <?php endif; ?>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 20px;">
                    <h2>Permissões</h2>

                    <?php
                    $labels = [
                        'pix'        => 'PIX',
                        'cartao'     => 'Cartão',
                        'order_bump' => 'Order bump',
                        'upsell'     => 'Upsell',
                        'ia'         => 'IA',
                        'chatbot'    => 'Chatbot',
                    ];
                    ?>

                    <ul>
                        <?php foreach ( $labels as $key => $label ) : ?>
                            <?php $ativo = isset( $permissoes[ $key ] ) ? (bool) $permissoes[ $key ] : false; ?>
                            <li style="margin-bottom: 8px;">
                                <strong><?php echo esc_html( $label ); ?>:</strong>
                                <?php if ( $ativo ) : ?>
                                    <span style="color: #047857; font-weight: 700;">Ativo</span>
                                <?php else : ?>
                                    <span style="color: #b91c1c; font-weight: 700;">Inativo</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

            </div>
        </div>
        <?php
    }
}

new Custom_Checkout_Braga_Config();