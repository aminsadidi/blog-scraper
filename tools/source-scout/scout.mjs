// Source scout: checks news sources from your own computer with a real browser and writes
// report.json + report.txt, which are used to configure the Parsi News Robot plugin.
//
//   npm install
//   node scout.mjs                       (addresses from urls.txt, keyword "مشهد")
//   WORKERS=6 node scout.mjs            (number of parallel browsers, default 4; PowerShell: $env:WORKERS="6"; node scout.mjs)
//   KEYWORD=تهران node scout.mjs          (another keyword; on Windows PowerShell: $env:KEYWORD="تهران"; node scout.mjs)
//
// It only reads public pages, one at a time with a pause in between.

import { chromium } from 'playwright-core';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const KEYWORD = process.env.KEYWORD || 'مشهد';
const ARTICLES_PER_SOURCE = Number(process.env.ARTICLES || 2);
const PAUSE_MS = 1500;
const HEADLESS = process.env.HEADLESS === '1';
// Number of browser windows working at the same time. Two addresses of the same site never run together.
const WORKERS = Math.max(1, Number(process.env.WORKERS || 4));

const BODY_SELECTORS = ['[itemprop="articleBody"]', '.item-text', '#echo_detail', '.news-text', '.newsText', '.news_body', '.news-body', '#newsMainBody', '.content-news', '.body', '.story', '.entry-content', '.post-content', '.article-body', 'article'];
const LEAD_SELECTORS = ['.summary', '.introtext', '.lead', '.news-lead', '.subtitle', '.sub-title', '.rutitr', '[itemprop="description"]'];

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const log = (...a) => console.log(...a);

function readUrls() {
	const file = path.join(DIR, process.argv[2] || 'urls.txt');
	return fs.readFileSync(file, 'utf8').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('#'));
}

function encodeUrl(url) {
	try {
		return new URL(url).href; // Percent-encodes Persian characters.
	} catch {
		return url;
	}
}

async function launch(announce = true) {
	const options = { headless: HEADLESS };
	for (const channel of ['msedge', 'chrome', undefined]) {
		try {
			const browser = await chromium.launch(channel ? { ...options, channel } : options);
			if (announce) log(`مرورگر: ${channel || 'chromium'}`);
			return browser;
		} catch {
			// Try the next one.
		}
	}
	throw new Error('مرورگر Edge یا Chrome پیدا نشد. یکی از آن‌ها را نصب کنید.');
}

const isChallenge = (body) => body.length < 60000 && /challenge-platform|cf-browser-verification|cf_chl_|Just a moment\.\.\.|Checking your browser|ddos-guard|__arvan|arvancloud/i.test(body);
const isFeed = (body) => /<(rss|feed|rdf:RDF)[\s>]/i.test(body.replace(/^﻿/, '').trimStart().slice(0, 3000));

// Downloads an address inside the real browser: the site is opened first (passing any firewall check and
// collecting its cookies), then the address is fetched from within the page. This also works for feeds a
// browser would otherwise save as a file instead of showing.
async function fetchRaw({ context, page, opened, say }, url) {
	const origin = new URL(url).origin;
	const openSite = async () => {
		await page.goto(origin + '/', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
		await sleep(2000);
		if (isChallenge(await page.content().catch(() => ''))) {
			say('دیوار امنیتی دیده شد؛ صبر برای عبور مرورگر…');
			await sleep(10000);
		}
	};
	const inPage = () => page.evaluate(async (u) => {
		const res = await fetch(u, { credentials: 'include' });
		return { status: res.status, type: res.headers.get('content-type') || '', url: res.url, body: await res.text() };
	}, url);
	if (!opened.has(origin)) {
		await openSite();
		opened.add(origin);
	} else if (!page.url().startsWith(origin)) {
		await page.goto(origin + '/', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});
	}
	try {
		const r = await inPage();
		if (isChallenge(r.body)) {
			await openSite();
			return { ...(await inPage()), challenge: true };
		}
		return r;
	} catch {
		// Fallback: the browser context's own request client.
		const res = await context.request.get(url, { timeout: 40000, failOnStatusCode: false, maxRedirects: 5 });
		return { status: res.status(), type: res.headers()['content-type'] || '', url: res.url(), body: await res.text() };
	}
}

