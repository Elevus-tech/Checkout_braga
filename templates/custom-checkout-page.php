<?php
/**
 * Template customizado da página de checkout - Modo Dark 3 Colunas.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// -----------------------------------------------------------------------------
// MODO PREVIEW DO ELEMENTOR: TELA PIX
// -----------------------------------------------------------------------------
$is_preview_pix = isset($settings['preview_pix']) && $settings['preview_pix'] === 'yes';

if ( $is_preview_pix ) {
    ?>
    <style>
        .braga-checkout-root { background-color: #0b0914; min-height: 100vh; padding: 40px 0; }
        .braga-pix-wrapper { flex: 1; max-width: 600px; margin: 40px auto; text-align: center; border: 1px solid rgba(46, 204, 113, 0.4); border-radius: 8px; padding: 40px; background: rgba(0,0,0,0.2); }
        .braga-pix-title { color: #2ecc71; margin-top: 0; }
        .braga-pix-text { color: #ccc; }
        .braga-pix-input { width: 100%; padding: 14px; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; text-align: center; background: rgba(255,255,255,0.05); color: #fff; cursor: pointer; }
        @keyframes spin-hourglass { 0% { transform: rotate(0deg); } 50% { transform: rotate(180deg); } 100% { transform: rotate(180deg); } }
        .spin-hourglass { display: inline-block; animation: spin-hourglass 2s infinite ease-in-out; }
    </style>
    <div class="braga-checkout-root">
        <div class="checkout-container" style="padding: 20px; font-family: sans-serif;">
            <div class="braga-pix-wrapper">
                <h2 class="braga-pix-title">Pedido Gerado com Sucesso! 🚀</h2>
                <p class="braga-pix-text">Abra o aplicativo do seu banco e escaneie o código abaixo (PREVIEW):</p>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=ExemploPreviewElementor" style="width: 250px; height: 250px; border-radius: 8px; margin: 20px auto; display: block;" />
                <p class="braga-pix-text"><strong>Ou utilize a chave Copia e Cola:</strong></p>
                <input type="text" value="00020126360014br.gov.bcb.pix0114+55119999999995204000053039865802BR5925LOJA DE TESTE..." class="braga-pix-input" readonly />
                <p style="margin-top: 25px;"><span class="braga-pix-text">Aguardando pagamento... <span class="spin-hourglass">⏳</span></span></p>
            </div>
        </div>
    </div>
    <?php
    return;
}

// -----------------------------------------------------------------------------
// FLUXO NORMAL DO CHECKOUT
// -----------------------------------------------------------------------------

$cart = WC()->cart;

if ( is_null( $cart ) || $cart->is_empty() ) {
    echo '<div style="padding: 50px; text-align: center; font-family: sans-serif; color: #fff; background: #0b0914;">';
    echo '<h2 style="color: #2ecc71;">🛒 Seu carrinho está vazio.</h2>';
    echo '<a href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background: #2ecc71; color: white; text-decoration: none; border-radius: 4px;">Voltar para a loja</a>';
    echo '</div>';
    return;
}

$needs_shipping = false;
foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
    $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
    if ( $_product && ! $_product->is_virtual() ) {
        $needs_shipping = true;
        break;
    }
}

// O PULO DO GATO: Lê a configuração do SaaS cacheada, e não o gateway antigo
$braga_config     = get_option( 'braga_checkout_cached_config', [] );
$mp_public_key    = isset( $braga_config['contrato']['mp_public_key'] ) ? $braga_config['contrato']['mp_public_key'] : '';
$cart_total_float = number_format( (float) $cart->get_total( 'edit' ), 2, '.', '' ); 

?>

<script src="https://sdk.mercadopago.com/js/v2"></script>

<style>
    .braga-checkout-root { background-color: #0b0914; min-height: 100vh; padding: 40px 0; }
    .braga-panel { background: transparent; border: 1px solid rgba(255,255,255,0.15); border-radius: 8px; padding: 20px; }
    .step-card { transition: opacity 0.3s ease; }
    .step-card.active { opacity: 1; pointer-events: auto; }
    .step-card.disabled { opacity: 0.5; pointer-events: none; }
    
    .step-card.active .braga-step-title, .summary-card h3, .braga-item-name, .braga-item-price { color: #fff; }
    .step-card.disabled .braga-step-title { color: #888; }
    .braga-total-label, .braga-total-price { color: #2ecc71; }
    .braga-payment-label, .braga-shipping-name { color: #fff; } 

    .braga-step-title { display: flex; align-items: center; gap: 12px; margin-top: 0; margin-bottom: 20px; font-size: 20px; }
    .braga-step-number { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background-color: #fff; color: #0b0914; border-radius: 50%; font-size: 14px; font-weight: 900; transition: all 0.3s ease; }
    .braga-btn-base { border: none; border-radius: 4px; cursor: pointer; font-weight: bold; transition: all 0.3s ease; text-transform: uppercase; padding: 14px 20px; width: 100%; font-size: 16px; }
    .braga-btn-base:disabled { opacity: 0.5; cursor: not-allowed; }
    .braga-checkout-input { width: 100%; padding: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.2); color: #fff; border-radius: 8px; box-sizing: border-box; transition: border 0.3s; }
    .braga-checkout-input:focus { border-color: #8e2de2; outline: none; }

    .braga-pix-wrapper { flex: 1; max-width: 600px; margin: 40px auto; text-align: center; border: 1px solid rgba(46, 204, 113, 0.4); border-radius: 8px; padding: 40px; background: rgba(0,0,0,0.2); }
    .braga-pix-title { color: #2ecc71; margin-top: 0; }
    .braga-pix-text { color: #ccc; }
    .braga-pix-input { width: 100%; padding: 14px; border: 1px solid rgba(255,255,255,0.2); border-radius: 6px; text-align: center; background: rgba(255,255,255,0.05); color: #fff; cursor: pointer; }
    
    @keyframes spin-hourglass { 0% { transform: rotate(0deg); } 50% { transform: rotate(180deg); } 100% { transform: rotate(180deg); } }
    .spin-hourglass { display: inline-block; animation: spin-hourglass 2s infinite ease-in-out; }
    /* Força a cor branca nos textos do formulário do MP */
    
