<?php
/**
 * لایه هوش مصنوعی با چرخش چند سرویس رایگان عمومی.
 *
 * کلید پولی داخل افزونه گذاشته نمی‌شود (در مخزن عمومی فوراً دزدیده می‌شود).
 * چند اندپوینت رایگانِ ازپیش‌تعریف‌شده به‌صورت گردشی صدا زده می‌شوند؛
 * اگر همه به سقف رایگان بخورند یا قطع باشند، استودیوی نویسندگی خواجوی
 * متن کامل را می‌سازد تا محصول هیچ‌وقت بدون توضیح نماند.
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
	const DAILY_CAP    = 28;
	const COOLDOWN     = 1200;

	/**
	 * فهرست ارائه‌دهنده‌های رایگان ازپیش‌جاگذاری‌شده.
	 *
	 * @return array
	 */
	public static function default_providers() {
		$providers = array(
			array(
				'id'    => 'pollinations_get',
				'label' => 'Pollinations Text',
				'type'  => 'pollinations_get',
				'url'   => 'https://text.pollinations.ai/',
				'model' => 'openai-fast',
			),
			array(
				'id'    => 'pollinations_openai',
				'label' => 'Pollinations Chat',
				'type'  => 'openai',
				'url'   => 'https://text.pollinations.ai/openai',
				'model' => 'openai',
				'key'   => 'pollinations',
			),
			array(
				'id'    => 'llm7_mini',
				'label' => 'LLM7 Mini',
				'type'  => 'openai',
				'url'   => 'https://api.llm7.io/v1/chat/completions',
				'model' => 'gpt-4o-mini-2024-07-18',
				'key'   => 'unused',
			),
			array(
				'id'    => 'llm7_llama',
				'label' => 'LLM7 Llama',
				'type'  => 'openai',
				'url'   => 'https://api.llm7.io/v1/chat/completions',
				'model' => 'llama-3.3-70b-instruct-fp8-fast',
				'key'   => 'unused',
			),
			array(
				'id'    => 'llm7_gemma',
				'label' => 'LLM7 Gemma',
				'type'  => 'openai',
				'url'   => 'https://api.llm7.io/v1/chat/completions',
				'model' => 'gemma-2-9b-it',
				'key'   => 'unused',
			),
		);
		/**
		 * اجازهٔ افزودن اندپوینت سفارشی بدون اجبار به کلید در UI.
		 *
		 * @param array $providers فهرست.
		 */
		return apply_filters( 'shoper_ai_providers', $providers );
	}

	/**
	 * بهبود محصول: چرخش سرویس‌ها + تکمیل با استودیو.
	 *
	 * @param array $data دادهٔ محصول.
	 * @return array
	 */
	public function enhance( $data ) {
		$studio = Shoper_Copywriter::enhance( $data );
		if ( 'no' === get_option( 'shoper_ai_enabled', 'yes' ) ) {
			return $studio;
		}

		$prompt    = $this->build_prompt( $data, $studio );
		$providers = $this->ordered_providers();
		foreach ( $providers as $provider ) {
			if ( $this->is_cooling( $provider['id'] ) || $this->over_daily( $provider['id'] ) ) {
				continue;
			}
			$text = $this->call_provider( $provider, $prompt );
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
			$merged                   = $this->merge( $studio, $parsed );
			$merged['provider']       = $provider['id'];
			$merged['provider_label'] = $provider['label'] . ' + تکمیل استودیو خواجوی';
			$merged['remote']         = true;
			return $merged;
		}

		$studio['fallback_reason'] = 'سرویس‌های رایگان در دسترس نبودند یا به سقف ساعتی رسیدند؛ متن کامل با استودیوی خواجوی نوشته شد.';
		return $studio;
	}

	/**
	 * ترتیب گردشی: از بعد از آخرین موفق شروع کن.
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
	 * وضعیت چرخش.
	 *
	 * @return array
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * ذخیره وضعیت.
	 *
	 * @param array $state وضعیت.
	 * @return void
	 */
	private function save_state( $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * آیا در کول‌داون است؟
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
	 * سقف روزانهٔ رایگان.
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
	 * ثبت موفقیت و رفتن به ارائه‌دهندهٔ بعدی (گردشی).
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
		$ids  = wp_list_pluck( self::default_providers(), 'id' );
		$idx  = array_search( $id, $ids, true );
		$state['next']     = ( false === $idx ) ? 0 : ( ( $idx + 1 ) % max( 1, count( $ids ) ) );
		$state['last_ok']  = $id;
		$state['last_at']  = time();
		unset( $state['cool'][ $id ] );
		$this->save_state( $state );
	}

	/**
	 * ثبت شکست و کول‌داون.
	 *
	 * @param string $id شناسه.
	 * @return void
	 */
	private function mark_fail( $id ) {
		$state = $this->state();
		$state['cool'][ $id ] = time() + self::COOLDOWN;
		$state['fails'][ $id ] = isset( $state['fails'][ $id ] ) ? ( (int) $state['fails'][ $id ] + 1 ) : 1;
		$this->save_state( $state );
	}

	/**
	 * پرامپت دقیق برای مدل.
	 *
	 * @param array $data   داده.
	 * @param array $studio پیش‌نویس استودیو.
	 * @return string
	 */
	public function build_prompt( $data, $studio ) {
		$facts = array(
			'name'        => isset( $data['name1'] ) ? $data['name1'] : '',
			'name_en'     => isset( $data['name2'] ) ? $data['name2'] : '',
			'source_text' => isset( $data['description'] ) ? $data['description'] : '',
			'specs'       => isset( $data['specs'] ) ? $data['specs'] : array(),
			'key_specs'   => isset( $data['key_specs'] ) ? $data['key_specs'] : array(),
		);
		$json  = wp_json_encode( $facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return "تو نویسنده تجاری فروشگاه اینترنتی ایرانی هستی. فقط فارسی روان و رسمی بنویس.\n"
			. "فقط از دادهٔ JSON زیر استفاده کن. هیچ مشخصه، قیمت، امتیاز مشتری یا قابلیت جدید اختراع نکن.\n"
			. "توضیحات باید شامل معرفی، تحلیل کارشناسی، بررسی نقاط قوت و نکات قابل توجه، مخاطب هدف و جمع‌بندی خرید باشد.\n"
			. "خروجی فقط یک JSON معتبر با کلیدهای: intro, analysis, review, audience, verdict, seo_title, seo_desc, focus_keyword, tags (آرایه رشته), highlights (آرایه رشته).\n"
			. "review باید بررسی کارشناسی باشد نه نظر جعلی مشتری.\n"
			. "seo_desc حداکثر ۱۵۵ نویسه.\n"
			. "داده محصول:\n" . $json;
	}

	/**
	 * تماس با یک ارائه‌دهنده.
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
					'model'  => isset( $provider['model'] ) ? $provider['model'] : 'openai-fast',
					'json'   => 'true',
				),
				$url
			);
			return $this->http_get( $url );
		}
		if ( 'openai' === $type ) {
			return $this->http_openai( $provider, $prompt );
		}
		return new WP_Error( 'unknown_provider', 'ارائه‌دهنده ناشناخته است.' );
	}

	/**
	 * GET ساده.
	 *
	 * @param string $url آدرس.
	 * @return string|WP_Error
	 */
	private function http_get( $url ) {
		$args = array(
			'timeout'     => 28,
			'redirection' => 2,
			'user-agent'  => 'ShoperStudio/1.5 (Khajavy)',
			'headers'     => array(
				'Accept' => 'application/json, text/plain, */*',
			),
			'sslverify'   => true,
		);
		if ( function_exists( 'wp_remote_get' ) ) {
			$res = wp_remote_get( $url, $args );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = (string) wp_remote_retrieve_body( $res );
			if ( $code < 200 || $code >= 300 ) {
				return new WP_Error( 'http_error', 'HTTP ' . $code, array( 'status' => $code ) );
			}
			return $body;
		}
		return new WP_Error( 'no_http', 'روش HTTP در دسترس نیست.' );
	}

	/**
	 * POST سازگار با OpenAI.
	 *
	 * @param array  $provider ارائه‌دهنده.
	 * @param string $prompt   پرامپت.
	 * @return string|WP_Error
	 */
	private function http_openai( $provider, $prompt ) {
		$payload = array(
			'model'       => isset( $provider['model'] ) ? $provider['model'] : 'openai',
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You write commercial Persian product copy. Return only valid JSON.',
				),
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0.35,
			'max_tokens'  => 1800,
		);
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		);
		if ( ! empty( $provider['key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $provider['key'];
		}
		$args = array(
			'timeout'     => 32,
			'redirection' => 2,
			'user-agent'  => 'ShoperStudio/1.5 (Khajavy)',
			'headers'     => $headers,
			'body'        => wp_json_encode( $payload ),
			'sslverify'   => true,
		);
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return new WP_Error( 'no_http', 'روش HTTP در دسترس نیست.' );
		}
		$res = wp_remote_post( $provider['url'], $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'http_error', 'HTTP ' . $code, array( 'status' => $code ) );
		}
		$json = json_decode( $body, true );
		if ( isset( $json['choices'][0]['message']['content'] ) ) {
			return (string) $json['choices'][0]['message']['content'];
		}
		if ( isset( $json['content'] ) && is_string( $json['content'] ) ) {
			return $json['content'];
		}
		return $body;
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
		$has = false;
		foreach ( array( 'analysis', 'review', 'intro', 'seo_title' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$has = true;
				break;
			}
		}
		return $has ? $data : null;
	}

	/**
	 * ادغام پاسخ مدل با پیش‌نویس استودیو (جای خالی پر می‌شود).
	 *
	 * @param array $studio استودیو.
	 * @param array $remote مدل.
	 * @param array $data   دادهٔ محصول.
	 * @return array
	 */
	public function merge( $studio, $remote, $data = array() ) {
		$out = $studio;
		$map = array(
			'analysis'      => 'analysis',
			'review'        => 'review',
			'audience'      => 'audience',
			'verdict'       => 'verdict',
			'seo_title'     => 'seo_title',
			'seo_desc'      => 'seo_desc',
			'focus_keyword' => 'focus_keyword',
		);
		foreach ( $map as $from => $to ) {
			if ( ! empty( $remote[ $from ] ) && is_string( $remote[ $from ] ) ) {
				$clean = Shoper_Copywriter::s( $remote[ $from ] );
				if ( strlen( $clean ) > 20 ) {
					$out[ $to ] = $clean;
				}
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
		$intro = ! empty( $remote['intro'] ) ? Shoper_Copywriter::s( $remote['intro'] ) : '';
		if ( strlen( $intro ) < 40 ) {
			$intro = Shoper_Copywriter::intro(
				isset( $studio['category'] ) ? $studio['category'] : 'generic',
				isset( $data['name1'] ) ? $data['name1'] : $studio['title'],
				isset( $data['name2'] ) ? $data['name2'] : '',
				Shoper_Copywriter::spec( isset( $data['specs'] ) ? $data['specs'] : array(), array( 'برند', 'سازنده' ) ),
				isset( $data['description'] ) ? $data['description'] : '',
				isset( $data['specs'] ) ? $data['specs'] : array()
			);
		}
		$out['description_html'] = Shoper_Copywriter::assemble_html(
			$data,
			$intro,
			$out['highlights'],
			$out['analysis'],
			$out['review'],
			$out['audience'],
			$out['verdict']
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
	 * خلاصه وضعیت برای UI.
	 *
	 * @return array
	 */
	public function status_snapshot() {
		$state = $this->state();
		$rows  = array();
		$day   = gmdate( 'Y-m-d' );
		foreach ( self::default_providers() as $p ) {
			$id    = $p['id'];
			$used  = isset( $state['day'][ $id ][ $day ] ) ? (int) $state['day'][ $id ][ $day ] : 0;
			$cool  = isset( $state['cool'][ $id ] ) ? (int) $state['cool'][ $id ] : 0;
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
