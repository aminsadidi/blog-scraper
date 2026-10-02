<?php
/**
 * Regression tests for bugs found in code review. Run on a test site with the plugin active:
 *
 *     wp eval-file tests/regression.php
 *
 * Each line prints PASS or FAIL; the script exits non-zero when anything fails.
 *
 * @package ParsiNewsRobot
 */


use ParsiNewsRobot\Import\Builder;
use ParsiNewsRobot\Import\Cleaner;
use ParsiNewsRobot\Import\Dedupe;
use ParsiNewsRobot\Import\Extractor;
use ParsiNewsRobot\Import\FeedReader;
use ParsiNewsRobot\Support\Util;

$GLOBALS['pnr_failed'] = 0;
function check( $name, $ok ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		$GLOBALS['pnr_failed']++;
	}
}
$p = str_repeat('<p>متن خبر اصلی که باید کامل بماند، با جزئیات بیشتر درباره موضوع خبر و نقل قول‌ها.</p>', 8);
// 1. ASP.NET form wrapping whole page
$html = '<html><body><form id="form1" method="post"><div class="header">منو</div><div class="item-text">'.$p.'</div></form></body></html>';
$r = Extractor::extract($html, 'https://news.example.ir/news/1', []);
check('asp.net form wrapper keeps body', mb_strlen(strip_tags($r['content'])) > 300);
// 2. iframe with "uploads" in src (own video player)
$html = '<html><body><div class="item-text">'.$p.'<iframe src="https://news.example.ir/uploads/player/embed/5"></iframe></div></body></html>';
$r = Extractor::extract($html, 'https://news.example.ir/news/1', []);
check('iframe with uploads kept', strpos($r['content'], 'iframe') !== false);
// 3. body class="body"
$html = '<html><body class="body"><div class="menu"><a href="/a">لینک</a></div><div id="main">'.$p.'</div><div class="footer-links">'.str_repeat('<p>متن پاورقی سایت که ربطی به خبر ندارد اما طولانی است، و تکرار می‌شود.</p>',3).'</div></body></html>';
$r = Extractor::extract($html, 'https://news.example.ir/news/1', []);
check('body.body not chosen', strpos($r['content'], 'پاورقی') === false);
// 4. signature false positive
$c = Cleaner::clean('<p>وزیر خارجه در تلگرام نوشت که مذاکرات ادامه دارد.</p><p>کشتی به کانال سوئز رسید.</p><p>انتهای پیام/</p><p>کد خبر: 12345</p>', 'https://x.ir/a');
check('legit short paragraphs kept', strpos($c['html'], 'تلگرام نوشت') !== false && strpos($c['html'], 'کانال سوئز') !== false);
check('signatures removed', strpos($c['html'], 'انتهای پیام') === false && strpos($c['html'], 'کد خبر') === false);
// 5. noscript lazy image
$c = Cleaner::clean('<p>متن</p><img src="data:image/gif;base64,R0lGOD"><noscript><img src="https://x.ir/real.jpg" width="800" height="600"></noscript>', 'https://x.ir/a');
check('noscript image recovered', strpos($c['html'], 'real.jpg') !== false);
// 6. form inside content (unwrap, not remove)
$c = Cleaner::clean('<form><p>متن داخل فرم که باید بماند</p></form>', 'https://x.ir/a');
check('form content kept', strpos($c['html'], 'داخل فرم') !== false);
// 1. title XSS via entities
$t = Util::text('&lt;img src=x onerror=alert(1)&gt;خبر مهم &lt;script&gt;alert(2)&lt;/script&gt;');
check('title has no tags after decode', strpos($t,'<')===false);
// 2. content XSS vectors
$c = Cleaner::clean('<p><a href="javascript:alert(1)">a</a><a href="data:text/html,x">b</a><img src="https://x.ir/i.jpg" onerror="alert(1)" width="400" height="300"><svg onload="alert(1)"></svg><iframe src="javascript:alert(1)"></iframe><span style="background:url(javascript:x)">s</span><math><mi xlink:href="javascript:x">m</mi></math></p>', 'https://x.ir/a');
$h=$c['html'];
check('no javascript: / onerror / svg / style', stripos($h,'javascript')===false && stripos($h,'onerror')===false && stripos($h,'<svg')===false && stripos($h,'style=')===false && stripos($h,'data:text')===false);
// 3. source-host iframe that is not a video page
$c = Cleaner::clean('<p>متن</p><iframe src="https://x.ir/login.php"></iframe><iframe src="https://x.ir/embed/video/12"></iframe>', 'https://x.ir/news/1');
check('source-host non-video iframe removed', strpos($c['html'],'login.php')===false && strpos($c['html'],'embed/video')!==false);
// 4. malformed feeds
$item = '<item><title>عنوان خبر یک</title><link>https://x.ir/n/1?a=1&b=2</link><description>متن&nbsp;خبر &copy; ۱۴۰۵</description></item>';
$cases = [
 'BOM+whitespace' => "\xEF\xBB\xBF\n  <?xml version=\"1.0\" encoding=\"utf-8\"?><rss version=\"2.0\"><channel><title>T</title>$item</channel></rss>",
 'bare & and html entities' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><rss version=\"2.0\"><channel><title>T & Co</title>$item</channel></rss>",
 'control chars' => "<?xml version=\"1.0\" encoding=\"utf-8\"?><rss version=\"2.0\"><channel><title>T</title><item><title>عنوان\x0B خبر\x01</title><link>https://x.ir/n/2</link><description>d</description></item></channel></rss>",
];
foreach ($cases as $n=>$xml) { $f = FeedReader::parse($xml); $ok = !is_wp_error($f) && count($f->get_items())===1; check("feed: $n", $ok); }
// 5. short recurring titles are not duplicates of each other
global $wpdb; $wpdb->insert(ParsiNewsRobot\Data\Seen::table(), ['source_id'=>999,'item_hash'=>md5('t'.microtime()),'title_norm'=>Dedupe::title_norm('عکس روز'),'title_hash'=>Dedupe::title_hash('عکس روز'),'link'=>'https://a.ir/1','status'=>'imported','created_at'=>time()]);
check('short title "عکس روز" not treated as duplicate', null === Dedupe::find_similar('عکس روز'));
$wpdb->query("DELETE FROM ".ParsiNewsRobot\Data\Seen::table()." WHERE source_id=999");
// 6. plain permalink path normalization
check('plain permalink ?p=123 normalized', '' !== Util::normalize_path('https://site.ir/?p=123'));
$p = str_repeat('<p>متن اصلی خبر درباره موضوع روز با جزئیات کامل، نقل قول‌ها و توضیحات بیشتر برای خواننده.</p>', 8);
// 1. lead must not come from a sidebar teaser list
$html = '<html><body><div class="sidebar"><ul><li><p class="summary">خلاصه یک خبر دیگر در ستون کناری که ربطی به این خبر ندارد و فقط تیزر است.</p></li><li><p class="summary">خلاصه خبر دیگری در فهرست پربازدیدها که نباید لید این خبر شود.</p></li><li><p class="summary">سومین خلاصه در فهرست کناری سایت خبری برای خبرهای دیگر.</p></li></ul></div><article><div class="item-text">'.$p.'</div></article></body></html>';
$r = Extractor::extract($html, 'https://x.ir/n/1', ['include_lead'=>1]);
check('lead not taken from sidebar teasers', $r['lead'] === '' || strpos($r['lead'],'کناری') === false);
// 2. same link for every item (bad feeds) must not collapse all items into one
$a = Dedupe::item_hash('https://x.ir/', 'g1', 'عنوان یک', true); $b = Dedupe::item_hash('https://x.ir/', 'g2', 'عنوان دو', true);
check('shared links hash per item', $a !== $b);
// 3. firewall / challenge page detected with a clear message
$ch = '<html><head><title>Just a moment...</title></head><body><script src="/cdn-cgi/challenge-platform/x.js"></script>Checking your browser</body></html>';
check('challenge page detected', Builder::is_challenge_page($ch));

