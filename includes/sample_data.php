<?php
/**
 * includes/sample_data.php
 *
 * Jab tak Google se real data nahi aata (72 hours), dashboard khali na dikhe
 * isliye sample data dikhate hain.
 *
 * ZAROORI: sample data hamesha "Sample data" badge + dim styling ke saath
 * dikhana hai. Warna user samjhega ye uska real data hai.
 *
 * Row shape bilkul GSC API jaisa:
 *   ['keys' => ['query'], 'clicks' => int, 'impressions' => int,
 *    'ctr' => float, 'position' => float]
 *
 * Numbers HARDCODED hain - random mat karna, warna har reload pe badlenge.
 */

// -------------------------------------------------------------------------
// HELPERS
// -------------------------------------------------------------------------

/** Rows khali hain? matlab sample dikhana hai */
function gsc_needs_sample($rows): bool
{
    return empty($rows) || !is_array($rows);
}

/** Sample wrapper ke liye CSS classes (dim + unclickable) */
function gsc_sample_class(bool $isSample): string
{
    return $isSample ? 'opacity-50 pointer-events-none select-none' : '';
}

/** "Sample data" badge - card heading ke paas */
function gsc_sample_badge(bool $isSample): string
{
    if (!$isSample) return '';

    /* ==========================================================
       The badge now carries an "i" that explains itself on hover.

       "Sample data" tells a merchant what the number is NOT. It
       does not tell them why it is there, whether something has
       gone wrong, or when it will change - and those are the
       three things they actually want to know from a label they
       did not expect to see.

       The tooltip is CSS-only. A click handler would need script
       on every page that renders a badge, and this has to work
       inside cards that are rebuilt by JavaScript after load.

       Styles are emitted once, guarded by a static, because this
       function is called eight or nine times on the dashboard and
       nine copies of the same rules is waste in every response.
    ========================================================== */
    static $stylesDone = false;

    $css = '';

    if (!$stylesDone) {
        $stylesDone = true;
        $css = '<style>
        .gsc-sb { position: relative; display: inline-flex; align-items: center; gap: 4px; }
        .gsc-sb-i {
            width: 14px; height: 14px; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700; font-style: normal;
            background: rgba(180,83,9,.15); color: #b45309; cursor: help;
        }
        .gsc-sb-tip {
            position: absolute; bottom: calc(100% + 8px); left: 50%;
            transform: translateX(-50%);
            width: 250px; padding: 10px 12px; border-radius: 9px;
            background: #1a1523; color: #f1f5f9;
            font-size: 12px; font-weight: 400; line-height: 1.55;
            text-align: left; white-space: normal;
            box-shadow: 0 8px 24px rgba(2,6,23,.28);
            opacity: 0; visibility: hidden; transition: opacity .14s;
            z-index: 70; pointer-events: none;
        }
        .gsc-sb-tip::after {
            content: ""; position: absolute; top: 100%; left: 50%;
            margin-left: -5px; border: 5px solid transparent;
            border-top-color: #1a1523;
        }
        .gsc-sb:hover .gsc-sb-tip,
        .gsc-sb:focus-within .gsc-sb-tip { opacity: 1; visibility: visible; }

        /* Near the right edge the tooltip would run off screen. Cards on
           the far column hit this, so it is worth the one rule. */
        @media (max-width: 640px) {
            .gsc-sb-tip { left: auto; right: -8px; transform: none; width: 210px; }
            .gsc-sb-tip::after { left: auto; right: 14px; }
        }
        </style>';
    }

    return $css
         . '<span class="gsc-sb">'
         .   '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full '
         .   'text-xs font-medium bg-warning-100 text-warning-700 '
         .   'dark:bg-warning-900/30 dark:text-warning-400 whitespace-nowrap">'
         .   'Sample data</span>'
         .   '<i class="gsc-sb-i" tabindex="0" role="button" aria-label="Why am I seeing sample data?">i</i>'
         .   '<span class="gsc-sb-tip" role="tooltip">'
         .     'Google takes up to 72 hours to send a site&rsquo;s first performance '
         .     'report. Until then we show example figures so the reports are not '
         .     'empty &mdash; they are replaced with yours automatically.'
         .   '</span>'
         . '</span>';
}

