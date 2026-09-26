import BrandLogo from '@/Components/BrandLogo';
import ApplicationLogo from '@/Components/ApplicationLogo';
import CreateWorkspaceModal from '@/Components/CreateWorkspaceModal';
import Dropdown from '@/Components/Dropdown';
import SocialConnectionAlertModal from '@/Components/SocialConnectionAlertModal';
import { AppFeedback } from '@/Components/ToastProvider';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const SIDEBAR_SECTIONS = [
    {
        title: 'Dashboard',
        icon: 'today',
        items: [{ label: 'Dashboard', key: 'today', icon: 'today', tone: 'amber' }],
    },
    {
        title: 'Business',
        icon: 'platform',
        items: [
            {
                label: 'Business Profile',
                key: 'business',
                icon: 'platform',
                tone: 'signal',
            },
            { label: 'Brand Kit', key: 'brand', icon: 'brand', tone: 'rose' },
        ],
    },
    {
        title: 'Marketing',
        icon: 'social',
        items: [
            { label: 'Social Media', key: 'social', icon: 'social', tone: 'fuchsia' },
            { label: 'SEO', key: 'seo', icon: 'seo', tone: 'emerald' },
            { label: 'WhatsApp', key: 'whatsapp', icon: 'whatsapp', tone: 'emerald' },
            { label: 'Email', icon: 'channels', tone: 'sky', comingSoon: true },
            { label: 'SMS', icon: 'channels', tone: 'sky', comingSoon: true },
        ],
    },
    {
        title: 'Leads',
        icon: 'funnels',
        items: [
            { label: 'Lead Forms', icon: 'funnels', tone: 'fuchsia', comingSoon: true },
            { label: 'Landing Pages', key: 'funnels', icon: 'funnels', tone: 'fuchsia' },
            { label: 'Leads', key: 'crm', icon: 'crm', tone: 'amber', match: 'crm.*' },
            { label: 'Automations', icon: 'crm', tone: 'fuchsia', comingSoon: true },
        ],
    },
    {
        title: 'Sales',
        icon: 'crm',
        items: [
            { label: 'Customers', icon: 'crm', tone: 'sky', comingSoon: true },
            {
                label: 'Quotations',
                key: 'studio',
                routeName: 'studio.quotations.index',
                match: 'studio.quotations.*',
                icon: 'studio',
                tone: 'violet',
            },
            { label: 'Proposals', icon: 'studio', tone: 'fuchsia', comingSoon: true },
            { label: 'Bookings', icon: 'crm', tone: 'amber', comingSoon: true },
            { label: 'Payments', icon: 'platform', tone: 'emerald', comingSoon: true },
        ],
    },
    {
        title: 'Business Studio',
        icon: 'studio',
        items: [
            {
                label: 'Business Card',
                key: 'studio',
                routeName: 'studio.cards.index',
                match: 'studio.cards.*',
                icon: 'studio',
                tone: 'violet',
            },
            {
                label: 'Brochure',
                key: 'studio',
                routeName: 'studio.brochures.index',
                match: 'studio.brochures.*',
                icon: 'media',
                tone: 'sky',
            },
            { label: 'Catalogue', icon: 'blog', tone: 'fuchsia', comingSoon: true },
            {
                label: 'Itinerary',
                key: 'studio',
                routeName: 'studio.itineraries.index',
                match: 'studio.itineraries.*',
                icon: 'today',
                tone: 'emerald',
            },
            { label: 'Document Studio', icon: 'studio', tone: 'rose', comingSoon: true },
        ],
    },
    {
        title: 'Analytics',
        icon: 'analytics',
        items: [
            { label: 'Overview', key: 'analytics', icon: 'analytics', tone: 'emerald' },
            { label: 'Marketing', key: 'analytics', icon: 'social', tone: 'emerald', noHighlight: true },
            { label: 'Leads', key: 'analytics', icon: 'crm', tone: 'emerald', noHighlight: true },
            { label: 'Sales & Revenue', key: 'analytics', icon: 'analytics', tone: 'emerald', noHighlight: true },
            { label: 'Documents', key: 'analytics', icon: 'studio', tone: 'emerald', noHighlight: true },
        ],
    },
    {
        title: 'Workspace',
        icon: 'workspace',
        items: [
            {
                label: 'Team',
                key: 'settings',
                routeName: 'settings.index',
                routeParams: { tab: 'workspace' },
                match: 'settings.*',
                icon: 'workspace',
                tone: 'signal',
                tabKey: 'workspace',
            },
            {
                label: 'Integrations',
                key: 'settings',
                routeName: 'settings.index',
                routeParams: { tab: 'providers' },
                match: 'settings.*',
                icon: 'platform',
                tone: 'signal',
                tabKey: 'providers',
            },
            {
                label: 'Settings',
                key: 'settings',
                routeName: 'settings.index',
                routeParams: { tab: 'account' },
                match: 'settings.*',
                icon: 'platform',
                tone: 'signal',
                tabKey: 'account',
            },
        ],
    },
];