</style>

<div id="custom-checkout-root" class="braga-checkout-root">
    <div style="text-align: right; margin-top: 20px; padding-right: 20px; width: 100%; max-width: 1200px; margin: 0 auto;">
        <label for="theme-selector" style="color: #ccc; font-size: 14px; font-weight: bold; margin-right: 10px;">Tema do Cartão:</label>
        <select id="theme-selector" style="background: rgba(255,255,255,0.05); color: #fff; border: 1px solid rgba(255,255,255,0.2); padding: 8px 12px; border-radius: 6px; outline: none; cursor: pointer;">
            <option value="dark" style="color: #000;">Escuro (Dark)</option>
            <option value="default" style="color: #000;">Claro (Default)</option>
        </select>
    </div>
    <div class="checkout-container" style="display: flex; flex-wrap: wrap; gap: 20px; max-width: 1200px; margin: 0 auto; padding: 20px; font-family: sans-serif; align-items: flex-start; color: #eee;">
        
        <!-- COLUNA 1: IDENTIFICAÇÃO -->
        <div class="col-1" style="flex: 1 1 300px; display: flex; flex-direction: column; gap: 15px;">
            <div id="step-1-container" class="braga-panel step-card active">
                <h3 class="braga-step-title">
                    <span class="braga-step-number">1</span> Identifique-se
                </h3>
                <p style="font-size: 14px; color: #bbb; margin-top: -10px;">Dados para emissão de nota fiscal e contato.</p>
                
                <div style="display: flex; flex-direction: column; gap: 15px; margin-top: 20px;">
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-size: 14px; font-weight: bold; color: #ddd;">E-mail</label>
                        <input type="email" id="chk-email" class="braga-checkout-input" placeholder="ex: maria@gmail.com">
                    </div>
                    <div>
                        <label style="display: block; margin-bottom: 5px; font-size: 14px; font-weight: bold; color: #ddd;">Nome Completo</label>
                        <input type="text" id="chk-nome" class="braga-checkout-input" placeholder="ex: Maria da Silva">
                    </div>
                    <div style="display: flex; gap: 15px;">
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 5px; font-size: 14px; font-weight: bold; color: #ddd;">CPF ou CNPJ</label>
                            <input type="text" id="chk-cpf" class="braga-checkout-input" placeholder="000.000.000-00">
                        </div>
                        <div style="flex: 1;">
                            <label style="display: block; margin-bottom: 5px; font-size: 14px; font-weight: bold; color: #ddd;">Celular / WhatsApp</label>
                            <input type="tel" id="chk-telefone" class="braga-checkout-input" placeholder="(00) 00000-0000">
                        </div>
                    </div>
                    <button id="btn-continuar" class="braga-btn-base braga-btn-step1" style="background: #8e2de2; color: #fff; margin-top: 10px;">
                        Continuar ➔
                    </button>
                </div>
            </div>
        </div>

        <!-- COLUNA 2: ENTREGA & PAGAMENTO -->
        <div class="col-2" style="flex: 1 1 300px; display: flex; flex-direction: column; gap: 15px;">
            
            <div id="step-entrega-container" class="braga-panel step-card disabled" style="<?php echo $needs_shipping ? '' : 'display: none;'; ?>">
                <h3 id="titulo-entrega" class="braga-step-title">
                    <span class="braga-step-number">2</span> Entrega
                </h3>
                
                <div id="conteudo-entrega" style="display: none;">
                    <p style="font-size: 14px; color: #bbb; margin-bottom: 15px;">Informe seu CEP para buscar as opções de entrega.</p>
                    
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="chk-cep" class="braga-checkout-input" maxlength="9" placeholder="00000-000">
                        <button id="btn-calcular-frete" class="braga-btn-base braga-btn-frete" style="background: #3498db; color: #fff; width: auto;">
                            Calcular
                        </button>
                    </div>

                    <div id="endereco-completo-container" style="display: none; flex-direction: column; gap: 10px; margin-bottom: 20px;">
                        <div style="display: flex; gap: 10px;">
                            <div style="flex: 3;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: bold; color: #ddd;">Rua</label>
                                <input type="text" id="chk-rua" class="braga-checkout-input" placeholder="Sua rua">
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: bold; color: #ddd;">Número</label>
                                <input type="text" id="chk-numero" class="braga-checkout-input" placeholder="123">
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <div style="flex: 1;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: bold; color: #ddd;">Complemento</label>
                                <input type="text" id="chk-complemento" class="braga-checkout-input" placeholder="Apto, Casa">
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: bold; color: #ddd;">Bairro</label>
                                <input type="text" id="chk-bairro" class="braga-checkout-input" placeholder="Seu bairro">
                            </div>
                        </div>
                        <div style="display: flex; gap: 10px;">
                            <div style="flex: 2;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: bold; color: #ddd;">Cidade</label>
                                <input type="text" id="chk-cidade" class="braga-checkout-input" readonly style="background: rgba(255,255,255,0.02); opacity: 0.8;">
                            </div>
                            <div style="flex: 1;">
                                <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: bold; color: #ddd;">UF</label>
                                <input type="text" id="chk-estado" class="braga-checkout-input" readonly style="background: rgba(255,255,255,0.02); opacity: 0.8;">
                            </div>
                        </div>
                    </div>

                    <div id="opcoes-frete-container" style="margin-top: 20px; display: flex; flex-direction: column; gap: 10px;"></div>

                    <div style="display: flex; gap: 10px; margin-top: 20px;">
                        <button id="btn-voltar-step1" class="braga-btn-base braga-btn-back" style="flex: 1; background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #ccc;">
                            ⬅ Voltar
                        </button>
                        <button id="btn-continuar-pagamento" class="braga-btn-base braga-btn-step2" style="flex: 2; display: none; background: #8e2de2; color: #fff;">
                            Ir para Pagamento ➔
                        </button>
                    </div>
                </div>
            </div>

            <div id="step-pagamento-container" class="braga-panel step-card disabled">
                <h3 id="titulo-pagamento" class="braga-step-title">
                    <span class="braga-step-number"><?php echo $needs_shipping ? '3' : '2'; ?></span> Pagamento
                </h3>

                <div id="conteudo-pagamento" style="display: none;">
                    <div style="display: flex; gap: 15px; margin-bottom: 25px;">
                        <label style="flex: 1; border: 1px solid #2ecc71; background: rgba(46, 204, 113, 0.1); padding: 15px; border-radius: 6px; cursor: pointer; font-weight: bold; text-align: center; transition: all 0.2s;">
                            <input type="radio" name="payment_method" value="pix" checked style="display: none;">
                            <span class="braga-payment-label" style="font-size: 16px;">💠 PIX</span>
                        </label>
                        <label style="flex: 1; border: 1px solid rgba(255,255,255,0.2); background: transparent; padding: 15px; border-radius: 6px; cursor: pointer; font-weight: bold; text-align: center; transition: all 0.2s;">
                            <input type="radio" name="payment_method" value="credit_card" style="display: none;">
                            <span class="braga-payment-label" style="font-size: 16px; opacity: 0.6;">💳 Cartão</span>
                        </label>
                    </div>

                    <div id="pix-container">
                        <p style="font-size: 14px; color: #bbb; text-align: center;">O código PIX será gerado na próxima etapa. Aprovação imediata.</p>
                        
                        <div style="display: flex; gap: 10px; margin-top: 10px;">
                            <button id="btn-voltar-step2" class="braga-btn-base braga-btn-back" style="flex: 1; background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #ccc;">
                                ⬅ Voltar
                            </button>
                            <button id="btn-finalizar-pix" class="braga-btn-base braga-btn-pix" style="flex: 2; background: #2ecc71; color: #fff;">
                                Finalizar Pedido ➔
                            </button>
                        </div>
                    </div>

                    <div id="cc-container" style="display: none;">
                        <?php if (empty($mp_public_key)): ?>
                            <div style="padding: 15px; background: rgba(231, 76, 60, 0.1); border: 1px solid #e74c3c; border-radius: 6px; text-align: center; color: #e74c3c; font-size: 14px; margin-bottom: 15px;">
                                A integração do Cartão de Crédito requer a configuração da Chave Pública do Mercado Pago.
                            </div>
                        <?php else: ?>
                            <div id="cardPaymentBrick_container"></div>
                        <?php endif; ?>
                        
                        <button id="btn-voltar-step2-cc" class="braga-btn-base braga-btn-back" style="margin-top: 10px; background: transparent; border: 1px solid rgba(255,255,255,0.2); color: #ccc;">
                            ⬅ Voltar
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- COLUNA 3: RESUMO -->
        <div class="col-3" style="flex: 1 1 300px;">
            <div class="braga-panel summary-card">
                <h3>RESUMO</h3>
                
                <div class="totals" style="border-top: 1px solid rgba(255,255,255,0.1); border-bottom: 1px solid rgba(255,255,255,0.1); padding: 15px 0; margin-bottom: 15px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 10px; color: #bbb;">
                        <span>Subtotal</span><span><?php wc_cart_totals_subtotal_html(); ?></span>
                    </div>
                    
                    <div id="resumo-frete-row" style="display: none; justify-content: space-between; margin-bottom: 10px; color: #bbb;">
                        <span>Frete</span><span id="resumo-frete-valor">R$ 0,00</span>
                    </div>

                    <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 1.2em;">
                        <span class="braga-total-label">Total</span>
                        <span class="braga-total-price" id="resumo-total-price"><?php wc_cart_totals_order_total_html(); ?></span>
                    </div>
                </div>

                <div class="cart-items" style="display: flex; flex-direction: column; gap: 15px;">
                    <?php
                    foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                        $_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

                        if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 ) {
                            $product_name  = $_product->get_name();
                            $thumbnail     = $_product->get_image( [ 50, 50 ] ); 
                            $product_price = apply_filters( 'woocommerce_cart_item_subtotal', $cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key );
                            ?>
                            <div class="item" style="display: flex; gap: 10px; align-items: center; background: transparent; padding: 10px; border-radius: 6px; border: 1px solid rgba(255,255,255,0.1);">
                                <div class="item-img" style="width: 50px; height: 50px; border-radius: 4px; overflow: hidden; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,0.05);">
                                    <?php echo $thumbnail ? $thumbnail : '<span style="font-size: 10px; color: #888;">S/ foto</span>'; ?>
                                </div>
                                <div class="item-info" style="flex: 1; font-size: 13px;">
                                    <strong class="braga-item-name" style="display: block; margin-bottom: 4px;"><?php echo esc_html( $product_name ); ?></strong>
                                    <span style="color: #999;">Qtd: <?php echo esc_html( $cart_item['quantity'] ); ?></span>
                                </div>
                                <div class="braga-item-price" style="font-weight: bold; font-size: 14px;">
                                    <?php echo $product_price; ?>
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS DE LÓGICA E ESTADO -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const mpPublicKey = '<?php echo esc_js($mp_public_key); ?>';
        
        const btnContinuar = document.getElementById('btn-continuar');
        const step1Container = document.getElementById('step-1-container');
        const stepPagamentoContainer = document.getElementById('step-pagamento-container');
        const conteudoPagamento = document.getElementById('conteudo-pagamento');
        
        let brickController = null; 
        
        window.checkoutSubtotalBase = parseFloat('<?php echo $cart_total_float; ?>') || 0;
        window.checkoutTotalFinal = parseFloat('<?php echo $cart_total_float; ?>') || 0;

        const needsShipping = <?php echo $needs_shipping ? 'true' : 'false'; ?>;
        const stepEntregaContainer = document.getElementById('step-entrega-container');
        const conteudoEntrega = document.getElementById('conteudo-entrega');
        
        // --- TRANSIÇÕES DE AVANÇO --- //

        btnContinuar.addEventListener('click', function(e) {
            e.preventDefault();
            const email = document.getElementById('chk-email').value.trim();
            const nome = document.getElementById('chk-nome').value.trim();
            const cpf = document.getElementById('chk-cpf').value.trim();

            if (!email || !nome || !cpf) {
                alert('Preencha E-mail, Nome e CPF para continuar.');
                return;
            }

            btnContinuar.innerText = 'DADOS CONFIRMADOS ✔';
            btnContinuar.style.background = '';
            btnContinuar.disabled = true;
            
            step1Container.classList.remove('active');
            step1Container.classList.add('disabled');

            if (needsShipping) {
                stepEntregaContainer.classList.remove('disabled');
                stepEntregaContainer.classList.add('active');
                conteudoEntrega.style.display = 'block';
            } else {
                ativarEtapaPagamento();
            }
        });

        document.getElementById('btn-continuar-pagamento').addEventListener('click', function(e) {
            e.preventDefault();
            this.innerText = 'FRETE CONFIRMADO ✔';
            this.disabled = true;
            this.style.background = '';
            
            stepEntregaContainer.classList.remove('active');
            stepEntregaContainer.classList.add('disabled');
            
            ativarEtapaPagamento();
        });

        function ativarEtapaPagamento() {
            stepPagamentoContainer.classList.remove('disabled');
            stepPagamentoContainer.classList.add('active');
            conteudoPagamento.style.display = 'block';
            
            const isCreditCardSelected = document.querySelector('input[name="payment_method"][value="credit_card"]').checked;
            if (isCreditCardSelected && mpPublicKey) {
                renderCardBrick();
            }
        }

        // --- TRANSIÇÕES DE RETORNO --- //

        document.getElementById('btn-voltar-step1').addEventListener('click', function(e) {
            e.preventDefault();
            stepEntregaContainer.classList.remove('active');
            stepEntregaContainer.classList.add('disabled');
            conteudoEntrega.style.display = 'none';

            step1Container.classList.remove('disabled');
            step1Container.classList.add('active');
            btnContinuar.innerText = 'Continuar ➔';
            btnContinuar.disabled = false;
        });

        function voltarDaEtapaPagamento(e) {
            e.preventDefault();
            stepPagamentoContainer.classList.remove('active');
            stepPagamentoContainer.classList.add('disabled');
            conteudoPagamento.style.display = 'none';

            if (needsShipping) {
                stepEntregaContainer.classList.remove('disabled');
                stepEntregaContainer.classList.add('active');
                conteudoEntrega.style.display = 'block';
                const btnPgto = document.getElementById('btn-continuar-pagamento');
                btnPgto.innerText = 'Ir para Pagamento ➔';
                btnPgto.disabled = false;
            } else {
                step1Container.classList.remove('disabled');
                step1Container.classList.add('active');
                btnContinuar.innerText = 'Continuar ➔';
                btnContinuar.disabled = false;
            }
        }

        document.getElementById('btn-voltar-step2').addEventListener('click', voltarDaEtapaPagamento);
        document.getElementById('btn-voltar-step2-cc').addEventListener('click', voltarDaEtapaPagamento);

        // --- LÓGICA DE FRETE --- //

        document.getElementById('btn-calcular-frete').addEventListener('click', function(e) {
            e.preventDefault();
            const btn = this;
            const cepInput = document.getElementById('chk-cep').value.replace(/\D/g, '');
            
            if (cepInput.length !== 8) {
                alert('Informe um CEP válido com 8 dígitos.');
                return;
            }

            btn.innerText = '...';
            btn.disabled = true;

            fetch(`https://viacep.com.br/ws/${cepInput}/json/`)
                .then(res => res.json())
                .then(viaCepData => {
                    if (viaCepData.erro) throw new Error('CEP não encontrado');
                    
                    document.getElementById('chk-rua').value = viaCepData.logradouro || '';
                    document.getElementById('chk-bairro').value = viaCepData.bairro || '';
                    document.getElementById('chk-cidade').value = viaCepData.localidade || '';
                    document.getElementById('chk-estado').value = viaCepData.uf || '';
                    
                    document.getElementById('endereco-completo-container').style.display = 'flex';
                    document.getElementById('chk-numero').focus();

                    return fetch('/wp-json/checkout1/v1/calculate-shipping', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ 
                            zipcode: cepInput,
                            city: viaCepData.localidade,
                            state: viaCepData.uf
                        })
                    });
                })
                .then(res => res.json())
                .then(data => {
                    btn.innerText = 'Calcular';
                    btn.disabled = false;

                    if (data.success && data.methods.length > 0) {
                        window.checkoutSubtotalBase = data.subtotal;
                        let html = '';
                        
                        data.methods.forEach((method, index) => {
                            const checked = index === 0 ? 'checked' : '';
                            html += `
                                <label style="display: flex; justify-content: space-between; align-items: center; border: 1px solid rgba(255,255,255,0.2); padding: 12px; border-radius: 6px; cursor: pointer; background: rgba(255,255,255,0.05); transition: all 0.2s;">
                                    <div style="display: flex; align-items: center; gap: 8px; max-width: calc(100% - 80px); overflow: hidden;">
                                        <input type="radio" name="shipping_method" value="${method.id}" data-cost="${method.cost}" ${checked} style="flex-shrink: 0;">
                                        <span class="braga-shipping-name" style="font-size: 12px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="${method.label}">${method.label}</span>
                                    </div>
                                    <strong style="color: #2ecc71; font-size: 14px;">R$ ${method.cost.toFixed(2).replace('.', ',')}</strong>
                                </label>
                            `;
                        });
                        
                        document.getElementById('opcoes-frete-container').innerHTML = html;
                        document.getElementById('btn-continuar-pagamento').style.display = 'block';

                        const radiosFrete = document.querySelectorAll('input[name="shipping_method"]');
                        radiosFrete.forEach(radio => {
                            radio.addEventListener('change', (ev) => {
                                let custo = parseFloat(ev.target.getAttribute('data-cost')) || 0;
                                window.checkoutTotalFinal = window.checkoutSubtotalBase + custo;
                                
                                document.getElementById('resumo-frete-row').style.display = 'flex';
                                document.getElementById('resumo-frete-valor').innerText = 'R$ ' + custo.toFixed(2).replace('.', ',');
                                document.getElementById('resumo-total-price').innerText = 'R$ ' + window.checkoutTotalFinal.toFixed(2).replace('.', ',');
                                
                                if (brickController) {
                                    brickController.unmount();
                                    brickController = null;
                                    if(document.getElementById('cc-container').style.display === 'block' && mpPublicKey) {
                                        renderCardBrick();
                                    }
                                }
                            });
                        });
                        
                        const firstRadio = document.querySelector('input[name="shipping_method"]:checked');
                        if (firstRadio) firstRadio.dispatchEvent(new Event('change'));

                    } else {
                        document.getElementById('opcoes-frete-container').innerHTML = '<p style="color: #e74c3c;">Nenhum frete retornado pelas transportadoras.</p>';
                        document.getElementById('resumo-frete-row').style.display = 'none';
                        window.checkoutTotalFinal = window.checkoutSubtotalBase;
                        document.getElementById('resumo-total-price').innerText = 'R$ ' + window.checkoutTotalFinal.toFixed(2).replace('.', ',');
                    }
                })
                .catch(err => {
                    btn.innerText = 'Calcular';
                    btn.disabled = false;
                    alert('Erro ao buscar o CEP ou calcular frete.');
                    console.error(err);
                });
        });

        // --- LÓGICA DE PAGAMENTO (ABAS E SDK) --- //
        const radios = document.querySelectorAll('input[name="payment_method"]');
        const pixContainer = document.getElementById('pix-container');
        const ccContainer = document.getElementById('cc-container');

        radios.forEach(radio => {
            radio.addEventListener('change', (e) => {
                radios.forEach(r => {
                    r.parentElement.style.borderColor = 'rgba(255,255,255,0.2)';
                    r.parentElement.style.background = 'transparent';
                    r.nextElementSibling.style.opacity = '0.6';
                });
                
                if (e.target.value === 'pix') {
                    e.target.parentElement.style.borderColor = '#2ecc71';
                    e.target.parentElement.style.background = 'rgba(46, 204, 113, 0.1)';
                    e.target.nextElementSibling.style.opacity = '1';
                    
                    pixContainer.style.display = 'block';
                    ccContainer.style.display = 'none';
                } else {
                    e.target.parentElement.style.borderColor = '#3498db';
                    e.target.parentElement.style.background = 'rgba(52, 152, 219, 0.1)';
                    e.target.nextElementSibling.style.opacity = '1';

                    pixContainer.style.display = 'none';
                    ccContainer.style.display = 'block';
                    
                    if (!brickController && mpPublicKey) {
                        renderCardBrick();
                    }
                }
            });
        });

        const renderCardBrick = async () => {
            const mp = new MercadoPago(mpPublicKey, { locale: 'pt-BR' });
            const bricksBuilder = mp.bricks();

            const settings = {
                initialization: {
                    amount: window.checkoutTotalFinal, // Agora é Float garantido!
                    payer: { email: document.getElementById('chk-email').value.trim() },
                },
                customization: {
                    visual: {
                        style: { 
                            // Puxa o tema dinamicamente do Select na tela
                            theme: document.getElementById('theme-selector').value,
                            customVariables: {
                                formBackgroundColor: '#0b0914',
                                baseColor: '#B414DE',
                                textPrimaryColor: '#ffffff',
                                textSecondaryColor: '#cccccc'
                            }
                        }
                    },
                    paymentMethods: { maxInstallments: 12 } // Aqui a gente trava em 12x
                },
                callbacks: {
                    onReady: () => {},
                    onSubmit: (cardFormData) => {
                        return new Promise((resolve, reject) => {
                            const selectedShipping = document.querySelector('input[name="shipping_method"]:checked');
                            
                            const payload = {
                                email: document.getElementById('chk-email').value.trim(),
                                nome: document.getElementById('chk-nome').value.trim(),
                                cpf: document.getElementById('chk-cpf').value.trim(),
                                telefone: document.getElementById('chk-telefone').value.trim(),
                                
                                cep: document.getElementById('chk-cep') ? document.getElementById('chk-cep').value.replace(/\D/g, '') : '',
                                rua: document.getElementById('chk-rua') ? document.getElementById('chk-rua').value : '',
                                numero: document.getElementById('chk-numero') ? document.getElementById('chk-numero').value : '',
                                complemento: document.getElementById('chk-complemento') ? document.getElementById('chk-complemento').value : '',
                                bairro: document.getElementById('chk-bairro') ? document.getElementById('chk-bairro').value : '',
                                cidade: document.getElementById('chk-cidade') ? document.getElementById('chk-cidade').value : '',
                                estado: document.getElementById('chk-estado') ? document.getElementById('chk-estado').value : '',
                                shipping_method: selectedShipping ? selectedShipping.value : '',
                                
                                payment_method: 'credit_card',
                                token: cardFormData.token,
                                installments: cardFormData.installments,
                                payment_method_id: cardFormData.payment_method_id,
                                issuer_id: cardFormData.issuer_id
                            };

                            fetch('/wp-json/checkout1/v1/process', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(payload)
                            })
                            .then(res => res.json())
                            .then(data => {
                                if (data.success) {
                                    window.location.href = data.redirect;
                                    resolve();
                                } else {
                                    alert('Transação recusada: ' + data.error);
                                    reject();
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                alert('Erro de comunicação.');
                                reject();
                            });
                        });
                    },
                    onError: (error) => { console.error('[MP Gateway] Erro no Brick:', error); }
                }
            };
            
            brickController = await bricksBuilder.create('cardPayment', 'cardPaymentBrick_container', settings);
        };
        // EVENT LISTENER DO TEMA: Desmonta e remonta o Brick se o cliente trocar o tema
        document.getElementById('theme-selector').addEventListener('change', function() {
            if (brickController && document.getElementById('cc-container').style.display === 'block') {
                brickController.unmount();
                brickController = null;
                renderCardBrick();
            }
        });

        // PIX Finalizar
        document.getElementById('btn-finalizar-pix').addEventListener('click', function(e) {
            e.preventDefault();
            const btnPix = this;
            const selectedShipping = document.querySelector('input[name="shipping_method"]:checked');
            
            const payload = {
                email: document.getElementById('chk-email').value.trim(),
                nome: document.getElementById('chk-nome').value.trim(),
                cpf: document.getElementById('chk-cpf').value.trim(),
                telefone: document.getElementById('chk-telefone').value.trim(),
                
                cep: document.getElementById('chk-cep') ? document.getElementById('chk-cep').value.replace(/\D/g, '') : '',
                rua: document.getElementById('chk-rua') ? document.getElementById('chk-rua').value : '',
                numero: document.getElementById('chk-numero') ? document.getElementById('chk-numero').value : '',
                complemento: document.getElementById('chk-complemento') ? document.getElementById('chk-complemento').value : '',
                bairro: document.getElementById('chk-bairro') ? document.getElementById('chk-bairro').value : '',
                cidade: document.getElementById('chk-cidade') ? document.getElementById('chk-cidade').value : '',
                estado: document.getElementById('chk-estado') ? document.getElementById('chk-estado').value : '',
                shipping_method: selectedShipping ? selectedShipping.value : '',
                
                payment_method: 'pix'
            };

            btnPix.innerText = 'Gerando PIX...';
            btnPix.disabled = true;

            fetch('/wp-json/checkout1/v1/process', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const rootContainer = document.querySelector('.checkout-container');
                    
                    rootContainer.innerHTML = `
                        <div class="braga-pix-wrapper">
                            <h2 class="braga-pix-title">Pedido Gerado com Sucesso! 🚀</h2>
                            <p class="braga-pix-text">Abra o aplicativo do seu banco e escaneie o código abaixo:</p>
                            <img src="data:image/jpeg;base64,${data.pix_base64}" style="width: 250px; height: 250px; border-radius: 8px; margin: 20px auto; display: block;" />
                            <p class="braga-pix-text"><strong>Ou utilize a chave Copia e Cola:</strong></p>
                            <input type="text" value="${data.pix_copia_cola}" class="braga-pix-input" onclick="this.select(); document.execCommand('copy'); alert('Chave copiada!');" readonly />
                            <p style="margin-top: 25px;"><span id="status-texto" class="braga-pix-text">Aguardando pagamento... <span class="spin-hourglass">⏳</span></span></p>
                        </div>
                    `;
                    window.scrollTo({ top: 0, behavior: 'smooth' });

                    const checkInterval = setInterval(() => {
                        fetch(`/wp-json/checkout1/v1/check-status?order_id=${data.order_id}&payment_id=${data.id}`)
                            .then(res => res.json())
                            .then(statusData => {
                                if (statusData.paid) {
                                    clearInterval(checkInterval);
                                    document.getElementById('status-texto').innerHTML = '<strong style="color: #2ecc71; font-size: 18px;">Pagamento Confirmado! 🎉 Redirecionando...</strong>';
                                    
                                    // Puxa a URL dinâmica da página de "Obrigado" do WooCommerce
                                    setTimeout(() => { 
                                        window.location.href = statusData.redirect || data.redirect; 
                                    }, 1500);
                                }
                            })
                            .catch(err => console.error('[Polling Erro]', err));
                    }, 3000);
                } else {
                    alert('Erro ao gerar PIX: ' + data.error);
                    btnPix.innerText = 'Finalizar Pedido ➔';
                    btnPix.disabled = false;
                }
            });
        });
    });
    </script>
</div>