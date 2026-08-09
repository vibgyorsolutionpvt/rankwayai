function MailIcon({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path
                d="M4 7.5A1.5 1.5 0 0 1 5.5 6h13A1.5 1.5 0 0 1 20 7.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 16.5v-9Z"
                stroke="currentColor"
                strokeWidth="1.75"
            />
            <path
                d="m5 8 7 5 7-5"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}

function PhoneIcon({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path
                d="M8.2 4.8c.4-.9 1.4-1.3 2.3-.9l1.7.7c.8.3 1.2 1.2 1 2.1l-.4 1.6c-.1.5 0 1 .4 1.3l1.8 1.8c.3.3.8.5 1.3.4l1.6-.4c.9-.2 1.8.2 2.1 1l.7 1.7c.4.9 0 1.9-.9 2.3l-1.3.6c-1.5.7-3.3.4-5.5-1.1-2.1-1.5-3.7-3.5-4.5-5.5-.9-2.2-1-4 .1-5.4l.6-1.2Z"
                stroke="currentColor"
                strokeWidth="1.75"
                strokeLinejoin="round"
            />
        </svg>
    );
}

export default function ContactChannels({
    email,
    phone,
    className = '',
    compact = false,
}) {
    const phoneHref = phone ? `tel:${String(phone).replace(/[^\d+]/g, '')}` : null;

    const channels = [
        email
            ? {
                  key: 'email',
                  label: 'Email',
                  value: email,
                  href: `mailto:${email}`,
                  hint: 'Reply within one business day',
                  icon: MailIcon,
              }
            : null,
        phone && phoneHref
            ? {
                  key: 'phone',
                  label: 'Call / WhatsApp',
                  value: phone,
                  href: phoneHref,
                  hint: 'Mon–Sat, 10:00 AM – 7:00 PM IST',
                  icon: PhoneIcon,
              }
            : null,
    ].filter(Boolean);

    if (!channels.length) {
        return null;
    }

    if (compact) {
        return (
            <div className={`flex flex-row flex-wrap items-center gap-x-5 gap-y-2 ${className}`}>
                {channels.map((item) => {
                    const Icon = item.icon;
                    return (
                        <a
                            key={item.key}
                            href={item.href}
                            className="group inline-flex items-center gap-2 text-ink-muted transition hover:text-ink"
                        >
                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-signal/10 text-signal-strong transition group-hover:bg-signal group-hover:text-white">
                                <Icon className="h-3.5 w-3.5" />
                            </span>
                            <span className="text-xs font-semibold text-ink">{item.value}</span>
                        </a>
                    );
                })}
            </div>
        );
    }

    return (
        <div className={`grid gap-4 sm:grid-cols-2 ${className}`}>
            {channels.map((item) => {
                const Icon = item.icon;
                return (
                    <a
                        key={item.key}
                        href={item.href}
                        className="group relative flex items-start gap-4 overflow-hidden rounded-xl border border-line bg-white/80 px-5 py-5 transition duration-200 hover:-translate-y-0.5 hover:border-signal/50 hover:shadow-panel"
                    >
                        <span className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-signal/50 to-transparent opacity-0 transition group-hover:opacity-100" />
                        <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-signal/10 text-signal-strong transition group-hover:bg-signal group-hover:text-white">
                            <Icon className="h-5 w-5" />
                        </span>
                        <span className="min-w-0 pt-0.5">
                            <span className="block text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                                {item.label}
                            </span>
                            <span className="mt-1.5 block font-display text-lg font-semibold tracking-tight text-ink transition group-hover:text-signal-strong sm:text-xl">
                                {item.value}
                            </span>
                            <span className="mt-1.5 block text-sm leading-relaxed text-ink-muted">
                                {item.hint}
                            </span>
                        </span>
                    </a>
                );
            })}
        </div>
    );
}