const MODULE_DEFAULTS = {
    today: { routeName: 'dashboard', match: 'dashboard' },
    business: { routeName: 'business.edit', match: 'business.*' },
    brand: { routeName: 'brand.edit', match: 'brand.*' },
    social: { routeName: 'social.index', match: 'social.*', routeParams: { view: 'calendar' } },
    seo: { routeName: 'seo.index', match: 'seo.*' },
    whatsapp: { routeName: 'whatsapp.index', match: 'whatsapp.*' },
    funnels: { routeName: 'funnels.index', match: 'funnels.*' },
    crm: { routeName: 'crm.index', match: 'crm.*' },
    studio: { routeName: 'studio.cards.index', match: 'studio.*' },
    analytics: { routeName: 'analytics.index', match: 'analytics.*' },
    settings: { routeName: 'settings.index', match: 'settings.*' },
};

function RestrictedIcon({ className = 'h-3.5 w-3.5' }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            className={className}
            aria-hidden
        >
            <circle cx="12" cy="12" r="9" />
            <path d="M8 8l8 8M16 8l-8 8" />
        </svg>
    );
}

function NavIcon({ name, className = 'h-4 w-4' }) {
    const common = {
        fill: 'none',
        stroke: 'currentColor',
        strokeWidth: 1.8,
        strokeLinecap: 'round',
        strokeLinejoin: 'round',
        viewBox: '0 0 24 24',
        className,
        'aria-hidden': true,
    };

    switch (name) {
        case 'today':
            return (
                <svg {...common}>
                    <rect x="3" y="5" width="18" height="16" rx="2" />
                    <path d="M8 3v4M16 3v4M3 11h18" />
                </svg>
            );
        case 'brand':
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="8" />
                    <circle cx="12" cy="12" r="3" />
                </svg>
            );
        case 'studio':
            return (
                <svg {...common}>
                    <rect x="3" y="6" width="18" height="12" rx="2" />
                    <path d="M8 10h8M8 14h5" />
                    <circle cx="17" cy="14" r="1.5" fill="currentColor" stroke="none" />
                </svg>
            );
        case 'media':
            return (
                <svg {...common}>
                    <rect x="3" y="5" width="18" height="14" rx="2" />
                    <path d="m3 15 5-4 4 3 3-2 6 4" />
                    <circle cx="9" cy="9" r="1.5" fill="currentColor" stroke="none" />
                </svg>
            );
        case 'social':
            return (
                <svg {...common}>
                    <circle cx="18" cy="6" r="2.5" />
                    <circle cx="6" cy="12" r="2.5" />
                    <circle cx="18" cy="18" r="2.5" />
                    <path d="m8.2 10.8 5.6-3.6M8.2 13.2l5.6 3.6" />
                </svg>
            );
        case 'seo':
            return (
                <svg {...common}>
                    <circle cx="11" cy="11" r="7" />
                    <path d="M20 20l-3.5-3.5" />
                </svg>
            );
        case 'blog':
            return (
                <svg {...common}>
                    <path d="M5 4h14v16H5z" />
                    <path d="M8 8h8M8 12h8M8 16h5" />
                </svg>
            );
        case 'channels':
            return (
                <svg {...common}>
                    <path d="M4 7h7v10H4zM13 7h7v10h-7z" />
                    <path d="M7.5 10.5h0M7.5 13.5h0M16.5 10.5h0M16.5 13.5h0" />
                </svg>
            );
        case 'funnels':
            return (
                <svg {...common}>
                    <path d="M4 5h16l-5 7v5l-6 2v-7L4 5z" />
                </svg>
            );
        case 'whatsapp':
            return (
                <svg {...common}>
                    <path d="M7 18.5 4.5 20l.7-3.2A7.5 7.5 0 1 1 12 19.5a7.4 7.4 0 0 1-3.4-.9L7 18.5z" />
                    <path d="M9.2 10.8c.3-.5.6-.5.8-.5h.3c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.5l-.4.4c-.1.1-.2.3 0 .5.3.5 1.1 1.4 2.2 1.9.4.2.6.1.8 0l.5-.6c.1-.2.3-.2.5-.1l1.7.7c.3.1.4.3.4.5v.3c0 .2 0 .5-.5.8-.5.3-1.4.5-2.3.3-2.2-.4-4.6-2.2-5.7-4.4-.4-.8-.5-1.6-.3-2.2.2-.5.5-.7.8-.9z" />
                </svg>
            );
        case 'crm':
            return (
                <svg {...common}>
                    <circle cx="9" cy="8" r="3" />
                    <circle cx="17" cy="9" r="2.5" />
                    <path d="M3.5 18c.8-3 2.8-4.5 5.5-4.5S13.7 15 14.5 18" />
                    <path d="M14 14.2c1.5-.5 3-.3 4.5.8.7.5 1.2 1.3 1.5 3" />
                </svg>
            );
        case 'analytics':
            return (
                <svg {...common}>
                    <path d="M4 19V5M4 19h16" />
                    <path d="M8 16V11M12 16V8M16 16v-5" />
                </svg>
            );
        case 'workspace':
            return (
                <svg {...common}>
                    <path d="M4 7h16v12H4z" />
                    <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2" />
                </svg>
            );
        case 'platform':
            return (
                <svg {...common}>
                    <path d="M4 10h16v9H4z" />
                    <path d="M8 10V7a4 4 0 0 1 8 0v3" />
                </svg>
            );
        default:
            return (
                <svg {...common}>
                    <circle cx="12" cy="12" r="8" />
                </svg>
            );
    }
}