// Body-text similarity (republished stories).
$pnr_base = str_repeat( 'دولت امروز اعلام کرد که بودجه سال آینده با تمرکز بر کاهش تورم و حمایت از تولید داخلی تنظیم شده است. ', 1 ) . 'وزیر اقتصاد گفت درآمدهای نفتی کمتر از پیش‌بینی بوده و دولت ناچار است هزینه‌های جاری را کاهش دهد. نمایندگان مجلس از برخی بندهای لایحه انتقاد کردند و خواستار افزایش بودجه بخش سلامت و آموزش شدند. کارشناسان می‌گویند اجرای این بودجه به همکاری دولت و مجلس و ثبات بازار ارز بستگی دارد و بدون اصلاحات ساختاری تورم مهار نمی‌شود.';
$pnr_a = ParsiNewsRobot\Import\Similarity::sketch( $pnr_base );
$pnr_b = ParsiNewsRobot\Import\Similarity::sketch( 'به گزارش خبرگزاری نمونه به نقل از یک رسانه دیگر، ' . $pnr_base . ' انتهای پیام' );
$pnr_c = ParsiNewsRobot\Import\Similarity::sketch( 'تیم ملی فوتبال ایران در دیدار دوستانه برابر حریف آسیایی خود با دو گل به پیروزی رسید. سرمربی تیم ملی پس از بازی گفت بازیکنان جوان عملکرد خوبی داشتند و تیم برای مسابقات انتخابی آماده می‌شود. هواداران در ورزشگاه آزادی حضور پرشوری داشتند و بازی با تشویق آنها به پایان رسید. دو گل ایران را مهاجمان جوان تیم در نیمه دوم به ثمر رساندند.' );
check( 'republished copy with attribution scores >= 80%', ParsiNewsRobot\Import\Similarity::score( $pnr_a, $pnr_b ) >= 0.8 );
check( 'different story scores < 30%', ParsiNewsRobot\Import\Similarity::score( $pnr_a, $pnr_c ) < 0.3 );
check( 'sketch survives encode/decode', ParsiNewsRobot\Import\Similarity::decode( ParsiNewsRobot\Import\Similarity::encode( $pnr_a ) ) == $pnr_a );
check( 'sketch of another format version is ignored', null === ParsiNewsRobot\Import\Similarity::decode( '12:AAAA' ) );
check( 'short texts are not fingerprinted', null === ParsiNewsRobot\Import\Similarity::sketch( 'خبر کوتاه دو خطی' ) );

