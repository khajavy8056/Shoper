<?php
/**
 * لایه هوش مصنوعی با حداقل سه مدل رایگانِ بدون کلید پولی.
 *
 * یافتهٔ زنده (اوت ۲۰۲۶):
 * - Pollinations GET مدل openai-fast برای درخواست ناشناس کار می‌کند.
 * - کاتالوگ LLM7 زنده است؛ مدل‌های usage_based_only=false شامل gpt-oss:20b و DeepSeek-V4-Flash-0731.
 * - کاتالوگ OVH AI Endpoints زنده است و دسترسی ناشناس رسمی دارد (gpt-oss-20b، Qwen3.6-27B).
 * - Hugging Face Router بدون توکن ۴۰۱ می‌دهد؛ فقط اگر کاربر توکن رایگان خودش را بگذارد.
 *
 * کلید پولی داخل افزونه جاسازی نمی‌شود (در مخزن عمومی فوراً دزدیده می‌شود).
 * اگر همه قطع شوند استودیوی خواجوی متن کامل را می‌نویسد؛ محصول بدون توضیح نمی‌ماند.
 *
 * @package Shoper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * کلاس Shoper_AI_Client.
 */
class Shoper_AI_Client {

	const STATE_OPTION = 'shoper_ai_rotation';
	const DAILY_CAP    = 40;
	const COOLDOWN     = 180;
	const MAX_TRIES    = 3;
	const TIMEOUT      = 16;

	/**
	 * فهرست ارائه‌دهنده‌ها — سه خانواده مستقل + مدل‌های ذخیره.
	 *
	 * @return array
	 */
	public static function default_providers() {
		$providers = array(
			array(
				'id'      => 'pollinations_get',
				'label'   => 'Pollinations GPT-OSS',
				'type'    => 'pollinations_get',
				'url'     => 'https://text.pollinations.ai/',
				'model'   => 'openai-fast',
				'family'  => 'pollinations',
				'browser' => true,
			),
			array(
				'id'      => 'llm7_gptoss',
				'label'   => 'LLM7 GPT-OSS 20B',
				'type'    => 'openai',
				'url'     => 'https://api.llm7.io/v1/chat/completions',
				'model'   => 'gpt-oss:20b',
				'key'     => 'unused',
				'family'  => 'llm7',
				'browser' => true,
			),
			array(
				'id'      => 'ovh_gptoss',
				'label'   => 'OVH GPT-OSS 20B',
				'type'    => 'openai',
				'url'     => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions',
				'model'   => 'gpt-oss-20b',
				'key'     => '',
				'family'  => 'ovh',
				'browser' => true,
			),
			array(
				'id'      => 'pollinations_openai',
				'label'   => 'Pollinations Chat',
				'type'    => 'openai',
				'url'     => 'https://text.pollinations.ai/openai',
				'model'   => 'openai-fast',
				'key'     => '',
				'family'  => 'pollinations',
				'browser' => false,
			),
			array(
				'id'      => 'llm7_flash',
				'label'   => 'LLM7 DeepSeek Flash',
				'type'    => 'openai',
				'url'     => 'https://api.llm7.io/v1/chat/completions',
				'model'   => 'DeepSeek-V4-Flash-0731',
				'key'     => 'unused',
				'family'  => 'llm7',
				'browser' => false,
			),
			array(
				'id'      => 'ovh_qwen',
				'label'   => 'OVH Qwen 3.6',
				'type'    => 'openai',
				'url'     => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions',
				'model'   => 'Qwen3.6-27B',
				'key'     => '',
				'family'  => 'ovh',
				'browser' => true,
			),
			array(
				'id'      => 'llm7_gemini',
				'label'   => 'LLM7 Gemini Lite',
				'type'    => 'openai',
				'url'     => 'https://api.llm7.io/v1/chat/completions',
				'model'   => 'gemini-3.1-flash-lite',
				'key'     => 'unused',
				'family'  => 'llm7',
				'browser' => false,
			),
			array(
				'id'      => 'ovh_mistral',
				'label'   => 'OVH Mistral Nemo',
				'type'    => 'openai',
				'url'     => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/chat/completions',
				'model'   => 'Mistral-Nemo-Instruct-2407',
				'key'     => '',
				'family'  => 'ovh',
				'browser' => false,
			),
		);

		$hf = (string) get_option( 'shoper_hf_token', '' );
		if ( $hf ) {
			array_unshift(
				$providers,
				array(
					'id'      => 'huggingface',
					'label'   => 'Hugging Face Router',
					'type'    => 'openai',
					'url'     => 'https://router.huggingface.co/v1/chat/completions',
					'model'   => 'HuggingFaceTB/SmolLM3-3B',
					'key'     => $hf,
					'family'  => 'huggingface',
					'browser' => false,
				)
			);
		}

		return apply_filters( 'shoper_ai_providers', $providers );
	}