/** Explanation line - heading ke neeche */
function gsc_sample_note(bool $isSample): string
{
    if (!$isSample) return '';
    return '<p class="text-xs text-danger-700 dark:text-danger-400 '
         . 'bg-warning-50 dark:bg-warning-900/20 rounded-md px-3 py-2 mb-3">'
         . 'This is example data. Google needs up to 72 hours to send your first '
         . 'report - your real data will replace this automatically.</p>';
}

/** Ek row banane ka shortcut */
function gsc_sample_row(string $key, int $clicks, int $impr, float $pos): array
{
    return [
        'keys'        => [$key],
        'clicks'      => $clicks,
        'impressions' => $impr,
        'ctr'         => $impr > 0 ? round(($clicks / $impr) * 100, 2) : 0,
        'position'    => $pos,
    ];
}

// -------------------------------------------------------------------------
// CHART + KPI
// -------------------------------------------------------------------------

/** 30 din ka realistic upward trend */
function gsc_sample_chart(): array
{
    $clicks = [8, 11, 9, 14, 12, 17, 15, 19, 16, 22, 20, 25, 23, 28, 26,
               31, 29, 34, 32, 37, 35, 41, 38, 44, 42, 47, 45, 51, 48, 54];
    $impr   = [210, 265, 240, 330, 305, 410, 375, 455, 400, 520, 490, 585,
               545, 650, 610, 720, 680, 790, 745, 860, 815, 940, 890, 1020,
               975, 1090, 1045, 1160, 1110, 1230];

    $dates = [];
    for ($i = 29; $i >= 0; $i--) {
        $dates[] = date('d F', strtotime("-{$i} days"));
    }

    return [
        'dates'       => $dates,
        'clicks'      => $clicks,
        'impressions' => $impr,
    ];
}

function gsc_sample_kpi(): array
{
    return [
        'clicks'      => 848,
        'impressions' => 20735,
        'ctr'         => 4.09,
        'position'    => 14.6,
    ];
}

// -------------------------------------------------------------------------
// KEYWORDS
// -------------------------------------------------------------------------

function gsc_sample_brand_keywords(): array
{
    return [
        gsc_sample_row('your brand name', 62, 840, 2.1),
        gsc_sample_row('your brand reviews', 38, 610, 3.4),
        gsc_sample_row('your brand contact', 24, 390, 2.8),
        gsc_sample_row('your brand pricing', 19, 355, 4.2),
        gsc_sample_row('your brand login', 15, 280, 1.9),
        gsc_sample_row('your brand near me', 11, 240, 5.6),
    ];
}

/** Non-brand - Overview tab aur Top Queries bhi yahi use karte hain */
function gsc_sample_non_brand_keywords(): array
{
    return [
        gsc_sample_row('affordable services near me', 47, 1240, 8.3),
        gsc_sample_row('best options for small business', 34, 980, 11.7),
        gsc_sample_row('how to choose a provider', 28, 1450, 14.2),
        gsc_sample_row('online booking service', 21, 720, 9.8),
        gsc_sample_row('same day delivery options', 17, 640, 16.4),
        gsc_sample_row('customer support hours', 12, 410, 12.1),
        gsc_sample_row('opening hours today', 9, 380, 10.5),
        gsc_sample_row('free consultation booking', 7, 295, 18.9),
        gsc_sample_row('price list 2026', 6, 260, 20.3),
        gsc_sample_row('reviews and ratings', 5, 215, 15.7),
    ];
}

/** Brand vs non-brand ke numbers */
function gsc_sample_brand_split(): array
{
    return [
        'branded_clicks'     => 169,
        'non_branded_clicks' => 186,
        'branded_impr'       => 2715,
        'non_branded_impr'   => 6590,
    ];
}

// -------------------------------------------------------------------------
// PAGES
// -------------------------------------------------------------------------