const SECTION_TONES = {
    Dashboard: {
        btn: 'bg-sky-100 text-sky-900 hover:bg-sky-200/80',
        active: 'bg-sky-200 text-sky-950 border-sky-400',
        subActive: 'bg-sky-200/90 text-sky-950',
        rail: 'border-sky-200',
    },
    Business: {
        btn: 'bg-rose-50 text-rose-800 hover:bg-rose-100',
        active: 'bg-rose-100 text-rose-950 border-rose-300',
        subActive: 'bg-rose-200/80 text-rose-950',
        rail: 'border-rose-200',
    },
    Marketing: {
        btn: 'bg-fuchsia-50 text-fuchsia-800 hover:bg-fuchsia-100',
        active: 'bg-fuchsia-100 text-fuchsia-950 border-fuchsia-300',
        subActive: 'bg-fuchsia-200/80 text-fuchsia-950',
        rail: 'border-fuchsia-200',
    },
    Leads: {
        btn: 'bg-amber-50 text-amber-900 hover:bg-amber-100',
        active: 'bg-amber-100 text-amber-950 border-amber-300',
        subActive: 'bg-amber-200/90 text-amber-950',
        rail: 'border-amber-200',
    },
    Sales: {
        btn: 'bg-violet-50 text-violet-800 hover:bg-violet-100',
        active: 'bg-violet-100 text-violet-950 border-violet-300',
        subActive: 'bg-violet-200/80 text-violet-950',
        rail: 'border-violet-200',
    },
    'Business Studio': {
        btn: 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
        active: 'bg-emerald-100 text-emerald-950 border-emerald-300',
        subActive: 'bg-emerald-200/80 text-emerald-950',
        rail: 'border-emerald-200',
    },
    Analytics: {
        btn: 'bg-teal-50 text-teal-800 hover:bg-teal-100',
        active: 'bg-teal-100 text-teal-950 border-teal-300',
        subActive: 'bg-teal-200/80 text-teal-950',
        rail: 'border-teal-200',
    },
    Workspace: {
        btn: 'bg-slate-100 text-slate-800 hover:bg-slate-200/80',
        active: 'bg-slate-200 text-slate-950 border-slate-400',
        subActive: 'bg-slate-200/90 text-slate-950',
        rail: 'border-slate-200',
    },
};

function itemIsActive(item, currentTab) {
    if (item.comingSoon || item.noHighlight || !item.match) return false;
    if (!route().current(item.match)) return false;
    if (item.tabKey) return currentTab === item.tabKey;
    return true;
}

