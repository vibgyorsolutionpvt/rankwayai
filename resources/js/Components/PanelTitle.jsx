import HelpGuide from '@/Components/HelpGuide';

/**
 * Consistent panel header with optional bilingual ⓘ help and right-side actions.
 */
export default function PanelTitle({ title, help, subtitle, action, className = '' }) {
    return (
        <div
            className={`flex items-start justify-between gap-3 border-b border-line bg-gradient-to-r from-signal-soft/25 via-white to-transparent px-5 py-5 ${className}`}
        >
            <div className="min-w-0">
                <div className="flex items-center gap-1.5">
                    <h3 className="font-display text-lg font-bold tracking-tight text-ink sm:text-xl">
                        {title}
                    </h3>
                    {help ? <HelpGuide help={help} /> : null}
                </div>
                {subtitle ? (
                    <p className="mt-1.5 text-sm leading-relaxed text-ink-muted">{subtitle}</p>
                ) : null}
            </div>
            {action ? <div className="shrink-0 self-center">{action}</div> : null}
        </div>
    );
}