function gsc_sample_pages(): array
{
    return [
        gsc_sample_row('/', 186, 4120, 9.2),
        gsc_sample_row('/services', 94, 2340, 12.6),
        gsc_sample_row('/about', 61, 1580, 15.3),
        gsc_sample_row('/blog/getting-started', 48, 1920, 18.7),
        gsc_sample_row('/contact', 37, 890, 8.4),
        gsc_sample_row('/pricing', 29, 1150, 21.2),
    ];
}

/** Query by page - do keys chahiye: [query, page] */
function gsc_sample_query_by_page(): array
{
    $make = function (string $q, string $page, int $clicks, int $impr, float $pos) {
        return [
            'keys'        => [$q, $page],
            'clicks'      => $clicks,
            'impressions' => $impr,
            'ctr'         => $impr > 0 ? round(($clicks / $impr) * 100, 2) : 0,
            'position'    => $pos,
        ];
    };

    return [
        $make('affordable services near me', '/services', 34, 820, 8.4),
        $make('how to choose a provider',    '/blog/getting-started', 28, 1450, 14.2),
        $make('your brand name',             '/', 26, 640, 2.1),
        $make('online booking service',      '/contact', 21, 720, 9.8),
        $make('price list 2026',             '/pricing', 17, 590, 16.3),
        $make('customer support hours',      '/contact', 12, 410, 12.1),
    ];
}

// -------------------------------------------------------------------------
// COUNTRIES / DEVICES
// -------------------------------------------------------------------------

function gsc_sample_countries(): array
{
    return [
        gsc_sample_row('usa', 412, 9840, 11.2),
        gsc_sample_row('gbr', 156, 3720, 13.8),
        gsc_sample_row('ind', 118, 3410, 16.4),
        gsc_sample_row('can', 87, 2130, 12.9),
        gsc_sample_row('aus', 54, 1490, 15.1),
    ];
}

function gsc_sample_devices(): array
{
    return [
        gsc_sample_row('MOBILE', 498, 12840, 15.7),
        gsc_sample_row('DESKTOP', 302, 6910, 11.4),
        gsc_sample_row('TABLET', 48, 985, 17.2),
    ];
}

// -------------------------------------------------------------------------
// INTENT BUCKETS
// -------------------------------------------------------------------------

/** Growth Engine tab (tab-2) */
function gsc_sample_ecommerce_intent(): array
{
    return [
        'ready_to_buy' => [
            gsc_sample_row('buy online with free shipping', 34, 720, 7.4),
            gsc_sample_row('best price for services', 26, 610, 9.8),
            gsc_sample_row('order online today', 19, 480, 11.2),
            gsc_sample_row('discount code available', 14, 390, 13.6),
            gsc_sample_row('book a service online', 11, 320, 10.5),
        ],
        'product_research' => [
            gsc_sample_row('best options compared', 41, 1180, 12.3),
            gsc_sample_row('top rated alternatives', 33, 940, 14.7),
            gsc_sample_row('features and specs guide', 25, 810, 16.1),
            gsc_sample_row('which one should i choose', 18, 690, 18.4),
            gsc_sample_row('vs competitor comparison', 13, 540, 15.9),
        ],
        'trust_comparison' => [
            gsc_sample_row('is it legit and safe', 22, 640, 13.8),
            gsc_sample_row('customer reviews and ratings', 18, 520, 11.6),
            gsc_sample_row('worth it or not', 14, 430, 17.2),
            gsc_sample_row('trusted provider testimonials', 9, 310, 14.5),
        ],
        'post_purchase' => [
            gsc_sample_row('track my order status', 16, 380, 6.2),
            gsc_sample_row('return and refund policy', 12, 340, 8.9),
            gsc_sample_row('warranty claim process', 8, 260, 12.4),
            gsc_sample_row('cancel my order', 6, 190, 9.7),
        ],
    ];
}