function SubLink({ item, currentTab, onNavigate, tone }) {
    const active = itemIsActive(item, currentTab);
    const locked = Boolean(item.locked);
    const restricted = Boolean(item.restricted);
    const blocked = locked || restricted;
    const href = item.routeName && !item.comingSoon && !blocked ? route(item.routeName, item.routeParams || {}) : '#';
    const isDisabled = Boolean(item.comingSoon || !item.routeName || blocked);
    const tip = item.comingSoon
        ? 'Coming soon'
        : restricted
          ? 'Please enable this'
          : locked
            ? 'Paid plan required'
            : undefined;

    const handleClick = (event) => {
        if (isDisabled) {
            event.preventDefault();
            return;
        }
        onNavigate?.();
        const isSocial = item.key === 'social' || item.routeName === 'social.index';
        if (!isSocial || locked) return;
        event.preventDefault();
        window.location.assign(route('social.index', { view: 'calendar' }));
    };

    return (
        <Link
            href={href}
            preserveState={item.key === 'social' ? false : undefined}
            onClick={handleClick}
            title={tip}
            aria-disabled={isDisabled || undefined}
            className={
                'block rounded-md px-2.5 py-2 text-[14px] transition ' +
                (active && !blocked
                    ? `font-semibold ${tone.subActive}`
                    : isDisabled
                      ? 'cursor-not-allowed text-ink-muted/55'
                      : 'font-medium text-ink-muted hover:bg-black/[0.04] hover:text-ink')
            }
        >
            <span className="flex items-center justify-between gap-2">
                <span className="flex min-w-0 items-center gap-2">
                    <NavIcon name={item.icon} className="h-4 w-4 shrink-0 opacity-80" />
                    <span className="truncate">{item.label}</span>
                </span>
                {item.comingSoon ? (
                    <span className="shrink-0 text-[9px] font-bold uppercase tracking-wide text-ink-muted/70">
                        Soon
                    </span>
                ) : blocked ? (
                    <span
                        className="inline-flex shrink-0 items-center gap-1 text-ink-muted/70"
                        title={tip}
                    >
                        <RestrictedIcon />
                    </span>
                ) : null}
            </span>
        </Link>
    );
}

function TopLink({ item, currentTab, onNavigate, tone, collapsed = false }) {
    const active = itemIsActive(item, currentTab);
    const locked = Boolean(item.locked);
    const restricted = Boolean(item.restricted);
    const blocked = locked || restricted;
    const href = item.routeName && !item.comingSoon && !blocked ? route(item.routeName, item.routeParams || {}) : '#';
    const isDisabled = Boolean(item.comingSoon || !item.routeName || blocked);
    const tip = restricted
        ? 'Please enable this'
        : locked
          ? 'Paid plan required'
          : item.label;

    const handleClick = (event) => {
        if (isDisabled) {
            event.preventDefault();
            return;
        }
        onNavigate?.();
    };

    return (
        <Link
            href={href}
            onClick={handleClick}
            title={tip}
            aria-label={item.label}
            aria-disabled={isDisabled || undefined}
            className={
                'flex w-full items-center rounded-lg border transition ' +
                (collapsed ? 'justify-center px-2 py-2.5 ' : 'gap-2 px-3 py-2.5 text-[15px] font-semibold ') +
                (active && !blocked
                    ? tone.active
                    : isDisabled
                      ? 'cursor-not-allowed border-transparent text-ink-muted/55'
                      : 'border-transparent text-ink-muted hover:bg-black/[0.04] hover:text-ink')
            }
        >
            {blocked ? (
                <RestrictedIcon className="h-4 w-4 shrink-0" />
            ) : (
                <NavIcon name={item.icon} className="h-4 w-4 shrink-0" />
            )}
            {!collapsed ? (
                <>
                    <span className="min-w-0 flex-1 truncate">{item.label}</span>
                    {blocked ? (
                        <span className="shrink-0 text-[9px] font-bold uppercase tracking-wide text-ink-muted/70">
                            {locked ? 'Pro' : 'Off'}
                        </span>
                    ) : null}
                </>
            ) : null}
        </Link>
    );
}

function NavGroups({
    sections,
    openSections,
    onToggleSection,
    currentTab,
    onNavigate,
    collapsed = false,
    onExpandSection,
}) {
    return (
        <nav className="space-y-2">
            {sections.map((group) => {
                const tone = SECTION_TONES[group.title] || SECTION_TONES.Workspace;
                const isSingle = group.items.length === 1;
                const isOpen = Boolean(openSections[group.title]);

                if (isSingle) {
                    return (
                        <TopLink
                            key={group.title}
                            item={group.items[0]}
                            currentTab={currentTab}
                            onNavigate={onNavigate}
                            tone={tone}
                            collapsed={collapsed}
                        />
                    );
                }

                return (
                    <div
                        key={group.title}
                        className={
                            'overflow-hidden rounded-lg border transition ' +
                            (isOpen && !collapsed
                                ? 'border-line bg-white'
                                : 'border-transparent bg-transparent')
                        }
                    >
                        <button
                            type="button"
                            title={group.title}
                            aria-label={group.title}
                            onClick={() => {
                                if (collapsed) {
                                    onExpandSection?.(group.title);
                                    return;
                                }
                                onToggleSection(group.title);
                            }}
                            className={
                                'flex w-full items-center transition ' +
                                (collapsed
                                    ? 'justify-center rounded-lg px-2 py-2.5 '
                                    : 'justify-between gap-2 px-3 py-2.5 text-left text-[13px] font-bold uppercase tracking-[0.06em] ') +
                                (isOpen && !collapsed
                                    ? `rounded-t-lg ${tone.active}`
                                    : `rounded-lg ${tone.btn}`)
                            }
                        >
                            <span
                                className={
                                    'flex items-center ' +
                                    (collapsed ? '' : 'min-w-0 gap-2')
                                }
                            >
                                <NavIcon name={group.icon} className="h-4 w-4 shrink-0" />
                                {!collapsed ? (
                                    <span className="truncate">{group.title}</span>
                                ) : null}
                            </span>
                            {!collapsed ? (
                                <svg
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    className={
                                        'h-5 w-5 shrink-0 opacity-80 transition ' +
                                        (isOpen ? 'rotate-180' : '')
                                    }
                                    aria-hidden
                                >
                                    <path
                                        fillRule="evenodd"
                                        d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                        clipRule="evenodd"
                                    />
                                </svg>
                            ) : null}
                        </button>
                        {isOpen && !collapsed ? (
                            <div
                                className={`mx-2.5 mb-2 mt-1 space-y-0.5 border-l-2 bg-white py-0.5 pl-2.5 ${tone.rail}`}
                            >
                                {group.items.map((item) => (
                                    <SubLink
                                        key={`${group.title}-${item.label}`}
                                        item={item}
                                        currentTab={currentTab}
                                        onNavigate={onNavigate}
                                        tone={tone}
                                    />
                                ))}
                            </div>
                        ) : null}
                    </div>
                );
            })}
        </nav>
    );
}