// Customisable attribution and links.
$pnr_html = '<p>متن <a href="https://src.ir/x" data-pnr-link="1">لینک متن</a></p><p class="pnr-source">به نقل از <a href="https://src.ir/" data-pnr-link="attr">منبع</a></p><p><a href="https://mine.ir/">لینک خودم</a></p>';
$pnr_out  = ParsiNewsRobot\Seo\Links::apply_rel( $pnr_html, 'strip', 'follow', true, false );
check( 'strip removes source-text links but keeps the attribution link', false === strpos( $pnr_out, 'src.ir/x' ) && false !== strpos( $pnr_out, 'https://src.ir/"' ) );
check( 'attribution rel "follow" + no new tab: no rel/target on it', 1 === preg_match( '~<a(?![^>]*\b(rel|target)=)[^>]*data-pnr-link="attr"~', $pnr_out ) );
check( 'links added by editors are never touched', false !== strpos( $pnr_out, '<a href="https://mine.ir/">' ) );
$pnr_out = ParsiNewsRobot\Seo\Links::apply_rel( $pnr_html, 'nofollow', 'sponsored', true, true );
check( 'sponsored attribution link', false !== strpos( $pnr_out, 'rel="sponsored nofollow noopener"' ) );
$pnr_cfg = ParsiNewsRobot\Settings::source_defaults();
$pnr_cfg['attr_mode'] = 'off';
check( 'source can switch attribution off', false === ParsiNewsRobot\Settings::attribution( $pnr_cfg )['enabled'] );
$pnr_cfg['attr_mode'] = 'on';
$pnr_cfg['attr_position'] = 'start';
$pnr_a = ParsiNewsRobot\Settings::attribution( $pnr_cfg );
check( 'source overrides position, inherits the rest', true === $pnr_a['enabled'] && 'start' === $pnr_a['position'] && ParsiNewsRobot\Settings::get( 'attr_link' ) === $pnr_a['link'] );
check( 'replacements touch text only, never URLs', '<p><a href="https://site.ir/قدیم">جدید</a> جدید</p>' === rawurldecode( ParsiNewsRobot\Import\Cleaner::replace_text( '<p><a href="https://site.ir/قدیم">قدیم</a> قدیم</p>', array( 'قدیم' => 'جدید' ) ) ) );
check( 'title cleanup removes the agency suffix', 'افزایش قیمت نان در تهران' === ParsiNewsRobot\Import\Cleaner::clean_title( 'افزایش قیمت نان در تهران - ایسنا', "ایسنا" ) );

