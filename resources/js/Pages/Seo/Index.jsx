import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import DateTimePicker from '@/Components/DateTimePicker';
import HelpGuide, { HELP, SEO_HELP } from '@/Components/HelpGuide';
import InputLabel from '@/Components/InputLabel';
import PanelTitle from '@/Components/PanelTitle';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const TABS = [
    { id: 'speed', label: 'Speed' },
    { id: 'fix', label: 'Fix' },
    { id: 'keywords', label: 'Keywords' },
    { id: 'grow', label: 'Grow' },
    { id: 'publish', label: 'Publish' },
    { id: 'map', label: 'Site map' },
];

function formatRetryWait(seconds) {
    if (!seconds || seconds <= 0) {
        return null;
    }
    const mins = Math.max(1, Math.ceil(seconds / 60));
    return mins === 1 ? '1 minute' : `${mins} minutes`;
}

const severityTone = {
    critical: 'bg-rose-50 text-rose-700 border-rose-200',
    warning: 'bg-amber-50 text-amber-800 border-amber-200',
    info: 'bg-sky-50 text-sky-800 border-sky-200',
};

function Badge({ children, className = '' }) {
    return (
        <span
            className={`inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide ${className}`}
        >
            {children}
        </span>
    );
}

function sourceLabel(url) {
    if (!url) {
        return '';
    }
    try {
        const parsed = new URL(url);
        const path = parsed.pathname || '/';
        const file = path.split('/').filter(Boolean).pop();
        if (file && /\.(png|jpe?g|gif|webp|svg|avif|bmp)$/i.test(file)) {
            return file;
        }
        return `${parsed.host}${path === '/' ? '' : path}`;
    } catch {
        return url;
    }
}

function SourceLink({ href, children, className = '' }) {
    if (!href) {
        return null;
    }
    return (
        <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            className={`inline-flex max-w-full items-center gap-1 truncate text-sky-700 underline-offset-2 hover:underline ${className}`}
            title={href}
        >
            <span className="truncate">{children || sourceLabel(href) || href}</span>
            <span className="shrink-0 text-[10px] text-sky-500" aria-hidden>
                ↗
            </span>
        </a>
    );
}