export default function AuthenticatedLayout({ header, children }) {
    const page = usePage().props;
    const currentUrl = usePage().url || '';
    const user = page.auth.user;
    const workspaces = page.workspaces || [];
    const canCreateWorkspace = !!page.can_create_workspace;
    const activeWorkspace = page.activeWorkspace || null;
    const plan = page.plan || null;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(() => {
        if (typeof window === 'undefined') return true;
        try {
            return window.localStorage.getItem('rankway.sidebarCollapsed') !== '1';
        } catch {
            return true;
        }
    });
    const [openSections, setOpenSections] = useState({});

    const toggleDesktopSidebar = () => {
        setSidebarOpen((open) => {
            const next = !open;
            try {
                window.localStorage.setItem('rankway.sidebarCollapsed', next ? '0' : '1');
            } catch {
                // ignore
            }
            return next;
        });
    };
    const currentTab = useMemo(() => {
        const query = currentUrl.split('?')[1] || '';
        return new URLSearchParams(query).get('tab');
    }, [currentUrl]);

    const navItems = useMemo(() => {
        const allowed = plan?.modules || null;
        const markLocked = (item) => {
            const unlocked = plan?.unlocked ?? plan?.paid;
            const locked =
                Boolean(plan) &&
                unlocked === false &&
                Array.isArray(allowed) &&
                !allowed.includes(item.key || item.route);
            return { ...item, locked };
        };

        const shared = page.navigation || [];
        if (shared.length > 0) {
            return shared.map((item) =>
                markLocked({
                    key: item.key,
                    label: item.label,
                    routeName: item.route,
                    routeParams: item.params || {},
                    match: item.match,
                    icon: item.icon,
                    tone: item.tone,
                    group: item.group || 'Work',
                    tabKey: item.tabKey || null,
                }),
            );
        }

        return [];
    }, [page.navigation, plan, user?.is_superadmin]);

    const sidebarSections = useMemo(() => {
        const byKey = new Map(navItems.map((item) => [item.key, item]));

        return SIDEBAR_SECTIONS.map((section) => ({
            title: section.title,
            icon: section.icon || 'platform',
            items: section.items.map((spec) => {
                const base = spec.key ? byKey.get(spec.key) : null;
                const defaults = spec.key ? MODULE_DEFAULTS[spec.key] : null;
                const icon = spec.icon || base?.icon || 'platform';
                const tone = spec.tone || base?.tone || 'signal';
                const locked = Boolean(base?.locked);
                const comingSoon = Boolean(spec.comingSoon);
                const restricted = Boolean(spec.key && !comingSoon && !base);

                return {
                    key: spec.key || `${section.title}-${spec.label}`,
                    label: spec.label,
                    routeName: spec.routeName || base?.routeName || defaults?.routeName,
                    routeParams: spec.routeParams || base?.routeParams || defaults?.routeParams,
                    match: comingSoon ? spec.match : (spec.match || base?.match || defaults?.match),
                    icon,
                    tone,
                    locked,
                    restricted,
                    comingSoon,
                    noHighlight: Boolean(spec.noHighlight),
                    tabKey: spec.tabKey || base?.tabKey || null,
                };
            }),
        })).filter((section) => section.items.length > 0);
    }, [navItems]);

    useEffect(() => {
        const activeTitle = sidebarSections.find((section) =>
            section.items.some((item) => itemIsActive(item, currentTab)),
        )?.title;

        if (!activeTitle || activeTitle === 'Dashboard') return;

        setOpenSections((prev) => {
            if (prev[activeTitle]) return prev;
            return { [activeTitle]: true };
        });
    }, [sidebarSections, currentTab, currentUrl]);

    const toggleSection = (title) => {
        setOpenSections((prev) => {
            if (prev[title]) return {};
            return { [title]: true };
        });
    };

    const expandSidebarSection = (title) => {
        setSidebarOpen(true);
        try {
            window.localStorage.setItem('rankway.sidebarCollapsed', '0');
        } catch {
            // ignore
        }
        setOpenSections({ [title]: true });
    };

    const homeHref = navItems[0] ? route(navItems[0].routeName) : route('profile.edit');
    const impersonating = Boolean(page.impersonating);
    const simulatingUser = Boolean(page.simulatingUser);
    const impersonator = page.impersonator;
    const sidebarCollapsed = !sidebarOpen;

    const sidebarBody = (
        <>
            <div className="flex-1 overflow-y-auto overflow-x-hidden pr-0.5">
                <NavGroups
                    sections={sidebarSections}
                    openSections={openSections}
                    onToggleSection={toggleSection}
                    currentTab={currentTab}
                    onNavigate={() => setMobileOpen(false)}
                    collapsed={sidebarCollapsed}
                    onExpandSection={expandSidebarSection}
                />
            </div>

            {sidebarCollapsed ? (
                <div
                    className="mt-3 flex h-10 w-10 items-center justify-center self-center rounded-xl border border-line/80 bg-white/90 text-sm font-bold text-ink shadow-sm"
                    title={user?.name || 'Signed in'}
                >
                    {(user?.name || 'U').charAt(0).toUpperCase()}
                </div>
            ) : (
                <div className="mt-3 rounded-xl border border-line/80 bg-white/90 p-3 shadow-sm shadow-ink/5">
                    <div className="text-[10px] font-semibold uppercase tracking-[0.14em] text-ink-muted">
                        {simulatingUser
                            ? 'Simulating'
                            : user?.is_superadmin
                              ? impersonating
                                  ? 'Viewing as'
                                  : 'Admin'
                              : 'Signed in'}
                    </div>
                    <div className="mt-1 truncate text-sm font-semibold text-ink">{user?.name}</div>
                    <div className="truncate text-xs text-ink-muted">{user?.email}</div>
                    {simulatingUser ? (
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.leave-simulation'))}
                            className="mt-2 w-full rounded-md bg-amber-600 px-2 py-1.5 text-xs font-semibold text-white"
                        >
                            Exit simulation
                        </button>
                    ) : impersonating ? (
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.leave-workspace'))}
                            className="mt-2 w-full rounded-md bg-ink px-2 py-1.5 text-xs font-semibold text-white"
                        >
                            Exit workspace
                        </button>
                    ) : null}
                </div>
            )}
        </>
    );

    return (
        <div className="flex min-h-screen">
            <AppFeedback />
            <SocialConnectionAlertModal />
            <aside
                className={
                    'sticky top-0 z-30 hidden h-svh shrink-0 self-start flex-col border-r border-line bg-gradient-to-b from-[#fcfcfe] via-white to-[#f5f8fb] transition-[width] duration-200 ease-out lg:flex ' +
                    (sidebarCollapsed ? 'w-[4.75rem]' : 'w-[248px]')
                }
            >
                <div
                    className={
                        'flex h-[4.75rem] shrink-0 items-center border-b border-line/70 ' +
                        (sidebarCollapsed ? 'justify-center px-2' : 'px-3')
                    }
                >
                    <Link href={homeHref} className="flex items-center justify-center">
                        {sidebarCollapsed ? (
                            <ApplicationLogo className="h-12 w-12" alt="RankwayAI" />
                        ) : (
                            <BrandLogo className="h-12 w-auto max-w-[210px]" />
                        )}
                    </Link>
                </div>
                <div
                    className={
                        'flex min-h-0 flex-1 flex-col py-3 ' +
                        (sidebarCollapsed ? 'items-stretch px-2' : 'px-3')
                    }
                >
                    {sidebarBody}
                </div>
            </aside>

            <div className="flex min-h-screen min-w-0 flex-1 flex-col">
                {simulatingUser ? (
                    <div className="flex items-center justify-between gap-3 bg-amber-600 px-4 py-2 text-xs font-semibold text-white sm:px-6">
                        <span>
                            User simulator · {user?.name} ({user?.email})
                            {impersonator ? ` · started by ${impersonator.name}` : ''}
                        </span>
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.leave-simulation'))}
                            className="rounded-md bg-white/15 px-2.5 py-1 transition hover:bg-white/25"
                        >
                            Back to admin
                        </button>
                    </div>
                ) : impersonating ? (
                    <div className="flex items-center justify-between gap-3 bg-ink px-4 py-2 text-xs font-semibold text-white sm:px-6">
                        <span>Super admin view · {activeWorkspace?.name || 'Workspace'}</span>
                        <button
                            type="button"
                            onClick={() => router.post(route('admin.leave-workspace'))}
                            className="rounded-md bg-white/15 px-2.5 py-1 transition hover:bg-white/25"
                        >
                            Back to admin
                        </button>
                    </div>
                ) : null}
                <header className="sticky top-0 z-20 border-b border-line/70 bg-white/85 backdrop-blur-md">
                    <div className="flex h-[4.75rem] items-center justify-between gap-3 px-4 sm:gap-4 sm:px-6">
                        <div className="flex min-w-0 items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setMobileOpen((v) => !v)}
                                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-line text-ink transition hover:bg-mist lg:hidden"
                                aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                                aria-expanded={mobileOpen}
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.8"
                                    className="h-4 w-4"
                                    aria-hidden
                                >
                                    {mobileOpen ? (
                                        <path strokeLinecap="round" d="M6 6l12 12M18 6L6 18" />
                                    ) : (
                                        <path strokeLinecap="round" d="M4 6h16M4 12h16M4 18h16" />
                                    )}
                                </svg>
                            </button>
                            <button
                                type="button"
                                onClick={toggleDesktopSidebar}
                                className="hidden h-9 w-9 shrink-0 items-center justify-center rounded-md border border-line text-ink transition hover:bg-mist lg:inline-flex"
                                aria-label={sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'}
                                aria-expanded={sidebarOpen}
                                title={sidebarOpen ? 'Collapse sidebar' : 'Expand sidebar'}
                            >
                                <svg
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.8"
                                    className="h-4 w-4"
                                    aria-hidden
                                >
                                    <rect x="3" y="4" width="18" height="16" rx="2" />
                                    <path strokeLinecap="round" d="M9 4v16" />
                                    {sidebarOpen ? (
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M14 9l-3 3 3 3"
                                        />
                                    ) : (
                                        <path
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            d="M12 9l3 3-3 3"
                                        />
                                    )}
                                </svg>
                            </button>
                            <Link href={homeHref} className="flex min-w-0 items-center lg:hidden">
                                <BrandLogo className="h-10 w-auto max-w-[180px]" />
                            </Link>
                        </div>

                        <div className="hidden min-w-0 flex-1 items-center lg:flex">{header}</div>

                        <div className="ml-auto flex shrink-0 items-center gap-2">
                            {canCreateWorkspace ? (
                                <CreateWorkspaceModal
                                    buttonLabel="Create workspace"
                                    triggerClassName="!px-2.5 !py-1.5 text-xs sm:text-sm"
                                />
                            ) : null}

                            {workspaces.length > 0 ? (
                                <Dropdown>
                                    <Dropdown.Trigger>
                                        <button
                                            type="button"
                                            className="inline-flex max-w-[10rem] items-center gap-1.5 rounded-md border border-line bg-white px-2.5 py-1.5 text-sm font-semibold text-ink transition hover:border-signal/40 sm:max-w-[14rem]"
                                            title="Switch workspace"
                                        >
                                            <span className="truncate">
                                                {activeWorkspace?.name || 'Workspace'}
                                            </span>
                                            <svg
                                                className="h-3.5 w-3.5 shrink-0 text-ink-muted"
                                                viewBox="0 0 20 20"
                                                fill="currentColor"
                                                aria-hidden
                                            >
                                                <path
                                                    fillRule="evenodd"
                                                    d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                                                    clipRule="evenodd"
                                                />
                                            </svg>
                                        </button>
                                    </Dropdown.Trigger>
                                    <Dropdown.Content
                                        width="48"
                                        contentClasses="py-1 bg-white max-h-72 overflow-y-auto"
                                    >
                                        <div className="border-b border-line px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide text-ink-muted">
                                            Workspaces
                                        </div>
                                        {workspaces.map((ws) => {
                                            const active = activeWorkspace?.id === ws.id;
                                            return (
                                                <button
                                                    key={ws.id}
                                                    type="button"
                                                    disabled={active}
                                                    onClick={() =>
                                                        router.post(
                                                            route('workspaces.switch', ws.id),
                                                            { redirect: 'back' },
                                                            {
                                                                preserveScroll: false,
                                                                preserveState: false,
                                                            },
                                                        )
                                                    }
                                                    className={
                                                        'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm transition ' +
                                                        (active
                                                            ? 'bg-signal-soft/60 font-semibold text-ink'
                                                            : 'text-ink hover:bg-mist')
                                                    }
                                                >
                                                    <span className="truncate">{ws.name}</span>
                                                    {active ? (
                                                        <span className="shrink-0 text-[10px] font-bold uppercase text-signal-strong">
                                                            Active
                                                        </span>
                                                    ) : null}
                                                </button>
                                            );
                                        })}
                                        <div className="border-t border-line px-1 py-1">
                                            <Dropdown.Link
                                                href={route('settings.index', { tab: 'workspace' })}
                                            >
                                                Manage workspaces…
                                            </Dropdown.Link>
                                        </div>
                                    </Dropdown.Content>
                                </Dropdown>
                            ) : null}

                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-2 rounded-md border border-line bg-white px-2.5 py-1.5 text-sm font-semibold text-ink transition hover:border-signal/40"
                                    >
                                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-signal-soft text-xs font-bold text-signal-strong">
                                            {(user?.name || '?').charAt(0).toUpperCase()}
                                        </span>
                                        <span className="hidden max-w-[9rem] truncate sm:inline">
                                            {user?.name || 'Account'}
                                        </span>
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content
                                    width="52"
                                    contentClasses="overflow-hidden bg-white py-1.5"
                                >
                                    <div className="border-b border-line px-3 pb-2 pt-1.5">
                                        <div className="truncate text-sm font-semibold text-ink">
                                            {user?.name || 'Account'}
                                        </div>
                                        {user?.email ? (
                                            <div className="truncate text-xs text-ink-muted">
                                                {user.email}
                                            </div>
                                        ) : null}
                                    </div>
                                    <Dropdown.Link
                                        href={route('profile.edit')}
                                        className="!flex !items-center !gap-2.5 !px-3 !py-2.5 !text-ink hover:!bg-sky-50"
                                    >
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-600">
                                            <svg
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.8"
                                                className="h-4 w-4"
                                                aria-hidden
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                                />
                                            </svg>
                                        </span>
                                        <span>
                                            <span className="block text-sm font-semibold leading-tight">
                                                Profile
                                            </span>
                                            <span className="block text-xs font-normal text-ink-muted">
                                                Name & password
                                            </span>
                                        </span>
                                    </Dropdown.Link>
                                    {!user?.is_superadmin ? (
                                        <Dropdown.Link
                                            href={route('settings.index', { tab: 'workspace' })}
                                            className="!flex !items-center !gap-2.5 !px-3 !py-2.5 !text-ink hover:!bg-emerald-50"
                                        >
                                            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="1.8"
                                                    className="h-4 w-4"
                                                    aria-hidden
                                                >
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                                                    />
                                                    <path
                                                        strokeLinecap="round"
                                                        strokeLinejoin="round"
                                                        d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                                    />
                                                </svg>
                                            </span>
                                            <span>
                                                <span className="block text-sm font-semibold leading-tight">
                                                    Settings
                                                </span>
                                                <span className="block text-xs font-normal text-ink-muted">
                                                    Workspace & providers
                                                </span>
                                            </span>
                                        </Dropdown.Link>
                                    ) : null}
                                    <div className="my-1 border-t border-line" />
                                    <Dropdown.Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="!flex !items-center !gap-2.5 !px-3 !py-2.5 !text-rose-700 hover:!bg-rose-50"
                                    >
                                        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-rose-100 text-rose-600">
                                            <svg
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.8"
                                                className="h-4 w-4"
                                                aria-hidden
                                            >
                                                <path
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                                                />
                                            </svg>
                                        </span>
                                        <span>
                                            <span className="block text-sm font-semibold leading-tight">
                                                Sign Out
                                            </span>
                                            <span className="block text-xs font-normal text-rose-500/80">
                                                End this session
                                            </span>
                                        </span>
                                    </Dropdown.Link>
                                </Dropdown.Content>
                            </Dropdown>
                        </div>
                    </div>

                    {mobileOpen ? (
                        <div className="max-h-[70vh] space-y-1 overflow-y-auto border-t border-line bg-[#f7f8fb] px-3 py-3 lg:hidden">
                            <NavGroups
                                sections={sidebarSections}
                                openSections={openSections}
                                onToggleSection={toggleSection}
                                currentTab={currentTab}
                                onNavigate={() => setMobileOpen(false)}
                            />
                        </div>
                    ) : null}
                </header>

                <div className="border-b border-line/60 bg-white/60 px-4 py-3 lg:hidden">{header}</div>
                <main className="flex-1 animate-fade-in">{children}</main>
            </div>
        </div>
    );
}
