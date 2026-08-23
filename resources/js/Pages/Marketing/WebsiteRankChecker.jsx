import BrandName from '@/Components/BrandName';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { Link, useForm, usePage } from '@inertiajs/react';

function ScoreRing({ score }) {
    const value = typeof score === 'number' ? score : null;
    const tone =
        value == null
            ? 'text-ink-muted'
            : value >= 75
              ? 'text-emerald-700'
              : value >= 50
                ? 'text-amber-700'
                : 'text-rose-700';

    return (
        <div className="flex flex-col items-center justify-center rounded-2xl border border-line bg-white px-8 py-10 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                Rankway Score
            </p>
            <p className={`mt-3 font-display text-6xl font-bold tabular-nums ${tone}`}>
                {value ?? '—'}
                <span className="text-2xl font-semibold text-ink-muted">/100</span>
            </p>
        </div>
    );
}

function LockedRow({ label }) {
    return (
        <div className="flex items-center justify-between rounded-xl border border-dashed border-line bg-mist/40 px-4 py-3 text-sm">
            <span className="font-medium text-ink-muted">{label}</span>
            <span className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                Locked
            </span>
        </div>
    );
}

export default function WebsiteRankChecker({
    auth,
    canLogin,
    canRegister,
    seo,
    result = null,
    unlocked = false,
    query_domain = '',
}) {
    const { flash } = usePage().props;
    const form = useForm({
        domain: query_domain || result?.domain || '',
        force: false,
    });

    const registerHref = auth?.user ? route('home') : route('register');
    const loginHref = route('login');

    return (
        <MarketingLayout
            seo={seo}
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            jsonLd={{
                '@context': 'https://schema.org',
                '@type': 'WebApplication',
                name: 'Rankway Website Rank Checker',
                applicationCategory: 'BusinessApplication',
                description: seo?.description,
                url: seo?.canonical,
            }}
        >
            <section className="relative overflow-hidden border-b border-line">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_55%_at_50%_0%,rgba(14,159,144,0.16),transparent_55%)]" />
                <div className="relative mx-auto max-w-3xl px-6 py-16 sm:py-20">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Free rank checker
                    </p>
                    <h1 className="mt-4 font-display text-3xl font-bold tracking-tight text-ink sm:text-5xl">
                        Check your website rank
                    </h1>
                    <p className="mt-4 max-w-2xl text-base leading-relaxed text-ink-muted">
                        Get a <BrandName className="text-inherit" /> Score and estimated rank among
                        Rankway-indexed websites — then unlock a full SEO report free.
                    </p>

                    <form
                        className="mt-8 flex flex-col gap-3 sm:flex-row"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('website-rank-checker.check'), {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <TextInput
                            className="w-full flex-1"
                            placeholder="example.com"
                            value={form.data.domain}
                            onChange={(e) => form.setData('domain', e.target.value)}
                            required
                        />
                        <PrimaryButton processing={form.processing} className="shrink-0">
                            {form.processing ? 'Checking…' : 'Check my rank'}
                        </PrimaryButton>
                    </form>
                    {flash?.error ? (
                        <p className="mt-3 text-sm font-medium text-rose-700">{flash.error}</p>
                    ) : null}
                    {flash?.success ? (
                        <p className="mt-3 text-sm font-medium text-emerald-700">{flash.success}</p>
                    ) : null}
                </div>
            </section>

            {result ? (
                <section className="mx-auto max-w-5xl px-6 py-12 sm:py-16">
                    <div className="mb-6">
                        <h2 className="font-display text-2xl font-bold text-ink">{result.domain}</h2>
                        {result.title ? (
                            <p className="mt-1 text-sm text-ink-muted">{result.title}</p>
                        ) : null}
                        <p className="mt-3 max-w-2xl text-xs leading-relaxed text-ink-muted">
                            {result.disclaimer}
                        </p>
                        {result.rank_preview_message ? (
                            <p className="mt-2 max-w-2xl rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-900">
                                {result.rank_preview_message}
                            </p>
                        ) : null}
                        {!result.has_verified_authority && result.data_source === 'probe' ? (
                            <p className="mt-2 max-w-2xl text-xs text-ink-muted">
                                Score uses homepage SEO signals only — connect DataForSEO or wait for
                                backlink data for authority accuracy.
                            </p>
                        ) : null}
                    </div>

                    <div className="grid gap-6 lg:grid-cols-[280px_1fr]">
                        <ScoreRing score={result.rankway_score} />

                        <div className="grid gap-3 sm:grid-cols-3">
                            <div className="rounded-2xl border border-line bg-white p-5">
                                <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                    Estimated global rank
                                </p>
                                <p className="mt-2 font-display text-3xl font-bold tabular-nums text-ink">
                                    {result.global_rank
                                        ? `#${result.global_rank.toLocaleString()}`
                                        : result.rank_among_indexed || 'Preview'}
                                </p>
                            </div>
                            <div className="rounded-2xl border border-line bg-white p-5">
                                <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                    {(result.country || 'IN') === 'IN' ? 'India' : result.country} rank
                                </p>
                                <p className="mt-2 font-display text-3xl font-bold tabular-nums text-ink">
                                    {result.country_rank
                                        ? `#${result.country_rank.toLocaleString()}`
                                        : result.rank_preview
                                          ? 'Preview'
                                          : '—'}
                                </p>
                            </div>
                            <div className="rounded-2xl border border-line bg-white p-5">
                                <p className="text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                    Indexed sites
                                </p>
                                <p className="mt-2 font-display text-3xl font-bold tabular-nums text-ink">
                                    {result.indexed_count?.toLocaleString?.() ?? result.indexed_count ?? '—'}
                                </p>
                            </div>
                        </div>
                    </div>

                    {typeof result.better_than_percent === 'number' ? (
                        <p className="mt-6 text-sm font-semibold text-signal-strong">
                            Better than {result.better_than_percent}% of websites analyzed by Rankway.
                        </p>
                    ) : null}

                    <div className="mt-10 grid gap-3 sm:grid-cols-2">
                        <div className="rounded-xl border border-line bg-white px-4 py-3 text-sm">
                            <span className="text-ink-muted">SEO score</span>
                            <span className="float-right font-semibold tabular-nums text-ink">
                                {result.scores?.seo ?? '—'}
                            </span>
                        </div>
                        <div className="rounded-xl border border-line bg-white px-4 py-3 text-sm">
                            <span className="text-ink-muted">Performance</span>
                            <span className="float-right font-semibold tabular-nums text-ink">
                                {result.scores?.performance ?? '—'}
                            </span>
                        </div>
                        {unlocked ? (
                            <>
                                <div className="rounded-xl border border-line bg-white px-4 py-3 text-sm">
                                    <span className="text-ink-muted">Backlinks</span>
                                    <span className="float-right font-semibold tabular-nums text-ink">
                                        {result.metrics?.backlinks?.toLocaleString?.() ??
                                            result.metrics?.backlinks ??
                                            '—'}
                                    </span>
                                </div>
                                <div className="rounded-xl border border-line bg-white px-4 py-3 text-sm">
                                    <span className="text-ink-muted">Referring domains</span>
                                    <span className="float-right font-semibold tabular-nums text-ink">
                                        {result.metrics?.referring_domains?.toLocaleString?.() ??
                                            result.metrics?.referring_domains ??
                                            '—'}
                                    </span>
                                </div>
                            </>
                        ) : (
                            <>
                                <LockedRow label="Backlinks" />
                                <LockedRow label="Keyword opportunities" />
                                <LockedRow label="Competitor analysis" />
                                <LockedRow label="AI SEO recommendations" />
                            </>
                        )}
                    </div>

                    {!unlocked ? (
                        <div className="mt-10 rounded-2xl border border-signal/30 bg-signal-soft/40 p-6 sm:p-8">
                            <h3 className="font-display text-xl font-bold text-ink">
                                Unlock full SEO report
                            </h3>
                            <p className="mt-2 max-w-xl text-sm text-ink-muted">
                                Create a free RankwayAI account to unlock backlinks, keyword
                                opportunities, and AI fixes — then improve this score from the SEO hub.
                            </p>
                            <div className="mt-5 flex flex-wrap gap-3">
                                <Link
                                    href={registerHref}
                                    className="inline-flex items-center justify-center rounded-lg bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90"
                                >
                                    Create free account
                                </Link>
                                <Link
                                    href={loginHref}
                                    className="inline-flex items-center justify-center rounded-lg border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink hover:border-signal/40"
                                >
                                    Log in
                                </Link>
                            </div>
                        </div>
                    ) : (
                        <div className="mt-10 rounded-2xl border border-line bg-white p-6">
                            <h3 className="font-display text-xl font-bold text-ink">
                                Improve this score in RankwayAI
                            </h3>
                            <p className="mt-2 text-sm text-ink-muted">
                                Open the SEO Rank tab to track history, fix issues, and grow visibility.
                            </p>
                            <Link
                                href={route('seo.index', { tab: 'rank' })}
                                className="mt-4 inline-flex items-center justify-center rounded-lg bg-ink px-4 py-2.5 text-sm font-semibold text-white hover:bg-ink/90"
                            >
                                Open SEO → Rank
                            </Link>
                        </div>
                    )}
                </section>
            ) : (
                <section className="mx-auto max-w-3xl px-6 py-14">
                    <p className="text-sm leading-relaxed text-ink-muted">
                        Enter any public domain. We probe technical SEO signals and estimate a
                        Rankway Score. Ranks are relative to websites analyzed on Rankway — not a
                        claim of Alexa or Google traffic rank.
                    </p>
                </section>
            )}
        </MarketingLayout>
    );
}