	/**
	 * مدل‌هایی که مرورگر مدیر می‌تواند مستقیم صدا بزند.
	 *
	 * @return array
	 */
	public static function browser_providers() {
		$out = array();
		foreach ( self::default_providers() as $p ) {
			if ( empty( $p['browser'] ) ) {
				continue;
			}
			$out[] = array(
				'id'    => $p['id'],
				'label' => $p['label'],
				'type'  => $p['type'],
				'url'   => $p['url'],
				'model' => $p['model'],
				'key'   => isset( $p['key'] ) ? $p['key'] : '',
			);
		}
		return $out;
	}

	/**
	 * بهبود محصول.
	 *
	 * @param array  $data داده.
	 * @param string $mode auto|studio|remote.
	 * @return array
	 */
	public function enhance( $data, $mode = 'auto' ) {
		$studio = Shoper_Copywriter::enhance( $data );
		if ( 'studio' === $mode || 'no' === get_option( 'shoper_ai_enabled', 'yes' ) ) {
			return $studio;
		}

		$providers = $this->ordered_providers();
		$tried     = array();
		$attempts  = 0;
		foreach ( $providers as $provider ) {
			$family = isset( $provider['family'] ) ? $provider['family'] : $provider['id'];
			if ( isset( $tried[ $family ] ) ) {
				continue;
			}
			if ( $this->is_cooling( $provider['id'] ) || $this->over_daily( $provider['id'] ) ) {
				continue;
			}
			$tried[ $family ] = true;
			if ( ++$attempts > self::MAX_TRIES ) {
				break;
			}
			$compact = ( 'pollinations_get' === $provider['type'] );
			$prompt  = $this->build_prompt( $data, $compact );
			$text    = $this->call_provider( $provider, $prompt );
			if ( is_wp_error( $text ) || ! is_string( $text ) || '' === trim( $text ) ) {
				$this->mark_fail( $provider['id'] );
				continue;
			}
			$parsed = $this->parse_payload( $text );
			if ( ! $parsed ) {
				$this->mark_fail( $provider['id'] );
				continue;
			}
			$this->mark_ok( $provider['id'] );
			$merged                   = $this->merge( $studio, $parsed, $data );
			$merged['provider']       = $provider['id'];
			$merged['provider_label'] = $provider['label'] . ' + تکمیل استودیو خواجوی';
			$merged['remote']         = true;
			return $merged;
		}

		$studio['fallback_reason'] = 'سرویس ابری الان در دسترس نبود؛ متن منبع همین‌جا مرتب و کامل شد.';
		return $studio;
	}

