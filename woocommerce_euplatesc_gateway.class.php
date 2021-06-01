<?php
		class wc_euplatesc extends WC_Payment_Gateway {
		
			public function __construct() {
				global $woocommerce;
				$this->id = "euplatesc";
				$this->method_title = __( 'EuPlatesc', 'woocommerce' );

				/* Load the settings.*/
				$this->init_form_fields();
				$this->init_settings();
				
				$this->mid = $this->settings['mid'];
				$this->key = $this->settings['key'];
				$this->settings['lang'] == "" ? $this->lang="no":$this->lang=$this->settings['lang'];
				$this->status_new = $this->settings['status_new'];
				$this->status_paid = $this->settings['status_paid'];
				$this->status_fail = $this->settings['status_fail'];
				
				/* Define user set variables*/
				$this->title = $this->get_option( 'title' );
				$this->description = $this->get_option( 'description' );
				$this->enable_for_methods = $this->get_option( 'enable_for_methods', array() );
				
				$this->virtual_complete = $this->get_option( 'virtual_complete' );
				
				
				$this->partial_amount = $this->get_option( 'partial_amount' );
				$this->pay_email = $this->get_option( 'pay_email' );
				
				/*aici adaugam ratele in descrierea*/
				$this->rate=$this->get_option( 'rate' );
				$this->rate_type=$this->get_option( 'rate_mode' );
				
				$this->rate_apb =$this->get_option( 'rate_apb' );
				$this->rate_bcr =$this->get_option( 'rate_bcr' );
				$this->rate_btrl=$this->get_option( 'rate_btrl' );
				$this->rate_brdf=$this->get_option( 'rate_brdf' );
				$this->rate_pbr =$this->get_option( 'rate_pbr' );
				$this->rate_rzb =$this->get_option( 'rate_rzb' );
				
				$this->test =$this->get_option( 'test' );
				
				$this->rate_order =$this->get_option( 'rate_order' );
				
				$this->recurentpreset=$this->get_option( 'recurente' );
				$this->normalprod=0;
				$this->recurentprod=0;
				$this->recurentdif=0;
				$this->lastrec=0;
				
				if(isset($woocommerce->cart)){
					$items = $woocommerce->cart->get_cart();
					
					foreach($items as $item) { 
						$oprec=$item['data']->get_attributes();
						$oprec=@$oprec['recurent']['options'][0];
						if(isset($oprec)){
							if($this->recurentprod && $this->lastrec!=$oprec){
								$this->recurentdif=1;
							}
							$this->has_fields = true;
							$this->recurentprod=1;
							$this->lastrec=$oprec;
						}else{
							$this->normalprod=1;
						}
					}

					if($this->normalprod && $this->recurentprod){
						$this->description="<span style='color:red'>Eroare: produs recurent si nerecurent</span>";
						// eroare produs recurent si nerecurent in cos
					}else if($this->recurentdif){
						$this->description="<span style='color:red'>Eroare: produse recurente cu durate diferite</span>";
						// eroare produse recurente cu recurente diferite
					}else if($this->recurentprod){
						$this->description.=$this->display_recurente();
					}
					
				}
				
				$this->validate_rate_settings();
				  
				if($this->rate=="yes" && !$this->recurentprod){
					$this->has_fields = true;
					$this->description.=$this->display_rate();
				}
				
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				add_action('woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_euplatesc_response' ) );
				add_action('woocommerce_receipt_euplatesc', array(&$this, 'receipt_page'));	
				add_action('woocommerce_order_status_processing', array(&$this,'pay_email_processing'));
				
				$this->mid == '' ? add_action( 'admin_notices', array( &$this, 'apikey_missing_message' ) ) : '';
				$this->key == '' ? add_action( 'admin_notices', array( &$this, 'secret_missing_message' ) ) : '';
				add_action( 'notices', array( &$this, 'secret_missing_message' ) );
				
			}
			
			function is_available(){
		    	$order          = null;
        		$needs_shipping = false;
        
        		// Test if shipping is needed first
        		if ( WC()->cart && WC()->cart->needs_shipping() ) {
        			$needs_shipping = true;
        		} elseif ( is_page( wc_get_page_id( 'checkout' ) ) && 0 < get_query_var( 'order-pay' ) ) {
        			$order_id = absint( get_query_var( 'order-pay' ) );
        			$order    = wc_get_order( $order_id );
        
        			// Test if order needs shipping.
        			if ( 0 < sizeof( $order->get_items() ) ) {
        				foreach ( $order->get_items() as $item ) {
        					$_product = $item->get_product();
        					if ( $_product && $_product->needs_shipping() ) {
        						$needs_shipping = true;
        						break;
        					}
        				}
        			}
        		}
        
        		$needs_shipping = apply_filters( 'woocommerce_cart_needs_shipping', $needs_shipping );
        
        		// Only apply if all packages are being shipped via chosen method, or order is virtual.
        		if ( ! empty( $this->enable_for_methods ) && $needs_shipping ) {
        			$chosen_shipping_methods = array();
        
        			if ( is_object( $order ) ) {
        				$chosen_shipping_methods = array_unique( array_map( 'wc_get_string_before_colon', $order->get_shipping_methods() ) );
        			} elseif ( $chosen_shipping_methods_session = WC()->session->get( 'chosen_shipping_methods' ) ) {
        				$chosen_shipping_methods = array_unique( array_map( 'wc_get_string_before_colon', $chosen_shipping_methods_session ) );
        			}
        
        			if ( 0 < count( array_diff( $chosen_shipping_methods, $this->enable_for_methods ) ) ) {
        				return false;
        			}
        		}
            	return parent::is_available();
			}
			
			function validate_rate_settings(){
				/*validare setari rate*/
				if($this->rate=="yes"){
					if(strlen($this->rate_order)<1){
						$this->rate="no";
						add_action( 'admin_notices', array( &$this, 'rate_error1') );
						return;
					}
					$banci = explode(",", $this->rate_order);
					$ratedisponibile=$this->getRateTableNumber();
					foreach($banci as $banca){
						if($banca<1 or $banca>6){
							$this->rate="no";
							add_action( 'admin_notices', array( &$this, 'rate_error2') );
							return;
						}
						if(strlen($ratedisponibile[$banca])<1){
							$this->rate="no";
							add_action( 'admin_notices', array( &$this, 'rate_error3') );
							return;
						}
						$ratedisponibilearr=explode(",", $ratedisponibile[$banca]);
						foreach($ratedisponibilearr as $rata){
							if($rata<2 or $rata>12){
								$this->rate="no";
								add_action( 'admin_notices', array( &$this, 'rate_error4') );
								return;
							}
						}
					}
				}
				
			}
			
			function validate_fields() {  
				/*validare recurente*/
				if($this->normalprod && $this->recurentprod){
					wc_add_notice( __( 'Eroare: produs recurent si nerecurent' ), 'error' );
					// eroare produs recurent si nerecurent in cos
				}else if($this->recurentdif){
					wc_add_notice( __( 'Eroare: produse recurente cu durate diferite' ), 'error' );
					// eroare produse recurente cu recurente diferite
				}else if($this->recurentprod){
					if($this->lastrec==0 and isset($_POST['recurent']) and (!isset($_POST['recurentval']) or $_POST['recurentval']==0)){
						wc_add_notice( __( 'Selecteaza durata platii recurente' ), 'error' );
						// nu a selectat durata
					}else if($this->lastrec==0 and isset($_POST['recurent']) and isset($_POST['recurentval'])){
						if(array_search($_POST['recurentval'],explode(",",$this->recurentpreset))!=false){
							WC()->session->set("euplatesc_recurent",$_POST['recurentval']);
						}else{
							wc_add_notice( __( 'Eroare: Perioada aleasa nu este disponibila' ) , 'error' );
						}
					}else if($this->lastrec!=0 and isset($_POST['recurentval'])){
						if(array_search($_POST['recurentval'],explode(",",$this->lastrec))!=false){
							WC()->session->set("euplatesc_recurent",$_POST['recurentval']);
						}else{
							wc_add_notice( __( 'Eroare: Perioada aleasa nu este disponibila' ) , 'error' );
						}
					}else if($this->lastrec!=0 and isset($_POST['recurent'])){
						WC()->session->set("euplatesc_recurent",$this->lastrec);
					}
					
				}
				
				/*validare rate*/
				if($this->rate=="no"){
					if(isset($_POST['epr_ptype'])&& $_POST['epr_ptype']=="rate"){
						wc_add_notice( __( 'Ratele sunt active!' ), 'error' );
					}
				}else{
					if(!isset($_POST['epr_ptype'])){
						wc_add_notice( __( 'Alege tipul de plata!' ), 'error' );
					}
					if($this->rate_type=="0"){
						if($_POST['epr_ptype']=="rate"){
							if(!isset($_POST['epr_bank']) or !isset($_POST['epr_nrrate'])){
								wc_add_notice( __( 'Alege banca si numarul de rate!' ), 'error' );
							}
							
							$rate_order = explode(",", $this->rate_order);
							
							if(array_search($_POST['epr_bank'],$this->bankcodetonumber($rate_order))!== false){
								$nr_rate=$this->getRateTable();
								if(array_search($_POST['epr_nrrate'],$nr_rate[$_POST['epr_bank']])!== false){
									 /*setam ratele*/
									 WC()->session->set("euplatesc_rate",$_POST['epr_bank']."-".$_POST['epr_nrrate']);
								}else{
									wc_add_notice( __( 'Selectie invalida numar rate!' ), 'error' );/*numarul de rate nu este activ sau existent*/
								}
							}else{
								wc_add_notice( __( 'Selectie invalida numar rate (2)!' ), 'error' );/*banca nu este activa sau nu exista*/
							}
						}
					}else{
						if($_POST['epr_ptype']=="rate"){
							
							if(!isset($_POST['epr_rate'])){
									wc_add_notice( __( 'Alege numarul de rate la banca dorita!' ), 'error' );
							}
							
							$rate_sel = explode("-", $_POST['epr_rate']);
							$rate_order = explode(",", $this->rate_order);
							
							if(count($rate_sel)==2){
								if(array_search($rate_sel[0],$this->bankcodetonumber($rate_order))!== false){
									$nr_rate=$this->getRateTable();
									if(array_search($rate_sel[1],$nr_rate[$rate_sel[0]])!== false){
										 /*setam ratele*/
										 WC()->session->set("euplatesc_rate",$_POST['epr_rate']);
									}else{
										wc_add_notice( __( 'Selectie invalida numar rate!' ), 'error' );/*numarul de rate nu este activ sau existent*/
									}
								}else{
									wc_add_notice( __( 'Selectie invalida numar rate (1)!!' ), 'error' );/*banca nu este activa sau nu exista*/
								}
							}else{
								wc_add_notice( __( 'Selectie invalida numar rate (2)!!' ), 'error' );
							}
						}
					}
					
				}
				return true; 
			}
			
			function bankcodetonumber($bank){
				switch($bank){
					case "apb": return 1;break;
					case "bcr": return 2;break;
					case "btrl": return 3;break;
					case "brdf": return 4;break;
					case "pbr": return 5;break;
					case "rzb": return 6;break;
				}
			}
			
			function getRateTable(){
				$table["apb"]=explode(",",$this->rate_apb);
				$table["bcr"]=explode(",",$this->rate_bcr);
				$table["btrl"]=explode(",",$this->rate_btrl);
				$table["brdf"]=explode(",",$this->rate_brdf);
				$table["pbr"]=explode(",",$this->rate_pbr);
				$table["rzb"]=explode(",",$this->rate_rzb);
				return $table;
			}
			
			function getRateTableNumber(){
				$table[1]=$this->rate_apb;
				$table[2]=$this->rate_bcr;
				$table[3]=$this->rate_btrl;
				$table[4]=$this->rate_brdf;
				$table[5]=$this->rate_pbr;
				$table[6]=$this->rate_rzb;
				return $table;
			}
			
			function getBanksRate(){
				$html="";
				$banci=$this->get_bank();
				$banci2=$this->get_bank2();
				$rateorder = explode(",", $this->rate_order);
				
				foreach($rateorder as $ord){
					$html.="<option value='".$banci2[$ord][0]."'>".$banci2[$ord][1]."</option>\n";
				}
								
				return $html;
			}
			
			function getDisplayRate2(){
				$allrate=$this->getRateTable();
				$html="";
				
				$banci=$this->get_bank();
				$banci2=$this->get_bank2();
						
				$rateorder = explode(",", $this->rate_order);
				foreach($rateorder as $ord){	
					foreach($allrate[$banci2[$ord][0]] as $nrrate){
						$html.="<tr><td><input name='epr_rate' style='display:inline;'  type='radio' value='".$banci2[$ord][0]."-".$nrrate."' /></td><td>&nbsp; ".$banci2[$ord][1]." in $nrrate rate</td></tr>";
					}	
				}
				return $html;
			}
			
			function display_recurente(){
				if($this->lastrec==0){
					$ret='<br><input style="display:inline;" type="checkbox" value="1" name="recurent" onclick="document.getElementById(\'recurentval\').style.display=this.checked?\'inline\':\'none\';"> Activeaza plata recurenta</input><span id="recurentval" style="display:none;"> pentru <select name="recurentval" style="height:100%;">';
					$ret.='<option value="0">Selecteaza</option>';
					foreach(explode(",",$this->recurentpreset) as $val){
						$ret.='<option value="'.$val.'">'.$val.' luni</option>';
					}
					$ret.='</select></span>';
				}else{
					if(count(explode(",",$this->lastrec))>1){
						$ret='<br><input style="display:inline;" type="checkbox" value="1" name="recurent" onclick="document.getElementById(\'recurentval\').style.display=this.checked?\'inline\':\'none\';"> Activeaza plata recurenta</input><span id="recurentval" style="display:none;"> pentru <select name="recurentval" style="display:inline;height:100%;">';
						$ret.='<option value="0">Selecteaza</option>';
						foreach(explode(",",$this->lastrec) as $val){
							$ret.='<option value="'.$val.'">'.$val.' luni</option>';
						}
						$ret.='</select></span>';
					}else{
						$ret='<br><input style="display:inline;" type="checkbox" value="1" name="recurent"> Activeaza plata recurenta pentru '.$this->lastrec.' luni</input>';
					}
				}
				return $ret;
			}
			
			function display_rate(){
				$ret='<script>';
				$ret.="window.epr_table=epr_table=".json_encode($this->getRateTable()).";";
				if($this->rate_type=="0"){
					$ret.='function epr_switch(a){document.getElementById("rateinfo").onchange();var b=document.getElementById("epr_rate");b.style.display=a?"block":"none"}function epr_changerate(a){for(var b=document.getElementById("rateinfo2"),c="",d=0;d<epr_table[a.value].length;d++)c+="<option value="+epr_table[a.value][d]+">"+epr_table[a.value][d]+"</option>";b.innerHTML=c}window.epr_switch=epr_switch,window.epr_changerate=epr_changerate;';
				}else{
					$ret.='function epr_switch(a){var b=document.getElementById("epr_rate");b.style.display=a?"block":"none"}window.epr_switch=epr_switch;';
				}
				$ret.='</script>';
				
				$ret.='<table style="width: 100%; margin-top:10px;margin-bottom:15px;">
					<tr>
						<td style="">
							<input style="display:inline;" type="radio" checked="checked" value="integral" name="epr_ptype" onchange="epr_switch(0)"> Plata Integrala</input>
						</td>
						<td>
							<input style="display:inline;" type="radio" value="rate" name="epr_ptype" onchange="epr_switch(1)"> Plata in rate</input>
						</td>
					</tr>
				</table>
				<table id="epr_rate" style="margin-bottom:15px;display:none">';
				
				if($this->rate_type=="0"){
					$ret.='<tr>
						<td>Alege banca:</td>
						<td>
							<select name="epr_bank" id="rateinfo" onchange="epr_changerate(this)">';
								$ret.= $this->getBanksRate();
					$ret.='	</select>
						</td>
					</tr>
					<tr>
						<td>Alege numarul de rate:&nbsp;&nbsp;&nbsp;&nbsp;</td>
						<td>
							<select id="rateinfo2" name="epr_nrrate" style="width:60px;"></select>
						</td>
					</tr>';
				}else{
					$ret.= $this->getDisplayRate2();
				} 
					
					
				$ret.= "</table>";
				
				
				
				return $ret;
			}
				
			function init_form_fields() {
			    
			    $shipping_methods = array();

        		foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
        			$shipping_methods[ $method->id ] = $method->get_method_title();
        		}
			    
				$this->form_fields = array(
				
					'enabled' => array(
						'title' => __( 'Activeaza/Dezactiveaza', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Activeaza EuPlatesc', 'woocommerce' ),
						'default' => 'yes'
					),
						
					'title' => array(
						'title' => __( 'Titlu', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'Titlul metodei de plata afisat clientului in timpul comenzii.', 'woocommerce' ),
						'default' => __( 'Online cu card bancar prin EuPlatesc.ro', 'woocommerce' ),
					),

					'description' => array(
						'title' => __( 'Descriere', 'woocommerce' ),
						'type' => 'textarea',
						
						'description' => __( 'Descrierea metodei de plata.', 'woocommerce' ),
						'default' => __('')
					),
						
					'mid' => array(
						'title' => __( 'Merchant ID', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'Va rog sa introduceti codul de client', 'woocommerce'),
						'default' => ''
					),
						
					'key' => array(
						'title' => __( 'Secret KEY', 'woocommerce' ),
						'type' => 'password',
						'description' => __( 'Va rog sa introduceti cheia de securitate', 'woocommerce' ),
						'default' => ''
					),
					 
					'status_new' => array(
						'title' => __( 'Status Comanda Noua', 'woocommerce' ),
						'type' => 'select',
						'default' => 'pending',
						'options' => array('pending'=>'Pending payment', 'pending'=>'Pending payment', 'processing'=>'Processing', 'on-hold'=>'On hold', 'completed'=>'Completed', 'cancelled'=>'Cancelled', 'refunded'=>'Refunded', 'failed'=>'Failed')
					),
					
					'status_paid' => array(
						'title' => __( 'Status Comanda Achitata', 'woocommerce' ),
						'type' => 'select',
						'default' => 'processing',
						'options' => array('pending'=>'Pending payment', 'processing'=>'Processing', 'on-hold'=>'On hold', 'completed'=>'Completed', 'cancelled'=>'Cancelled', 'refunded'=>'Refunded', 'failed'=>'Failed')
					),
					
					'status_fail' => array(
						'title' => __( 'Status Comanda Esuata', 'woocommerce' ),
						'type' => 'select',
						'default' => 'failed',
						'options' => array('pending'=>'Pending payment', 'processing'=>'Processing', 'on-hold'=>'On hold', 'completed'=>'Completed', 'cancelled'=>'Cancelled', 'refunded'=>'Refunded', 'failed'=>'Failed')
					),
					
					'virtual_complete' => array(
						'title' => __( 'Virtual orders', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'La plata cu succes daca comanda este virtuala statusul este complete', 'woocommerce' ),
						'default' => 'no'
					),
					
					'enable_for_methods' => array(
        				'title'             => __( 'Enable for shipping methods', 'woocommerce' ),
        				'type'              => 'multiselect',
        				'class'             => 'wc-enhanced-select',
        				'css'               => 'width: 400px;',
        				'default'           => '',
        				'options'           => $shipping_methods,
        				'custom_attributes' => array(
        					'data-placeholder' => __( 'Select shipping methods', 'woocommerce' ),
        				),
        			),
					
					'test' => array(
						'title' => __( 'Sandbox', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Mod test pentru verificarea functionalitatii (utilizabil doar cat timp contul de comerciant este pe modul test)', 'woocommerce' ),
						'default' => 'no'
					),
					
					'hide_pending' => array(
						'title' => __( 'Pending orders', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Ascunde Comenzile in asteptarea platii', 'woocommerce' ),
						'default' => 'no'
					),
					
					'recurente' => array(
						'title' => __( 'Plati recurente', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'Va rog sa introduceti valorile dorite pentru recurenta<br>Ex: 2,4,5,6,8,10,11,12,24<br>Acestea vor putea fi alese de client pentru produsele ce au atributul recurent = 0<br>Daca atributul recurent al produsului are o alta valoare, aceasta va fi preselectata', 'woocommerce' ),
						'default' => ''
					),
					
					'partial_amount' => array(
						'title' => __( 'Suma partiala', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'Se introduce procentul de suma ce va fi achitata (1-100', 'woocommerce' ),
						'default' => '100'
					),
					
					'pay_email' => array(
						'title' => __( 'Email de plata', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Trimite un email de plata catre client doar cand starea comezii este processing', 'woocommerce' ),
						'default' => 'no'
					),
						
					'lang' => array(
						'title' => __( 'Limba', 'woocommerce' ),
						'type' => 'select',
						'description' => __( 'Limba in care va fi afisata pagina de plata', 'woocommerce' ),
						'default' => '',
						'options' => array('no'=>'Auto', 'ro'=>'Romana', 'en'=>'Engleza', 'de'=>'Germana', 'fr'=>'Franceza', 'es'=>'Spaniola', 'it'=>'Italiana', 'hu'=>'Maghiara')
					),
					
					'rate' => array('title' => __( 'Plati in rate', 'woocommerce' ),'type' => 'checkbox','label' => __( 'Activeaza rate', 'woocommerce' ),'default' => 'no'),
					
					'rate_apb'  => array('title' => __( 'Rate AlphaBank(1)', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 2,4,6,12', 'woocommerce'),'default' => ''),
					'rate_bcr'  => array('title' => __( 'Rate Banca Comerciala Romana(2)', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 2,4,6,12', 'woocommerce'),'default' => ''),
					'rate_btrl' => array('title' => __( 'Rate Banca Transilvania(3)', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 2,4,6,12', 'woocommerce'),'default' => ''),
					'rate_brdf' => array('title' => __( 'Rate BRD Finance(4)', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 2,4,6,12', 'woocommerce'),'default' => ''),
					'rate_pbr'  => array('title' => __( 'Rate Piraeus Bank(5)', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 2,4,6,12', 'woocommerce'),'default' => ''),
					'rate_rzb'  => array('title' => __( 'Rate Raiffeisen Bank(6)', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 2,4,6,12', 'woocommerce'),'default' => ''),
					'rate_order'  => array('title' => __( 'Ordine afisare rate', 'woocommerce' ),'type' => 'text','description' => __( 'Ex: 4,2,5,1,3<br>Apar doar ratele bancilor specificate, in ordinea setata'),'default' => ''),
					'rate_mode' => array(
						'title' => __( 'Mod afisare rate', 'woocommerce' ),
						'type' => 'select',
						'default' => '1',
						'options' => array('0'=>'Restrans', '1'=>'Extins')
					),
				);
			}
				
			function payment_fields() {
				echo $this->description;
			}
			
			function process_payment($order_id){
				global $woocommerce;
				$order = new WC_Order($order_id);
				$order->update_status($this->status_new ,'');
				
				if($this->pay_email=="yes"){
					return array(
						'result' => 'success', 
						'redirect' => $this->get_return_url( $order )
					);
				}else{
					return array(
						'result' => 'success', 
						'redirect' => $this->generate_euplatesc_form($order_id,true)
					);
				}
			} 
				
			function receipt_page($order){			
				echo '<p><strong>' . __('Thank you for your order.', 'woo_euplatesc').'</strong></p>';
				//echo $this->generate_euplatesc_form($order);
			}
				
			function getEndpoint(){
				if($this->test=="yes"){
					return "https://secure.euplatesc.ro/tdsprocess/sandbox.php";
				}else{
					return "https://secure.euplatesc.ro/tdsprocess/tranzactd.php";
				}
			}
				
			function generate_euplatesc_form( $order_id, $link=false ) {			
				global $woocommerce;
				$order = new WC_Order( $order_id );

				$desc = "";
				$payment_schedule = $order->get_meta('_wc_deposits_payment_schedule', true);
				if(strlen($payment_schedule['deposit']['total'])) { // partial payment 
					$desc = "Avans 15% plata masina inchiriere daleo.ro";  
				} else {
					$desc = "Plata masina inchiriere daleo.ro";
				}

				
				$dataAll = array(
					'amount'      => number_format($order->get_total()*((float)$this->partial_amount/100.0),2,'.',''),
					'curr'        => get_woocommerce_currency(),
					'invoice_id'  => $order_id,
					'order_desc'  => $desc, 
					'merch_id'    => $this->mid,                                                
					'timestamp'   => gmdate("YmdHis"),                                     
					'nonce'       => md5(microtime() . mt_rand()),       
				);
				
				if(is_callable(WC()->session->get) && $recurr=WC()->session->get("euplatesc_recurent")){
					$dataAll['recurent_freq']=28;
					$dataAll['recurent_exp']=date('Y-m-d', strtotime("+".$recurr." months", time()));
					$order->add_order_note("Plata recurenta - ".$recurr." luni");
				}
				
				$dataAll['fp_hash'] = strtoupper($this->euplatesc_mac($dataAll,$this->key));
				
				if(is_callable(WC()->session->get) && $recurr=WC()->session->get("euplatesc_recurent")){
					$dataAll['recurent']="Base";
					WC()->session->__unset("euplatesc_rate");
				}
				
				if ( version_compare( WOOCOMMERCE_VERSION, '3.0.0', '>=' ) ) {
					$dataBill = array(
						'fname'	   => $order->get_billing_first_name(),     
						'lname'	   => $order->get_billing_last_name(),  
						'country'  => $order->get_billing_country(),  
						'city'	   => $order->get_billing_city(),      
						'add'	   => $order->get_billing_address_1().$order->get_billing_address_2(),  
						'email'	   => $order->get_billing_email(), 
						'phone'	   => $order->get_billing_phone(),  
					);
				}else{
					$dataBill = array(
						'fname'	   => $order->shipping_first_name,     
						'lname'	   => $order->shipping_last_name,  
						'country'  => $order->shipping_country,  
						'city'	   => $order->shipping_city,      
						'add'	   => $order->shipping_address_1 .' '. $order->shipping_address_2,  
						'email'	   => $order->billing_email, 
						'phone'	   => $order->billing_phone,  
					);
				}
				
				if($this->lang!="no")$dataAll['lang']=$this->lang;
				$dataAll['ExtraData[successurl]']=$this->get_return_url( $order );
				$dataAll['ExtraData[backtosite]']=$order->get_checkout_payment_url( true ).'&pay_for_order=true';
				
				if(is_callable(WC()->session->get) && $nr_rate=WC()->session->get("euplatesc_rate")){
					$dataAll['ExtraData[rate]']=$nr_rate;
					WC()->session->__unset("euplatesc_rate");
				}
				
				/* Remove cart*/
				if(is_callable(WC()->session->get))
					$woocommerce->cart->empty_cart();
				
				$dataAll = array_merge($dataAll,$dataBill);
				
				if($link){
					return $this->getEndpoint()."?".http_build_query($dataAll);
				}
				
				$ret= '<div align="center">';
				$ret.= '<form ACTION="'.$this->getEndpoint().'" METHOD="POST" name="gateway" target="_self">';
				foreach($dataAll as $key=>$value){
					$ret.= '<input type="hidden" name="'.$key.'" VALUE="'.$value.'" />';
				}
				$ret.= '<p class="tx_red_mic">Transferring to EuPlatesc.ro gateway</p>';
				$ret.= '<p><img src="https://www.euplatesc.ro/plati-online/tdsprocess/images/progress.gif" onload="javascript:document.gateway.submit()"></p>';
				$ret.= '<p><a href="javascript:document.gateway.submit();" class="txtCheckout">Go Now!</a></p></form></div>';
				
				return $ret;
			}
			
			function pay_email_processing($order_id){
				
				if($this->pay_email!="yes"){
					return;
				}
				
				$order = new WC_Order( $order_id );
				if ( version_compare( WOOCOMMERCE_VERSION, '3.0.0', '>=' ) ) {
					$to=$order->get_billing_email();
				}else{
					$to=$order->billing_email;
				}
				
				if($order->get_payment_method()!=$this->id){
					return;
				}
				
				$subject="Link de plata finalizare comanda #".$order_id;
				$headers = array('Content-Type: text/html; charset=UTF-8');
				$message="Buna ziua,<br>Pentru a achita comanda #$order_id va rugam sa accesati urmatorul link:<br><br> <a href='".$this->generate_euplatesc_form($order_id,true)."'>Achita comanda #$order_id</a><br><br>Va multumim!";
				wp_mail( $to, $subject, $message,$headers);
				
			}
			
			function is_virtual_order( $order_id ) {
				$order = new WC_Order( $order_id );
				$virtual_order = false;
				if ( count( $order->get_items() ) > 0 ) {
					foreach( $order->get_items() as $item ) {
						if ( 'line_item' == $item['type'] ) {
							$_product = $order->get_product_from_item( $item );
							if ( ! $_product->is_virtual() ) {
								$virtual_order = false;
								break;
							} else {
								$virtual_order = true;
							}
						}
					}
				}
				return $virtual_order;
			}
			
			function check_euplatesc_response(){
				global $wpdb;
				
				if(isset($_POST['cart_id'])){
					
					$order_id = $wpdb->get_var( "SELECT invoice_id FROM ".$wpdb->prefix."wc_euplatesc_trid WHERE ep_id='".$_POST['cart_id']."'" );
								
					$order = new WC_Order( $order_id );
										
					if(isset($_POST['sec_status'])){
						if($_POST['sec_status']==8 or $_POST['sec_status']==9){
							
							if($this->virtual_complete=="yes" && $this->is_virtual_order($_POST['invoice_id'])){$this->status_paid="completed";}
							
							$order->update_status($this->status_paid, __( 'Payment success', 'woocommerce' ));
							$order->reduce_order_stock();
						}if($_POST['sec_status']==5 or $_POST['sec_status']==6){
							$order->update_status($this->status_fail, __( 'Payment failed', 'woocommerce' ));
						}
					}
								
				}else  if(isset($_POST['sec_status']) and isset($_POST['invoice_id'])) {
					$zcrsp =  array (
						'amount'     => addslashes(trim(@$_POST['amount'])), 
						'curr'       => addslashes(trim(@$_POST['curr'])),  
						'invoice_id' => addslashes(trim(@$_POST['invoice_id'])),
						'ep_id'      => addslashes(trim(@$_POST['ep_id'])), 
						'merch_id'   => addslashes(trim(@$_POST['merch_id'])),
						'action'     => addslashes(trim(@$_POST['action'])),
						'message'    => addslashes(trim(@$_POST['message'])),
						'approval'   => addslashes(trim(@$_POST['approval'])),
						'timestamp'  => addslashes(trim(@$_POST['timestamp'])),
						'nonce'      => addslashes(trim(@$_POST['nonce'])),
						'sec_status' => addslashes(trim(@$_POST['sec_status'])),
					);
							 
					$zcrsp['fp_hash'] = strtoupper($this->euplatesc_mac($zcrsp, $this->key));
					$fp_hash=addslashes(trim(@$_POST['fp_hash']));
					
					if($zcrsp['fp_hash']!=$fp_hash)	{
						file_put_contents("ep_err.txt","--- Invalid ipn ".time().print_r($_POST,true)."\n", FILE_APPEND | LOCK_EX);
					} else {
		
						$order = new WC_Order( $_POST['invoice_id'] );
						if($_POST['action'] == 0) {
							if($_POST['sec_status']==8 or $_POST['sec_status']==9){
								if(strpos(strtolower($zcrsp['message']),"pending")==false){ /*to filter sms pending message*/
								
									if($this->virtual_complete=="yes" && $this->is_virtual_order($_POST['invoice_id'])){$this->status_paid="completed";}
									
									$order->update_status($this->status_paid, __( 'Payment success', 'woocommerce' ));
									$order->reduce_order_stock();
								}
							}else{
								$wpdb->insert( $wpdb->prefix . 'wc_euplatesc_trid', array( 'id' => null, 'ep_id' => $zcrsp['ep_id'], 'invoice_id' => $zcrsp['invoice_id']));
							}
							
						} else {
							$order->update_status($this->status_fail, __( 'Payment failed', 'woocommerce' ));
						}
						
					}
						
				} else {
					
					$zcrsp =  array (
						'amount'     => addslashes(trim(@$_POST['amount'])),
						'curr'       => addslashes(trim(@$_POST['curr'])), 
						'invoice_id' => addslashes(trim(@$_POST['invoice_id'])),
						'ep_id'      => addslashes(trim(@$_POST['ep_id'])),
						'merch_id'   => addslashes(trim(@$_POST['merch_id'])),
						'action'     => addslashes(trim(@$_POST['action'])), 
						'message'    => addslashes(trim(@$_POST['message'])),
						'approval'   => addslashes(trim(@$_POST['approval'])),
						'timestamp'  => addslashes(trim(@$_POST['timestamp'])),
						'nonce'      => addslashes(trim(@$_POST['nonce'])),
					);
						 
					$zcrsp['fp_hash'] = strtoupper($this->euplatesc_mac($zcrsp, $this->key));
					$fp_hash=addslashes(trim(@$_POST['fp_hash']));
					
					if($zcrsp['fp_hash']!=$fp_hash)	{
						file_put_contents("ep_err.txt","--- Invalid ipn ".time().print_r($_POST,true)."\n", FILE_APPEND | LOCK_EX);
					} else {
		
						$order = new WC_Order( $_POST['invoice_id'] );
						if($_POST['action'] == 0) {
							if(strpos(strtolower($zcrsp['message']),"pending")==false){ /*to filter sms pending message*/
							
								if($this->virtual_complete=="yes" && $this->is_virtual_order($_POST['invoice_id'])){$this->status_paid="completed";}
								
								$order->update_status($this->status_paid, __( 'Payment success', 'woocommerce' ));
								$order->reduce_order_stock();
							}
						} else {
							$order->update_status($this->status_fail, __( 'Payment failed', 'woocommerce' ));
						}
						
					}
					
				}
				
			}
			
			function hmacsha1($key,$data) {
				$blocksize = 64;
				$hashfunc  = 'md5';
			   
				if(strlen($key) > $blocksize)
					$key = pack('H*', $hashfunc($key));
			   
				$key  = str_pad($key, $blocksize, chr(0x00));
				$ipad = str_repeat(chr(0x36), $blocksize);
				$opad = str_repeat(chr(0x5c), $blocksize);
			   
				$hmac = pack('H*', $hashfunc(($key ^ $opad) . pack('H*', $hashfunc(($key ^ $ipad) . $data))));
				return bin2hex($hmac);
			}

			function euplatesc_mac($data, $key){
				$str = NULL;
				foreach($data as $d){
					if($d === NULL || strlen($d) == 0)
						$str .= '-';
					else
						$str .= strlen($d) . $d;
				}
				$key = pack('H*', $key);
				return $this->hmacsha1($key, $str);
			}
				
			public function get_total() {
			  return apply_filters( 'woocommerce_order_amount_total', number_format( (double) $this->order_total, 2, '.', '' ) );
			}
			
			public function admin_options() {
				echo '<h3>';
				_e( 'Euplatesc Payment', 'woocommerce' );
				echo '</h3><table class="form-table">';
				$this->generate_settings_html();
				echo '</table>';
			}
			
			public function apikey_missing_message() {
				$message = '<div class="error">';
				$message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your MID in euplatesc configuration. %sClick here to configure!%s' , 'woocommerce' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_euplatesc">', '</a>' ) . '</p>';
				$message .= '</div>';
				echo $message;
			}
			
			public function secret_missing_message() {
				$message = '<div class="error">';
				$message .= '<p>' . sprintf( __( '<strong>Gateway Disabled</strong> You should enter your KEY in euplatesc configuration. %sClick here to configure!%s' , 'woocommerce' ), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&amp;tab=checkout&amp;section=wc_euplatesc">', '</a>' ) . '</p>';
				$message .= '</div>';
				echo $message;
			}
			
			public function ep_error_message($title,$msg) {
				$message = '<div class="error">';
				$message .= '<p>' . sprintf( __( '<strong>%s</strong> %s' , 'woocommerce' ), $title, $msg) . '</p>';
				$message .= '</div>';
				echo $message;
			}
			
			public function rate_error1(){$this->ep_error_message("Setari EuPlatesc","Ratele sunt active dar nici o banca nu a fost adaugata in lista ordinii de afisare!<br>Ratele au fost dezactivate pana la rezolvarea problemei!");}
			public function rate_error2(){$this->ep_error_message("Setari EuPlatesc","Cod banca inexistent in lista ordinii de afisare!<br>Ratele au fost dezactivate pana la rezolvarea problemei!");}
			public function rate_error3(){$this->ep_error_message("Setari EuPlatesc","O banca din lista ordinii de afisare nu are nici o rata activa!<br>Ratele au fost dezactivate pana la rezolvarea problemei!");}
			public function rate_error4(){$this->ep_error_message("Setari EuPlatesc","O banca din lista ordinii de afisare are un numar de rate invalid!<br>Ratele au fost dezactivate pana la rezolvarea problemei!");}
				
			private function get_bank(){
				return array(
				  'apb'=>'Alpha Bank',
				  'bcr'=>'Banca Comerciala Romana',
				  'btrl'=>'Banca Transilvania',            
				  'brdf'=>'BRD Finance',         
				  'pbr'=>'Piraeus Bank',                     
				  'rzb'=>'Raiffeisen Bank'                     
				);
			}
			
			private function get_bank2(){
				return array(
				  array('frm','unused'),
				  array('apb','Alpha Bank'),
				  array('bcr','Banca Comerciala Romana'),
				  array('btrl','Banca Transilvania'),            
				  array('brdf','BRD Finance'),         
				  array('pbr','Piraeus Bank'),         
				  array('rzb','Raiffeisen Bank')                    
				);
			}
			
		}	
?>