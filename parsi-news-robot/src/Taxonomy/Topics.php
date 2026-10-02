<?php
/**
 * Topic detection: picks the best "topic" category for a story from keywords in its title, its feed
 * categories and its text. Used by sources whose stories cover many subjects (e.g. a city or province
 * feed, where the agency files everything under one desk).
 *
 * @package ParsiNewsRobot
 */

namespace ParsiNewsRobot\Taxonomy;

use ParsiNewsRobot\Settings;
use ParsiNewsRobot\Support\Util;

defined( 'ABSPATH' ) || exit;

class Topics {

	/** A topic needs at least this score (one hit in the title, or three in the text). */
	const MIN_SCORE = 3;

	/** Characters of the story text that are searched. */
	const TEXT_CHARS = 3000;

	/**
	 * Built-in word lists, chosen by the category's name. Category name pattern => words.
	 */
	const DEFAULTS = array(
		'مجازی|سایبر|دیجیتال|تکنولوژی|فناوری اطلاعات' => 'فضای مجازی، اینترنت، اینترنتی، فیلترینگ، فیلترشکن، وی پی ان، VPN، پیام رسان، اینستاگرام، تلگرام، واتساپ، ایتا، روبیکا، تیک تاک، یوتیوب، شبکه اجتماعی، شبکه های اجتماعی، کاربران فضای مجازی، سایبری، هکر، هک، فیشینگ، پلیس فتا، کلاهبرداری اینترنتی، هوش مصنوعی، اپلیکیشن، استارتاپ، پهنای باند، فیبر نوری، اپراتور، همراه اول، ایرانسل، رمز ارز، ارز دیجیتال، وایرال، ترند',
		'ورزش'                                        => 'ورزش، ورزشی، ورزشکار، ورزشکاران، فوتبال، فوتسال، والیبال، بسکتبال، هندبال، کشتی، کشتی گیر، وزنه برداری، تکواندو، کاراته، جودو، ووشو، بوکس، شنا، دوومیدانی، دو و میدانی، دوچرخه سواری، چوخه، لیگ، لیگ برتر، جام حذفی، جام جهانی، المپیک، پارالمپیک، بازی های آسیایی، قهرمانی، مدال، سرمربی، بازیکن، بازیکنان، باشگاه، ورزشگاه، استادیوم، تیم ملی، داور، پنالتی، نقل و انتقالات، پدیده، فرش آرا',
		'حوادث|حادثه|انتظامی'                          => 'حادثه، حوادث، حریق، آتش سوزی، آتش نشانی، آتش نشانان، تصادف، واژگونی، سانحه، کشته، جان باخت، جان باختن، فوت، مرگ، مرگبار، سرنشین، سرنشینان، جرثقیل، مصدوم، مجروح، غرق، سقوط، انفجار، گازگرفتگی، مسمومیت، قتل، قاتل، سرقت، سارق، سارقان، زورگیری، کلاهبرداری، دستگیری، دستگیر، بازداشت، متهم، قمه، شرور، اراذل، مواد مخدر، قاچاق، پلیس، انتظامی، فراجا، اورژانس، هلال احمر، امدادرسانی، زلزله، سیل، آوار',
		'سیاس'                                        => 'سیاسی، سیاست، مجلس، نماینده، نمایندگان، استیضاح، استاندار، استانداری، فرماندار، فرمانداری، دولت، رئیس جمهور، رییس جمهور، انتخابات، نامزد، حزب، احزاب، اصلاح طلب، اصلاح طلبان، اصولگرا، اصولگرایان، رهبر انقلاب، رهبری، امام جمعه، نماز جمعه، علم الهدی، نماینده ولی فقیه، سپاه، بسیج، ارتش، نیروهای مسلح، راهپیمایی، تجمع، حضور مردم، شهید، شهدا',
		'بین الملل|جهان|خارجی'                         => 'بین الملل، بین المللی، آمریکا، آمریکایی، ترامپ، اسرائیل، رژیم صهیونیستی، صهیونیستی، اروپا، اروپایی، روسیه، چین، عراق، افغانستان، طالبان، سوریه، لبنان، حزب الله، یمن، غزه، فلسطین، سازمان ملل، شورای امنیت، ناتو، سفیر، سفارت، کنسولگری، سرکنسول، ترکمنستان، تاجیکستان، ازبکستان، پاکستان، عربستان، تحریم، آژانس بین المللی',
		'اقتصاد|بازار'                                 => 'اقتصاد، اقتصادی، قیمت، قیمت ها، گرانی، گران فروشی، گران فروش، تورم، بازار، تومان، ریال، دلار، ارز، طلا، سکه، بورس، بانک، وام، تسهیلات، یارانه، مالیات، گمرک، صادرات، واردات، ترانزیت، تجارت، بازرگانی، صنعت، صنایع، معدن، کارخانه، تولید، تولیدکننده، تولیدکنندگان، اشتغال، کارآفرینی، بیکاری، مسکن، مسکن ملی، اجاره، اجاره بها، خودرو، بنزین، سوخت، کشاورزی، کشاورزان، نرخ، سرمایه گذاری، بودجه، اعتبارات، تعاون، کالا، کالاهای اساسی، تعزیرات، اتحادیه، اصناف، پلمب، برق، انرژی، نیروگاه، صرافی، مرز، حمل و نقل، ناوگان',
		'فرهنگ|هنر|دین|مذهب'                           => 'فرهنگ، فرهنگی، هنر، هنری، هنرمند، هنرمندان، سینما، سینمایی، تئاتر، نمایش، موسیقی، کنسرت، کتاب، کتابخانه، نویسنده، شاعر، شعر، ادبیات، جشنواره، میراث فرهنگی، موزه، گردشگری، گردشگران، زائر، زائران، زیارت، حرم، آستان قدس، امام رضا، قرآن، قرآنی، مسجد، مساجد، هیئت، عزاداری، اربعین، محرم، رمضان، مداحی، آیین، نماز، اقامه نماز، دینی، مذهبی، معارف، امامزاده',
		'سلامت|پزشک|بهداشت'                            => 'سلامت، بهداشت، درمان، بیمارستان، پزشک، پزشکان، پرستار، دارو، بیماری، بیماران، واکسن، علوم پزشکی، اهدای عضو، اهدای خون',
		'اجتماع|جامعه|شهری'                            => 'اجتماعی، شهری، شهرداری، شورای شهر، ترافیک، مترو، قطار شهری، اتوبوس، محیط زیست، آلودگی هوا، کیفیت هوا، آلوده، هوای ناسالم، گردوغبار، بارندگی، سد، کم آبی، آموزش و پرورش، مدرسه، مدارس، دانش آموز، دانش آموزان، معلم، بهزیستی، کمیته امداد، خانواده، ازدواج، طلاق، اعتیاد، سالمندان، حاشیه شهر',
		'علم|دانشگاه|فناوری'                           => 'علمی، علم، دانشگاه، دانشجو، دانشجویان، پژوهش، پژوهشگر، فناوری، دانش بنیان، پارک علم و فناوری، نخبه، نخبگان، اختراع',
	);