async function parseFeed(parser, xml) {
	return parser.evaluate((xml) => {
		const doc = new DOMParser().parseFromString(xml, 'text/xml');
		if (doc.querySelector('parsererror')) {
			return { error: 'XML خراب است: ' + doc.querySelector('parsererror').textContent.slice(0, 150) };
		}
		const text = (el, sel) => (el.querySelector(sel)?.textContent || '').trim();
		const nodes = [...doc.querySelectorAll('item, entry')];
		return {
			title: text(doc, 'channel > title, feed > title'),
			items: nodes.slice(0, 40).map((n) => ({
				title: text(n, 'title'),
				link: text(n, 'link') || n.querySelector('link')?.getAttribute('href') || '',
				date: text(n, 'pubDate, published, updated, date'),
				categories: [...n.querySelectorAll('category')].map((c) => c.textContent.trim() || c.getAttribute('term') || ''),
				description: text(n, 'description, summary').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 200),
				content_length: (n.getElementsByTagName('content:encoded')[0]?.textContent || text(n, 'content')).replace(/<[^>]+>/g, ' ').trim().length,
				image: n.querySelector('enclosure')?.getAttribute('url') || '',
			})),
		};
	}, xml);
}

// Everything useful about a web page: declared feeds, RSS links, filters, article links and their markup.
async function inspectPage(page) {
	return page.evaluate(() => {
		const host = location.hostname.replace(/^www\./, '');
		const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
		const shape = (u) => {
			try {
				return new URL(u).pathname.split('/').filter(Boolean).map((s) => {
					s = decodeURIComponent(s);
					if (/^\d+$/.test(s)) return 'N';
					if (/^[a-z]{1,10}[-_]?\d{3,}$/i.test(s)) return s.replace(/\d+$/, 'N');
					if (/^(?=.*\d)(?=.*[a-z])[a-z0-9]{8,}$/i.test(s)) return 'X';
					if (s.length > 24 || (s.match(/-/g) || []).length >= 2 || /[^\x00-\x7F]/.test(s)) return 'S';
					return s.toLowerCase();
				}).join('/');
			} catch { return ''; }
		};
		const cssPath = (el) => {
			const parts = [];
			for (let n = el; n && n !== document.body && parts.length < 5; n = n.parentElement) {
				const cls = [...n.classList].slice(0, 2).map((c) => '.' + c).join('');
				parts.unshift(n.tagName.toLowerCase() + (n.id ? '#' + n.id : '') + cls);
			}
			return parts.join(' > ');
		};
		const links = [...document.querySelectorAll('a[href]')]
			.map((a) => ({ a, href: a.href.split('#')[0], text: clean(a.textContent) }))
			.filter((l) => l.href.startsWith('http') && new URL(l.href).hostname.replace(/^www\./, '').endsWith(host));
		const groups = {};
		for (const l of links) {
			if (l.text.length < 18) continue;
			const k = shape(l.href);
			(groups[k] = groups[k] || []).push(l);
		}
		const ranked = Object.entries(groups).sort((a, b) => b[1].length - a[1].length).slice(0, 5);
		return {
			title: document.title,
			site: document.querySelector('meta[property="og:site_name"]')?.content || '',
			declared_feeds: [...document.querySelectorAll('link[rel="alternate"][type*="rss"], link[rel="alternate"][type*="atom"]')].map((l) => l.href),
			// The link text is often the address itself; the row around it carries the feed's name.
			rss_links: links.filter((l) => /rss|feed/i.test(l.href)).slice(0, 500).map((l) => {
				const row = l.a.closest('tr, li, .rss_row, p, div');
				const label = clean((row ? row.textContent : '').replace(l.a.textContent, ' ')) || l.text;
				return { text: label.slice(0, 80), href: l.href };
			}),
			filters: [...document.querySelectorAll('select')].map((s) => ({
				name: s.name || s.id,
				options: [...s.options].map((o) => ({ value: o.value, label: clean(o.textContent) })).filter((o) => o.label).slice(0, 150),
			})).filter((s) => s.name && s.options.length > 1),
			link_groups: ranked.map(([k, list]) => ({
				shape: '/' + k,
				count: new Set(list.map((l) => l.href)).size,
				markup: cssPath(list[0].a),
				samples: [...new Map(list.map((l) => [l.href, l.text])).entries()].slice(0, 6).map(([href, text]) => ({ text: text.slice(0, 90), href })),
			})),
		};
	});
}