	/**
	 * پرامپت سئو و بازنویسی.
	 *
	 * @param array $data    داده.
	 * @param bool  $compact فشرده برای GET.
	 * @return string
	 */
	public function build_prompt( $data, $compact = false ) {
		$name   = isset( $data['name1'] ) ? Shoper_Copywriter::s( $data['name1'] ) : '';
		$name2  = isset( $data['name2'] ) ? Shoper_Copywriter::s( $data['name2'] ) : '';
		$source = isset( $data['description'] ) ? Shoper_Copywriter::s( $data['description'] ) : '';
		$limit  = $compact ? 360 : 1400;
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $source, 'UTF-8' ) > $limit ) {
			$source = mb_substr( $source, 0, $limit, 'UTF-8' );
		} elseif ( strlen( $source ) > ( $compact ? 420 : 1600 ) ) {
			$source = substr( $source, 0, $compact ? 420 : 1600 );
		}
		$spec_bits = array();
		$bag       = array();
		if ( ! empty( $data['key_specs'] ) && is_array( $data['key_specs'] ) ) {
			$bag = $data['key_specs'];
		}
		if ( ! empty( $data['specs'] ) && is_array( $data['specs'] ) ) {
			$bag = array_merge( $bag, $data['specs'] );
		}
		$i     = 0;
		$max_s = $compact ? 12 : 24;
		foreach ( $bag as $k => $v ) {
			$spec_bits[] = $k . ': ' . Shoper_Copywriter::s( $v );
			if ( ++$i >= $max_s ) {
				break;
			}
		}
		$specs = implode( '؛ ', $spec_bits );

		$rules = "نقش: نویسنده صفحه نقد و بررسی همین کالا.\n"
			. "کار: متن را فقط از منبع و مشخصات همین کالا بنویس. چیدمان را از گروه‌های همین کالا بساز؛ قالب گوشی را روی کالای دیگر کپی نکن. کالا ساده را کوتاه، موبایل و لپ‌تاپ را کامل‌تر بنویس.\n"
			. "لحن: متقاعدکننده اما نامحسوس. شعار نزن. نظر مشتری نساز. مشخصه تازه اختراع نکن.\n"
			. "سئو اجباری: seo_title بین ۵۰ تا ۶۰ نویسه و با کلمه خرید؛ seo_desc بین ۱۴۰ تا ۱۵۵ نویسه با ۲ مشخصه واقعی؛ focus_keyword دو تا چهار کلمه؛ tags هشت مورد.\n"
			. "خروجی فقط JSON با کلیدهای: intro, highlights, pros, cons, verdict, seo_title, seo_desc, focus_keyword, tags\n"
			. "intro معرفی و بررسی ۲ تا ۴ پاراگراف. highlights چهار تا شش نکته. pros مزایا از مشخصات واقعی. cons فقط اگر در جدول نشانه دارد. verdict نتیجه‌گیری کوتاه خرید.\n";

		return $rules . "محصول: {$name}\nانگلیسی: {$name2}\nمنبع: {$source}\nمشخصات: {$specs}";
	}

	/**
	 * ترتیب گردشی.
	 *
	 * @return array
	 */
	public function ordered_providers() {
		$list  = self::default_providers();
		$state = $this->state();
		$start = isset( $state['next'] ) ? (int) $state['next'] : 0;
		$count = count( $list );
		if ( $count < 1 ) {
			return array();
		}
		$start = $start % $count;
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $list[ ( $start + $i ) % $count ];
		}
		return $out;
	}

	/**
	 * وضعیت.
	 *
	 * @return array
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * ذخیره.
	 *
	 * @param array $state وضعیت.
	 * @return void
	 */
	private function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * کول‌داون.
	 *
	 * @param string $id شناسه.
	 * @return bool
	 */
	public function is_cooling( $id ) {
		$state = $this->state();
		$until = isset( $state['cool'][ $id ] ) ? (int) $state['cool'][ $id ] : 0;
		return $until > time();
	}

	/**
	 * سقف روزانه.
	 *
	 * @param string $id شناسه.
	 * @return bool
	 */
	public function over_daily( $id ) {
		$state = $this->state();
		$day   = gmdate( 'Y-m-d' );
		$used  = isset( $state['day'][ $id ][ $day ] ) ? (int) $state['day'][ $id ][ $day ] : 0;
		return $used >= self::DAILY_CAP;
	}

	/**
	 * موفقیت.
	 *
	 * @param string $id شناسه.
	 * @return void
	 */
	private function mark_ok( $id ) {
		$state = $this->state();
		$day   = gmdate( 'Y-m-d' );
		if ( ! isset( $state['day'][ $id ] ) || ! is_array( $state['day'][ $id ] ) ) {
			$state['day'][ $id ] = array();
		}
		$state['day'][ $id ][ $day ] = isset( $state['day'][ $id ][ $day ] ) ? ( (int) $state['day'][ $id ][ $day ] + 1 ) : 1;
		$ids = wp_list_pluck( self::default_providers(), 'id' );
		$idx = array_search( $id, $ids, true );
		$state['next']    = ( false === $idx ) ? 0 : ( ( $idx + 1 ) % max( 1, count( $ids ) ) );
		$state['last_ok'] = $id;
		$state['last_at'] = time();
		unset( $state['cool'][ $id ] );
		$this->save_state( $state );
	}

	/**
	 * شکست.
	 *
	 * @param string $id شناسه.
	 * @return void
	 */
	private function mark_fail( $id ) {
		$state                 = $this->state();
		$state['cool'][ $id ]  = time() + self::COOLDOWN;
		$state['fails'][ $id ] = isset( $state['fails'][ $id ] ) ? ( (int) $state['fails'][ $id ] + 1 ) : 1;
		$this->save_state( $state );
	}

	/**
	 * تماس با ارائه‌دهنده.
	 *
	 * @param array  $provider ارائه‌دهنده.
	 * @param string $prompt   پرامپت.
	 * @return string|WP_Error
	 */
	private function call_provider( $provider, $prompt ) {
		$type = isset( $provider['type'] ) ? $provider['type'] : '';
		if ( 'pollinations_get' === $type ) {
			$url = rtrim( $provider['url'], '/' ) . '/' . rawurlencode( $prompt );
			$url = add_query_arg(
				array(
					'model' => isset( $provider['model'] ) ? $provider['model'] : 'openai-fast',
				),
				$url
			);
			return $this->http( 'GET', $url, array( 'Accept: application/json, text/plain, */*' ), '' );
		}
		if ( 'openai' === $type ) {
			$payload = wp_json_encode(
				array(
					'model'       => isset( $provider['model'] ) ? $provider['model'] : 'openai-fast',
					'messages'    => array(
						array(
							'role'    => 'system',
							'content' => 'You write a Persian product review page from source and specs only. Subtle sales tone. Do not invent specs, prices, or fake reviews. Valid JSON only.',
						),
						array(
							'role'    => 'user',
							'content' => $prompt,
						),
					),
					'temperature' => 0.25,
					'max_tokens'  => 2600,
				)
			);
			$headers = array(
				'Accept: application/json',
				'Content-Type: application/json',
			);
			if ( ! empty( $provider['key'] ) ) {
				$headers[] = 'Authorization: Bearer ' . $provider['key'];
			}
			$body = $this->http( 'POST', $provider['url'], $headers, $payload );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$json = json_decode( (string) $body, true );
			if ( isset( $json['choices'][0]['message']['content'] ) ) {
				return (string) $json['choices'][0]['message']['content'];
			}
			if ( isset( $json['content'] ) && is_string( $json['content'] ) ) {
				return $json['content'];
			}
			return $body;
		}
		return new WP_Error( 'unknown_provider', 'ارائه‌دهنده ناشناخته است.' );
	}

	/**
	 * HTTP با cURL سپس WP HTTP API.
	 *
	 * @param string $method  روش.
	 * @param string $url     آدرس.
	 * @param array  $headers هدرهای خام.
	 * @param string $body    بدنه.
	 * @return string|WP_Error
	 */
	private function http( $method, $url, $headers, $body ) {
		$ua = 'ShoperStudio/1.5.6 (Khajavy; +https://github.com/khajavy8056/Shoper)';
		if ( function_exists( 'curl_init' ) ) {
			$ch  = curl_init( $url );
			$opt = array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 2,
				CURLOPT_TIMEOUT        => self::TIMEOUT,
				CURLOPT_CONNECTTIMEOUT => 8,
				CURLOPT_USERAGENT      => $ua,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_HTTPHEADER     => $headers ? $headers : array( 'Accept: application/json, text/plain, */*' ),
			);
			if ( 'POST' === $method ) {
				$opt[ CURLOPT_POST ]       = true;
				$opt[ CURLOPT_POSTFIELDS ] = $body;
			}
			curl_setopt_array( $ch, $opt );
			$resp  = curl_exec( $ch );
			$errno = (int) curl_errno( $ch );
			$error = curl_error( $ch );
			$code  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			curl_close( $ch );
			if ( false === $resp || 0 !== $errno ) {
				return new WP_Error( 'curl_failed', sprintf( 'cURL #%d: %s', $errno, $error ) );
			}
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'http_error', 'HTTP ' . $code, array( 'status' => $code ) );
			}
			return (string) $resp;
		}

		if ( ! function_exists( 'wp_remote_request' ) ) {
			return new WP_Error( 'no_http', 'روش HTTP در دسترس نیست.' );
		}
		$wp_headers = array();
		foreach ( $headers as $h ) {
			if ( false !== strpos( $h, ':' ) ) {
				list( $k, $v ) = array_map( 'trim', explode( ':', $h, 2 ) );
				$wp_headers[ $k ] = $v;
			}
		}
		$res = wp_remote_request(
			$url,
			array(
				'method'     => $method,
				'timeout'    => self::TIMEOUT,
				'headers'    => $wp_headers,
				'body'       => $body,
				'sslverify'  => true,
				'user-agent' => $ua,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$out  = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'http_error', 'HTTP ' . $code, array( 'status' => $code ) );
		}
		return $out;
	}

	/**
	 * استخراج JSON از پاسخ مدل.
	 *
	 * @param string $text متن.
	 * @return array|null
	 */
	public function parse_payload( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m ) ) {
			$text = $m[1];
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$text = substr( $text, $start, $end - $start + 1 );
		}
		$data = json_decode( $text, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'intro', 'analysis', 'seo_title', 'seo_desc', 'review' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				return $data;
			}
		}
		return null;
	}

	/**
	 * ادغام با حفظ جداول مشخصات و اعمال سئو.
	 *
	 * @param array $studio استودیو.
	 * @param array $remote مدل.
	 * @param array $data   محصول.
	 * @return array
	 */
	public function merge( $studio, $remote, $data = array() ) {
		$out = $studio;
		foreach ( array( 'analysis', 'review', 'audience', 'verdict', 'seo_title', 'seo_desc', 'focus_keyword' ) as $k ) {
			if ( empty( $remote[ $k ] ) || ! is_string( $remote[ $k ] ) ) {
				continue;
			}
			$clean = Shoper_Copywriter::s( $remote[ $k ] );
			$min   = in_array( $k, array( 'analysis', 'review' ), true ) ? 70 : 18;
			if ( strlen( $clean ) > $min ) {
				$out[ $k ] = $clean;
			}
		}
		if ( ! empty( $remote['highlights'] ) && is_array( $remote['highlights'] ) ) {
			$hs = array();
			foreach ( $remote['highlights'] as $h ) {
				$h = Shoper_Copywriter::s( $h );
				if ( $h ) {
					$hs[] = $h;
				}
			}
			if ( $hs ) {
				$out['highlights'] = array_slice( $hs, 0, 8 );
			}
		}
		if ( ! empty( $remote['tags'] ) && is_array( $remote['tags'] ) ) {
			$tags = array();
			foreach ( $remote['tags'] as $t ) {
				$t = Shoper_Copywriter::s( $t );
				if ( $t ) {
					$tags[] = $t;
				}
			}
			if ( $tags ) {
				$out['tags'] = array_slice( array_values( array_unique( $tags ) ), 0, 12 );
			}
		}
		if ( ! empty( $remote['faq'] ) && is_array( $remote['faq'] ) ) {
			$faq = array();
			foreach ( $remote['faq'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$q = Shoper_Copywriter::s( isset( $item['q'] ) ? $item['q'] : '' );
				$a = Shoper_Copywriter::s( isset( $item['a'] ) ? $item['a'] : '' );
				if ( $q && $a ) {
					$faq[] = array(
						'q' => $q,
						'a' => $a,
					);
				}
			}
			if ( $faq ) {
				$out['faq'] = array_slice( $faq, 0, 5 );
			}
		}
		$intro = '';
		if ( ! empty( $remote['intro'] ) ) {
			$intro = Shoper_Copywriter::polish_source( $remote['intro'] );
		}
		if ( Shoper_Copywriter::len( $intro ) < 80 ) {
			$intro = ! empty( $studio['analysis'] ) ? $studio['analysis'] : '';
			if ( ! empty( $data['description'] ) && Shoper_Copywriter::len( $intro ) < 80 ) {
				$intro = Shoper_Copywriter::polish_source( $data['description'] );
			}
		}
		if ( $intro ) {
			$out['intro'] = $intro;
		}
		foreach ( array( 'pros', 'cons' ) as $list_key ) {
			if ( empty( $remote[ $list_key ] ) || ! is_array( $remote[ $list_key ] ) ) {
				continue;
			}
			$items = array();
			foreach ( $remote[ $list_key ] as $item ) {
				$item = Shoper_Copywriter::s( $item );
				if ( $item ) {
					$items[] = $item;
				}
			}
			if ( $items ) {
				$out[ $list_key ] = array_slice( $items, 0, 6 );
			}
		}
		$seo = Shoper_Copywriter::clamp_seo(
			isset( $out['seo_title'] ) ? $out['seo_title'] : '',
			isset( $out['seo_desc'] ) ? $out['seo_desc'] : '',
			isset( $out['focus_keyword'] ) ? $out['focus_keyword'] : '',
			isset( $data['name1'] ) ? $data['name1'] : ( isset( $studio['title'] ) ? $studio['title'] : '' ),
			isset( $data['key_specs'] ) ? $data['key_specs'] : array()
		);
		$out['seo_title']     = $seo['title'];
		$out['seo_desc']      = $seo['description'];
		$out['focus_keyword'] = $seo['keyword'];

		$tech = '';
		if ( ! empty( $remote['analysis'] ) && is_string( $remote['analysis'] ) && Shoper_Copywriter::len( $remote['analysis'] ) > 70 ) {
			$tech = Shoper_Copywriter::s( $remote['analysis'] );
		} elseif ( ! empty( $studio['tech_analysis'] ) ) {
			$tech = $studio['tech_analysis'];
		}
		$out['tech_analysis'] = $tech;
		if ( $intro ) {
			$out['analysis'] = $intro;
		}

		$out['description_html'] = Shoper_Copywriter::assemble_html(
			$data,
			$intro,
			$out['highlights'],
			$tech,
			$out['review'],
			$out['audience'],
			isset( $out['verdict'] ) ? $out['verdict'] : '',
			isset( $out['faq'] ) ? $out['faq'] : array(),
			isset( $out['pros'] ) ? $out['pros'] : array(),
			isset( $out['cons'] ) ? $out['cons'] : array()
		);
		$out['short_description'] = Shoper_Copywriter::short_html(
			isset( $data['name1'] ) ? $data['name1'] : $studio['title'],
			isset( $data['name2'] ) ? $data['name2'] : '',
			isset( $data['key_specs'] ) ? $data['key_specs'] : array(),
			$out['highlights']
		);
		return $out;
	}

	/**
	 * پروب اتصال کوتاه (بدون ساخت محصول).
	 *
	 * @return array
	 */
	public function probe() {
		$rows = array();
		$seen = array();
		foreach ( self::default_providers() as $p ) {
			$family = isset( $p['family'] ) ? $p['family'] : $p['id'];
			if ( isset( $seen[ $family ] ) ) {
				continue;
			}
			$seen[ $family ] = true;
			$row             = array(
				'id'     => $p['id'],
				'label'  => $p['label'],
				'ok'     => false,
				'detail' => '',
			);
			if ( 'pollinations_get' === $p['type'] ) {
				$url  = rtrim( $p['url'], '/' ) . '/OK?model=' . rawurlencode( $p['model'] );
				$body = $this->http( 'GET', $url, array( 'Accept: text/plain, */*' ), '' );
				if ( is_wp_error( $body ) ) {
					$row['detail'] = $body->get_error_message();
				} else {
					$row['ok']     = strlen( trim( (string) $body ) ) > 0;
					$row['detail'] = $row['ok'] ? 'پاسخ متنی دریافت شد' : 'پاسخ خالی';
				}
			} elseif ( 'llm7' === $family ) {
				$body = $this->http( 'GET', 'https://api.llm7.io/v1/models', array( 'Accept: application/json' ), '' );
				$row  = $this->probe_catalog( $row, $body, 'gpt-oss' );
			} elseif ( 'ovh' === $family ) {
				$body = $this->http( 'GET', 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1/models', array( 'Accept: application/json' ), '' );
				$row  = $this->probe_catalog( $row, $body, 'gpt-oss-20b' );
			} else {
				$row['detail'] = 'در چرخش ساخت محصول امتحان می‌شود';
			}
			$rows[] = $row;
		}
		$rows[] = array(
			'id'     => 'studio',
			'label'  => 'استودیوی نویسندگی خواجوی',
			'ok'     => true,
			'detail' => 'همیشه آماده است و به کلید خارجی وابسته نیست',
		);
		return array(
			'providers' => $rows,
			'note'      => 'سه سرویس رایگان بدون کلید پولی در افزونه است: Pollinations، LLM7، OVH. Hugging Face بدون توکن Hub کار نمی‌کند.',
		);
	}

	/**
	 * پروب کاتالوگ مدل.
	 *
	 * @param array             $row  ردیف.
	 * @param string|WP_Error   $body پاسخ.
	 * @param string            $need مدل لازم.
	 * @return array
	 */
	private function probe_catalog( $row, $body, $need ) {
		if ( is_wp_error( $body ) ) {
			$row['detail'] = $body->get_error_message();
			return $row;
		}
		$ok = false !== stripos( (string) $body, $need );
		$row['ok']     = $ok;
		$row['detail'] = $ok ? ( 'کاتالوگ زنده است و مدل ' . $need . ' دیده شد' ) : 'کاتالوگ خوانده شد اما مدل موردنظر نبود';
		return $row;
	}

	/**
	 * خلاصه UI.
	 *
	 * @return array
	 */
	public function status_snapshot() {
		$state = $this->state();
		$rows  = array();
		$day   = gmdate( 'Y-m-d' );
		foreach ( self::default_providers() as $p ) {
			$id   = $p['id'];
			$used = isset( $state['day'][ $id ][ $day ] ) ? (int) $state['day'][ $id ][ $day ] : 0;
			$cool = isset( $state['cool'][ $id ] ) ? (int) $state['cool'][ $id ] : 0;
			$rows[] = array(
				'id'       => $id,
				'label'    => $p['label'],
				'used'     => $used,
				'cap'      => self::DAILY_CAP,
				'cooling'  => $cool > time(),
				'cool_for' => $cool > time() ? ( $cool - time() ) : 0,
			);
		}
		return array(
			'next'      => isset( $state['next'] ) ? (int) $state['next'] : 0,
			'last_ok'   => isset( $state['last_ok'] ) ? $state['last_ok'] : '',
			'providers' => $rows,
		);
	}
}