// Listing pages without RSS.
check( 'address shapes group articles', '/news/N/S' === ParsiNewsRobot\Import\ListingReader::shape( 'https://www.mehrnews.com/news/6612001/' . rawurlencode( 'افتتاح-خط-سوم' ) ) && '/persian/articles/X' === ParsiNewsRobot\Import\ListingReader::shape( 'https://www.bbc.com/persian/articles/cmd7912g4xeno' ) );
check( 'one article linked twice is one item', ParsiNewsRobot\Import\ListingReader::article_key( 'https://www.mehrnews.com/news/6612001' ) === ParsiNewsRobot\Import\ListingReader::article_key( 'https://www.mehrnews.com/news/6612001/slug' ) );
check( 'date paths are not article ids', ParsiNewsRobot\Import\ListingReader::article_key( 'https://www.tasnimnews.com/fa/news/1405/07/10/3412345/a' ) !== ParsiNewsRobot\Import\ListingReader::article_key( 'https://www.tasnimnews.com/fa/news/1405/07/10/3412399/b' ) );
$pnr_li = function ( $id, $t ) {
	return '<li class="news"><h3><a href="/news/' . $id . '/s">' . $t . '</a></h3><p class="introtext">خلاصه خبر شماره ' . $id . ' که به اندازه کافی بلند است تا خلاصه حساب شود.</p><time><a href="/news/' . $id . '">۱۰ مهر ۱۴۰۵، ۱۲:۳۰</a></time><figure><a href="/news/' . $id . '/s"><img data-src="https://media.example.ir/' . $id . '.jpg" src="/loader.gif"></a></figure></li>';
};
$pnr_page = '<html><body><header><nav><a href="/service/politics">سیاسی</a></nav></header><main><ul>' . $pnr_li( 1001, 'عنوان خبر اول درباره شهر مشهد و قطار شهری' ) . $pnr_li( 1002, 'عنوان خبر دوم درباره بارش باران در خراسان' ) . $pnr_li( 1003, 'عنوان خبر سوم درباره ثبت‌نام زائران در مشهد' ) . $pnr_li( 1004, 'عنوان خبر چهارم درباره نشست شورای شهر مشهد' ) . '</ul></main><footer><a href="/news/900/x">لینک فوتر با متن طولانی کافی برای عنوان</a></footer></body></html>';
$pnr_r = ParsiNewsRobot\Import\ListingReader::parse( $pnr_page, 'https://news.example.ir/tag/x' );
check( 'listing: 4 articles, headline titles, lazy images, summaries', 4 === count( $pnr_r['items'] ) && 'عنوان خبر اول درباره شهر مشهد و قطار شهری' === $pnr_r['items'][0]['title'] && 'https://media.example.ir/1001.jpg' === $pnr_r['items'][0]['enclosures'][0]['url'] && '' !== $pnr_r['items'][0]['description'] );
if ( $GLOBALS['pnr_failed'] ) { WP_CLI::error( $GLOBALS['pnr_failed'] . " test(s) failed." ); } WP_CLI::success( "All tests passed." );