async function inspectArticle(page, url, bodySelectors, leadSelectors) {
	await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
	await sleep(1500);
	return page.evaluate(({ bodySelectors, leadSelectors }) => {
		const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
		const meta = (n) => document.querySelector(`meta[property="${n}"], meta[name="${n}"]`)?.content || '';
		const bodies = bodySelectors.map((sel) => {
			const els = [...document.querySelectorAll(sel)];
			const el = els.sort((a, b) => clean(b.textContent).length - clean(a.textContent).length)[0];
			return el ? { selector: sel, matches: els.length, chars: clean(el.textContent).length, paragraphs: el.querySelectorAll('p').length, images: el.querySelectorAll('img').length } : null;
		}).filter((b) => b && b.chars > 150);
		// Element with the most paragraph text (for CMSs we do not know yet).
		let best = null;
		for (const el of document.querySelectorAll('div, section, article, main')) {
			const chars = [...el.children].filter((c) => c.tagName === 'P').reduce((s, p) => s + clean(p.textContent).length, 0);
			if (!best || chars > best.chars) best = { el, chars };
		}
		const describe = (el) => el ? el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + [...el.classList].map((c) => '.' + c).join('') : '';
		return {
			url: location.href,
			h1: clean(document.querySelector('h1')?.textContent).slice(0, 150),
			og_title: meta('og:title').slice(0, 150),
			og_image: meta('og:image'),
			published: meta('article:published_time') || document.querySelector('[itemprop="datePublished"]')?.getAttribute('content') || document.querySelector('time[datetime]')?.getAttribute('datetime') || '',
			leads: leadSelectors.map((sel) => ({ selector: sel, text: clean(document.querySelector(sel)?.textContent).slice(0, 120) })).filter((l) => l.text.length > 25),
			bodies,
			densest_block: best ? { element: describe(best.el), chars: best.chars } : null,
			iframes: [...document.querySelectorAll('iframe[src]')].map((f) => f.src).filter((s) => !/ads|doubleclick|googletag/i.test(s)).slice(0, 5),
			videos: document.querySelectorAll('video').length,
			first_text: clean(document.querySelector(bodies[0]?.selector || 'article')?.textContent).slice(0, 200),
		};
	}, { bodySelectors, leadSelectors });
}

