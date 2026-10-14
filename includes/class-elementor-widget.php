<?php
/**
 * Registra o Widget Customizado do Checkout no Elementor.
 * Gerencia a renderização do frontend e expõe controles de estilização avançada,
 * incluindo o modo de pré-visualização da tela de sucesso (PIX).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Custom_Checkout_Elementor_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'checkout_1_percent';
    }

    public function get_title() {
        return 'Checkout Customizado';
    }

    public function get_icon() {
        return 'eicon-checkout';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    private function add_button_style_controls( $section_id, $section_label, $css_selector ) {
        $this->start_controls_section(
            $section_id,
            [
                'label' => $section_label,
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->start_controls_tabs( $section_id . '_tabs' );

        // Normal
        $this->start_controls_tab( $section_id . '_normal', [ 'label' => 'Normal' ] );
        $this->add_control(
            $section_id . '_color',
            [
                'label'     => 'Cor do Texto',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} ' . $css_selector => 'color: {{VALUE}} !important;' ],
            ]
        );
        $this->add_control(
            $section_id . '_bg',
            [
                'label'     => 'Cor do Fundo',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} ' . $css_selector => 'background: {{VALUE}} !important;' ],
            ]
        );
        $this->end_controls_tab();

        // Hover
        $this->start_controls_tab( $section_id . '_hover', [ 'label' => 'Hover' ] );
        $this->add_control(
            $section_id . '_hover_color',
            [
                'label'     => 'Cor do Texto',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} ' . $css_selector . ':hover' => 'color: {{VALUE}} !important;' ],
            ]
        );
        $this->add_control(
            $section_id . '_hover_bg',
            [
                'label'     => 'Cor do Fundo',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} ' . $css_selector . ':hover' => 'background: {{VALUE}} !important;' ],
            ]
        );
        $this->end_controls_tab();

        $this->end_controls_tabs();
        $this->end_controls_section();
    }

    protected function register_controls() {
        
        // ---------------------------------------------------------
        // ABA CONTEÚDO: FERRAMENTAS DE DESENVOLVEDOR
        // ---------------------------------------------------------
        $this->start_controls_section(
            'section_dev_tools',
            [
                'label' => 'Ferramentas de Design',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'preview_pix',
            [
                'label'        => 'Modo Preview: Tela PIX',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Ver',
                'label_off'    => 'Ocultar',
                'return_value' => 'yes',
                'default'      => '',
                'description'  => 'Ative para visualizar e estilizar a tela de sucesso do PIX sem precisar gerar um pedido.',
            ]
        );

        $this->end_controls_section();

        // ---------------------------------------------------------
        // ABA ESTILO: ESTILOS GERAIS
        // ---------------------------------------------------------
        $this->start_controls_section(
            'section_general_style',
            [
                'label' => 'Estilos Gerais',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'bg_color',
            [
                'label'     => 'Cor de Fundo da Página',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-checkout-root' => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'panel_border_color',
            [
                'label'     => 'Cor da Borda dos Blocos',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-panel' => 'border-color: {{VALUE}} !important;',
                ],
            ]
        );
        $this->add_control(
            'panel_border_color_inactive',
            [
                'label'     => 'Cor da Borda dos Blocos (Inativos)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-panel.disabled' => 'border-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'step_number_bg',
            [
                'label'     => 'Cor de Fundo dos Números (Círculo)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-step-number' => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'step_number_color',
            [
                'label'     => 'Cor do Texto dos Números',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-step-number' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->end_controls_section();

        // ---------------------------------------------------------
        // ABA ESTILO: TELA DE SUCESSO (PIX)
        // ---------------------------------------------------------
        $this->start_controls_section(
            'section_pix_success_style',
            [
                'label' => 'Tela de Sucesso (PIX)',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'pix_bg_color',
            [
                'label'     => 'Cor de Fundo do Bloco PIX',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-pix-wrapper' => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );
        
        $this->add_control(
            'pix_border_color',
            [
                'label'     => 'Cor da Borda do Bloco PIX',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-pix-wrapper' => 'border-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'pix_title_color',
            [
                'label'     => 'Cor do Título (Sucesso)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-pix-title' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'pix_text_color',
            [
                'label'     => 'Cor dos Textos Informativos',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-pix-text' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'pix_input_bg',
            [
                'label'     => 'Fundo do Campo Copia e Cola',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-pix-input' => 'background-color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'pix_input_color',
            [
                'label'     => 'Cor da Chave Copia e Cola',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-pix-input' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->end_controls_section();

        // ---------------------------------------------------------
        // ABA ESTILO: TEXTOS DE DESTAQUE
        // ---------------------------------------------------------
        $this->start_controls_section(
            'section_text_style',
            [
                'label' => 'Textos de Destaque',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ]
        );

        $this->add_control(
            'title_active_color',
            [
                'label'     => 'Cor dos Títulos e Produtos',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .step-card.active .braga-step-title' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .summary-card h3' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .braga-item-name' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .braga-item-price' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'title_inactive_color',
            [
                'label'     => 'Cor dos Títulos Inativos',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .step-card.disabled .braga-step-title' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'total_color',
            [
                'label'     => 'Cor do Valor Total',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'default'   => '#2ecc71',
                'selectors' => [
                    '{{WRAPPER}} .braga-total-label' => 'color: {{VALUE}} !important;',
                    '{{WRAPPER}} .braga-total-price' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'shipping_method_color',
            [
                'label'     => 'Cor do Texto (Opções de Frete)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-shipping-name' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->add_control(
            'payment_method_color',
            [
                'label'     => 'Cor do Texto (Pix e Cartão)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .braga-payment-label' => 'color: {{VALUE}} !important;',
                ],
            ]
        );

        $this->end_controls_section();

        // ---------------------------------------------------------
        // ABA ESTILO: BOTÕES
        // ---------------------------------------------------------
        $this->add_button_style_controls( 'btn_step1', 'Botão: Identificação', '.braga-btn-step1' );
        $this->add_button_style_controls( 'btn_frete', 'Botão: Calcular Frete', '.braga-btn-frete' );
        $this->add_button_style_controls( 'btn_step2', 'Botão: Ir para Pagamento', '.braga-btn-step2' );
        $this->add_button_style_controls( 'btn_pix', 'Botão: Finalizar PIX', '.braga-btn-pix' );
        $this->add_button_style_controls( 'btn_back', 'Botões: Voltar', '.braga-btn-back' );
    }

    protected function render() {
        // Expondo os settings para o template PHP
        $settings = $this->get_settings_for_display();
        
        echo '<div class="elementor-custom-checkout-wrapper">';
        
        $template_path = plugin_dir_path( __DIR__ ) . 'templates/custom-checkout-page.php';
        if ( file_exists( $template_path ) ) {
            // O arquivo custom-checkout-page.php agora pode acessar a variável $settings
            include $template_path;
        }

        echo '</div>';
    }
}