/** Lead Generator tab (tab-5) */
function gsc_sample_search_intent(): array
{
    return [
        'informational' => [
            gsc_sample_row('how does the process work', 38, 1420, 14.6),
            gsc_sample_row('what is included in the service', 29, 1080, 16.2),
            gsc_sample_row('why choose a professional', 21, 870, 18.9),
            gsc_sample_row('when should i book', 15, 640, 15.3),
            gsc_sample_row('can i get a free quote', 11, 510, 12.8),
        ],
        'commercial' => [
            gsc_sample_row('pricing and packages', 32, 890, 10.4),
            gsc_sample_row('cheap and affordable options', 24, 730, 13.1),
            gsc_sample_row('special offer this month', 17, 560, 11.7),
            gsc_sample_row('cost estimate calculator', 12, 470, 15.8),
        ],
        'transactional' => [
            gsc_sample_row('book an appointment now', 27, 610, 8.2),
            gsc_sample_row('sign up for free trial', 20, 540, 10.9),
            gsc_sample_row('download the price list', 14, 420, 13.4),
            gsc_sample_row('register online today', 9, 300, 11.5),
        ],
        'navigational' => [
            gsc_sample_row('official website homepage', 25, 480, 3.1),
            gsc_sample_row('customer support contact', 18, 390, 5.4),
            gsc_sample_row('account login page', 14, 320, 2.7),
            gsc_sample_row('help center portal', 8, 210, 6.8),
        ],
    ];
}

// -------------------------------------------------------------------------
// ALL DATA TAB
// -------------------------------------------------------------------------

function gsc_sample_position_bands(): array
{
    return [
        ['band' => '1-2',   'impressions' => 1840, 'clicks' => 142, 'rows' => 6],
        ['band' => '3-5',   'impressions' => 2960, 'clicks' => 118, 'rows' => 11],
        ['band' => '6-10',  'impressions' => 4210, 'clicks' => 96,  'rows' => 19],
        ['band' => '11-20', 'impressions' => 5480, 'clicks' => 54,  'rows' => 27],
        ['band' => '21-50', 'impressions' => 3910, 'clicks' => 18,  'rows' => 34],
        ['band' => '51+',   'impressions' => 1420, 'clicks' => 3,   'rows' => 22],
    ];
}

function gsc_sample_striking_distance(): array
{
    return [
        gsc_sample_row('best options for small business', 12, 980, 11.7),
        gsc_sample_row('how to choose a provider', 9, 1450, 14.2),
        gsc_sample_row('same day delivery options', 6, 640, 16.4),
        gsc_sample_row('price list 2026', 4, 260, 20.3),
        gsc_sample_row('features and specs guide', 3, 810, 16.1),
        gsc_sample_row('free consultation booking', 2, 295, 18.9),
        gsc_sample_row('which one should i choose', 2, 690, 18.4),
        gsc_sample_row('why choose a professional', 1, 870, 18.9),
    ];
}

function gsc_sample_cannibalization(): array
{
    return [
        [
            'keyword' => 'affordable services near me',
            'gap'     => 24,
            'pages'   => [
                ['page' => '/services', 'impressions' => 820],
                ['page' => '/',         'impressions' => 620],
            ],
        ],
        [
            'keyword' => 'online booking service',
            'gap'     => 41,
            'pages'   => [
                ['page' => '/contact',  'impressions' => 720],
                ['page' => '/services', 'impressions' => 425],
            ],
        ],
        [
            'keyword' => 'price list 2026',
            'gap'     => 68,
            'pages'   => [
                ['page' => '/pricing', 'impressions' => 590],
                ['page' => '/about',   'impressions' => 190],
            ],
        ],
    ];
}

function gsc_sample_search_appearance(): array
{
    return [
        gsc_sample_row('Rich snippet', 84, 2140, 9.4),
        gsc_sample_row('FAQ rich result', 46, 1380, 12.7),
        gsc_sample_row('Breadcrumb', 32, 1920, 14.1),
        gsc_sample_row('Sitelinks searchbox', 18, 640, 6.2),
    ];
}

function gsc_sample_sitemap_status(): array
{
    return [
        [
            'path'           => '/sitemap.xml',
            'lastSubmitted'  => null,
            'lastDownloaded' => null,
            'isPending'      => false,
            'warnings'       => 2,
            'errors'         => 0,
            'submitted'      => 48,
            'indexed'        => 39,
        ],
    ];
}