function summarize(src) {
	const lines = [`### ${src.url}`];
	if (src.error) return lines.concat(`خطا: ${src.error}`).join('\n');
	lines.push(`نوع: ${src.kind} | وضعیت HTTP: ${src.status}${src.challenge ? ' | دیوار امنیتی' : ''}${src.items ? ` | خبرها: ${src.items.length} | شامل «${KEYWORD}»: ${src.keyword_share}٪` : ''}`);
	if (src.categories && Object.keys(src.categories).length) lines.push('دسته‌های RSS: ' + Object.entries(src.categories).map(([k, v]) => `${k} (${v})`).join(' / '));
	(src.items || []).slice(0, 5).forEach((i) => lines.push(`- ${i.title}${i.categories.length ? ' [' + i.categories.join('، ') + ']' : ''}`));
	if (src.page) {
		src.page.link_groups.slice(0, 2).forEach((g) => lines.push(`گروه لینک ${g.shape} (${g.count}) — ${g.markup}`));
		if (src.page.declared_feeds.length) lines.push('RSS اعلام‌شده: ' + src.page.declared_feeds.join(' , '));
		if (src.page.rss_links.length) lines.push(`لینک‌های RSS در صفحه (${src.page.rss_links.length}):`, ...src.page.rss_links.map((l) => `  ${l.text} => ${l.href}`));
		src.page.filters.forEach((f) => lines.push(`فیلتر ${f.name}: ` + f.options.map((o) => `${o.value}=${o.label}`).join('، ')));
	}
	(src.articles || []).forEach((a) => lines.push(a.error ? `خبر: خطا — ${a.error}` : `خبر: ${a.h1 || a.og_title} | متن: ${a.bodies[0] ? a.bodies[0].selector + ' ' + a.bodies[0].chars + ' حرف' : 'پیدا نشد'} | بلوک پرمتن: ${a.densest_block?.element} | تاریخ: ${a.published || '-'}`));
	return lines.join('\n');
}

// Checks one address with a worker's browser and returns its report entry.
async function inspectSource(w, raw) {
	const url = encodeUrl(raw);
	const src = { url: raw };
	let r = await fetchRaw(w, url);
	if (r.status >= 400 && !isFeed(r.body)) {
		// Some sites refuse the in-page download but answer the browser's own request client.
		const res = await w.context.request.get(url, { timeout: 40000, failOnStatusCode: false, maxRedirects: 5 }).catch(() => null);
		if (res && res.status() < 400) r = { status: res.status(), type: res.headers()['content-type'] || '', url: res.url(), body: await res.text() };
		else throw new Error(`سایت خطای HTTP ${r.status} داد`);
	}
	Object.assign(src, { status: r.status, final_url: r.url, content_type: r.type, challenge: !!r.challenge });
	let articleLinks = [];
	if (isFeed(r.body)) {
		const feed = await parseFeed(w.parser, r.body);
		if (feed.error) throw new Error(feed.error);
		src.kind = 'rss';
		src.title = feed.title;
		src.items = feed.items;
		src.keyword_share = feed.items.length ? Math.round(100 * feed.items.filter((i) => (i.title + i.description).includes(KEYWORD)).length / feed.items.length) : 0;
		src.categories = {};
		feed.items.flatMap((i) => i.categories).forEach((c) => (src.categories[c] = (src.categories[c] || 0) + 1));
		articleLinks = feed.items.map((i) => i.link).filter(Boolean);
		w.say(`RSS: ${feed.items.length} خبر، ${src.keyword_share}٪ شامل «${KEYWORD}»`);
	} else {
		src.kind = 'html';
		await w.page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
		await sleep(2500);
		src.page = await inspectPage(w.page);
		articleLinks = src.page.link_groups[0]?.samples.map((s) => s.href) || [];
		w.say(`صفحه: ${src.page.link_groups[0]?.count || 0} لینک خبر، ${src.page.rss_links.length} لینک RSS، ${src.page.filters.length} فیلتر`);
	}
	src.articles = [];
	for (const link of articleLinks.slice(0, ARTICLES_PER_SOURCE)) {
		await sleep(PAUSE_MS);
		try {
			src.articles.push(await inspectArticle(w.page, link, BODY_SELECTORS, LEAD_SELECTORS));
		} catch (e) {
			src.articles.push({ url: link, error: String(e.message || e).split('\n')[0] });
		}
	}
	return src;
}