function IssueSourceLinks({ pageUrl, assetUrls = [] }) {
    const images = Array.isArray(assetUrls) ? assetUrls.filter(Boolean) : [];
    if (!pageUrl && images.length === 0) {
        return null;
    }
    return (
        <div className="mt-1.5 space-y-1">
            {pageUrl ? (
                <div className="flex min-w-0 items-baseline gap-1.5 text-xs">
                    <span className="shrink-0 font-semibold text-ink-muted">Page</span>
                    <SourceLink href={pageUrl} />
                </div>
            ) : null}
            {images.length > 0 ? (
                <div className="space-y-0.5">
                    <div className="text-[11px] font-semibold text-ink-muted">
                        Image{images.length > 1 ? 's' : ''} missing ALT
                    </div>
                    <ul className="space-y-0.5">
                        {images.map((src) => (
                            <li key={src} className="min-w-0 text-xs">
                                <SourceLink href={src} />
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}

/** PDF file icon with download arrow */
function PdfIcon({ className = 'h-5 w-5' }) {
    return (
        <svg viewBox="0 0 24 24" className={className} aria-hidden>
            <path
                d="M5.75 3.25h7.1L18.25 8.5v10.75a1.5 1.5 0 0 1-1.5 1.5H5.75a1.5 1.5 0 0 1-1.5-1.5V4.75a1.5 1.5 0 0 1 1.5-1.5Z"
                fill="currentColor"
                opacity="0.14"
            />
            <path
                d="M12.75 3.25v4.4c0 .55.45 1 1 1h4.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinejoin="round"
            />
            <path
                d="M5.75 3.25h7.1L18.25 8.5v10.75a1.5 1.5 0 0 1-1.5 1.5H5.75a1.5 1.5 0 0 1-1.5-1.5V4.75a1.5 1.5 0 0 1 1.5-1.5Z"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
                strokeLinejoin="round"
            />
            <text
                x="11.2"
                y="15.6"
                textAnchor="middle"
                fill="currentColor"
                fontSize="6"
                fontWeight="800"
                fontFamily="ui-sans-serif, system-ui, -apple-system, sans-serif"
                letterSpacing="-0.04em"
            >
                PDF
            </text>
            <circle cx="18.1" cy="18.1" r="4.35" fill="currentColor" />
            <path
                d="M18.1 15.85v3.4m0 0-1.5-1.45M18.1 19.25l1.5-1.45"
                stroke="#fff"
                strokeWidth="1.45"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

/** Excel spreadsheet icon with download arrow */
function ExcelIcon({ className = 'h-5 w-5' }) {
    return (
        <svg viewBox="0 0 24 24" className={className} aria-hidden>
            <rect
                x="3.75"
                y="3.25"
                width="13.5"
                height="16"
                rx="1.5"
                fill="currentColor"
                opacity="0.14"
            />
            <rect
                x="3.75"
                y="3.25"
                width="13.5"
                height="16"
                rx="1.5"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.5"
            />
            <path
                d="M3.75 8.1h13.5M3.75 12.5h13.5M3.75 16.7h13.5M8.4 3.25v16M13.1 3.25v16"
                stroke="currentColor"
                strokeWidth="1.15"
            />
            <text
                x="10.5"
                y="14.4"
                textAnchor="middle"
                fill="currentColor"
                fontSize="7.5"
                fontWeight="800"
                fontFamily="ui-sans-serif, system-ui, -apple-system, sans-serif"
            >
                X
            </text>
            <circle cx="18.1" cy="18.1" r="4.35" fill="currentColor" />
            <path
                d="M18.1 15.85v3.4m0 0-1.5-1.45M18.1 19.25l1.5-1.45"
                stroke="#fff"
                strokeWidth="1.45"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function psiScoreTone(score) {
    if (score == null) {
        return { stroke: '#94a3b8', text: 'text-ink-muted', label: '—' };
    }
    if (score >= 90) {
        return { stroke: '#16a34a', text: 'text-emerald-600', label: 'Good' };
    }
    if (score >= 50) {
        return { stroke: '#d97706', text: 'text-amber-600', label: 'Needs work' };
    }
    return { stroke: '#e11d48', text: 'text-rose-600', label: 'Poor' };
}

function PsiScoreRing({ score, label }) {
    const tone = psiScoreTone(score);
    const radius = 34;
    const circumference = 2 * Math.PI * radius;
    const pct = score == null ? 0 : Math.max(0, Math.min(100, score)) / 100;
    const dash = circumference * pct;

    return (
        <div className="flex flex-col items-center gap-1.5">
            <div className="relative h-[88px] w-[88px]">
                <svg viewBox="0 0 80 80" className="h-full w-full -rotate-90">
                    <circle
                        cx="40"
                        cy="40"
                        r={radius}
                        fill="none"
                        stroke="#e2e8f0"
                        strokeWidth="7"
                    />
                    <circle
                        cx="40"
                        cy="40"
                        r={radius}
                        fill="none"
                        stroke={tone.stroke}
                        strokeWidth="7"
                        strokeLinecap="round"
                        strokeDasharray={`${dash} ${circumference - dash}`}
                    />
                </svg>
                <div
                    className={`absolute inset-0 flex items-center justify-center font-display text-2xl font-bold tabular-nums ${tone.text}`}
                >
                    {score != null ? score : '—'}
                </div>
            </div>
            <div className="text-center text-xs font-semibold text-ink">{label}</div>
        </div>
    );
}

function PsiMetric({ label, value, hint }) {
    return (
        <div className="rounded-md border border-line bg-white px-3 py-2.5">
            <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                {label}
            </div>
            <div className="mt-0.5 font-display text-xl font-bold tabular-nums text-ink">
                {value}
            </div>
            {hint ? <div className="text-[11px] text-ink-muted">{hint}</div> : null}
        </div>
    );
}

export default function Index({
    workspace,
    sites = [],
    other_workspaces_with_sites = [],
    site,
    issues = [],
    keywords = [],
    tasks = [],
    pages = [],
    suggestions = [],
    competitors = [],
    reports = [],
    stats,
    providers = {},
    plan = null,
    backlinks = { summary: null, items: [] },
    local_targets = [],
    architecture = { nodes: [], edges: [] },
    cms_connections = [],
    content_drafts = [],
    blog_posts = [],
    blog_share_channels = [],
    blog_synced_at = null,
    blog_feed_url = null,
    pagespeed_quota = null,
}) {
    const { errors, flash } = usePage().props;
    const initialTab = (() => {
        const q = new URLSearchParams(window.location.search).get('tab');
        return TABS.some((t) => t.id === q) ? q : 'fix';
    })();
    const [tab, setTab] = useState(initialTab);
    const [addingSite, setAddingSite] = useState(sites.length === 0);
    const [addingKeyword, setAddingKeyword] = useState(false);
    const [buildingTodos, setBuildingTodos] = useState(false);
    const [scanning, setScanning] = useState(false);
    const [syncingBlogs, setSyncingBlogs] = useState(false);
    const [sharingBlogId, setSharingBlogId] = useState(null);
    const [shareMenuPostId, setShareMenuPostId] = useState(null);
    const [reportPeriod, setReportPeriod] = useState('weekly');
    const [reportStart, setReportStart] = useState('');
    const [reportEnd, setReportEnd] = useState('');
    const [generatingReport, setGeneratingReport] = useState(false);
    const [psiStrategy, setPsiStrategy] = useState(
        site?.pagespeed_strategy === 'desktop' ? 'desktop' : 'mobile',
    );
    const [researchSeed, setResearchSeed] = useState('');
    const [researching, setResearching] = useState(false);
    const [researchIdeas, setResearchIdeas] = useState([]);

    useEffect(() => {
        if (Array.isArray(flash?.keyword_research)) {
            setResearchIdeas(flash.keyword_research);
            setResearching(false);
        }
    }, [flash?.keyword_research]);

    useEffect(() => {
        if (flash?.share_open_url) {
            window.open(flash.share_open_url, '_blank', 'noopener,noreferrer');
        }
    }, [flash?.share_open_url]);

    const seoApisLocked = plan && !plan.features?.seo_apis;
    const seoMetricsLocked = plan && !plan.features?.seo_metrics;
    const seoBacklinksLocked = plan && !plan.features?.seo_backlinks;
    const seoLocalLocked = plan && !plan.features?.seo_local;
    const seoCmsLocked = plan && !plan.features?.seo_cms;
    const seoJsLocked = plan && !plan.features?.seo_js_crawl;

    const gscCooldownLabel = formatRetryWait(site?.gsc_sync_retry_after);
    const pagespeedCooldownLabel = formatRetryWait(site?.pagespeed_retry_after);
    const gscOnCooldown = !!gscCooldownLabel;
    const pagespeedOnCooldown = !!pagespeedCooldownLabel;

    const psiSnapshot = useMemo(() => {
        const report = site?.pagespeed_report || null;
        const fromReport = report?.[psiStrategy] || null;
        if (fromReport) {
            return fromReport;
        }

        // Legacy rows (no pagespeed_report yet): map flat fields only to the
        // strategy that was last run — never invent Mobile from a Desktop run.
        const hasAnyStrategyReport = Boolean(report?.mobile || report?.desktop);
        if (hasAnyStrategyReport) {
            return null;
        }

        const lastStrategy = site?.pagespeed_strategy || 'mobile';
        if (
            psiStrategy === lastStrategy &&
            site?.pagespeed_score != null
        ) {
            return {
                strategy: lastStrategy,
                score: site.pagespeed_score,
                categories: {
                    performance: site.pagespeed_score,
                    accessibility: null,
                    'best-practices': null,
                    seo: null,
                },
                metrics: {
                    fcp: null,
                    lcp: site.cwv_lcp,
                    tbt: null,
                    cls: site.cwv_cls,
                    si: null,
                    inp: site.cwv_inp,
                },
                issues: site.pagespeed_issues || [],
            };
        }

        return null;
    }, [site, psiStrategy]);

    const psiIssues = psiSnapshot?.issues || [];

    const siteForm = useForm({ domain: '', sitemap_url: '', crawl_frequency: 'daily' });
    const keywordForm = useForm({
        keyword: '',
        group_name: 'General',
        position: '',
        is_local: false,
        location: '',
    });
    const competitorForm = useForm({ domain: '' });
    const localForm = useForm({
        keyword: '',
        location_name: '',
        business_name: workspace?.name || '',
        site_id: site?.id || '',
    });
    const cmsForm = useForm({
        base_url: '',
        username: '',
        app_password: '',
        label: 'WordPress',
    });
    const draftForm = useForm({ keyword: '', seo_keyword_id: '' });

    const openTasks = useMemo(() => tasks.filter((t) => t.status === 'open').slice(0, 12), [tasks]);
    const openIssues = useMemo(
        () => issues.filter((i) => i.status === 'open' || !i.status),
        [issues],
    );
    const criticalCount = openIssues.filter((i) => i.severity === 'critical').length;

    const switchSite = (id) => {
        router.get(route('seo.index'), { site: id }, { preserveState: true, replace: true });
    };

    const flashMsg =
        (typeof flash?.success === 'string' && flash.success.trim()) ||
        (typeof flash?.error === 'string' && flash.error.trim()) ||
        null;

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold leading-tight text-ink">
                            SEO
                        </h2>
                        <HelpGuide help={HELP.seo} />
                    </div>
                </div>
            }
        >
            <Head title="SEO" />

            <div className="atlas-shell space-y-5 stagger">
                {flashMsg ? (
                    <div
                        className={`rounded-lg border px-4 py-2.5 text-sm ${
                            flash?.error
                                ? 'border-rose-200 bg-rose-50 text-rose-800'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        }`}
                    >
                        {flashMsg}
                    </div>
                ) : null}

                <div className="atlas-panel flex flex-wrap items-end justify-between gap-3 p-4">
                    <div className="min-w-0 flex-1 sm:max-w-xs">
                        <InputLabel value="Website" />
                        <div className="mt-1.5">
                            {sites.length > 0 ? (
                                <SelectMenu
                                    value={site?.id || ''}
                                    onChange={(id) => switchSite(id)}
                                    placeholder="Choose a site"
                                    options={sites.map((item) => ({
                                        value: item.id,
                                        label: item.domain,
                                    }))}
                                />
                            ) : (
                                <p className="text-sm text-ink-muted">
                                    No website in <span className="font-semibold text-ink">{workspace.name}</span>.
                                </p>
                            )}
                        </div>
                    </div>
                    <SecondaryButton type="button" onClick={() => setAddingSite((v) => !v)}>
                        {addingSite ? 'Cancel' : 'Add website'}
                    </SecondaryButton>
                </div>

                {sites.length === 0 && other_workspaces_with_sites.length > 0 ? (
                    <section className="atlas-panel space-y-3 border border-amber-200 bg-amber-50/70 p-4">
                        <div>
                            <div className="font-semibold text-ink">
                                Domains are in another workspace
                            </div>
                            <p className="mt-1 text-sm text-ink-muted">
                                You&apos;re on <span className="font-semibold text-ink">{workspace.name}</span>,
                                which has no sites. Switch to load your saved domains.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {other_workspaces_with_sites.map((ws) => (
                                <PrimaryButton
                                    key={ws.id}
                                    type="button"
                                    onClick={() =>
                                        router.post(route('workspaces.switch', ws.id), {
                                            redirect: 'seo',
                                        })
                                    }
                                >
                                    Switch to {ws.name} ({ws.sites_count})
                                </PrimaryButton>
                            ))}
                        </div>
                    </section>
                ) : null}

                {addingSite || sites.length === 0 ? (
                    <form
                        className="atlas-panel space-y-3 p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            siteForm.post(route('seo.sites.store'), {
                                onSuccess: () => {
                                    siteForm.reset();
                                    setAddingSite(false);
                                },
                            });
                        }}
                    >
                        <h3 className="font-display text-lg font-bold text-ink">Add a website</h3>
                        <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                            <div>
                                <InputLabel value="Domain" />
                                <TextInput
                                    className="mt-1.5 block w-full"
                                    placeholder="example.com"
                                    value={siteForm.data.domain}
                                    onChange={(e) => siteForm.setData('domain', e.target.value)}
                                    required
                                />
                                {errors?.domain ? (
                                    <p className="mt-1 text-xs text-rose-600">{errors.domain}</p>
                                ) : null}
                            </div>
                            <div className="sm:w-36">
                                <InputLabel value="Auto-check" />
                                <div className="mt-1.5">
                                    <SelectMenu
                                        value={siteForm.data.crawl_frequency}
                                        onChange={(v) => siteForm.setData('crawl_frequency', v)}
                                        options={[
                                            { value: 'daily', label: 'Daily' },
                                            { value: 'weekly', label: 'Weekly' },
                                            { value: 'manual', label: 'Manual' },
                                        ]}
                                    />
                                </div>
                            </div>
                            <PrimaryButton processing={siteForm.processing}>
                                {siteForm.processing ? 'Scanning…' : 'Add + scan'}
                            </PrimaryButton>
                        </div>
                    </form>
                ) : null}

                {!site ? (
                    <div className="atlas-panel px-4 py-16 text-center text-sm text-ink-muted">
                        Connect a website to start.
                    </div>
                ) : (
                    <>
                        <section className="atlas-panel p-4 sm:p-5">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="font-display text-xl font-bold text-ink">
                                            {site.domain}
                                        </h3>
                                        <HelpGuide help={SEO_HELP.overview} />
                                        <Badge className="border-emerald-200 bg-emerald-50 text-emerald-800">
                                            {site.status}
                                        </Badge>
                                    </div>
                                    <p className="mt-1 text-sm text-ink-muted">
                                        Last scan {site.last_crawled_label || 'never'}
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-4">
                                    <div className="text-center">
                                        <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                            Health
                                        </div>
                                        <div
                                            className={`font-display text-3xl font-bold tabular-nums ${
                                                (site.health_score ?? 0) >= 70
                                                    ? 'text-emerald-600'
                                                    : (site.health_score ?? 0) >= 40
                                                      ? 'text-amber-600'
                                                      : 'text-rose-600'
                                            }`}
                                        >
                                            {site.health_score ?? 0}%
                                        </div>
                                    </div>
                                    <div className="hidden h-10 w-px bg-line sm:block" />
                                    <div className="grid grid-cols-3 gap-4 text-center">
                                        <div>
                                            <div className="font-display text-xl font-bold text-rose-600">
                                                {criticalCount}
                                            </div>
                                            <div className="text-[11px] text-ink-muted">Critical</div>
                                        </div>
                                        <div>
                                            <div className="font-display text-xl font-bold text-ink">
                                                {openTasks.length}
                                            </div>
                                            <div className="text-[11px] text-ink-muted">To-dos</div>
                                        </div>
                                        <div>
                                            <div className="font-display text-xl font-bold text-ink">
                                                {stats?.pages ?? pages.length}
                                            </div>
                                            <div className="text-[11px] text-ink-muted">Pages</div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
                                <PrimaryButton
                                    type="button"
                                    processing={scanning}
                                    onClick={() => {
                                        setScanning(true);
                                        router.post(
                                            route('seo.sites.crawl', site.id),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onFinish: () => setScanning(false),
                                            },
                                        );
                                    }}
                                >
                                    Scan again
                                </PrimaryButton>
                                <SecondaryButton
                                    type="button"
                                    disabled={seoJsLocked || !providers.browserless}
                                    processing={scanning}
                                    onClick={() => {
                                        setScanning(true);
                                        router.post(
                                            route('seo.sites.crawl-mode', site.id),
                                            { crawl_mode: 'js' },
                                            {
                                                preserveScroll: true,
                                                onFinish: () => setScanning(false),
                                            },
                                        );
                                    }}
                                    title={
                                        !providers.browserless
                                            ? 'Set BROWSERLESS_TOKEN (or BROWSERLESS_URL) on the server'
                                            : seoJsLocked
                                              ? 'Needs paid plan or credit top-up'
                                              : 'Render React/SPA pages in a real browser'
                                    }
                                >
                                    JS crawl (React)
                                </SecondaryButton>
                                {(site.crawl_mode || 'static') === 'js' && (
                                    <SecondaryButton
                                        type="button"
                                        processing={scanning}
                                        onClick={() => {
                                            setScanning(true);
                                            router.post(
                                                route('seo.sites.crawl-mode', site.id),
                                                { crawl_mode: 'static' },
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () => setScanning(false),
                                                },
                                            );
                                        }}
                                    >
                                        Back to static
                                    </SecondaryButton>
                                )}
                                <SecondaryButton
                                    type="button"
                                    processing={buildingTodos}
                                    onClick={() => {
                                        setBuildingTodos(true);
                                        setTab('fix');
                                        router.post(
                                            route('seo.tasks.generate'),
                                            { site_id: site.id },
                                            {
                                                preserveScroll: true,
                                                onFinish: () => {
                                                    setBuildingTodos(false);
                                                },
                                            },
                                        );
                                    }}
                                >
                                    Build to-dos
                                </SecondaryButton>
                                <SecondaryButton
                                    type="button"
                                    disabled={seoApisLocked || !site}
                                    onClick={async () => {
                                        if (site.gsc_connected) {
                                            if (gscOnCooldown) {
                                                await confirmAsk({
                                                    title: 'Already synced recently',
                                                    message: `Google Search Console data was synced recently. Next sync in ${gscCooldownLabel} to protect free Google API quota.`,
                                                    confirmLabel: 'OK',
                                                });
                                                return;
                                            }
                                            const ok = await confirmAsk({
                                                title: 'Already connected',
                                                message: `Google Search Console is already connected. Sync latest data now? (Limited to about once every ${site.gsc_sync_cooldown_minutes || 60} minutes so free Google quota lasts.)`,
                                                confirmLabel: 'Sync data',
                                            });
                                            if (!ok) {
                                                return;
                                            }
                                            router.post(route('seo.sites.gsc.sync', site.id));
                                            setTab('keywords');
                                            return;
                                        }
                                        if (!providers.google_oauth) {
                                            router.visit(
                                                route('settings.index', {
                                                    tab: 'providers',
                                                    category: 'seo',
                                                    configure: 'google_gsc',
                                                }),
                                            );
                                            return;
                                        }
                                        window.location.assign(route('seo.sites.gsc', site.id));
                                    }}
                                >
                                    {site.gsc_connected ? 'GSC connected' : 'Connect GSC'}
                                </SecondaryButton>
                                <SecondaryButton
                                    type="button"
                                    disabled={seoApisLocked || pagespeedOnCooldown}
                                    title={
                                        pagespeedOnCooldown
                                            ? `Next speed check in ${pagespeedCooldownLabel}`
                                            : undefined
                                    }
                                    onClick={async () => {
                                        if (pagespeedOnCooldown) {
                                            await confirmAsk({
                                                title: 'Speed check locked',
                                                message: `You already ran ${site?.pagespeed_max_runs || 2} speed checks. Button unlocks in ${pagespeedCooldownLabel}.`,
                                                confirmLabel: 'OK',
                                            });
                                            return;
                                        }
                                        if (!providers.pagespeed) {
                                            router.visit(
                                                route('settings.index', {
                                                    tab: 'providers',
                                                    category: 'seo',
                                                    configure: 'google_pagespeed',
                                                }),
                                            );
                                            return;
                                        }
                                        const remainingRuns =
                                            site?.pagespeed_runs_remaining ??
                                            site?.pagespeed_max_runs ??
                                            2;
                                        const strategyLabel =
                                            psiStrategy === 'desktop' ? 'Desktop' : 'Mobile';
                                        const ok = await confirmAsk({
                                            title: `Run ${strategyLabel} PageSpeed?`,
                                            message: `Same Google PageSpeed Insights API (${strategyLabel} lab data). Up to ${site?.pagespeed_max_runs || 2} checks every ${site?.pagespeed_cooldown_minutes || 30} minutes (${remainingRuns} left). Mobile and Desktop scores differ — compare like-for-like.`,
                                            confirmLabel: `Run ${strategyLabel}`,
                                        });
                                        if (!ok) {
                                            return;
                                        }
                                        setTab('speed');
                                        router.post(route('seo.sites.pagespeed', site.id), {
                                            strategy: psiStrategy,
                                        });
                                    }}
                                >
                                    {pagespeedOnCooldown
                                        ? `Speed in ${pagespeedCooldownLabel}`
                                        : 'Speed check'}
                                </SecondaryButton>
                            </div>
                            {site.last_crawl_error ? (
                                <p className="mt-3 text-sm text-rose-700">{site.last_crawl_error}</p>
                            ) : null}
                        </section>

                        <div className="inline-flex flex-wrap gap-0.5 rounded-lg border border-line bg-mist/80 p-1">
                            {TABS.map((t) => (
                                <button
                                    key={t.id}
                                    type="button"
                                    onClick={() => {
                                        setTab(t.id);
                                        const url = new URL(window.location.href);
                                        url.searchParams.set('tab', t.id);
                                        window.history.replaceState({}, '', url);
                                    }}
                                    className={`rounded-md px-3.5 py-1.5 text-sm font-semibold transition ${
                                        tab === t.id
                                            ? 'bg-white text-ink shadow-sm'
                                            : 'text-ink-muted hover:text-ink'
                                    }`}
                                >
                                    {t.label}
                                    {t.id === 'fix' && openIssues.length > 0 ? (
                                        <span className="ml-1.5 inline-flex min-w-[1.15rem] justify-center rounded-full bg-rose-100 px-1 text-[10px] font-bold text-rose-700">
                                            {openIssues.length}
                                        </span>
                                    ) : null}
                                    {t.id === 'speed' && site.pagespeed_score != null ? (
                                        <span className="ml-1.5 text-[10px] font-bold tabular-nums text-ink-muted">
                                            {site.pagespeed_score}
                                        </span>
                                    ) : null}
                                </button>
                            ))}
                        </div>

                        {tab === 'speed' ? (
                            <section className="atlas-panel space-y-4 p-4 sm:p-5">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="text-sm font-bold text-ink">
                                        PageSpeed Insights
                                    </div>
                                    <div className="inline-flex rounded-md border border-line bg-mist/80 p-0.5">
                                        {[
                                            { id: 'mobile', label: 'Mobile' },
                                            { id: 'desktop', label: 'Desktop' },
                                        ].map((opt) => (
                                            <button
                                                key={opt.id}
                                                type="button"
                                                onClick={() => setPsiStrategy(opt.id)}
                                                className={`rounded px-3 py-1.5 text-xs font-semibold transition ${
                                                    psiStrategy === opt.id
                                                        ? 'bg-white text-ink shadow-sm'
                                                        : 'text-ink-muted hover:text-ink'
                                                }`}
                                            >
                                                {opt.label}
                                            </button>
                                        ))}
                                    </div>
                                </div>

                                {psiSnapshot ? (
                                    <>
                                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                            <PsiScoreRing
                                                score={psiSnapshot.categories?.performance}
                                                label="Performance"
                                            />
                                            <PsiScoreRing
                                                score={psiSnapshot.categories?.accessibility}
                                                label="Accessibility"
                                            />
                                            <PsiScoreRing
                                                score={psiSnapshot.categories?.['best-practices']}
                                                label="Best Practices"
                                            />
                                            <PsiScoreRing
                                                score={psiSnapshot.categories?.seo}
                                                label="SEO"
                                            />
                                        </div>
                                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                                            <PsiMetric
                                                label="FCP"
                                                value={
                                                    psiSnapshot.metrics?.fcp != null
                                                        ? `${psiSnapshot.metrics.fcp}s`
                                                        : '—'
                                                }
                                                hint="First paint"
                                            />
                                            <PsiMetric
                                                label="LCP"
                                                value={
                                                    psiSnapshot.metrics?.lcp != null
                                                        ? `${psiSnapshot.metrics.lcp}s`
                                                        : '—'
                                                }
                                                hint="Largest paint"
                                            />
                                            <PsiMetric
                                                label="TBT"
                                                value={
                                                    psiSnapshot.metrics?.tbt != null
                                                        ? `${psiSnapshot.metrics.tbt}ms`
                                                        : '—'
                                                }
                                                hint="Blocking time"
                                            />
                                            <PsiMetric
                                                label="CLS"
                                                value={
                                                    psiSnapshot.metrics?.cls != null
                                                        ? psiSnapshot.metrics.cls
                                                        : '—'
                                                }
                                                hint="Layout shift"
                                            />
                                            <PsiMetric
                                                label="SI"
                                                value={
                                                    psiSnapshot.metrics?.si != null
                                                        ? `${psiSnapshot.metrics.si}s`
                                                        : '—'
                                                }
                                                hint="Speed index"
                                            />
                                            <PsiMetric
                                                label="INP"
                                                value={
                                                    psiSnapshot.metrics?.inp != null
                                                        ? `${psiSnapshot.metrics.inp}ms`
                                                        : '—'
                                                }
                                                hint="Interaction"
                                            />
                                        </div>
                                        <p className="text-[11px] text-ink-muted">
                                            Showing{' '}
                                            {psiStrategy === 'desktop' ? 'Desktop' : 'Mobile'}
                                            {psiSnapshot.checked_at
                                                ? ` · checked ${new Date(
                                                      psiSnapshot.checked_at,
                                                  ).toLocaleString()}`
                                                : site.pagespeed_strategy === psiStrategy &&
                                                    site.pagespeed_checked_at
                                                  ? ` · checked ${site.pagespeed_checked_at}`
                                                  : ''}
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-sm text-ink-muted">
                                        No {psiStrategy} report yet. Run Speed check above.
                                    </p>
                                )}

                                {site.pagespeed_error ? (
                                    <p className="text-xs text-rose-600">{site.pagespeed_error}</p>
                                ) : null}

                                {psiIssues.length > 0 ? (
                                    <div className="border-t border-line pt-4">
                                        <div className="mb-2 flex items-baseline justify-between gap-2">
                                            <h4 className="text-sm font-bold text-ink">
                                                Diagnostics ·{' '}
                                                {psiStrategy === 'desktop' ? 'Desktop' : 'Mobile'}
                                            </h4>
                                            <span className="text-[11px] text-ink-muted">
                                                {psiIssues.length} item
                                                {psiIssues.length === 1 ? '' : 's'}
                                            </span>
                                        </div>
                                        <ul className="max-h-[28rem] space-y-2 overflow-y-auto">
                                            {psiIssues.map((issue) => (
                                                <li
                                                    key={issue.id}
                                                    className="rounded-md border border-line bg-mist/40 px-3 py-2.5"
                                                >
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <Badge
                                                            className={
                                                                issue.group === 'opportunities'
                                                                    ? 'border-amber-200 bg-amber-50 text-amber-800'
                                                                    : 'border-sky-200 bg-sky-50 text-sky-800'
                                                            }
                                                        >
                                                            {issue.group === 'opportunities'
                                                                ? 'Opportunity'
                                                                : 'Diagnostic'}
                                                        </Badge>
                                                        {issue.display_value ? (
                                                            <span className="text-[11px] font-semibold text-ink-muted">
                                                                {issue.display_value}
                                                            </span>
                                                        ) : null}
                                                        {issue.savings_ms != null &&
                                                        issue.savings_ms > 0 ? (
                                                            <span className="text-[11px] font-semibold text-emerald-700">
                                                                ~{Math.round(issue.savings_ms)}ms
                                                                potential
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                    <div className="mt-1 text-sm font-semibold text-ink">
                                                        {issue.title}
                                                    </div>
                                                    {issue.detail ? (
                                                        <p className="mt-0.5 text-xs leading-relaxed text-ink-muted">
                                                            {issue.detail}
                                                        </p>
                                                    ) : null}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ) : psiSnapshot && !site.pagespeed_error ? (
                                    <p className="text-xs text-ink-muted">
                                        No major speed fixes from the last {psiStrategy} check.
                                    </p>
                                ) : null}
                            </section>
                        ) : null}

                        {tab === 'fix' ? (
                            <div className="grid items-start gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                                <section className="atlas-panel overflow-hidden">
                                    <PanelTitle
                                        title="Problems to fix"
                                        help={SEO_HELP.fix}
                                        action={
                                            site ? (
                                                <a
                                                    href={route('seo.export', {
                                                        type: 'issues',
                                                        site_id: site.id,
                                                    })}
                                                    title="Export issues to Excel"
                                                    aria-label="Export issues to Excel"
                                                    className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line bg-white text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-50"
                                                >
                                                    <ExcelIcon />
                                                </a>
                                            ) : null
                                        }
                                    />
                                    <ul className="max-h-[28rem] divide-y divide-line overflow-y-auto">
                                        {openIssues.length === 0 ? (
                                            <li className="px-4 py-10 text-center text-sm text-emerald-700">
                                                No open issues.
                                            </li>
                                        ) : (
                                            openIssues.map((issue) => (
                                                <li
                                                    key={issue.id}
                                                    className="flex items-start gap-3 px-4 py-3"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge
                                                                className={
                                                                    severityTone[issue.severity] ||
                                                                    severityTone.info
                                                                }
                                                            >
                                                                {issue.severity}
                                                            </Badge>
                                                            <span className="text-sm font-semibold text-ink">
                                                                {issue.message}
                                                            </span>
                                                        </div>
                                                        <IssueSourceLinks
                                                            pageUrl={issue.page_url}
                                                            assetUrls={issue.asset_urls}
                                                        />
                                                        {issue.suggestion ? (
                                                            <p className="mt-1 text-xs text-ink-muted">
                                                                {issue.suggestion}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            router.post(
                                                                route('seo.issues.resolve', issue.id),
                                                            )
                                                        }
                                                        className="shrink-0 rounded-md border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-800"
                                                    >
                                                        Done
                                                    </button>
                                                </li>
                                            ))
                                        )}
                                    </ul>
                                </section>

                                <div className="space-y-4">
                                    <section id="seo-todos" className="atlas-panel overflow-hidden">
                                        <PanelTitle
                                            title="Today’s to-dos"
                                            help={SEO_HELP.todos}
                                            action={
                                                site ? (
                                                    <a
                                                        href={route('seo.export', {
                                                            type: 'tasks',
                                                            site_id: site.id,
                                                        })}
                                                        title="Export to-dos to Excel"
                                                        aria-label="Export to-dos to Excel"
                                                        className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line bg-white text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-50"
                                                    >
                                                        <ExcelIcon />
                                                    </a>
                                                ) : null
                                            }
                                        />
                                        <ul className="max-h-72 divide-y divide-line overflow-y-auto">
                                            {openTasks.length === 0 ? (
                                                <li className="px-4 py-8 text-center text-sm text-ink-muted">
                                                    Click “Build to-dos” after a scan.
                                                </li>
                                            ) : (
                                                openTasks.map((task) => (
                                                    <li
                                                        key={task.id}
                                                        className="flex items-start gap-2 px-4 py-2.5"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="text-sm font-medium text-ink">
                                                                {task.title}
                                                            </div>
                                                            <IssueSourceLinks
                                                                pageUrl={task.page_url}
                                                                assetUrls={task.asset_urls}
                                                            />
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                router.post(
                                                                    route(
                                                                        'seo.tasks.complete',
                                                                        task.id,
                                                                    ),
                                                                )
                                                            }
                                                            className="shrink-0 text-xs font-semibold text-signal-strong"
                                                        >
                                                            Done
                                                        </button>
                                                    </li>
                                                ))
                                            )}
                                        </ul>
                                    </section>

                                    {suggestions.length > 0 ? (
                                        <section className="atlas-panel overflow-hidden">
                                            <div className="border-b border-line px-4 py-3 font-display text-base font-bold text-ink">
                                                AI tips
                                            </div>
                                            <ul className="divide-y divide-line">
                                                {suggestions.slice(0, 4).map((s) => (
                                                    <li key={s.id} className="px-4 py-2.5">
                                                        <div className="text-sm font-semibold text-ink">
                                                            {s.title}
                                                        </div>
                                                        <p className="mt-0.5 line-clamp-2 text-xs text-ink-muted">
                                                            {s.body}
                                                        </p>
                                                        <button
                                                            type="button"
                                                            className="mt-1 text-xs font-semibold text-ink-muted"
                                                            onClick={() =>
                                                                router.post(
                                                                    route(
                                                                        'seo.suggestions.dismiss',
                                                                        s.id,
                                                                    ),
                                                                )
                                                            }
                                                        >
                                                            Dismiss
                                                        </button>
                                                    </li>
                                                ))}
                                            </ul>
                                        </section>
                                    ) : null}
                                </div>
                            </div>
                        ) : null}

                        {tab === 'keywords' ? (
                            <div className="space-y-4">
                                <section className="atlas-panel overflow-hidden">
                                    <PanelTitle
                                        title="Google Search Console"
                                        subtitle={
                                            site?.gsc_connected
                                                ? site.gsc_synced_at
                                                    ? `Last sync ${site.gsc_synced_at}`
                                                    : 'Connected — sync to pull queries'
                                                : 'Connect GSC to see real Google queries, clicks & impressions'
                                        }
                                        action={
                                            site ? (
                                                <div className="flex flex-wrap gap-2">
                                                    {site.gsc_connected ? (
                                                        <>
                                                            <SecondaryButton
                                                                type="button"
                                                                disabled={seoApisLocked}
                                                                title={
                                                                    gscOnCooldown
                                                                        ? `Next sync in ${gscCooldownLabel}`
                                                                        : undefined
                                                                }
                                                                onClick={async () => {
                                                                    if (gscOnCooldown) {
                                                                        await confirmAsk({
                                                                            title: 'Quota cooldown',
                                                                            message: `Next GSC sync in ${gscCooldownLabel}. This limit protects free Google API quota.`,
                                                                            confirmLabel: 'OK',
                                                                        });
                                                                        return;
                                                                    }
                                                                    const ok = await confirmAsk({
                                                                        title: 'Sync GSC data?',
                                                                        message: `Pull latest queries from Google? Allowed about once every ${site.gsc_sync_cooldown_minutes || 60} minutes per site.`,
                                                                        confirmLabel: 'Sync now',
                                                                    });
                                                                    if (ok) {
                                                                        router.post(
                                                                            route(
                                                                                'seo.sites.gsc.sync',
                                                                                site.id,
                                                                            ),
                                                                        );
                                                                    }
                                                                }}
                                                            >
                                                                {gscOnCooldown
                                                                    ? `Sync in ${gscCooldownLabel}`
                                                                    : 'Sync GSC data'}
                                                            </SecondaryButton>
                                                            <SecondaryButton
                                                                type="button"
                                                                onClick={async () => {
                                                                    const ok = await confirmAsk({
                                                                        title: 'Disconnect GSC?',
                                                                        message:
                                                                            'Google query data for this site will be cleared. Crawl / Fix issues stay. You can connect again anytime.',
                                                                        confirmLabel: 'Disconnect',
                                                                    });
                                                                    if (ok) {
                                                                        router.delete(
                                                                            route(
                                                                                'seo.sites.gsc.disconnect',
                                                                                site.id,
                                                                            ),
                                                                        );
                                                                    }
                                                                }}
                                                            >
                                                                Disconnect
                                                            </SecondaryButton>
                                                        </>
                                                    ) : (
                                                        <SecondaryButton
                                                            type="button"
                                                            disabled={seoApisLocked}
                                                            onClick={() => {
                                                                if (!providers.google_oauth) {
                                                                    router.visit(
                                                                        route('settings.index', {
                                                                            tab: 'providers',
                                                                            category: 'seo',
                                                                            configure:
                                                                                'google_gsc',
                                                                        }),
                                                                    );
                                                                    return;
                                                                }
                                                                window.location.assign(
                                                                    route(
                                                                        'seo.sites.gsc',
                                                                        site.id,
                                                                    ),
                                                                );
                                                            }}
                                                        >
                                                            Connect GSC
                                                        </SecondaryButton>
                                                    )}
                                                </div>
                                            ) : null
                                        }
                                    />
                                    {site?.gsc_last_error ? (
                                        <p className="border-b border-line px-4 py-2 text-xs text-rose-600">
                                            {site.gsc_last_error}
                                        </p>
                                    ) : null}
                                    {site?.gsc_summary ? (
                                        <div className="grid grid-cols-2 gap-3 border-b border-line px-4 py-3 sm:grid-cols-4">
                                            {[
                                                {
                                                    label: 'Clicks',
                                                    value: site.gsc_summary.clicks ?? 0,
                                                },
                                                {
                                                    label: 'Impressions',
                                                    value: site.gsc_summary.impressions ?? 0,
                                                },
                                                {
                                                    label: 'Avg CTR',
                                                    value:
                                                        site.gsc_summary.avg_ctr != null
                                                            ? `${site.gsc_summary.avg_ctr}%`
                                                            : '—',
                                                },
                                                {
                                                    label: 'Avg position',
                                                    value: site.gsc_summary.avg_position ?? '—',
                                                },
                                            ].map((m) => (
                                                <div key={m.label}>
                                                    <div className="text-[11px] font-semibold uppercase tracking-wide text-ink-muted">
                                                        {m.label}
                                                    </div>
                                                    <div className="font-display text-xl font-bold tabular-nums text-ink">
                                                        {m.value}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : null}
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-left text-sm">
                                            <thead className="bg-mist/80 text-[11px] uppercase text-ink-muted">
                                                <tr>
                                                    <th className="px-4 py-2.5">Query</th>
                                                    <th className="px-4 py-2.5">Clicks</th>
                                                    <th className="px-4 py-2.5">Impr.</th>
                                                    <th className="px-4 py-2.5">CTR</th>
                                                    <th className="px-4 py-2.5">Pos.</th>
                                                    <th className="px-4 py-2.5" />
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-line">
                                                {(site?.gsc_queries || []).length === 0 ? (
                                                    <tr>
                                                        <td
                                                            colSpan={6}
                                                            className="px-4 py-8 text-center text-ink-muted"
                                                        >
                                                            {site?.gsc_connected
                                                                ? 'No GSC query rows yet — click Sync GSC data (Google often needs a few days of Search Console traffic).'
                                                                : 'Connect GSC to load Google search queries here.'}
                                                        </td>
                                                    </tr>
                                                ) : (
                                                    (site.gsc_queries || []).map((row) => (
                                                        <tr key={row.query}>
                                                            <td className="px-4 py-2.5 font-medium text-ink">
                                                                {row.query}
                                                            </td>
                                                            <td className="px-4 py-2.5 tabular-nums">
                                                                {row.clicks}
                                                            </td>
                                                            <td className="px-4 py-2.5 tabular-nums">
                                                                {row.impressions}
                                                            </td>
                                                            <td className="px-4 py-2.5 tabular-nums">
                                                                {row.ctr}%
                                                            </td>
                                                            <td className="px-4 py-2.5 tabular-nums">
                                                                {row.position}
                                                            </td>
                                                            <td className="px-4 py-2.5 text-right">
                                                                <button
                                                                    type="button"
                                                                    className="text-xs font-semibold text-signal-strong"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route(
                                                                                'seo.keywords.store',
                                                                            ),
                                                                            {
                                                                                keyword: row.query,
                                                                                group_name: 'GSC',
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Track
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </table>
                                    </div>
                                    {site?.gsc_summary?.start ? (
                                        <p className="border-t border-line px-4 py-2 text-[11px] text-ink-muted">
                                            Window {site.gsc_summary.start} → {site.gsc_summary.end}
                                            {site.gsc_summary.property
                                                ? ` · ${site.gsc_summary.property}`
                                                : ''}
                                        </p>
                                    ) : null}
                                </section>

                            <section className="atlas-panel overflow-hidden">
                                <PanelTitle title="Keyword research" />
                                <div className="space-y-3 px-4 py-3">
                                    <div className="flex flex-col gap-2 sm:flex-row">
                                        <TextInput
                                            value={researchSeed}
                                            onChange={(e) => setResearchSeed(e.target.value)}
                                            placeholder="Optional seed (e.g. seo agency Noida)"
                                            className="flex-1"
                                        />
                                        <PrimaryButton
                                            type="button"
                                            disabled={!site || researching}
                                            onClick={() => {
                                                setResearching(true);
                                                router.post(
                                                    route('seo.keywords.research'),
                                                    {
                                                        site_id: site?.id,
                                                        seed: researchSeed,
                                                    },
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: () => setResearching(false),
                                                    },
                                                );
                                            }}
                                        >
                                            {researching ? 'Researching…' : 'Run research'}
                                        </PrimaryButton>
                                    </div>
                                    {researchIdeas.length > 0 ? (
                                        <div className="overflow-x-auto rounded-md border border-line">
                                            <table className="min-w-full text-left text-sm">
                                                <thead className="bg-mist/80 text-[11px] uppercase text-ink-muted">
                                                    <tr>
                                                        <th className="px-3 py-2">Keyword</th>
                                                        <th className="px-3 py-2">Source</th>
                                                        <th className="px-3 py-2">GSC impr.</th>
                                                        <th className="px-3 py-2">Why</th>
                                                        <th className="px-3 py-2" />
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-line">
                                                    {researchIdeas.map((idea) => (
                                                        <tr key={`${idea.source}-${idea.keyword}`}>
                                                            <td className="px-3 py-2 font-medium text-ink">
                                                                {idea.keyword}
                                                            </td>
                                                            <td className="px-3 py-2">
                                                                <Badge
                                                                    className={
                                                                        idea.source === 'gsc'
                                                                            ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                                                                            : idea.source === 'ai'
                                                                              ? 'border-sky-200 bg-sky-50 text-sky-800'
                                                                              : 'border-line bg-mist text-ink-muted'
                                                                    }
                                                                >
                                                                    {idea.source}
                                                                </Badge>
                                                            </td>
                                                            <td className="px-3 py-2 tabular-nums text-ink-muted">
                                                                {idea.impressions != null
                                                                    ? idea.impressions
                                                                    : '—'}
                                                            </td>
                                                            <td className="max-w-xs px-3 py-2 text-xs text-ink-muted">
                                                                {idea.reason}
                                                            </td>
                                                            <td className="px-3 py-2 text-right">
                                                                <button
                                                                    type="button"
                                                                    className="text-xs font-semibold text-signal-strong"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route(
                                                                                'seo.keywords.store',
                                                                            ),
                                                                            {
                                                                                keyword:
                                                                                    idea.keyword,
                                                                                group_name:
                                                                                    idea.source ===
                                                                                    'gsc'
                                                                                        ? 'GSC'
                                                                                        : 'Research',
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Track
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : null}
                                </div>
                            </section>

                            <section className="atlas-panel overflow-hidden">
                                <PanelTitle
                                    title="Keywords"
                                    help={SEO_HELP.keywords}
                                    action={
                                        <div className="flex flex-wrap items-center justify-end gap-2">
                                            {site ? (
                                                <a
                                                    href={route('seo.export', {
                                                        type: 'keywords',
                                                        site_id: site.id,
                                                    })}
                                                    title="Export keywords to Excel"
                                                    aria-label="Export keywords to Excel"
                                                    className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line bg-white text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-50"
                                                >
                                                    <ExcelIcon />
                                                </a>
                                            ) : null}
                                            <SecondaryButton
                                                type="button"
                                                onClick={() => setAddingKeyword((v) => !v)}
                                            >
                                                {addingKeyword ? 'Cancel' : 'Add keyword'}
                                            </SecondaryButton>
                                            {providers.dataforseo ? (
                                                <>
                                                    <SecondaryButton
                                                        type="button"
                                                        disabled={seoMetricsLocked}
                                                        onClick={() =>
                                                            router.post(
                                                                route('seo.keywords.track'),
                                                            )
                                                        }
                                                    >
                                                        Update ranks
                                                    </SecondaryButton>
                                                    <SecondaryButton
                                                        type="button"
                                                        disabled={seoMetricsLocked}
                                                        onClick={() =>
                                                            router.post(
                                                                route('seo.keywords.metrics'),
                                                            )
                                                        }
                                                    >
                                                        Refresh metrics
                                                    </SecondaryButton>
                                                </>
                                            ) : null}
                                        </div>
                                    }
                                />

                                {addingKeyword ? (
                                    <form
                                        className="grid gap-2 border-b border-line px-4 py-3 sm:grid-cols-5"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            keywordForm.post(route('seo.keywords.store'), {
                                                onSuccess: () => {
                                                    keywordForm.reset(
                                                        'keyword',
                                                        'position',
                                                        'location',
                                                    );
                                                    setAddingKeyword(false);
                                                },
                                            });
                                        }}
                                    >
                                        <TextInput
                                            placeholder="Keyword"
                                            value={keywordForm.data.keyword}
                                            onChange={(e) =>
                                                keywordForm.setData('keyword', e.target.value)
                                            }
                                            required
                                        />
                                        <TextInput
                                            placeholder="Group"
                                            value={keywordForm.data.group_name}
                                            onChange={(e) =>
                                                keywordForm.setData('group_name', e.target.value)
                                            }
                                        />
                                        <TextInput
                                            placeholder="City"
                                            value={keywordForm.data.location}
                                            onChange={(e) =>
                                                keywordForm.setData('location', e.target.value)
                                            }
                                        />
                                        <Toggle
                                            checked={!!keywordForm.data.is_local}
                                            onChange={(v) => keywordForm.setData('is_local', v)}
                                            label="Local"
                                        />
                                        <PrimaryButton processing={keywordForm.processing}>
                                            Save
                                        </PrimaryButton>
                                    </form>
                                ) : null}

                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-left text-sm">
                                        <thead className="bg-mist/80 text-[11px] uppercase text-ink-muted">
                                            <tr>
                                                <th className="px-4 py-2.5">Keyword</th>
                                                <th className="px-4 py-2.5">Vol</th>
                                                <th className="px-4 py-2.5">KD</th>
                                                <th className="px-4 py-2.5">Rank</th>
                                                <th className="px-4 py-2.5">Change</th>
                                                <th className="px-4 py-2.5">Checked</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-line">
                                            {keywords.length === 0 ? (
                                                <tr>
                                                    <td
                                                        colSpan={6}
                                                        className="px-4 py-10 text-center text-ink-muted"
                                                    >
                                                        No keywords yet.
                                                    </td>
                                                </tr>
                                            ) : (
                                                keywords.map((kw) => (
                                                    <tr key={kw.id}>
                                                        <td className="px-4 py-2.5 font-semibold text-ink">
                                                            {kw.keyword}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-ink-muted">
                                                            {kw.search_volume != null
                                                                ? Number(
                                                                      kw.search_volume,
                                                                  ).toLocaleString()
                                                                : '—'}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-ink-muted">
                                                            {kw.keyword_difficulty ?? '—'}
                                                        </td>
                                                        <td className="px-4 py-2.5 font-bold text-signal-strong">
                                                            #{kw.position ?? '—'}
                                                        </td>
                                                        <td className="px-4 py-2.5">
                                                            {kw.position_change > 0
                                                                ? `↑ ${kw.position_change}`
                                                                : kw.position_change < 0
                                                                  ? `↓ ${Math.abs(kw.position_change)}`
                                                                  : '—'}
                                                        </td>
                                                        <td className="px-4 py-2.5 text-xs text-ink-muted">
                                                            {kw.last_checked_at || '—'}
                                                        </td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="border-t border-line p-4">
                                    <form
                                        className="max-w-xl space-y-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            competitorForm.post(route('seo.competitors.store'), {
                                                onSuccess: () => competitorForm.reset(),
                                            });
                                        }}
                                    >
                                        <h4 className="text-sm font-bold text-ink">Competitors</h4>
                                        <div className="flex gap-2">
                                            <TextInput
                                                className="flex-1"
                                                placeholder="competitor.com"
                                                value={competitorForm.data.domain}
                                                onChange={(e) =>
                                                    competitorForm.setData(
                                                        'domain',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                            <PrimaryButton
                                                processing={competitorForm.processing}
                                            >
                                                Add
                                            </PrimaryButton>
                                        </div>
                                        <ul className="space-y-1 text-sm text-ink-muted">
                                            {competitors.map((c) => (
                                                <li key={c.id}>
                                                    <span className="font-semibold text-ink">
                                                        {c.domain}
                                                    </span>{' '}
                                                    · {c.overlap_score}%
                                                </li>
                                            ))}
                                        </ul>
                                    </form>
                                </div>
                            </section>
                            </div>
                        ) : null}

                        {tab === 'grow' ? (
                            <div className="space-y-4">
                                <section className="atlas-panel overflow-hidden">
                                    <PanelTitle title="SEO reports" help={SEO_HELP.overview} />
                                    <div className="space-y-3 border-b border-line px-4 py-3">
                                        <p className="text-sm text-ink-muted">
                                            Pick a period, generate a snapshot, then download PDF or
                                            Excel.
                                        </p>
                                        <div className="flex flex-wrap gap-1.5">
                                            {[
                                                { id: 'today', label: 'Today' },
                                                { id: 'weekly', label: 'Weekly' },
                                                { id: 'monthly', label: 'Monthly' },
                                                { id: 'custom', label: 'Custom' },
                                            ].map((opt) => (
                                                <button
                                                    key={opt.id}
                                                    type="button"
                                                    onClick={() => setReportPeriod(opt.id)}
                                                    className={
                                                        'rounded-md border px-3 py-1.5 text-xs font-semibold transition ' +
                                                        (reportPeriod === opt.id
                                                            ? 'border-signal bg-signal-soft text-signal-strong'
                                                            : 'border-line bg-white text-ink hover:border-signal/40')
                                                    }
                                                >
                                                    {opt.label}
                                                </button>
                                            ))}
                                        </div>
                                        {reportPeriod === 'custom' ? (
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                <div>
                                                    <InputLabel value="From" />
                                                    <div className="mt-1.5">
                                                        <DateTimePicker
                                                            dateOnly
                                                            placeholder="From date"
                                                            value={reportStart}
                                                            onChange={setReportStart}
                                                        />
                                                    </div>
                                                </div>
                                                <div>
                                                    <InputLabel value="To" />
                                                    <div className="mt-1.5">
                                                        <DateTimePicker
                                                            dateOnly
                                                            placeholder="To date"
                                                            value={reportEnd}
                                                            onChange={setReportEnd}
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        ) : null}
                                        <SecondaryButton
                                            type="button"
                                            processing={generatingReport}
                                            disabled={
                                                reportPeriod === 'custom' &&
                                                (!reportStart || !reportEnd)
                                            }
                                            onClick={() => {
                                                setGeneratingReport(true);
                                                router.post(
                                                    route('seo.reports.weekly'),
                                                    {
                                                        site_id: site.id,
                                                        period: reportPeriod,
                                                        period_start:
                                                            reportPeriod === 'custom'
                                                                ? reportStart
                                                                : undefined,
                                                        period_end:
                                                            reportPeriod === 'custom'
                                                                ? reportEnd
                                                                : undefined,
                                                    },
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: () =>
                                                            setGeneratingReport(false),
                                                    },
                                                );
                                            }}
                                        >
                                            Generate{' '}
                                            {reportPeriod === 'today'
                                                ? 'today'
                                                : reportPeriod === 'monthly'
                                                  ? 'monthly'
                                                  : reportPeriod === 'custom'
                                                    ? 'custom'
                                                    : 'weekly'}{' '}
                                            report
                                        </SecondaryButton>
                                    </div>
                                    <div className="px-4 py-3">
                                        <ul className="space-y-2">
                                            {reports.length === 0 ? (
                                                <li className="rounded-md border border-dashed border-line px-3 py-6 text-center text-sm text-ink-muted">
                                                    No reports yet — generate one above.
                                                </li>
                                            ) : (
                                                reports.slice(0, 8).map((r) => (
                                                    <li
                                                        key={r.id}
                                                        className="rounded-md border border-line bg-mist/30 px-3 py-2"
                                                    >
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <div className="min-w-0 text-sm text-ink">
                                                                <span className="font-semibold capitalize">
                                                                    {r.summary?.period_label ||
                                                                        r.period}
                                                                </span>
                                                                <span className="text-ink-muted">
                                                                    {' '}
                                                                    · health{' '}
                                                                    {r.summary?.health_score ??
                                                                        '—'}
                                                                    %
                                                                </span>
                                                                {r.period_start && r.period_end ? (
                                                                    <div className="text-xs text-ink-muted">
                                                                        {r.period_start} →{' '}
                                                                        {r.period_end}
                                                                    </div>
                                                                ) : r.period_end ? (
                                                                    <div className="text-xs text-ink-muted">
                                                                        Through {r.period_end}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                            <div className="flex shrink-0 items-center gap-1.5">
                                                                <a
                                                                    href={
                                                                        r.download_pdf ||
                                                                        route(
                                                                            'seo.reports.download',
                                                                            {
                                                                                report: r.id,
                                                                                format: 'pdf',
                                                                            },
                                                                        )
                                                                    }
                                                                    title="Download PDF"
                                                                    aria-label="Download PDF"
                                                                    className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line bg-white text-rose-600 transition hover:border-rose-300 hover:bg-rose-50"
                                                                >
                                                                    <PdfIcon />
                                                                </a>
                                                                <a
                                                                    href={
                                                                        r.download_excel ||
                                                                        route(
                                                                            'seo.reports.download',
                                                                            {
                                                                                report: r.id,
                                                                                format: 'excel',
                                                                            },
                                                                        )
                                                                    }
                                                                    title="Download Excel"
                                                                    aria-label="Download Excel"
                                                                    className="inline-flex h-9 w-9 items-center justify-center rounded-md border border-line bg-white text-emerald-700 transition hover:border-emerald-300 hover:bg-emerald-50"
                                                                >
                                                                    <ExcelIcon />
                                                                </a>
                                                            </div>
                                                        </div>
                                                    </li>
                                                ))
                                            )}
                                        </ul>
                                    </div>
                                </section>

                                <div className="grid gap-4 lg:grid-cols-2">
                                <section className="atlas-panel overflow-hidden">
                                    <PanelTitle
                                        title="Backlinks"
                                        help={SEO_HELP.backlinks}
                                        action={
                                            <SecondaryButton
                                                type="button"
                                                disabled={
                                                    seoBacklinksLocked || !providers.dataforseo
                                                }
                                                onClick={() =>
                                                    router.post(
                                                        route('seo.sites.backlinks', site.id),
                                                    )
                                                }
                                            >
                                                Sync
                                            </SecondaryButton>
                                        }
                                    />
                                    <div className="grid grid-cols-3 gap-2 border-b border-line px-4 py-3 text-center">
                                        <div>
                                            <div className="font-display text-xl font-bold text-ink">
                                                {backlinks.summary?.backlinks ?? '—'}
                                            </div>
                                            <div className="text-[11px] text-ink-muted">
                                                Total links
                                            </div>
                                        </div>
                                        <div>
                                            <div className="font-display text-xl font-bold text-ink">
                                                {backlinks.summary?.referring_domains ?? '—'}
                                            </div>
                                            <div className="text-[11px] text-ink-muted">
                                                Unique sites
                                            </div>
                                        </div>
                                        <div className="self-center text-xs text-ink-muted">
                                            {backlinks.summary?.synced_at ?? 'Not synced'}
                                        </div>
                                    </div>
                                    <ul className="max-h-64 divide-y divide-line overflow-y-auto text-sm">
                                        {(backlinks.items || []).length === 0 ? (
                                            <li className="px-4 py-8 text-center text-ink-muted">
                                                Click Sync to load backlinks.
                                            </li>
                                        ) : (
                                            backlinks.items.map((b) => (
                                                <li key={b.id} className="px-4 py-2">
                                                    <div className="font-semibold text-ink">
                                                        {b.source_domain || b.source_url}
                                                    </div>
                                                    <div className="truncate text-xs text-ink-muted">
                                                        {b.anchor || '—'}
                                                    </div>
                                                </li>
                                            ))
                                        )}
                                    </ul>
                                </section>

                                <section className="atlas-panel overflow-hidden">
                                    <PanelTitle
                                        title="Local pack / Maps"
                                        help={SEO_HELP.localPack}
                                    />
                                    <form
                                        className="grid gap-3 border-b border-line px-4 py-3 sm:grid-cols-2"
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            localForm.transform((d) => ({
                                                ...d,
                                                site_id: site.id,
                                            }));
                                            localForm.post(route('seo.local.store'), {
                                                onSuccess: () =>
                                                    localForm.reset('keyword', 'location_name'),
                                            });
                                        }}
                                    >
                                        <div>
                                            <InputLabel value="Keyword" />
                                            <TextInput
                                                className="mt-1.5"
                                                placeholder="e.g. plumber near me"
                                                value={localForm.data.keyword}
                                                onChange={(e) =>
                                                    localForm.setData('keyword', e.target.value)
                                                }
                                                required
                                            />
                                        </div>
                                        <div>
                                            <InputLabel value="City" />
                                            <TextInput
                                                className="mt-1.5"
                                                placeholder="Jaipur,India"
                                                value={localForm.data.location_name}
                                                onChange={(e) =>
                                                    localForm.setData(
                                                        'location_name',
                                                        e.target.value,
                                                    )
                                                }
                                                required
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <InputLabel value="Business name on Google" />
                                            <TextInput
                                                className="mt-1.5"
                                                value={localForm.data.business_name}
                                                onChange={(e) =>
                                                    localForm.setData(
                                                        'business_name',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <PrimaryButton
                                            className="sm:col-span-2"
                                            processing={localForm.processing}
                                            disabled={seoLocalLocked}
                                        >
                                            Add local target
                                        </PrimaryButton>
                                    </form>
                                    <ul className="max-h-64 divide-y divide-line overflow-y-auto">
                                        {local_targets.length === 0 ? (
                                            <li className="px-4 py-8 text-center text-sm text-ink-muted">
                                                No local targets yet.
                                            </li>
                                        ) : (
                                            local_targets.map((t) => (
                                                <li
                                                    key={t.id}
                                                    className="flex items-center justify-between gap-2 px-4 py-2.5"
                                                >
                                                    <div className="min-w-0">
                                                        <div className="truncate text-sm font-semibold text-ink">
                                                            {t.keyword}
                                                        </div>
                                                        <div className="text-xs text-ink-muted">
                                                            {t.location_name} ·{' '}
                                                            {t.our_rank != null
                                                                ? `#${t.our_rank}`
                                                                : '—'}
                                                        </div>
                                                    </div>
                                                    <SecondaryButton
                                                        type="button"
                                                        disabled={seoLocalLocked}
                                                        onClick={() =>
                                                            router.post(
                                                                route('seo.local.track', t.id),
                                                            )
                                                        }
                                                    >
                                                        Check
                                                    </SecondaryButton>
                                                </li>
                                            ))
                                        )}
                                    </ul>
                                </section>
                                </div>
                            </div>
                        ) : null}

                        {tab === 'publish' ? (
                            <div className="space-y-4">
                                <section className="atlas-panel overflow-hidden">
                                    <PanelTitle
                                        title="Your blogs"
                                        subtitle="Fetch from RSS or /blog sitemap URLs, then share to Reddit and other sites for backlinks (your account)."
                                        action={
                                            <SecondaryButton
                                                type="button"
                                                disabled={!site}
                                                processing={syncingBlogs}
                                                onClick={() => {
                                                    if (!site) return;
                                                    setSyncingBlogs(true);
                                                    router.post(
                                                        route('seo.blogs.sync', site.id),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                            onFinish: () => setSyncingBlogs(false),
                                                        },
                                                    );
                                                }}
                                            >
                                                Fetch blogs
                                            </SecondaryButton>
                                        }
                                    />
                                    <div className="border-b border-line px-4 py-2 text-xs text-ink-muted">
                                        {blog_feed_url
                                            ? `Feed: ${blog_feed_url}`
                                            : 'Looks for RSS (/feed, /rss.xml, …) then sitemap /blog paths.'}
                                        {blog_synced_at ? ` · Synced ${blog_synced_at}` : ''}
                                    </div>
                                    <ul className="divide-y divide-line">
                                        {blog_posts.length === 0 ? (
                                            <li className="px-4 py-10 text-center text-sm text-ink-muted">
                                                No blogs listed yet. Click Fetch blogs — needs RSS or
                                                URLs like /blog/... in your sitemap.
                                            </li>
                                        ) : (
                                            blog_posts.map((post) => (
                                                <li
                                                    key={post.id}
                                                    className="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
                                                >
                                                    <div className="min-w-0 flex-1">
                                                        <a
                                                            href={post.url}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-semibold text-ink hover:underline"
                                                        >
                                                            {post.title}
                                                        </a>
                                                        <div className="mt-0.5 truncate text-xs text-ink-muted">
                                                            {post.url}
                                                        </div>
                                                        <div className="mt-1 text-[11px] uppercase tracking-wide text-ink-muted">
                                                            {post.source}
                                                            {post.published_at
                                                                ? ` · ${post.published_at}`
                                                                : ''}
                                                            {post.share_count
                                                                ? ` · ${post.share_count} share(s)`
                                                                : ''}
                                                            {post.last_shared_at
                                                                ? ` · last ${post.last_shared_at}`
                                                                : ''}
                                                        </div>
                                                    </div>
                                                    <div className="relative">
                                                        <PrimaryButton
                                                            type="button"
                                                            processing={sharingBlogId === post.id}
                                                            onClick={() =>
                                                                setShareMenuPostId((id) =>
                                                                    id === post.id ? null : post.id,
                                                                )
                                                            }
                                                        >
                                                            Share for backlinks
                                                        </PrimaryButton>
                                                        {shareMenuPostId === post.id ? (
                                                            <div className="absolute right-0 z-20 mt-2 w-56 rounded-xl border border-line bg-white p-1 shadow-lg">
                                                                {blog_share_channels.map((ch) => (
                                                                    <button
                                                                        key={ch.id}
                                                                        type="button"
                                                                        className="flex w-full flex-col rounded-lg px-3 py-2 text-left hover:bg-surface"
                                                                        onClick={() => {
                                                                            setSharingBlogId(post.id);
                                                                            setShareMenuPostId(null);
                                                                            router.post(
                                                                                route(
                                                                                    'seo.blogs.share',
                                                                                    post.id,
                                                                                ),
                                                                                {
                                                                                    channel: ch.id,
                                                                                },
                                                                                {
                                                                                    preserveScroll: true,
                                                                                    onFinish: () =>
                                                                                        setSharingBlogId(
                                                                                            null,
                                                                                        ),
                                                                                },
                                                                            );
                                                                        }}
                                                                    >
                                                                        <span className="text-sm font-semibold text-ink">
                                                                            {ch.label}
                                                                        </span>
                                                                        <span className="text-[11px] text-ink-muted">
                                                                            {ch.blurb}
                                                                        </span>
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        ) : null}
                                                    </div>
                                                </li>
                                            ))
                                        )}
                                    </ul>
                                </section>

                                {!seoCmsLocked ? (
                                    <section className="atlas-panel overflow-hidden">
                                        <PanelTitle title="WordPress (optional)" />
                                        <div className="grid gap-4 border-b border-line p-4 lg:grid-cols-2">
                                            <form
                                                className="space-y-2"
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    cmsForm.post(route('seo.cms.store'), {
                                                        onSuccess: () => cmsForm.reset(),
                                                    });
                                                }}
                                            >
                                                <h4 className="text-sm font-bold text-ink">
                                                    Connect WordPress
                                                </h4>
                                                <TextInput
                                                    placeholder="https://yoursite.com"
                                                    value={cmsForm.data.base_url}
                                                    onChange={(e) =>
                                                        cmsForm.setData('base_url', e.target.value)
                                                    }
                                                    required
                                                />
                                                <TextInput
                                                    placeholder="Username"
                                                    value={cmsForm.data.username}
                                                    onChange={(e) =>
                                                        cmsForm.setData('username', e.target.value)
                                                    }
                                                    required
                                                />
                                                <TextInput
                                                    type="password"
                                                    placeholder="Application password"
                                                    value={cmsForm.data.app_password}
                                                    onChange={(e) =>
                                                        cmsForm.setData(
                                                            'app_password',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                                <PrimaryButton processing={cmsForm.processing}>
                                                    Connect
                                                </PrimaryButton>
                                            </form>
                                            <form
                                                className="space-y-2"
                                                onSubmit={(e) => {
                                                    e.preventDefault();
                                                    draftForm.post(route('seo.content.store'), {
                                                        onSuccess: () =>
                                                            draftForm.reset('keyword'),
                                                    });
                                                }}
                                            >
                                                <h4 className="text-sm font-bold text-ink">
                                                    New draft
                                                </h4>
                                                <TextInput
                                                    placeholder="Topic / keyword"
                                                    value={draftForm.data.keyword}
                                                    onChange={(e) =>
                                                        draftForm.setData(
                                                            'keyword',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                                <PrimaryButton processing={draftForm.processing}>
                                                    Create draft
                                                </PrimaryButton>
                                            </form>
                                        </div>
                                        <ul className="divide-y divide-line">
                                            {content_drafts.length === 0 ? (
                                                <li className="px-4 py-8 text-center text-sm text-ink-muted">
                                                    No WordPress drafts.
                                                </li>
                                            ) : (
                                                content_drafts.map((d) => (
                                                    <li
                                                        key={d.id}
                                                        className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                                                    >
                                                        <div>
                                                            <div className="font-semibold text-ink">
                                                                {d.title}
                                                            </div>
                                                            <div className="text-xs uppercase text-ink-muted">
                                                                {d.status}
                                                                {d.published_url
                                                                    ? ` · ${d.published_url}`
                                                                    : ''}
                                                            </div>
                                                        </div>
                                                        <div className="flex gap-2">
                                                            {d.status === 'draft' ||
                                                            d.status === 'failed' ? (
                                                                <SecondaryButton
                                                                    type="button"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route(
                                                                                'seo.content.approve',
                                                                                d.id,
                                                                            ),
                                                                        )
                                                                    }
                                                                >
                                                                    Approve
                                                                </SecondaryButton>
                                                            ) : null}
                                                            {(d.status === 'approved' ||
                                                                d.status === 'draft') &&
                                                            cms_connections[0] ? (
                                                                <PrimaryButton
                                                                    type="button"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route(
                                                                                'seo.content.publish',
                                                                                d.id,
                                                                            ),
                                                                            {
                                                                                cms_connection_id:
                                                                                    cms_connections[0]
                                                                                        .id,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Publish
                                                                </PrimaryButton>
                                                            ) : null}
                                                        </div>
                                                    </li>
                                                ))
                                            )}
                                        </ul>
                                    </section>
                                ) : null}
                            </div>
                        ) : null}

                        {tab === 'map' ? (
                            <section className="atlas-panel overflow-hidden">
                                <PanelTitle
                                    title="Site map"
                                    help={SEO_HELP.siteMap}
                                    action={
                                        <SecondaryButton
                                            type="button"
                                            onClick={() =>
                                                router.get(
                                                    route('seo.index'),
                                                    {
                                                        site: site.id,
                                                        tab: 'map',
                                                        refresh_sitemap: 1,
                                                    },
                                                    { preserveState: false },
                                                )
                                            }
                                        >
                                            Refresh sitemap
                                        </SecondaryButton>
                                    }
                                />
                                {architecture.sitemap_url ? (
                                    <div className="border-b border-line px-4 py-2 text-xs text-ink-muted">
                                        Source:{' '}
                                        <a
                                            href={architecture.sitemap_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="font-semibold text-sky-700 underline-offset-2 hover:underline"
                                        >
                                            {architecture.sitemap_url}
                                        </a>
                                        {(architecture.nodes || []).length > 0
                                            ? ` · ${(architecture.nodes || []).length} URL${
                                                  (architecture.nodes || []).length === 1
                                                      ? ''
                                                      : 's'
                                              }`
                                            : ''}
                                    </div>
                                ) : null}
                                {architecture.error ? (
                                    <p className="border-b border-line px-4 py-2 text-xs text-rose-700">
                                        {architecture.error}
                                    </p>
                                ) : null}
                                <ul className="max-h-[28rem] divide-y divide-line overflow-y-auto">
                                    {(architecture.nodes || []).length === 0 ? (
                                        <li className="px-4 py-10 text-center text-sm text-ink-muted">
                                            {architecture.error
                                                ? 'Fix sitemap.xml then click Refresh sitemap.'
                                                : 'Loading URLs from sitemap.xml…'}
                                        </li>
                                    ) : (
                                        architecture.nodes.map((n) => (
                                            <li key={n.id} className="px-4 py-2.5">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    {n.priority != null && n.priority !== '' ? (
                                                        <span className="rounded bg-mist px-1.5 text-[10px] font-bold text-ink-muted">
                                                            p {n.priority}
                                                        </span>
                                                    ) : null}
                                                    <a
                                                        href={n.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="font-semibold text-ink underline-offset-2 hover:text-sky-700 hover:underline"
                                                    >
                                                        {n.title || n.url}
                                                    </a>
                                                    {n.crawled ? (
                                                        <Badge className="border-emerald-200 bg-emerald-50 text-emerald-800">
                                                            crawled
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                                <div className="mt-0.5 truncate text-xs text-ink-muted">
                                                    {n.lastmod ? `lastmod ${n.lastmod} · ` : ''}
                                                    {n.url}
                                                </div>
                                            </li>
                                        ))
                                    )}
                                </ul>
                            </section>
                        ) : null}
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
