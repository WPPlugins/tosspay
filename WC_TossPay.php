<?php
class WC_TossPay extends WC_Payment_Gateway
{
	function __construct()
	{
		$this->id                 = 'TossPay';
		$this->title              = 'TossPay';
		$this->icon               = WP_PLUGIN_URL.'/'.plugin_basename( dirname(__FILE__) ).'/assets/TossPay_logo_text.png';
		$this->method_title       = 'TossPay';
		$this->method_description = 'TossPay로 결제하기';
		$this->supports           = array( 'products', 'refunds' );

		//관리페이지 환경 설정 초기화
		$this->init_form_fields();
		$this->init_settings();

		//관리자 환경설정 내용 저장 hook
		add_action( 'woocommerce_update_options_payment_gateways_'.$this->id, array( $this, 'process_admin_options' ) );

		//callback 처리
		add_action( 'woocommerce_api_'.strtolower( get_class( $this ) ), array( $this, 'preOrderCheck' )  );
	}

	//관리페이지를 위한 설정
	function init_form_fields()
	{
		$this->form_fields = array
		(
			'enabled' => array
			(
				'title'       => __( 'Enable/Disable', 'woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'TossPay 활성화', 'woocommerce' ),
				'default'     => 'yes'
			),
			'title' => array
			(
				'title'       => __( 'Title', 'woocommerce' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
				'default'     => __( 'TossPay로 결제하기', 'woocommerce' ),
				'desc_tip'    => true,
			),
			'apikey' => array
			(
				'title'       => __( 'API Key', 'woocommerce' ),
				'type'        => 'text',
				'description' => '먼저 Toss Pay 홈페이지(<a href="http://toss.im/tosspay" target="_blank">http://toss.im/tosspay</a>)에 등록하신 후, 발급받은 상점 API Key를 이곳에 입력해 주세요.',
				'default'     => ''
			)
		);
	}

	//결제 진행하기
	function process_payment( $order_id )
	{
		global $woocommerce;
		$order = new WC_Order( $order_id );

		//tossPay에 결제 생성 요청하기
		$tossPay_Result = $this->createTossPayment( $order_id, $order->get_total() );

		//TossPay에서 발급받은 payToken 저장하기
		update_post_meta( $order_id, 'TossPay_payToken', sanitize_text_field( $tossPay_Result['payToken'] ) );

		//TossPay에서 정상적으로 결제를 생성 받았으면
		if( $tossPay_Result['code'] == 0 )
		{
			// 주문을 on-hold 상태로 변경
			$order->update_status('on-hold');

			// 재고 감소시키기
			$order->reduce_order_stock();

			// 장바구니에 담긴 상품을 비우기
			$woocommerce->cart->empty_cart();

			// TossPay를 위한 결제페이지로 보내기
			return array(
				'result'   => 'success',
				'redirect' => 'https://toss.im/tosspay/order/orderWait?payToken='.$tossPay_Result['payToken'].'&retUrl='.site_url('/my-account/')
			);
		}
		else
		{
			wc_add_notice( '문제가 발생했습니다. - '.$tossPay_Result['code'], 'error' );
			$order->add_order_note( '[TossPay 결제요청 실패] '.$tossPay_Result['code'] );
			return;
		}
	}

	//TossPay에 결제 생성 요청하기
	function createTossPayment( $order_id, $total_price )
	{
		global $woocommerce;
        $order = new WC_Order( $order_id );

        //주문한 상품명 필터링
		$item_names = array();
        if ( sizeof( $order->get_items() ) > 0 )
        {
            foreach ( $order->get_items() as $item )
            {
                if ( $item['qty'] )
                {
                    $item_names[] = preg_replace ("/[ #\&\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#<>()\[\]\{\}]/i", "", $item['name']);
                }
            }
        }
        $goodname = implode( ', ', $item_names );

        //TossPay 결제 생성을 위한 요청 데이터 조합
		$arrayBody = array();
		$arrayBody["orderNo"]            = $order_id;
		$arrayBody["amount"]             = $total_price;
		$arrayBody["productDesc"]        = $goodname;
		$arrayBody["apiKey"]             = $this->get_option( 'apikey' );
		$arrayBody["orderCheckCallback"] = site_url('/wc-api/WC_TossPay?mode=orderCheck');
		$arrayBody["resultCallback"]     = site_url('/wc-api/WC_TossPay?mode=result');
		$arrayBody["expiredTime"]        = date( 'Y-m-d H:i:s', strtotime( current_time('mysql') )+86400 );
		$jsonBody = json_encode($arrayBody);

		//TossPay 결제 생성 요청 기록 메모 남기기
		$order->add_order_note( '[TossPay 결제요청 전문] '.$jsonBody );

		//TossPay에 요청 보내기
		$result = $this->tossCall( 'https://toss.im/tosspay/api/v1/payments', $jsonBody );

		//TossPay에서 받은 결과
		$strGetResult = json_decode($result, true);
		return $strGetResult;
	}

	//callback 처리
	function preOrderCheck()
	{
		@ob_clean();
		global $woocommerce;

		if( isset( $_REQUEST['mode'] ) )
		{
			//결제 변동 상태 통보받기
			if( $_REQUEST['mode'] == 'result' )
			{
				if( isset( $_REQUEST['status'] ) && isset( $_REQUEST['orderNo'] ) )
				{
					$order = new WC_Order( $_REQUEST['orderNo'] );
					if( $order->status !== 'completed' )
					{
						//결제 상태 확인
						switch( $_REQUEST['status'] )
						{
							case 'PAY_SUCCESS':
								$order->add_order_note( '[TossPay 결제 완료됨]');
								$order->update_status('processing');
								break;
							case 'PAY_CANCEL':
								$order->add_order_note( '[TossPay 결제 취소됨 - 토스관리페이지]');
								$order->update_status('cancelled');
								break;
							case 'REFUND_SUCCESS':
								$order->add_order_note( '[TossPay 결제 환불됨 - 토스관리페이지');
								$order->update_status('refunded');
								break;
						}
					}
				}
			}
			//결제할 상품이 결제 가능한지 상태값 돌려주기
			else if( $_REQUEST['mode'] == 'orderCheck' )
			{
				if( isset( $_REQUEST['orderNo'] ) )
				{
					//주문가져오기
					$order = new WC_Order( $_REQUEST['orderNo'] );

					//주문한상품의 재고 가져오기
					$items = $order->get_items();
					$isInStock = true;
					foreach( $items as $item )
					{
						$product_id = $item['product_id'];
						$product = new WC_Product( $product_id );
						if( !$product->is_in_stock() )
						{
							$isInStock = false;
							break;
						}
					}

					//보류중 상태이고, 재고있는 상태일 때 결제 가능함
					if( $order->status == 'on-hold' && $isInStock )
					{
						echo 'ORDER_VALID';
					}
					else
					{
						//결제상태가 보류중이 아님
						if( $order->status !== 'on-hold' )
						{
							$order->add_order_note( '[TossPay 결제확인 실패] 현주문상태 : ' . $order->status );
						}
						//재고부족
						else if( !$isInStock )
						{
							$order->add_order_note( '[TossPay 결제확인 실패] 재고부족 - : '.$product->get_title() );
						}
					}
				}
			}
		}
		exit();
	}

	//TossPay에 환불 요청 보내기
	function process_refund( $order_id, $amount = null, $reason = '' )
	{
		$order = wc_get_order( $order_id );
		$order->add_order_note( '[TossPay 환불요청 시작] ');

		//TossPay에서 발급받은 해당 주문의 payToken 가져오기
		$tossPay_payToken = get_post_meta( $order_id, 'TossPay_payToken', true );
		if( !$tossPay_payToken )
		{
			$order->add_order_note( '[TossPay 환불요청 실패] payToken을 받아오지 못함');
			return false;
		}

		//TossPay에 환불 요청을 위한 데이터 조합
		$arrayBody = array();
		$arrayBody["payToken"] = $tossPay_payToken;
		$arrayBody["amount"]   = $amount;
		$arrayBody["apiKey"]   = $this->get_option( 'apikey' );
		$jsonBody = json_encode($arrayBody);

		//TossPay에 요청 보내기
		$result = $this->tossCall( 'https://toss.im/tosspay/api/v1/refunds', $jsonBody );
		$strGetResult = json_decode($result, true);

		//메모남기기
		$order->add_order_note( '[TossPay 환불요청 전문] '.$jsonBody );

		//TossPay에서 받은 결과 구분하기(0:환불완료)
		if( $strGetResult['code'] == 0 )
		{
			$order->add_order_note( '[TossPay 환불요청 완료]' );
			$order->update_status('refunded');
			return true;
		}
		else
		{
			switch( $strGetResult['code'] )
			{
				case 50:
					$order->add_order_note( '[TossPay 환불요청 실패] 결제 환불 에러' );
					break;
				case 51:
					$order->add_order_note( '[TossPay 환불요청 실패] 존재하지 않는 결제' );
					break;
				case 52:
					$order->add_order_note( '[TossPay 환불요청 실패] 환불 불가 상태' );
					break;
				case 53:
					$order->add_order_note( '[TossPay 환불요청 실패] 기 환불된 결제' );
					break;
				case 54:
					$order->add_order_note( '[TossPay 환불요청 실패] 대기중인 환불이 있음' );
					break;
				case 55:
					$order->add_order_note( '[TossPay 환불요청 실패] 결제 미결' );
					break;
				case 56:
					$order->add_order_note( '[TossPay 환불요청 실패] 환불 가능 금액 초과' );
					break;
				default:
					$order->add_order_note( '[TossPay 환불요청 실패] 실패코드 : ' . $strGetResult['code'] );
					break;
			}
			return false;
		}
		return false;
	}

	//TossPay에 요청 보내기
	function tossCall( $strUrl, $strBody )
	{
		$ch = curl_init( $strUrl );
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $strBody);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Content-Type: application/json',
			'Content-Length: ' . strlen($strBody))
		);

		$result = curl_exec($ch);
		curl_close($ch);

		return $result;
	}
}
?>