// One browser window with its own pages; reopened when it was closed or crashed.
function makeWorker(id) {
	const w = { id, browser: null, context: null, page: null, parser: null, opened: new Set(), say: log };
	w.open = async () => {
		await w.browser?.close().catch(() => {});
		w.browser = await launch(id === 1);
		// SCOUT_IGNORE_CERT=1 is only for networks behind an inspecting proxy (corporate / test servers).
		w.context = await w.browser.newContext({ locale: 'fa-IR', viewport: { width: 1100, height: 800 }, ignoreHTTPSErrors: process.env.SCOUT_IGNORE_CERT === '1' });
		// Pictures, videos and fonts are not needed for the report; skipping them saves a lot of bandwidth.
		await w.context.route('**/*', (route) => (['image', 'media', 'font'].includes(route.request().resourceType()) ? route.abort() : route.continue()));
		w.page = await w.context.newPage();
		w.parser = await w.context.newPage();
		await w.parser.goto('about:blank');
		w.opened.clear();
	};
	w.alive = () => !!w.browser && w.browser.isConnected() && !w.page.isClosed() && !w.parser.isClosed();
	return w;
}

async function main() {
	const urls = readUrls();
	const count = Math.min(WORKERS, urls.length);
	log(`${urls.length} آدرس، کلمه کلیدی: «${KEYWORD}»، ${count} مرورگر همزمان`);

	const results = new Array(urls.length);
	const report = { generated: new Date().toISOString(), keyword: KEYWORD, sources: [] };
	const save = () => {
		report.sources = results.filter(Boolean);
		fs.writeFileSync(path.join(DIR, 'report.json'), JSON.stringify(report, null, 1));
		fs.writeFileSync(path.join(DIR, 'report.txt'), report.sources.map(summarize).join('\n\n') + '\n');
	};

	// Shared queue: a worker takes the next address whose site no other worker is visiting right now.
	const pending = urls.map((raw, index) => ({ raw, index, host: (() => { try { return new URL(encodeUrl(raw)).hostname.replace(/^www\./, ''); } catch { return raw; } })() }));
	const busy = new Set();
	let done = 0;
	const take = () => {
		const i = pending.findIndex((t) => !busy.has(t.host));
		return i < 0 ? null : pending.splice(i, 1)[0];
	};

	const run = async (w) => {
		await w.open();
		while (pending.length) {
			const task = take();
			if (!task) {
				await sleep(500); // Remaining addresses belong to sites other windows are visiting.
				continue;
			}
			busy.add(task.host);
			const tag = `[${task.index + 1}/${urls.length}]`;
			w.say = (msg) => log(`${tag} ${msg}`);
			w.say(`شروع ${task.raw}  (مرورگر ${w.id})`);
			let src;
			for (let attempt = 1; ; attempt++) {
				try {
					if (!w.alive()) {
						w.say('مرورگر بسته شده بود؛ دوباره باز می‌شود…');
						await w.open();
					}
					src = await inspectSource(w, task.raw);
					break;
				} catch (e) {
					const error = String(e.message || e).split('\n')[0];
					if (attempt < 2 && !w.alive()) continue; // The browser went away mid-source: one more try.
					src = { url: task.raw, error };
					w.say('خطا: ' + error);
					break;
				}
			}
			results[task.index] = src;
			done++;
			save(); // After every source, so a crash never loses earlier results.
			log(`   ── ${done} از ${urls.length} انجام شد`);
			busy.delete(task.host);
			await sleep(PAUSE_MS);
		}
		await w.browser?.close().catch(() => {});
	};

	await Promise.all(Array.from({ length: count }, (_, i) => run(makeWorker(i + 1)).catch((e) => log(`مرورگر ${i + 1} از کار افتاد: ${e.message || e}`))));
	save();
	log(`\nتمام شد. فایل report.json را برای پیکربندی بفرستید (خلاصه در report.txt).`);
}

main().catch((e) => {
	console.error('خطا:', e.message || e);
	process.exit(1);
});
