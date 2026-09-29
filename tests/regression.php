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

$pnr_failed = 0;
function check( $name, $ok ) {
	global $pnr_failed;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	if ( ! $ok ) {
		$pnr_failed++;
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
if ( $pnr_failed ) { WP_CLI::error( $pnr_failed . " test(s) failed." ); } WP_CLI::success( "All tests passed." );
