import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import HelpGuide, { HELP } from '@/Components/HelpGuide';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, router } from '@inertiajs/react';

export default function Index({ workspace, brand, site, seoTasks, posts, keywords, counts }) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        {workspace.name}
                    </div>
                    <div className="flex items-center gap-1.5">
                        <h2 className="font-display text-2xl font-bold text-ink">Today</h2>
                        <HelpGuide help={HELP.today} />
                    </div>
                </div>
            }
        >
            <Head title="Today" />

            <div className="atlas-shell space-y-2.5 stagger">
                <section className="atlas-panel flex flex-wrap items-center justify-between gap-2 p-3">
                    <div className="min-w-0">
                        <div className="flex items-center gap-1">
                            <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                AI assist
                            </div>
                            <HelpGuide help={HELP.ai} className="!h-6 !w-6" />
                        </div>
                        <p className="mt-0.5 text-sm text-ink-muted">
                            Draft posts for today (approval required).
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        <PrimaryButton
                            type="button"
                            onClick={() => router.post(route('ai.generate-today'))}
                        >
                            Generate today’s posts
                        </PrimaryButton>
                        <Link
                            href={route('ai.index')}
                            className="inline-flex items-center rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink"
                        >
                            Open AI studio
                        </Link>
                    </div>
                </section>

                <section className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    {[
                        {
                            label: 'SEO tasks',
                            value: counts.open_seo_tasks,
                            href: route('seo.index'),
                        },
                        {
                            label: 'Scheduled posts',
                            value: counts.scheduled_posts,
                            href: route('social.index'),
                        },
                        { label: 'Open issues', value: counts.issues, href: route('seo.index') },
                        { label: 'Media assets', value: counts.media, href: route('media.index') },
                        {
                            label: 'Open leads',
                            value: counts.open_leads ?? 0,
                            href: route('crm.index'),
                        },
                        {
                            label: 'Channel campaigns',
                            value: counts.channel_campaigns ?? 0,
                            href: route('channels.index'),
                        },
                    ].map((card) => (
                        <Link
                            key={card.label}
                            href={card.href}
                            className="atlas-panel flex items-center gap-3 p-3 transition hover:border-signal/40"
                        >
                            <div className="min-w-0">
                                <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                                    {card.label}
                                </div>
                                <div className="mt-0.5 font-display text-2xl font-bold tabular-nums leading-tight text-ink">
                                    {card.value}
                                </div>
                            </div>
                        </Link>
                    ))}
                </section>

                <section className="grid gap-2.5 lg:grid-cols-2">
                    <div className="atlas-panel overflow-hidden">
                        <div className="flex items-center justify-between border-b border-line px-3 py-2">
                            <div className="flex items-center gap-1">
                                <h3 className="font-display text-base font-bold text-ink">
                                    Do these SEO tasks
                                </h3>
                                <HelpGuide help={HELP.seo} className="!h-6 !w-6" />
                            </div>
                            <Link
                                href={route('seo.index')}
                                className="text-sm font-semibold text-signal-strong"
                            >
                                Open SEO
                            </Link>
                        </div>
                        <ul className="max-h-[320px] divide-y divide-line overflow-y-auto">
                            {seoTasks.length === 0 ? (
                                <li className="px-3 py-5 text-sm text-ink-muted">
                                    No open tasks. Connect a site in SEO to generate today’s work.
                                </li>
                            ) : (
                                seoTasks.map((task) => (
                                    <li
                                        key={task.id}
                                        className="flex items-center justify-between gap-2 px-3 py-2"
                                    >
                                        <div className="min-w-0">
                                            <div className="truncate text-sm font-semibold text-ink">
                                                {task.title}
                                            </div>
                                            <div className="text-[10px] font-bold uppercase tracking-wide text-ink-muted">
                                                {task.priority} · {task.source || 'manual'}
                                            </div>
                                        </div>
                                        <PrimaryButton
                                            className="shrink-0 !px-3 !py-1.5"
                                            onClick={() =>
                                                router.post(route('seo.tasks.complete', task.id))
                                            }
                                        >
                                            Done
                                        </PrimaryButton>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>

                    <div className="atlas-panel overflow-hidden">
                        <div className="flex items-center justify-between border-b border-line px-3 py-2">
                            <div className="flex items-center gap-1">
                                <h3 className="font-display text-base font-bold text-ink">
                                    SMM queue
                                </h3>
                                <HelpGuide help={HELP.social} className="!h-6 !w-6" />
                            </div>
                            <Link
                                href={route('social.index')}
                                className="text-sm font-semibold text-signal-strong"
                            >
                                Open SMM
                            </Link>
                        </div>
                        <ul className="max-h-[320px] divide-y divide-line overflow-y-auto">
                            {posts.length === 0 ? (
                                <li className="px-3 py-5 text-sm text-ink-muted">
                                    No drafts or posts for today. Compose one in Social.
                                </li>
                            ) : (
                                posts.map((post) => (
                                    <li key={post.id} className="px-3 py-2">
                                        <div className="flex items-center justify-between gap-2">
                                            <div className="min-w-0 truncate text-sm font-semibold text-ink">
                                                {post.title || 'Untitled post'}
                                            </div>
                                            <span className="shrink-0 rounded-md bg-mist px-1.5 py-0.5 text-[10px] font-bold uppercase text-ink-muted">
                                                {post.status}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 line-clamp-1 text-[11px] text-ink-muted">
                                            {post.body}
                                        </p>
                                    </li>
                                ))
                            )}
                        </ul>
                    </div>
                </section>

                <section className="grid gap-2.5 lg:grid-cols-2">
                    <div className="atlas-panel p-3">
                        <div className="flex items-center justify-between gap-2">
                            <div className="flex items-center gap-1">
                                <h3 className="font-display text-base font-bold text-ink">
                                    Brand pulse
                                </h3>
                                <HelpGuide help={HELP.brand} className="!h-6 !w-6" />
                            </div>
                            {brand ? (
                                <Link
                                    href={route('brand.edit')}
                                    className="text-sm font-semibold text-signal-strong"
                                >
                                    Edit
                                </Link>
                            ) : null}
                        </div>
                        {brand ? (
                            <div className="mt-2 flex items-center gap-2.5">
                                <div
                                    className="h-9 w-9 shrink-0 rounded-md border border-line"
                                    style={{ background: brand.primary_color }}
                                />
                                <div className="min-w-0">
                                    <div className="text-sm font-semibold text-ink">
                                        {brand.font_family}
                                    </div>
                                    <div className="text-[11px] text-ink-muted">
                                        CTA: {brand.default_cta_label || '—'}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <p className="mt-2 text-sm text-ink-muted">
                                Set brand colors and CTA once, reuse everywhere.
                            </p>
                        )}
                    </div>

                    <div className="atlas-panel p-3">
                        <div className="flex items-center gap-1">
                            <h3 className="font-display text-base font-bold text-ink">
                                Rank snapshot
                            </h3>
                            <HelpGuide help={HELP.seo} className="!h-6 !w-6" />
                        </div>
                        {keywords.length === 0 ? (
                            <p className="mt-2 text-sm text-ink-muted">
                                {site
                                    ? 'Add keywords in SEO.'
                                    : 'Connect a website in SEO to track ranks.'}
                            </p>
                        ) : (
                            <ul className="mt-2 max-h-[140px] space-y-1 overflow-y-auto">
                                {keywords.map((kw) => (
                                    <li
                                        key={kw.id}
                                        className="flex items-center justify-between text-sm"
                                    >
                                        <span className="truncate font-medium text-ink">
                                            {kw.keyword}
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums text-ink">
                                            #{kw.position ?? '—'}
                                            <span className="ms-1.5 text-ink-muted">
                                                {kw.position_change > 0
                                                    ? `+${kw.position_change}`
                                                    : kw.position_change}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