	/**
	 * Default word list for a category, from its name ('' when no list fits).
	 */
	public static function default_words( $name ) {
		$name = Util::normalize_fa( $name );
		foreach ( self::DEFAULTS as $pattern => $words ) {
			if ( preg_match( '/' . Util::normalize_fa( $pattern ) . '/u', $name ) ) {
				return $words;
			}
		}
		return '';
	}

	/**
	 * Words of every topic category: the admin's list, or the built-in one, plus the category's own name.
	 *
	 * @return array<int, string[]> term id => normalised words
	 */
	public static function lexicon() {
		$out = array();
		foreach ( Settings::roles() as $term_id => $role ) {
			if ( 'topic' !== $role['role'] ) {
				continue;
			}
			$term = get_term( $term_id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			$words = isset( $role['keywords'] ) && '' !== trim( (string) $role['keywords'] ) ? $role['keywords'] : self::default_words( $term->name );
			$list  = array( Util::normalize_fa( $term->name ) );
			foreach ( self::split( $words ) as $word ) {
				$list[] = Util::normalize_fa( $word );
			}
			$out[ $term_id ] = array_values( array_unique( array_filter( $list ) ) );
		}
		return $out;
	}

	/**
	 * Words separated by new lines, commas (Latin or Persian) or semicolons.
	 *
	 * @return string[]
	 */
	public static function split( $words ) {
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n,،;؛]+/u', (string) $words ) ) ) );
	}

	/**
	 * Best topic for a story.
	 *
	 * @param string[] $categories The feed entry's own categories (e.g. «اخبار اجتماعی > حوادث»).
	 * @return array{term: int, score: int, words: string[]} term 0 = no confident match.
	 */
	public static function detect( $title, $text, array $categories = array(), $lexicon = null ) {
		$lexicon = null === $lexicon ? self::lexicon() : $lexicon;
		$title   = self::prepare( $title . ' ' . implode( ' ', $categories ) );
		$text    = self::prepare( Util::substr( (string) $text, 0, self::TEXT_CHARS ) );
		$scores  = array();
		foreach ( $lexicon as $term_id => $words ) {
			$score = 0;
			$lead  = 0;
			$hits  = array();
			foreach ( $words as $word ) {
				$in_title = self::count( $title, $word );
				$in_text  = self::count( $text, $word );
				if ( $in_title || $in_text ) {
					$score += 3 * $in_title + min( 3, $in_text );
					$lead  += $in_title;
					$hits[] = $word;
				}
			}
			if ( $score ) {
				$scores[ $term_id ] = array(
					'term'  => (int) $term_id,
					'score' => $score,
					'title' => $lead,
					'words' => $hits,
				);
			}
		}
		$none = array(
			'term'  => 0,
			'score' => 0,
			'words' => array(),
		);
		if ( ! $scores ) {
			return $none;
		}
		uasort(
			$scores,
			function ( $a, $b ) {
				return $b['score'] !== $a['score'] ? $b['score'] - $a['score'] : $b['title'] - $a['title'];
			}
		);
		$ranked = array_values( $scores );
		$top  = $ranked[0];
		$next = isset( $ranked[1] ) ? $ranked[1] : null;
		if ( $top['score'] < self::MIN_SCORE || ( $next && $next['score'] === $top['score'] && $next['title'] === $top['title'] ) ) {
			return array( 'score' => $top['score'] ) + $none; // Too weak, or a real tie: let the fallback decide.
		}
		unset( $top['title'] );
		return $top;
	}

	private static function prepare( $text ) {
		return ' ' . preg_replace( '/[^\p{L}\p{N}]+/u', ' ', Util::normalize_fa( Util::text( $text ) ) ) . ' ';
	}

	/**
	 * Whole-word occurrences, allowing common Persian suffixes (ها، های، ی، ای، ان).
	 */
	private static function count( $haystack, $word ) {
		$word = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $word );
		if ( '' === trim( $word ) ) {
			return 0;
		}
		return (int) preg_match_all( '/ ' . preg_quote( trim( $word ), '/' ) . '(?:ها|های|هایی|ی|ای|ان)? /u', $haystack );
	}
}
