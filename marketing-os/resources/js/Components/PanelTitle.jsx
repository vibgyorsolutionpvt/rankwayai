import HelpGuide from '@/Components/HelpGuide';

/**
 * Consistent panel header with optional bilingual ⓘ help and right-side actions.
 */
export default function PanelTitle({ title, help, subtitle, action, className = '' }) {
    return (
        <div
            className={`flex items-start justify-between gap-3 border-b border-line px-4 py-3.5 ${className}`}
        >
            <div className="min-w-0">
                <div className="flex items-center gap-1.5">
                    <h3 className="font-display text-base font-bold tracking-tight text-ink sm:text-lg">
                        {title}
                    </h3>
                    {help ? <HelpGuide help={help} /> : null}
                </div>
                {subtitle ? (
                    <p className="mt-0.5 text-xs text-ink-muted sm:text-sm">{subtitle}</p>
                ) : null}
            </div>
            {action ? <div className="shrink-0">{action}</div> : null}
        </div>
    );
}
